<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

final class WinnerStatusEmailService
{
    private const CODE_SHORTAGE_STATUS_ID = 'predefined:prize_granted_no_codes_email_template';

    public function __construct(private readonly Mailer $mailer)
    {
    }

    /**
     * @param array<string, mixed> $winnerRow
     * @return array{success: bool, message: string, error: string|null}
     */
    public function sendForStatus(
        Connection $connection,
        array $winnerRow,
        int $recordId,
        string $table,
        string $statusId,
        bool $allowResend,
        bool $afterCreation = false,
        bool $forceQueue = false
    ): array {
        $predefinedTemplateColumn = $this->resolvePredefinedTemplateColumn($statusId);
        $statusName = $this->predefinedStatusLabel($statusId);
        $prizeType = [];
        $templateSource = 'status';

        if ($predefinedTemplateColumn !== null) {
            $templateResolution = $this->resolvePrizeTypeTemplate(
                $connection,
                $winnerRow,
                $predefinedTemplateColumn
            );
            if (!$templateResolution['success']) {
                return ['success' => false, 'message' => '', 'error' => $templateResolution['error']];
            }
            if ($templateResolution['template_id'] === '') {
                return ['success' => true, 'message' => $templateResolution['message'], 'error' => null];
            }

            $templateId = $templateResolution['template_id'];
            $prizeType = $templateResolution['prize_type'];
            $templateSource = $templateResolution['template_source'];
        } else {
            $statusRow = $connection->createQueryBuilder()
                ->select('email_template', 'name')
                ->from('ustawienia_statusow')
                ->where('id = :id')
                ->setParameter('id', $statusId)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
            if (!is_array($statusRow) || $statusRow === []) {
                return ['success' => false, 'message' => '', 'error' => 'Nie znaleziono ustawień dla przypisanego statusu.'];
            }

            $statusName = trim((string) ($statusRow['name'] ?? ''));
            $configuredTemplate = trim((string) ($statusRow['email_template'] ?? ''));
            if ($configuredTemplate === '') {
                if (!$allowResend) {
                    return ['success' => true, 'message' => '', 'error' => null];
                }

                return ['success' => false, 'message' => '', 'error' => 'Brak przypisanego szablonu e-mail dla statusu.'];
            }

            $predefinedTemplateColumn = $this->resolvePredefinedTemplateColumn($configuredTemplate);
            if ($predefinedTemplateColumn !== null) {
                $templateResolution = $this->resolvePrizeTypeTemplate(
                    $connection,
                    $winnerRow,
                    $predefinedTemplateColumn
                );
                if (!$templateResolution['success']) {
                    return ['success' => false, 'message' => '', 'error' => $templateResolution['error']];
                }
                if ($templateResolution['template_id'] === '') {
                    return ['success' => true, 'message' => $templateResolution['message'], 'error' => null];
                }

                $templateId = $templateResolution['template_id'];
                $prizeType = $templateResolution['prize_type'];
                $templateSource = $templateResolution['template_source'];
            } else {
                $templateId = $configuredTemplate;
            }
        }

        $historyField = 'historia_wysylek_email_status';
        $decodedHistory = json_decode((string) ($winnerRow[$historyField] ?? ''), true);
        if (!is_array($decodedHistory)) {
            $decodedHistory = [];
        }

        if (!$allowResend) {
            foreach ($decodedHistory as $historyEntry) {
                if (!is_array($historyEntry)) {
                    continue;
                }

                $sentForSameStatus = trim((string) ($historyEntry['status_id'] ?? '')) === $statusId;
                $sentWithSameTemplate = trim((string) ($historyEntry['template_id'] ?? '')) === (string) $templateId;
                $sentFromSameSource = trim((string) ($historyEntry['template_source'] ?? '')) === $templateSource;
                if ($sentForSameStatus && $sentWithSameTemplate && $sentFromSameSource) {
                    return ['success' => true, 'message' => 'Wiadomość e-mail dla tego statusu i szablonu została już wcześniej wysłana.', 'error' => null];
                }
            }
        }

        $recipient = trim((string) ($winnerRow['email'] ?? ''));
        if ($recipient === '') {
            return ['success' => false, 'message' => '', 'error' => 'Brak adresu e-mail dla tego rekordu.'];
        }

        $template = $connection->createQueryBuilder()
            ->select('subject', 'body', 'name', 'bcc', 'priority_email')
            ->from('email_templates')
            ->where('id = :id')
            ->setParameter('id', $templateId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($template) || $template === []) {
            return ['success' => false, 'message' => '', 'error' => 'Nie znaleziono przypisanego szablonu e-mail.'];
        }

        $sentAt = new \DateTimeImmutable();
        $codeRows = $this->fetchWinnerCodeRows($connection, $recordId);
        $rawBodyTemplate = (string) ($template['body'] ?? '');
        $rawSubjectTemplate = (string) ($template['subject'] ?? '');
        $graphicCodeMarkers = $rawSubjectTemplate . "\n" . $rawBodyTemplate;
        try {
            $codeRows = $this->prepareGraphicCodes(
                $codeRows,
                preg_match('/\{\s*kod_graficzny\s*\}/i', $rawBodyTemplate) === 1,
                preg_match('/\{\s*(?:kod_graficzny(?:_link)?|kody)\s*\}/i', $graphicCodeMarkers) === 1
            );
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => '',
                'error' => 'Nie udało się przygotować graficznego kodu do wysyłki: ' . $exception->getMessage(),
            ];
        }
        $placeholders = $this->placeholders($winnerRow, $recipient, $sentAt, $prizeType, $codeRows);
        $placeholders['data_losowania'] = (new WinnerDrawingDateResolver())->resolve($connection, $winnerRow);
        $settings = $this->fetchLotterySettings($connection);
        $deadlineSentAt = $templateSource === 'prize_type_winner_email' ? $sentAt : null;
        $placeholders['termin_formularza'] = (new WinnerFormReminderService($this->mailer))
            ->formatFormExpiry($winnerRow, $settings, $deadlineSentAt);
        $subjectTemplate = $this->renderWinnerCodesLoop($rawSubjectTemplate, $codeRows);
        $bodyTemplate = $this->renderWinnerCodesLoop($rawBodyTemplate, $codeRows, true);
        $subjectPlaceholders = $placeholders;
        $subjectPlaceholders['kod_graficzny'] = implode(', ', array_values(array_filter(array_map(
            static fn(array $row): string => trim((string) ($row['graphic_filename'] ?? '')),
            $codeRows
        ))));
        $subjectPlaceholders['kod_graficzny_link'] = implode(', ', array_values(array_filter(array_map(
            static fn(array $row): string => trim((string) ($row['graphic_url'] ?? '')),
            $codeRows
        ))));
        $subject = $this->replaceEmailPlaceholders($subjectTemplate, $subjectPlaceholders);
        $body = $this->replaceEmailPlaceholders($bodyTemplate, $placeholders);

        $mailResult = $this->mailer->sendWithError(
            $recipient, $subject, $body, true, $this->resolveTemplateBcc($template),
            false, $this->isPriorityEmailTemplate($template), $forceQueue
        );
        if (!$mailResult['success']) {
            $mailError = trim((string) ($mailResult['error'] ?? ''));

            return [
                'success' => false,
                'message' => '',
                'error' => $mailError !== ''
                    ? 'Nie udało się wysłać wiadomości e-mail: ' . $mailError
                    : 'Nie udało się wysłać wiadomości e-mail.',
            ];
        }
        $queued = ($mailResult['queued'] ?? false) === true;

        $templateName = trim((string) ($template['name'] ?? ''));
        if ($templateName === '') {
            $templateName = '#' . $templateId;
        }

        $decodedHistory[] = [
            'sent_at' => $sentAt->format('Y-m-d H:i:s'),
            'status_id' => $statusId,
            'status_name' => $statusName,
            'template_id' => $templateId,
            'template_name' => $templateName,
            'type' => $afterCreation ? 'winner_created' : ($allowResend ? 'manual_resend' : 'status_change'),
            'template_source' => $templateSource,
        ];

        $updateData = [$historyField => json_encode($decodedHistory, JSON_UNESCAPED_UNICODE)];
        if ($templateSource === 'prize_type_winner_email' && array_key_exists('email_o_wygranej_wyslany_at', $winnerRow)) {
            $sentAtValue = trim((string) ($winnerRow['email_o_wygranej_wyslany_at'] ?? ''));
            if ($sentAtValue === '') {
                $updateData['email_o_wygranej_wyslany_at'] = $sentAt->format('Y-m-d H:i:s');
            }
        }

        $connection->update($table, $updateData, ['id' => $recordId]);

        return [
            'success' => true,
            'message' => $queued
                ? 'Wiadomość e-mail została dodana do kolejki. Szablon: ' . $templateName
                : 'Wiadomość e-mail została wysłana. Szablon: ' . $templateName,
            'error' => null,
        ];
    }

    /**
     * Sends the administrator notification configured directly on a winner status.
     * Missing or incomplete administrator configuration intentionally means that
     * no notification should be sent.
     *
     * @param array<string, mixed> $winnerRow
     * @return array{success: bool, message: string, error: string|null}
     */
    public function sendToAdministratorForStatus(
        Connection $connection,
        array $winnerRow,
        int $recordId,
        string $statusId,
        bool $forceQueue = false
    ): array {
        $statusRow = $connection->createQueryBuilder()
            ->select('name', 'administrator_email_template', 'administrator_email')
            ->from('ustawienia_statusow')
            ->where('id = :id')
            ->setParameter('id', $statusId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($statusRow) || $statusRow === []) {
            return ['success' => true, 'message' => '', 'error' => null];
        }

        $templateId = trim((string) ($statusRow['administrator_email_template'] ?? ''));
        $recipient = trim((string) ($statusRow['administrator_email'] ?? ''));
        if ($templateId === '' || $recipient === '') {
            return ['success' => true, 'message' => '', 'error' => null];
        }

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return [
                'success' => false,
                'message' => '',
                'error' => 'Mail administratora przypisany do statusu ma nieprawidłowy format.',
            ];
        }

        $template = $connection->createQueryBuilder()
            ->select('subject', 'body', 'name', 'bcc', 'priority_email')
            ->from('email_templates')
            ->where('id = :id')
            ->setParameter('id', $templateId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($template) || $template === []) {
            return [
                'success' => false,
                'message' => '',
                'error' => 'Nie znaleziono szablonu e-mail przypisanego do powiadomienia administratora.',
            ];
        }

        $prizeType = [];
        $prizeTypeId = trim((string) ($winnerRow['rodzaj_wygranej'] ?? ''));
        if ($prizeTypeId !== '') {
            $resolvedPrizeType = $this->fetchPrizeType($connection, $prizeTypeId);
            if (is_array($resolvedPrizeType)) {
                $prizeType = $resolvedPrizeType;
            }
        }

        $sentAt = new \DateTimeImmutable();
        $codeRows = $this->fetchWinnerCodeRows($connection, $recordId);
        $placeholders = $this->placeholders($winnerRow, $recipient, $sentAt, $prizeType, $codeRows);
        $placeholders['email_zwyciezcy'] = trim((string) ($winnerRow['email'] ?? ''));
        $placeholders['id_zwyciezcy'] = $recordId;
        $placeholders['status'] = trim((string) ($statusRow['name'] ?? ''));
        $placeholders['termin_formularza'] = (new WinnerFormReminderService($this->mailer))
            ->formatFormExpiry($winnerRow, $this->fetchLotterySettings($connection), null);

        $subjectTemplate = $this->renderWinnerCodesLoop((string) ($template['subject'] ?? ''), $codeRows);
        $bodyTemplate = $this->renderWinnerCodesLoop((string) ($template['body'] ?? ''), $codeRows, true);
        $subject = $this->replaceEmailPlaceholders($subjectTemplate, $placeholders);
        $body = $this->replaceEmailPlaceholders($bodyTemplate, $placeholders);

        $mailResult = $this->mailer->sendWithError(
            $recipient,
            $subject,
            $body,
            true,
            $this->resolveTemplateBcc($template),
            false,
            $this->isPriorityEmailTemplate($template),
            $forceQueue
        );
        if (!$mailResult['success']) {
            $mailError = trim((string) ($mailResult['error'] ?? ''));

            return [
                'success' => false,
                'message' => '',
                'error' => $mailError !== ''
                    ? 'Nie udało się wysłać wiadomości e-mail do administratora: ' . $mailError
                    : 'Nie udało się wysłać wiadomości e-mail do administratora.',
            ];
        }

        $templateName = trim((string) ($template['name'] ?? ''));
        if ($templateName === '') {
            $templateName = '#' . $templateId;
        }

        return [
            'success' => true,
            'message' => ($mailResult['queued'] ?? false) === true
                ? 'Wiadomość e-mail do administratora została dodana do kolejki. Szablon: ' . $templateName
                : 'Wiadomość e-mail do administratora została wysłana. Szablon: ' . $templateName,
            'error' => null,
        ];
    }

    /**
     * @param array<string, mixed> $winnerRow
     */
    public function recordSentEmailInHistory(
        Connection $connection,
        array $winnerRow,
        int $recordId,
        string $table,
        string $statusId,
        string $templateId,
        string $templateSource,
        \DateTimeImmutable $sentAt,
        string $type
    ): void {
        if ($statusId === '' || $templateId === '') {
            return;
        }

        $historyField = 'historia_wysylek_email_status';
        $decodedHistory = json_decode((string) ($winnerRow[$historyField] ?? ''), true);
        if (!is_array($decodedHistory)) {
            $decodedHistory = [];
        }

        foreach ($decodedHistory as $historyEntry) {
            if (!is_array($historyEntry)) {
                continue;
            }

            if (
                trim((string) ($historyEntry['status_id'] ?? '')) === $statusId
                && trim((string) ($historyEntry['template_id'] ?? '')) === $templateId
                && trim((string) ($historyEntry['type'] ?? '')) === $type
            ) {
                return;
            }
        }

        $templateName = $this->resolveTemplateName($connection, $templateId);
        $statusName = $this->resolveStatusName($connection, $statusId);

        $decodedHistory[] = [
            'sent_at' => $sentAt->format('Y-m-d H:i:s'),
            'status_id' => $statusId,
            'status_name' => $statusName,
            'template_id' => $templateId,
            'template_name' => $templateName,
            'type' => $type,
            'template_source' => $templateSource,
        ];

        $connection->update($table, [
            $historyField => json_encode($decodedHistory, JSON_UNESCAPED_UNICODE),
        ], ['id' => $recordId]);
    }

    private function resolveTemplateName(Connection $connection, string $templateId): string
    {
        $templateName = $connection->createQueryBuilder()
            ->select('name')
            ->from('email_templates')
            ->where('id = :id')
            ->setParameter('id', $templateId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        $templateName = is_scalar($templateName) ? trim((string) $templateName) : '';

        return $templateName !== '' ? $templateName : '#' . $templateId;
    }

    private function resolveStatusName(Connection $connection, string $statusId): string
    {
        $predefinedLabel = $this->predefinedStatusLabel($statusId);
        if ($predefinedLabel !== '') {
            return $predefinedLabel;
        }

        $statusName = $connection->createQueryBuilder()
            ->select('name')
            ->from('ustawienia_statusow')
            ->where('id = :id')
            ->setParameter('id', $statusId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_scalar($statusName) ? trim((string) $statusName) : '';
    }

    /**
     * @param array<string, mixed> $winnerRow
     * @param array<string, mixed> $prizeType
     * @param array<int, array{pickup_code: string, kwota: string, kod_ean: bool, code_128: bool}> $codeRows
     * @return array<string, scalar|null>
     */
    private function placeholders(array $winnerRow, string $recipient, \DateTimeImmutable $sentAt, array $prizeType, array $codeRows): array
    {
        $prizeName = trim((string) ($prizeType['nazwa_nagrody_do_wyswietlenia'] ?? ''));
        if ($prizeName === '') {
            $prizeName = trim((string) ($prizeType['name'] ?? ''));
        }

        $codeValues = array_values(array_map(
            static fn(array $row): string => $row['pickup_code'],
            $codeRows
        ));
        $amountValues = array_values(array_unique(array_filter(array_map(
            static fn(array $row): string => $row['kwota'],
            $codeRows
        ), static fn(string $value): bool => $value !== '')));
        return [
            'data' => $sentAt->format('d-m-Y'),
            'data_czas' => $sentAt->format('Y-m-d H:i:s'),
            'email' => $recipient,
            'telefon' => trim((string) ($winnerRow['telefon'] ?? '')),
            'los' => trim((string) ($winnerRow['numer_dowodu_zakupu'] ?? '')),
            'dowod_zakupu' => trim((string) ($winnerRow['numer_dowodu_zakupu'] ?? '')),
            'nagroda' => $prizeName,
            'wartosc-nagrody' => trim((string) ($prizeType['wartosc_nagrody'] ?? '')),
            'kod_vouchera' => implode(', ', $codeValues),
            'pickup_code' => implode(', ', $codeValues),
            'kwota' => implode(', ', $amountValues),
            'kod_graficzny' => implode('', array_map(
                fn(array $row): string => $this->renderGraphicCodeValue($row),
                $codeRows
            )),
            'kod_graficzny_link' => implode('<br>', array_values(array_filter(array_map(
                fn(array $row): string => $this->renderGraphicCodeLink($row),
                $codeRows
            )))),
            'link_zwyciezca' => $this->buildWinnerLink($winnerRow),
        ];
    }

    /** @return array<string, mixed> */
    private function fetchLotterySettings(Connection $connection): array
    {
        $settings = $connection->createQueryBuilder()
            ->select('*')
            ->from('loteria_ustawienia')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($settings) ? $settings : [];
    }

    /**
     * @param array<string, mixed> $winnerRow
     * @return array{success: bool, template_id: string, prize_type: array<string, mixed>, template_source: string, message: string, error: string|null}
     */
    private function resolvePrizeTypeTemplate(Connection $connection, array $winnerRow, string $templateColumn): array
    {
        $prizeTypeId = trim((string) ($winnerRow['rodzaj_wygranej'] ?? ''));
        if ($prizeTypeId === '') {
            return [
                'success' => false,
                'template_id' => '',
                'prize_type' => [],
                'template_source' => '',
                'message' => '',
                'error' => 'Brak rodzaju nagrody dla zwycięzcy.',
            ];
        }

        $prizeTypeRow = $this->fetchPrizeType($connection, $prizeTypeId);
        if (!is_array($prizeTypeRow) || $prizeTypeRow === []) {
            return [
                'success' => false,
                'template_id' => '',
                'prize_type' => [],
                'template_source' => '',
                'message' => '',
                'error' => 'Nie znaleziono rodzaju nagrody zwycięzcy.',
            ];
        }

        if ($templateColumn === 'prize_info_email_template') {
            $formType = trim((string) ($prizeTypeRow['form_type'] ?? ''));
            if ($formType === 'no_form') {
                return [
                    'success' => true,
                    'template_id' => '',
                    'prize_type' => [],
                    'template_source' => '',
                    'message' => 'Rodzaj nagrody nie wymaga wysyłki maila o wygranej z formularzem.',
                    'error' => null,
                ];
            }
        }

        $templateId = trim((string) ($prizeTypeRow[$templateColumn] ?? ''));
        if ($templateId === '') {
            return [
                'success' => false,
                'template_id' => '',
                'prize_type' => [],
                'template_source' => '',
                'message' => '',
                'error' => match ($templateColumn) {
                    'prize_info_email_template' => 'Brak szablonu maila o wygranej w rodzaju nagrody.',
                    'prize_granted_no_codes_email_template' => 'Brak szablonu maila o przyznaniu nagrody dla braku kodów w rodzaju nagrody.',
                    default => 'Brak szablonu maila o przyznaniu nagrody w rodzaju nagrody.',
                },
            ];
        }

        return [
            'success' => true,
            'template_id' => $templateId,
            'prize_type' => $prizeTypeRow,
            'template_source' => match ($templateColumn) {
                'prize_info_email_template' => 'prize_type_winner_email',
                'prize_granted_no_codes_email_template' => 'prize_type_granted_no_codes_email',
                default => 'prize_type_granted_email',
            },
            'message' => '',
            'error' => null,
        ];
    }

    private function resolvePredefinedTemplateColumn(string $statusId): ?string
    {
        return match ($statusId) {
            'predefined:prize_info_email_template' => 'prize_info_email_template',
            'predefined:prize_granted_email_template' => 'prize_granted_email_template',
            self::CODE_SHORTAGE_STATUS_ID => 'prize_granted_no_codes_email_template',
            default => null,
        };
    }

    private function predefinedStatusLabel(string $statusId): string
    {
        return match ($statusId) {
            'predefined:prize_info_email_template' => 'Nagrody - Mail z informacją o wygranej i formularzu',
            'predefined:prize_granted_email_template' => 'Nagrody - Mail z informacją o przyznaniu nagrody (wysyłka kodów)',
            self::CODE_SHORTAGE_STATUS_ID => 'Nagrody - Mail z informacją o przyznaniu nagrody (gdy brak kodów w bazie)',
            default => '',
        };
    }

    /** @return array<string, mixed>|false */
    private function fetchPrizeType(Connection $connection, string $prizeTypeId): array|false
    {
        return $connection->createQueryBuilder()
            ->select(
                'prize_info_email_template',
                'prize_granted_email_template',
                'prize_granted_no_codes_email_template',
                'name',
                'nazwa_nagrody_do_wyswietlenia',
                'wartosc_nagrody',
                'form_type'
            )
            ->from('rodzaje_nagrod_entries')
            ->where('id = :id')
            ->setParameter('id', $prizeTypeId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
    }

    /** @param array<string, mixed> $winnerRow */
    private function buildWinnerLink(array $winnerRow): string
    {
        $winnerToken = trim((string) ($winnerRow['token_formularza'] ?? ''));
        if ($winnerToken === '') {
            return '';
        }

        return $this->mailer->absoluteUrl('/loteria/zwyciezca/' . urlencode($winnerToken) . '#zglos');
    }

    /**
     * @return array<int, array{pickup_code: string, kwota: string, kod_ean: bool, code_128: bool}>
     */
    private function fetchWinnerCodeRows(Connection $connection, int $recordId): array
    {
        if ($recordId <= 0) {
            return [];
        }

        $codeRows = [];
        $schemaManager = $connection->createSchemaManager();
        if ($schemaManager->tablesExist(['lista_wygranych_kodow_entries'])) {
            $columns = $this->listTableColumns($connection, 'lista_wygranych_kodow_entries');
            $selectColumns = ['pickup_code', 'wartosc_kodu', 'kod_ean'];
            if (isset($columns['code_128'])) {
                $selectColumns[] = 'code_128';
            }

            $rows = $connection->createQueryBuilder()
                ->select(...$selectColumns)
                ->from('lista_wygranych_kodow_entries')
                ->where('kod_przyznany_do_zwyciezcy = :winnerId')
                ->orderBy('id', 'ASC')
                ->setParameter('winnerId', $recordId)
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($rows as $row) {
                $pickupCode = trim((string) ($row['pickup_code'] ?? ''));
                if ($pickupCode === '') {
                    continue;
                }

                $codeRows[] = [
                    'pickup_code' => $pickupCode,
                    'kwota' => $this->normalizeAmount($row['wartosc_kodu'] ?? ''),
                    'kod_ean' => $this->isTruthy($row['kod_ean'] ?? false),
                    'code_128' => $this->isTruthy($row['code_128'] ?? false),
                    'graphic_path' => '',
                    'graphic_filename' => '',
                    'graphic_mime' => '',
                ];
            }
        }

        if ($schemaManager->tablesExist([GraphicCodeImporter::TABLE])) {
            $rows = $connection->createQueryBuilder()
                ->select('id', 'nazwa_pliku', 'sciezka_pliku', 'typ_pliku', 'wartosc_kodu', 'token_www')
                ->from(GraphicCodeImporter::TABLE)
                ->where('kod_przyznany_do_zwyciezcy = :winnerId')
                ->orderBy('id', 'ASC')
                ->setParameter('winnerId', $recordId)
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($rows as $row) {
                $filename = trim((string) ($row['nazwa_pliku'] ?? ''));
                $relativePath = trim((string) ($row['sciezka_pliku'] ?? ''));
                if ($filename === '' || $relativePath === '') {
                    continue;
                }
                $webToken = $this->ensureGraphicCodeWebToken(
                    $connection,
                    (int) ($row['id'] ?? 0),
                    (string) ($row['token_www'] ?? '')
                );

                $codeRows[] = [
                    'pickup_code' => $filename,
                    'kwota' => $this->normalizeAmount($row['wartosc_kodu'] ?? ''),
                    'kod_ean' => false,
                    'code_128' => false,
                    'graphic_path' => $relativePath,
                    'graphic_filename' => $filename,
                    'graphic_mime' => trim((string) ($row['typ_pliku'] ?? 'image/jpeg')),
                    'graphic_url' => $webToken !== ''
                        ? $this->mailer->absoluteUrl('/kod-graficzny/' . rawurlencode($webToken))
                        : '',
                ];
            }
        }

        return $codeRows;
    }

    /** @param array<int, array<string, mixed>> $codeRows */
    private function prepareGraphicCodes(array $codeRows, bool $includeInlineImages, bool $attachFiles): array
    {
        if (!$attachFiles) {
            return $codeRows;
        }

        $storage = new GraphicCodeImporter();
        $attachments = [];
        foreach ($codeRows as $index => $codeRow) {
            $relativePath = trim((string) ($codeRow['graphic_path'] ?? ''));
            if ($relativePath === '') {
                continue;
            }

            $absolutePath = $storage->resolveStoredPath($relativePath);
            $filename = trim((string) ($codeRow['graphic_filename'] ?? 'kod-graficzny'));
            if ($absolutePath === null) {
                throw new \RuntimeException('nie znaleziono pliku „' . $filename . '”.');
            }

            $attachments[] = [
                'index' => $index,
                'path' => $absolutePath,
                'mime' => strtolower(trim((string) ($codeRow['graphic_mime'] ?? 'image/jpeg'))),
                'filename' => $filename,
            ];
        }

        foreach ($attachments as $attachment) {
            if ($includeInlineImages && in_array($attachment['mime'], ['image/jpeg', 'image/png', 'image/gif'], true)) {
                $codeRows[$attachment['index']]['graphic_inline_src'] = $this->mailer->attachInlineFile(
                    $attachment['path'],
                    $attachment['mime'],
                    $attachment['filename']
                );
            }
            $this->mailer->attachFile(
                $attachment['path'],
                $attachment['mime'],
                $attachment['filename']
            );
        }

        return $codeRows;
    }

    /**
     * @param array<int, array{pickup_code: string, kwota: string, kod_ean: bool, code_128: bool}> $codeRows
     */
    private function renderWinnerCodesLoop(string $template, array $codeRows, bool $renderEanImages = false): string
    {
        if ($template === '') {
            return $template;
        }

        return (string) preg_replace_callback('/\{\s*kody\s*\}(.*?)\{\s*\/\s*kody\s*\}/si', function (array $matches) use ($codeRows, $renderEanImages): string {
            $rowTemplate = (string) ($matches[1] ?? '');
            if ($rowTemplate === '' || $codeRows === []) {
                return '';
            }

            $sharedHeader = '';
            if ($renderEanImages && count($codeRows) > 1 && $this->hasBarcodeCodeRows($codeRows)) {
                [$sharedHeader, $rowTemplate] = $this->extractWinnerCodesLoopSharedHeader($rowTemplate);
            }

            $renderCodeRow = function (array $codeRow, string $template, bool $includeHeader = true): string {
                return $this->replaceEmailPlaceholders($template, [
                    'pickup_code' => $this->renderPickupCodeValue($codeRow, $includeHeader),
                    'kod_ean' => $this->renderPickupCodeValue($codeRow, $includeHeader),
                    'code_128' => $this->renderCode128Value($codeRow, $includeHeader),
                    'kod_graficzny' => $this->renderGraphicCodeValue($codeRow),
                    'kod_graficzny_link' => $this->renderGraphicCodeLink($codeRow),
                    'kwota' => $codeRow['kwota'],
                ]);
            };

            if (!$renderEanImages) {
                $renderCodeRow = function (array $codeRow, string $template): string {
                    return $this->replaceEmailPlaceholders($template, [
                        'pickup_code' => $codeRow['pickup_code'],
                        'kod_ean' => $codeRow['pickup_code'],
                        'code_128' => $codeRow['pickup_code'],
                        'kod_graficzny' => trim((string) ($codeRow['graphic_filename'] ?? '')),
                        'kod_graficzny_link' => trim((string) ($codeRow['graphic_url'] ?? '')),
                        'kwota' => $codeRow['kwota'],
                    ]);
                };
            }

            $renderedRows = [];
            foreach ($codeRows as $index => $codeRow) {
                $renderedRows[] = $renderCodeRow($codeRow, $rowTemplate, !$renderEanImages || $index === 0);
            }

            return ($sharedHeader !== '' ? $renderCodeRow($codeRows[0], $sharedHeader) : '') . implode('', $renderedRows);
        }, $template);
    }

    /**
     * @param array<int, array{pickup_code: string, kwota: string, kod_ean: bool, code_128: bool}> $codeRows
     */
    private function hasBarcodeCodeRows(array $codeRows): bool
    {
        foreach ($codeRows as $codeRow) {
            if (
                $this->isTruthy($codeRow['kod_ean'] ?? false)
                || $this->isTruthy($codeRow['code_128'] ?? false)
                || trim((string) ($codeRow['graphic_path'] ?? '')) !== ''
            ) {
                return true;
            }
        }

        return false;
    }


    /**
     * Splits repeated winner-code loop markup so decorative content placed before the
     * first code placeholder (for example a large beer image) is rendered once above
     * all generated EAN/Code-128 barcodes instead of being duplicated for each code.
     *
     * @return array{0: string, 1: string}
     */
    private function extractWinnerCodesLoopSharedHeader(string $rowTemplate): array
    {
        if (!preg_match('/\{\s*(?:pickup_code|kod_ean|code_128|kod_graficzny|kod_graficzny_link)\s*\}/i', $rowTemplate, $matches, PREG_OFFSET_CAPTURE)) {
            return ['', $rowTemplate];
        }

        $placeholderOffset = (int) $matches[0][1];
        if ($placeholderOffset <= 0) {
            return ['', $rowTemplate];
        }

        return [substr($rowTemplate, 0, $placeholderOffset), substr($rowTemplate, $placeholderOffset)];
    }

    /** @param array{pickup_code: string, kwota: string, kod_ean?: bool, code_128?: bool} $codeRow */
    private function renderPickupCodeValue(array $codeRow, bool $includeHeader = true): string
    {
        if ($this->isTruthy($codeRow['code_128'] ?? false)) {
            return $this->renderCode128Value($codeRow, $includeHeader);
        }

        $pickupCode = trim($codeRow['pickup_code']);
        if ($pickupCode === '' || !$this->isTruthy($codeRow['kod_ean'] ?? false)) {
            return htmlspecialchars($pickupCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $this->renderBarcodeImage('/kod-ean/', 'Kod EAN', $pickupCode, 420, $includeHeader);
    }

    /** @param array{pickup_code: string, kwota: string, code_128?: bool} $codeRow */
    private function renderCode128Value(array $codeRow, bool $includeHeader = true): string
    {
        $pickupCode = trim($codeRow['pickup_code']);
        if ($pickupCode === '' || !$this->isTruthy($codeRow['code_128'] ?? false)) {
            return htmlspecialchars($pickupCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $this->renderBarcodeImage('/kod-code-128/', 'Kod Code-128', $pickupCode, 520, $includeHeader);
    }

    /** @param array<string, mixed> $codeRow */
    private function renderGraphicCodeValue(array $codeRow): string
    {
        $imageSource = trim((string) ($codeRow['graphic_inline_src'] ?? ''));
        if ($imageSource === '') {
            return '';
        }

        $filename = trim((string) ($codeRow['graphic_filename'] ?? 'Kod graficzny'));

        return sprintf(
            '<img src="%s" alt="%s" style="display:block;max-width:100%%;height:auto;border:0;">',
            htmlspecialchars($imageSource, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($filename, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    /** @param array<string, mixed> $codeRow */
    private function renderGraphicCodeLink(array $codeRow): string
    {
        $url = trim((string) ($codeRow['graphic_url'] ?? ''));
        if ($url === '') {
            return '';
        }

        $escapedUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $linkLabel = strtolower(trim((string) ($codeRow['graphic_mime'] ?? ''))) === 'application/pdf'
            ? 'Otwórz kod PDF'
            : 'Wyświetl kod graficzny';

        return sprintf(
            '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
            $escapedUrl,
            $linkLabel
        );
    }

    private function ensureGraphicCodeWebToken(Connection $connection, int $codeId, string $currentToken): string
    {
        $currentToken = strtolower(trim($currentToken));
        if (preg_match('/^[a-f0-9]{64}$/', $currentToken) === 1) {
            return $currentToken;
        }
        if ($codeId <= 0) {
            return '';
        }

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $token = bin2hex(random_bytes(32));
            try {
                $updated = $connection->createQueryBuilder()
                    ->update(GraphicCodeImporter::TABLE)
                    ->set('token_www', ':token')
                    ->where('id = :id')
                    ->andWhere("token_www IS NULL OR token_www = '' OR token_www = :current_token")
                    ->setParameter('token', $token)
                    ->setParameter('id', $codeId)
                    ->setParameter('current_token', $currentToken)
                    ->executeStatement();
                if ($updated === 1) {
                    return $token;
                }

                $storedToken = strtolower(trim((string) $connection->createQueryBuilder()
                    ->select('token_www')
                    ->from(GraphicCodeImporter::TABLE)
                    ->where('id = :id')
                    ->setParameter('id', $codeId)
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchOne()));
                if (preg_match('/^[a-f0-9]{64}$/', $storedToken) === 1) {
                    return $storedToken;
                }
            } catch (\Throwable $exception) {
                if ($attempt === 4) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Nie udało się utworzyć bezpiecznego linku do kodu graficznego.');
    }

    private function renderBarcodeImage(string $pathPrefix, string $altPrefix, string $pickupCode, int $width, bool $includeHeader = true): string
    {
        $url = $this->mailer->absoluteUrl($pathPrefix . rawurlencode($pickupCode) . '.jpg' . ($includeHeader ? '' : '?header=0'));
        $imageSrc = $this->renderInlineBarcodeImageSource($pathPrefix, $pickupCode, $includeHeader) ?? $url;
        $escapedUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedImageSrc = htmlspecialchars($imageSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedCode = htmlspecialchars($pickupCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return sprintf('<a href="%1$s"><img src="%2$s" alt="%3$s %4$s" width="%5$d" style="display:block;width:%5$dpx;max-width:100%%;height:auto;border:0;"></a>', $escapedUrl, $escapedImageSrc, $altPrefix, $escapedCode, $width);
    }

    private function renderInlineBarcodeImageSource(string $pathPrefix, string $pickupCode, bool $includeHeader): ?string
    {
        try {
            if ($pathPrefix === '/kod-ean/') {
                $generator = new \App\Service\Ean13BarcodeGenerator();
                $jpeg = $generator->generateJpeg($pickupCode, includeHeader: $includeHeader);
                $filename = $generator->normalize($pickupCode) . '.jpg';
            } elseif ($pathPrefix === '/kod-code-128/') {
                $generator = new \App\Service\Code128BarcodeGenerator();
                $jpeg = $generator->generateJpeg($pickupCode, includeHeader: $includeHeader);
                $filename = (preg_replace('/[^A-Za-z0-9._-]+/', '_', $generator->normalize($pickupCode)) ?: 'code-128') . '.jpg';
            } else {
                return null;
            }
            return $this->mailer->attachInlineImage($jpeg, 'image/jpeg', $filename);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /** @return array<string, bool> */
    private function listTableColumns(Connection $connection, string $table): array
    {
        $columns = [];
        foreach ($connection->createSchemaManager()->listTableColumns($table) as $name => $column) {
            $columns[strtolower((string) $name)] = true;
        }

        return $columns;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'tak', 'yes', 'on'], true);
    }

    private function normalizeAmount(mixed $value): string
    {
        $normalized = trim(str_replace(',', '.', (string) $value));
        if ($normalized === '') {
            return '';
        }

        if (is_numeric($normalized)) {
            $floatValue = (float) $normalized;

            return floor($floatValue) === $floatValue
                ? (string) (int) $floatValue
                : rtrim(rtrim(number_format($floatValue, 2, '.', ''), '0'), '.');
        }

        return mb_strtolower($normalized);
    }

    /** @param array<string, scalar|null> $values */
    private function replaceEmailPlaceholders(string $template, array $values): string
    {
        if ($template === '' || $values === []) {
            return $template;
        }

        $normalizedValues = [];
        foreach ($values as $key => $value) {
            $normalizedValues[strtolower(trim((string) $key))] = (string) ($value ?? '');
        }

        return (string) preg_replace_callback('/\{\s*([a-zA-Z0-9_-]+)\s*\}/', static function (array $matches) use ($normalizedValues): string {
            $key = strtolower($matches[1] ?? '');

            return array_key_exists($key, $normalizedValues) ? $normalizedValues[$key] : ($matches[0] ?? '');
        }, $template);
    }

    /**
     * @param array<string, mixed> $template
     */
    private function isPriorityEmailTemplate(array $template): bool
    {
        return filter_var($template['priority_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string, mixed> $template
     */
    private function resolveTemplateBcc(array $template): ?string
    {
        $bcc = trim((string) ($template['bcc'] ?? ''));

        return $bcc !== '' ? $bcc : null;
    }


}
