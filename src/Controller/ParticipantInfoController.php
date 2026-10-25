<?php

namespace App\Controller;

use App\Service\PermissionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ParticipantInfoController extends BaseController
{
    private const MAX_ROWS_PER_SECTION = 200;
    private const LOTTERY_TICKET_STATUS_LABELS = [
        'nie_sprawdzany' => 'Nie sprawdzany',
        'sprawdzony_ai' => 'Sprawdzony AI',
        'sprawdzony_recznie' => 'Sprawdzony ręcznie',
        'do_sprawdzenia' => 'Do sprawdzenia',
        'odrzucony' => 'Odrzucony',
    ];
    private const WINNER_STATUS_LABELS = [
        'wyslane' => 'Wysłane',
        'do_sprawdzenia' => 'Do sprawdzenia',
        'do_weryfikacji' => 'Do weryfikacji',
        'sprawdzany_przez_ai' => 'Sprawdzany przez AI',
        'ai_sprawdzone' => 'AI Sprawdzone - poprawnie',
        'ai_sprawdzone_niepoprawnie' => 'AI Sprawdzone - niepoprawnie',
        'sprawdzone' => 'Sprawdzone',
        'odrzucone' => 'Odrzucone',
        'reklamacja' => 'Reklamacja',
    ];

    public function index(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if ($response = $this->denyUnlessPermission('zwyciezcy', PermissionService::ACTION_EDIT)) {
            return $response;
        }

        $email = mb_strtolower(trim((string) $request->query->get('email', '')));
        $results = null;

        if ($email !== '') {
            $results = $this->collectParticipantDataByEmail($email);
        }

        return $this->render('backend/participant_info.html.twig', [
            'user' => $this->authService->user(),
            'email' => $email,
            'results' => $results,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function collectParticipantDataByEmail(string $email): array
    {
        $sections = [
            [
                'key' => 'submissions',
                'label' => 'Zgłoszenia',
                'moduleKey' => 'loteria_zgloszenia',
                'idColumn' => 'id',
                'table' => 'loteria_zgloszenia_entries',
                'columns' => ['id', 'loteria', 'email', 'phone', 'purchase_document_number', 'purchase_amount', 'seller_code', 'submitted_at'],
                'whereColumn' => 'email',
            ],
            [
                'key' => 'tickets',
                'label' => 'Losy',
                'moduleKey' => 'loteria_losy',
                'idColumn' => 'id',
                'table' => 'loteria_losy_entries',
                'columns' => ['id', 'ticket_id', 'email', 'phone', 'prize_name', 'wygrany', 'status', 'submitted_at'],
                'whereColumn' => 'email',
            ],
            [
                'key' => 'scratchcards',
                'label' => 'Zdrapki',
                'moduleKey' => 'loteria_zdrapki',
                'idColumn' => 'id',
                'table' => 'loteria_zdrapki_entries',
                'columns' => ['id', 'ticket_id', 'email', 'phone', 'wygrany', 'status', 'submitted_at', 'clicked_at'],
                'whereColumn' => 'email',
            ],
            [
                'key' => 'winners',
                'label' => 'Zwycięzcy',
                'moduleKey' => 'zwyciezcy',
                'idColumn' => 'id',
                'table' => 'loteria_zwyciezcy_entries',
                'columns' => ['id', 'email', 'imie', 'nazwisko', 'telefon', 'stan', 'rodzaj', 'email_o_wygranej_wyslany_at', 'rachunek'],
                'whereColumn' => 'email',
            ],
            [
                'key' => 'seller_tickets',
                'label' => 'Losy sprzedawców',
                'moduleKey' => 'sprzedawcy_losy',
                'idColumn' => 'id',
                'table' => 'sprzedawcy_losy_entries',
                'columns' => ['id', 'ticket_id', 'email', 'seller_email', 'seller_code', 'status', 'wygrany', 'submitted_at'],
                'whereColumn' => 'email',
            ],
            [
                'key' => 'email_logs',
                'label' => 'Logi wysłanych e-maili',
                'table' => 'email_logs',
                'columns' => ['id', 'recipient', 'subject', 'success', 'error', 'context', 'sent_at', 'sentAt'],
                'whereColumn' => 'recipient',
                'dateColumns' => ['sent_at', 'sentAt'],
            ],
            [
                'key' => 'email_queue',
                'label' => 'Kolejka e-mail',
                'table' => 'email_queue',
                'columns' => ['id', 'recipient', 'subject', 'status', 'attempts', 'max_attempts', 'last_error', 'created_at', 'createdAt', 'available_at', 'availableAt', 'sent_at', 'sentAt'],
                'whereColumn' => 'recipient',
                'dateColumns' => ['created_at', 'createdAt', 'available_at', 'availableAt', 'sent_at', 'sentAt'],
            ],
        ];

        $connection = $this->entityManager->getConnection();
        $schemaManager = $connection->createSchemaManager();

        $resolvedSections = [];
        $searchedTables = [];

        foreach ($sections as $section) {
            $tableName = $section['table'];
            if (!$schemaManager->tablesExist([$tableName])) {
                continue;
            }

            $availableColumns = array_keys($schemaManager->listTableColumns($tableName));
            $selectableColumns = array_values(array_intersect($section['columns'], $availableColumns));
            if ($selectableColumns === [] || !in_array($section['whereColumn'], $availableColumns, true)) {
                continue;
            }

            $searchedTables[$tableName] = true;
            $rows = $this->fetchRowsByEmail(
                $connection,
                $tableName,
                $selectableColumns,
                $section['whereColumn'],
                $email
            );


            if ($section['key'] === 'submissions') {
                $rows = $this->attachLotteryTypeToSubmissions($connection, $rows);
            }

            $displayRows = $this->normalizeDisplayValues($rows, $section['key']);
            $rowsWithMoreAction = $this->attachMoreAction($displayRows, $section);
            $latestDate = $this->findLatestDate($rows, $section);

            $resolvedSections[] = [
                'key' => $section['key'],
                'label' => $section['label'],
                'count' => count($rowsWithMoreAction),
                'rows' => $rowsWithMoreAction,
                'latestDate' => $latestDate,
            ];
        }

        $additionalMatches = $this->findAdditionalEmailMatches($connection, array_keys($searchedTables), $email);

        return [
            'sections' => $resolvedSections,
            'additionalMatches' => $additionalMatches,
            'additionalMatchesCount' => count($additionalMatches),
        ];
    }

    /**
     * @param string[] $columns
     * @return array<int, array<string, mixed>>
     */
    private function fetchRowsByEmail(Connection $connection, string $table, array $columns, string $whereColumn, string $email): array
    {
        $platform = $connection->getDatabasePlatform();
        $quotedColumns = array_map(
            static fn(string $column): string => $platform->quoteIdentifier($column),
            $columns
        );

        $sql = sprintf(
            'SELECT %s FROM %s WHERE LOWER(TRIM(%s)) = :email ORDER BY 1 DESC LIMIT %d',
            implode(', ', $quotedColumns),
            $platform->quoteIdentifier($table),
            $platform->quoteIdentifier($whereColumn),
            self::MAX_ROWS_PER_SECTION
        );

        return $connection->executeQuery($sql, ['email' => $email])->fetchAllAssociative();
    }


    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function attachLotteryTypeToSubmissions(Connection $connection, array $rows): array
    {
        $lotteryIds = [];
        foreach ($rows as $row) {
            $lotteryId = $row['loteria'] ?? null;
            if (!is_scalar($lotteryId)) {
                continue;
            }

            $normalizedId = trim((string) $lotteryId);
            if ($normalizedId === '') {
                continue;
            }

            $lotteryIds[$normalizedId] = true;
        }

        if ($lotteryIds === []) {
            return $rows;
        }

        foreach ($rows as &$row) {
            $lotteryId = isset($row['loteria']) && is_scalar($row['loteria']) ? trim((string) $row['loteria']) : '';
            $row['lottery_type'] = $lotteryId !== '' ? 'losowanie_zdrapki' : '';
        }

        unset($row);

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $section
     * @return array<int, array{values: array<string, mixed>, moreAction: ?array{label: string, url: string}}>
     */
    private function attachMoreAction(array $rows, array $section): array
    {
        $moduleKey = isset($section['moduleKey']) && is_string($section['moduleKey']) ? trim($section['moduleKey']) : '';
        $idColumn = isset($section['idColumn']) && is_string($section['idColumn']) ? trim($section['idColumn']) : 'id';

        $user = $this->authService->user();
        $canEdit = $moduleKey !== '' && $this->permissionService->can($user, $moduleKey, PermissionService::ACTION_EDIT);
        $canView = $moduleKey !== '' && $this->permissionService->can($user, $moduleKey, PermissionService::ACTION_VIEW);

        $result = [];

        foreach ($rows as $row) {
            $moreAction = null;
            $recordId = $row[$idColumn] ?? null;
            $recordId = is_scalar($recordId) ? trim((string) $recordId) : '';

            if ($moduleKey !== '' && $recordId !== '' && ($canEdit || $canView)) {
                $action = $canEdit ? 'edit' : 'view';
                $moreAction = [
                    'label' => $canEdit ? 'Edytuj' : 'Podgląd',
                    'url' => '/cms/modules/' . rawurlencode($moduleKey) . '/' . rawurlencode($recordId) . '/' . $action,
                ];
            }

            $result[] = [
                'values' => $row,
                'moreAction' => $moreAction,
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeDisplayValues(array $rows, string $sectionKey): array
    {
        foreach ($rows as &$row) {
            if ($sectionKey === 'tickets' || $sectionKey === 'seller_tickets') {
                $this->replaceValueWithLabel($row, 'status', self::LOTTERY_TICKET_STATUS_LABELS);
            }

            if ($sectionKey === 'winners') {
                $this->replaceValueWithLabel($row, 'stan', self::WINNER_STATUS_LABELS);
            }
        }

        unset($row);

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $labelsMap
     */
    private function replaceValueWithLabel(array &$row, string $fieldName, array $labelsMap): void
    {
        $value = $row[$fieldName] ?? null;
        if (!is_scalar($value)) {
            return;
        }

        $normalizedValue = trim((string) $value);
        if ($normalizedValue === '') {
            return;
        }

        $row[$fieldName] = $labelsMap[$normalizedValue] ?? $normalizedValue;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $section
     */
    private function findLatestDate(array $rows, array $section): ?string
    {
        $dateColumns = [];
        if (isset($section['dateColumns']) && is_array($section['dateColumns'])) {
            $dateColumns = array_filter($section['dateColumns'], static fn($column): bool => is_string($column) && $column !== '');
        }

        if ($dateColumns === []) {
            return null;
        }

        $latestTimestamp = null;
        $latestValue = null;

        foreach ($rows as $row) {
            foreach ($dateColumns as $dateColumn) {
                $rawValue = $row[$dateColumn] ?? null;
                if (!is_scalar($rawValue)) {
                    continue;
                }

                $dateValue = trim((string) $rawValue);
                if ($dateValue === '') {
                    continue;
                }

                $timestamp = strtotime($dateValue);
                if ($timestamp === false) {
                    continue;
                }

                if ($latestTimestamp === null || $timestamp > $latestTimestamp) {
                    $latestTimestamp = $timestamp;
                    $latestValue = $dateValue;
                }
            }
        }

        return $latestValue;
    }

    /**
     * @param string[] $alreadyHandledTables
     * @return array<int, array{table: string, column: string, count: int}>
     */
    private function findAdditionalEmailMatches(Connection $connection, array $alreadyHandledTables, string $email): array
    {
        $schemaManager = $connection->createSchemaManager();
        $platform = $connection->getDatabasePlatform();
        $matches = [];

        foreach ($schemaManager->listTableNames() as $tableName) {
            if (in_array($tableName, $alreadyHandledTables, true)) {
                continue;
            }

            $columns = $schemaManager->listTableColumns($tableName);

            foreach ($columns as $columnName => $column) {
                $normalizedColumnName = mb_strtolower((string) $columnName);
                if (!str_contains($normalizedColumnName, 'email') && $normalizedColumnName !== 'recipient') {
                    continue;
                }

                $sql = sprintf(
                    'SELECT COUNT(*) FROM %s WHERE LOWER(TRIM(%s)) = :email',
                    $platform->quoteIdentifier($tableName),
                    $platform->quoteIdentifier($columnName)
                );

                $count = (int) $connection->executeQuery($sql, ['email' => $email])->fetchOne();
                if ($count < 1) {
                    continue;
                }

                $matches[] = [
                    'table' => $tableName,
                    'column' => $columnName,
                    'count' => $count,
                ];
            }
        }

        usort($matches, static function (array $left, array $right): int {
            if ($left['count'] === $right['count']) {
                return strcmp($left['table'] . ':' . $left['column'], $right['table'] . ':' . $right['column']);
            }

            return $right['count'] <=> $left['count'];
        });

        return $matches;
    }
}
