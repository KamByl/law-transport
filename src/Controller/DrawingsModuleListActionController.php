<?php

namespace App\Controller;

use App\Service\DrawingProtocolDocumentGenerator;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DrawingsModuleListActionController extends ModuleListActionController
{
    /**
     * @var \Closure(string, array{module: array<string, mixed>, database: array<string, mixed>}): array<string, mixed>
     */
    private \Closure $resolveDrawingRecord;
    private ?Connection $connection;

    /** @var array<string, array<int, string>> */
    private array $tableColumns = [];

    /** @var array<string, bool> */
    private array $tableExistence = [];

    /**
     * @param \Closure(string, array{module: array<string, mixed>, database: array<string, mixed>}): array<string, mixed> $resolveDrawingRecord
     */
    public function __construct(?\Closure $resolveDrawingRecord = null, ?Connection $connection = null)
    {
        $this->resolveDrawingRecord = $resolveDrawingRecord ?? static fn(): array => [];
        $this->connection = $connection;
    }

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    public function openLotterySubmissions(Request $request, string $moduleKey, string $recordId, array $definition): ?Response
    {
        return new RedirectResponse('/cms/modules/loteria_zgloszenia?' . $this->buildLotteryQuery($recordId, $definition));
    }

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    public function openLotteryTickets(Request $request, string $moduleKey, string $recordId, array $definition): ?Response
    {
        return new RedirectResponse('/cms/modules/loteria_losy?' . $this->buildLotteryQuery($recordId, $definition, true));
    }

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    public function openLotteryScratchcards(Request $request, string $moduleKey, string $recordId, array $definition): ?Response
    {
        return new RedirectResponse('/cms/modules/loteria_zdrapki?' . $this->buildLotteryQuery($recordId, $definition));
    }

    /**
     * Exports one CSV row per eligible ticket.
     *
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    public function downloadLotteryTicketsCsv(Request $request, string $moduleKey, string $recordId, array $definition): ?Response
    {
        if (!$this->connection instanceof Connection) {
            return new Response('Brak połączenia z bazą danych.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $drawing = ($this->resolveDrawingRecord)($recordId, $definition);
        if ($drawing === []) {
            return new Response('Nie znaleziono losowania.', Response::HTTP_NOT_FOUND);
        }

        $ticketTable = 'loteria_losy_entries';
        $submissionTable = 'loteria_zgloszenia_entries';
        if (!$this->tableExists($ticketTable) || !$this->tableExists($submissionTable)) {
            return new Response('Brak tabel losów lub zgłoszeń.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        foreach (['start_date', 'end_date'] as $dateField) {
            $dateValue = trim((string) ($drawing[$dateField] ?? ''));
            if ($dateValue !== '' && !($this->parseDrawingDate($dateValue) instanceof \DateTimeImmutable)) {
                return new Response('Losowanie ma nieprawidłowy zakres dat.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $sourceDefinitions = [
            'ticket' => [
                'label' => 'Los',
                'columns' => array_values(array_diff(
                    $this->getTableColumns($ticketTable),
                    ['id', 'wygrany', 'status']
                )),
            ],
        ];
        $optionalSources = [
            'entry_code' => [
                'label' => 'Kod zgłoszeniowy',
                'table' => 'kody_zywiec_entries',
                'excluded_columns' => [
                    'id',
                    'wykorzystany_do_loterii',
                    'wykorzystany_do_konkursu',
                    'data_wykorzystania_do_loterii',
                    'data_wykorzystania_do_konkursu',
                ],
            ],
            'seller' => [
                'label' => 'Sprzedawca',
                'table' => 'sprzedawcy_entries',
                'excluded_columns' => [],
            ],
        ];
        foreach ($optionalSources as $sourceKey => $source) {
            $sourceDefinitions[$sourceKey] = [
                'label' => $source['label'],
                'columns' => $this->tableExists($source['table'])
                    ? array_values(array_diff($this->getTableColumns($source['table']), $source['excluded_columns']))
                    : [],
            ];
        }

        $headers = [];
        foreach ($sourceDefinitions as $sourceKey => $source) {
            foreach ($source['columns'] as $column) {
                if ($sourceKey === 'ticket' && $column === 'ticket_id') {
                    $headers[] = 'Los - ID';
                    continue;
                }
                $headers[] = sprintf('%s - %s', $source['label'], $column);
            }
        }
        $filename = sprintf(
            'losowanie-%s-losy-%s.csv',
            preg_replace('/[^A-Za-z0-9_-]+/', '-', $recordId) ?: '0',
            (new \DateTimeImmutable())->format('Y-m-d_His')
        );
        $response = new StreamedResponse(function () use (
            $drawing,
            $sourceDefinitions,
            $headers,
            $ticketTable,
            $submissionTable
        ): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Nie udało się otworzyć strumienia CSV.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers, ';', '"', '');

            $offset = 0;
            $batchSize = 1000;
            while (true) {
                $tickets = $this->buildEligibleTicketsQuery($drawing, $ticketTable, $submissionTable)
                    ->orderBy('t.id', 'ASC')
                    ->setFirstResult($offset)
                    ->setMaxResults($batchSize)
                    ->executeQuery()
                    ->fetchAllAssociative();
                if ($tickets === []) {
                    break;
                }

                $submissionIds = $this->uniquePositiveIntegers(array_column($tickets, 'zgloszenie'));
                $submissions = $this->fetchRowsByColumn($submissionTable, 'id', $submissionIds, ArrayParameterType::INTEGER);
                $submissionsById = $this->indexRowsBy($submissions, 'id');

                $entryCodeValues = array_values(array_unique(array_filter(array_map(
                    static fn(array $submission): string => trim((string) ($submission['seller_code'] ?? '')),
                    $submissions
                ), static fn(string $value): bool => $value !== '')));
                $entryCodes = $this->fetchRowsByColumn('kody_zywiec_entries', 'kod', $entryCodeValues, ArrayParameterType::STRING);
                $entryCodesByValue = $this->indexRowsBy($entryCodes, 'kod');
                $numericEntryCodeValues = $this->uniquePositiveIntegers($entryCodeValues);
                foreach ($this->fetchRowsByColumn('kody_zywiec_entries', 'id', $numericEntryCodeValues, ArrayParameterType::INTEGER) as $entryCodeRow) {
                    $entryCodeId = trim((string) ($entryCodeRow['id'] ?? ''));
                    if ($entryCodeId !== '' && !isset($entryCodesByValue[$entryCodeId])) {
                        $entryCodesByValue[$entryCodeId] = $entryCodeRow;
                    }
                }
                $sellers = $this->fetchRowsByColumn('sprzedawcy_entries', 'kod_sprzedawcy', $entryCodeValues, ArrayParameterType::STRING);
                $sellersByCode = $this->indexRowsBy($sellers, 'kod_sprzedawcy');
                foreach ($this->fetchRowsByColumn('sprzedawcy_entries', 'id', $numericEntryCodeValues, ArrayParameterType::INTEGER) as $sellerRow) {
                    $sellerId = trim((string) ($sellerRow['id'] ?? ''));
                    if ($sellerId !== '' && !isset($sellersByCode[$sellerId])) {
                        $sellersByCode[$sellerId] = $sellerRow;
                    }
                }

                foreach ($tickets as $ticket) {
                    $submissionId = (string) ($ticket['zgloszenie'] ?? '');
                    $submission = $submissionsById[$submissionId] ?? [];
                    $entryCode = trim((string) ($submission['seller_code'] ?? ''));

                    $sourceRows = [
                        'ticket' => $ticket,
                        'entry_code' => $entryCodesByValue[$entryCode] ?? [],
                        'seller' => $sellersByCode[$entryCode] ?? [],
                    ];
                    $line = [];
                    foreach ($sourceDefinitions as $sourceKey => $source) {
                        $sourceRow = $sourceRows[$sourceKey] ?? [];
                        foreach ($source['columns'] as $column) {
                            $line[] = $this->normalizeCsvCell($sourceRow[$column] ?? null);
                        }
                    }

                    fputcsv($output, $line, ';', '"', '');
                }

                $offset += count($tickets);
                if (count($tickets) < $batchSize) {
                    break;
                }
            }

            fclose($output);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    public function generateLotteryProtocolDocx(Request $request, string $moduleKey, string $recordId, array $definition): ?Response
    {
        if (!$this->connection instanceof Connection) {
            return new Response('Brak połączenia z bazą danych.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $drawing = ($this->resolveDrawingRecord)($recordId, $definition);
        if ($drawing === []) {
            return new Response('Nie znaleziono losowania.', Response::HTTP_NOT_FOUND);
        }

        foreach (['loteria_zwyciezcy_entries', 'loteria_losy_entries', 'rodzaje_nagrod_entries'] as $requiredTable) {
            if (!$this->tableExists($requiredTable)) {
                return new Response('Brak danych wymaganych do wygenerowania protokołu.', Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        $winnerColumns = $this->getTableColumns('loteria_zwyciezcy_entries');
        $ticketColumns = $this->getTableColumns('loteria_losy_entries');
        $prizeColumns = $this->getTableColumns('rodzaje_nagrod_entries');
        foreach (['rodzaj', 'losowanie', 'kupon_w_loterii', 'rodzaj_wygranej'] as $requiredColumn) {
            if (!in_array($requiredColumn, $winnerColumns, true)) {
                return new Response('Brak pola zwycięzcy wymaganego do wygenerowania protokołu.', Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }
        if (!in_array('ticket_id', $ticketColumns, true) || !in_array('name', $prizeColumns, true)) {
            return new Response('Brak identyfikatora losu lub nazwy nagrody.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $queryBuilder = $this->connection->createQueryBuilder()
            ->select(
                'w.id AS winner_id',
                'w.rodzaj_wygranej AS prize_type_id',
                'p.name AS prize_name',
                't.ticket_id AS public_ticket_id'
            )
            ->from('loteria_zwyciezcy_entries', 'w')
            ->innerJoin('w', 'loteria_losy_entries', 't', 't.id = w.kupon_w_loterii')
            ->innerJoin('w', 'rodzaje_nagrod_entries', 'p', 'p.id = w.rodzaj_wygranej')
            ->where('w.rodzaj = :winner_kind')
            ->andWhere('w.losowanie = :drawing_id')
            ->setParameter('winner_kind', 'los')
            ->setParameter('drawing_id', $recordId);

        if (in_array('wartosc_nagrody', $prizeColumns, true)) {
            $queryBuilder->addSelect('p.wartosc_nagrody AS prize_value')
                ->orderBy('p.wartosc_nagrody', 'DESC')
                ->addOrderBy('p.id', 'ASC');
        } else {
            $queryBuilder->orderBy('p.id', 'ASC');
        }
        $winners = $queryBuilder
            ->addOrderBy('w.id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        if ($winners === []) {
            return new Response('Do tego losowania nie przypisano żadnych zwycięzców.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $templatePath = dirname(__DIR__, 2) . '/resources/docx/protokol-losowania.docx';
        $outputPath = tempnam(sys_get_temp_dir(), 'protokol-losowania-');
        if (!is_string($outputPath)) {
            return new Response('Nie udało się przygotować pliku protokołu.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            (new DrawingProtocolDocumentGenerator())->generate($templatePath, $outputPath, $drawing, $winners);
        } catch (\InvalidArgumentException $exception) {
            @unlink($outputPath);
            return new Response($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $exception) {
            @unlink($outputPath);
            return new Response('Nie udało się wygenerować protokołu.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $safeRecordId = preg_replace('/[^A-Za-z0-9_-]+/', '-', $recordId) ?: '0';
        $drawingDate = $this->parseDrawingDate(trim((string) ($drawing['drawing_date'] ?? '')));
        $filename = sprintf(
            'protokol-losowania-%s-%s.docx',
            $safeRecordId,
            $drawingDate?->format('Y-m-d') ?? (new \DateTimeImmutable())->format('Y-m-d')
        );

        $response = new StreamedResponse(static function () use ($outputPath): void {
            try {
                readfile($outputPath);
            } finally {
                @unlink($outputPath);
            }
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        $response->headers->set('Content-Length', (string) filesize($outputPath));
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    private function buildEligibleTicketsQuery(array $drawing, string $ticketTable, string $submissionTable): \Doctrine\DBAL\Query\QueryBuilder
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('t.*')
            ->from($ticketTable, 't')
            ->innerJoin('t', $submissionTable, 's', 's.id = t.zgloszenie')
            ->where('(s.zgloszenie_sprawdzone IS NULL OR s.zgloszenie_sprawdzone <> :invalid_submission_status)')
            ->setParameter('invalid_submission_status', 'niepoprawne');

        $startDate = trim((string) ($drawing['start_date'] ?? ''));
        $endDate = trim((string) ($drawing['end_date'] ?? ''));
        if ($startDate !== '') {
            $parsedStartDate = $this->parseDrawingDate($startDate);
            if (!$parsedStartDate instanceof \DateTimeImmutable) {
                throw new \UnexpectedValueException('Nieprawidłowa data rozpoczęcia losowania.');
            }
            $queryBuilder->andWhere('t.submitted_at >= :drawing_start')
                ->setParameter('drawing_start', $parsedStartDate->format('Y-m-d 00:00:00'));
        }
        if ($endDate !== '') {
            $parsedEndDate = $this->parseDrawingDate($endDate);
            if (!$parsedEndDate instanceof \DateTimeImmutable) {
                throw new \UnexpectedValueException('Nieprawidłowa data zakończenia losowania.');
            }
            $nextDay = $parsedEndDate->modify('+1 day')->format('Y-m-d 00:00:00');
            $queryBuilder->andWhere('t.submitted_at < :drawing_end')
                ->setParameter('drawing_end', $nextDay);
        }

        $minimum = (int) trim((string) ($drawing['eligible_submissions_count'] ?? ''));
        if ($minimum > 1) {
            $eligibleEmails = $this->connection->createQueryBuilder()
                ->select('t_min.email')
                ->from($ticketTable, 't_min')
                ->innerJoin('t_min', $submissionTable, 's_min', 's_min.id = t_min.zgloszenie')
                ->where('(s_min.zgloszenie_sprawdzone IS NULL OR s_min.zgloszenie_sprawdzone <> :invalid_submission_status)')
                ->andWhere('t_min.email IS NOT NULL')
                ->andWhere("t_min.email <> ''")
                ->groupBy('t_min.email')
                ->having('COUNT(*) >= :eligible_ticket_minimum');
            if ($startDate !== '') {
                $eligibleEmails->andWhere('t_min.submitted_at >= :drawing_start');
            }
            if ($endDate !== '') {
                $eligibleEmails->andWhere('t_min.submitted_at < :drawing_end');
            }

            $queryBuilder->andWhere(sprintf('t.email IN (%s)', $eligibleEmails->getSQL()))
                ->setParameter('eligible_ticket_minimum', $minimum);
        }

        return $queryBuilder;
    }

    /**
     * @param array<int, int|string> $values
     * @return array<int, array<string, mixed>>
     */
    private function fetchRowsByColumn(string $table, string $column, array $values, int $parameterType): array
    {
        if ($values === [] || !$this->tableExists($table) || !in_array($column, $this->getTableColumns($table), true)) {
            return [];
        }

        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where($column . ' IN (:related_values)')
            ->setParameter('related_values', array_values(array_unique($values)), $parameterType);
        if (in_array('id', $this->getTableColumns($table), true)) {
            $queryBuilder->orderBy('id', 'ASC');
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableExistence)) {
            return $this->tableExistence[$table];
        }

        try {
            return $this->tableExistence[$table] = $this->connection instanceof Connection
                && $this->connection->createSchemaManager()->tablesExist([$table]);
        } catch (\Throwable $exception) {
            return $this->tableExistence[$table] = false;
        }
    }

    /** @return string[] */
    private function getTableColumns(string $table): array
    {
        if (isset($this->tableColumns[$table])) {
            return $this->tableColumns[$table];
        }
        if (!$this->connection instanceof Connection) {
            return [];
        }

        try {
            return $this->tableColumns[$table] = array_keys($this->connection->createSchemaManager()->listTableColumns($table));
        } catch (\Throwable $exception) {
            return $this->tableColumns[$table] = [];
        }
    }

    /**
     * @param array<int, mixed> $values
     * @return int[]
     */
    private function uniquePositiveIntegers(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): int => (int) $value,
            $values
        ), static fn(int $value): bool => $value > 0)));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexRowsBy(array $rows, string $column): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row[$column] ?? ''));
            if ($value !== '' && !isset($indexed[$value])) {
                $indexed[$value] = $row;
            }
        }

        return $indexed;
    }

    private function parseDrawingDate(string $value): ?\DateTimeImmutable
    {
        foreach (['!Y-m-d', '!d.m.Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if (
                $date instanceof \DateTimeImmutable
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            ) {
                return $date;
            }
        }

        return null;
    }

    private function normalizeCsvCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        } elseif (is_array($value) || is_object($value)) {
            $value = json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ) ?: '';
        }

        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
        if (preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) === 1) {
            $value = "'" . $value;
        }

        return $value;
    }

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    private function buildLotteryQuery(string $recordId, array $definition, bool $includeEligibleTicketsMinimum = false): string
    {
        $query = [
            'list_filters' => 'loteria,submitted_at',
            'loteria' => $recordId,
        ];

        if ($includeEligibleTicketsMinimum) {
            $query['exclude_invalid_submissions'] = '1';
        }

        $record = ($this->resolveDrawingRecord)($recordId, $definition);
        $eligibleTicketsMinimum = (int) trim((string) ($record['eligible_submissions_count'] ?? ''));
        if ($includeEligibleTicketsMinimum && $eligibleTicketsMinimum > 1) {
            $query['eligible_tickets_min'] = (string) $eligibleTicketsMinimum;
        }

        $startDate = trim((string) ($record['start_date'] ?? ''));
        $endDate = trim((string) ($record['end_date'] ?? ''));
        if ($startDate !== '') {
            $query['submitted_at_from'] = $startDate . ' 00:00:00';
        }
        if ($endDate !== '') {
            $query['submitted_at_to'] = $endDate . ' 23:59:59';
        }

        return http_build_query($query);
    }
}
