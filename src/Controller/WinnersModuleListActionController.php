<?php

namespace App\Controller;

use App\Service\Mailer;
use App\Service\WinnerFormReminderService;
use App\Service\WinnerPrizeCodeAssignmentService;
use App\Service\WinnerStatusEmailService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class WinnersModuleListActionController extends ModuleListActionController
{
    /** @var callable(Request, string, string, string): RedirectResponse */
    private $redirectBuilder;

    /** @var callable(Request, string, string, ?int): void */
    private $recordChange;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Mailer $mailer,
        callable $redirectBuilder,
        callable $recordChange,
    ) {
        $this->redirectBuilder = $redirectBuilder;
        $this->recordChange = $recordChange;
    }

    // Place winner-list extra row action handlers here to keep them portable between projects.

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    public function retryWinnerPostCreateProcessing(Request $request, string $moduleKey, string $recordId, array $definition): ?Response
    {
        $table = trim((string) ($definition['database']['table'] ?? ''));
        $winnerId = (int) $recordId;
        if ($table === '' || $winnerId <= 0) {
            return ($this->redirectBuilder)($request, $moduleKey, 'error', 'Brak poprawnych danych zwycięzcy.');
        }

        $connection = $this->entityManager->getConnection();
        $columns = array_keys($connection->createSchemaManager()->listTableColumns($table));
        $requiredColumns = [
            'post_create_processing_status',
            'post_create_attempts',
            'post_create_last_error',
            'post_create_locked_at',
            'post_create_next_attempt_at',
            'post_create_processed_at',
        ];
        if (array_diff($requiredColumns, $columns) !== []) {
            return ($this->redirectBuilder)(
                $request,
                $moduleKey,
                'error',
                'Brak pól kolejki przetwarzania. Uruchom instalację/aktualizację bazy danych w CMS.'
            );
        }

        $status = $connection->createQueryBuilder()
            ->select('post_create_processing_status')
            ->from($table)
            ->where('id = :id')
            ->setParameter('id', $winnerId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        if ($status === false) {
            return ($this->redirectBuilder)($request, $moduleKey, 'error', 'Nie znaleziono zwycięzcy.');
        }
        if (trim((string) $status) !== 'failed') {
            return ($this->redirectBuilder)(
                $request,
                $moduleKey,
                'error',
                'Ponowić można tylko przetwarzanie zakończone błędem.'
            );
        }

        $connection->update($table, [
            'post_create_processing_status' => 'pending',
            'post_create_attempts' => 0,
            'post_create_last_error' => null,
            'post_create_locked_at' => null,
            'post_create_next_attempt_at' => null,
            'post_create_processed_at' => null,
        ], ['id' => $winnerId]);
        ($this->recordChange)($request, $moduleKey, 'retry_winner_post_create_processing', $winnerId);

        return ($this->redirectBuilder)(
            $request,
            $moduleKey,
            'success',
            'Zwycięzca został ponownie dodany do kolejki przetwarzania.'
        );
    }

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    public function markAdminVerificationValid(Request $request, string $moduleKey, string $recordId, array $definition): ?Response
    {
        return $this->changeAdminVerificationStatus($request, $moduleKey, $recordId, $definition, 'next');
    }

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    public function markAdminVerificationInvalid(Request $request, string $moduleKey, string $recordId, array $definition): ?Response
    {
        return $this->changeAdminVerificationStatus($request, $moduleKey, $recordId, $definition, 'rejected');
    }

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    public function markClientVerificationValid(Request $request, string $moduleKey, string $recordId, array $definition): ?Response
    {
        return $this->changeAdminVerificationStatus($request, $moduleKey, $recordId, $definition, 'client_valid', 'client');
    }

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    public function markClientVerificationInvalid(Request $request, string $moduleKey, string $recordId, array $definition): ?Response
    {
        return $this->changeAdminVerificationStatus($request, $moduleKey, $recordId, $definition, 'rejected', 'client');
    }

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    public function savePrizeShippingData(Request $request, string $moduleKey, string $recordId, array $definition): ?Response
    {
        return $this->changeAdminVerificationStatus($request, $moduleKey, $recordId, $definition, 'fulfilled', 'shipping');
    }

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    public function saveVerificationEditableFields(Request $request, string $moduleKey, string $recordId, array $definition): ?Response
    {
        $table = $definition['database']['table'] ?? null;
        $primaryKey = $definition['database']['primary_key'] ?? 'id';
        if (!is_string($table) || $table === '' || !is_string($primaryKey) || $primaryKey === '') {
            return $this->redirectToRecordView($request, $moduleKey, $recordId, 'error', 'Brak konfiguracji bazy danych.');
        }

        $winnerId = (int) $recordId;
        if ($winnerId <= 0) {
            return $this->redirectToRecordView($request, $moduleKey, $recordId, 'error', 'Brak identyfikatora zgłoszenia.');
        }

        $editableResult = $this->extractViewEditableValues($request, $definition);
        if ($editableResult['errors'] !== []) {
            return $this->redirectToRecordView($request, $moduleKey, $recordId, 'error', implode(' ', $editableResult['errors']));
        }

        if ($editableResult['values'] === []) {
            return $this->redirectToRecordView($request, $moduleKey, $recordId, 'error', 'Brak danych do zapisania.');
        }

        $connection = $this->entityManager->getConnection();
        $winnerExists = (bool) $connection->createQueryBuilder()
            ->select('1')
            ->from($table)
            ->where($primaryKey . ' = :id')
            ->setParameter('id', $winnerId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if (!$winnerExists) {
            return $this->redirectToRecordView($request, $moduleKey, $recordId, 'error', 'Nie znaleziono zgłoszenia.');
        }

        $connection->update($table, $editableResult['values'], [$primaryKey => $winnerId]);

        ($this->recordChange)(
            $request,
            $moduleKey,
            'save_verification_comment',
            $winnerId
        );

        return $this->redirectToRecordView($request, $moduleKey, $recordId, 'success', 'Komentarz został zapisany bez zmiany statusu zgłoszenia.');
    }

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    private function changeAdminVerificationStatus(Request $request, string $moduleKey, string $recordId, array $definition, string $target, string $actionPrefix = 'admin'): Response
    {
        $table = $definition['database']['table'] ?? null;
        $primaryKey = $definition['database']['primary_key'] ?? 'id';
        if (!is_string($table) || $table === '' || !is_string($primaryKey) || $primaryKey === '') {
            return $this->redirectToRecordView($request, $moduleKey, $recordId, 'error', 'Brak konfiguracji bazy danych.');
        }

        $winnerId = (int) $recordId;
        if ($winnerId <= 0) {
            return $this->redirectToRecordView($request, $moduleKey, $recordId, 'error', 'Brak identyfikatora zgłoszenia.');
        }

        $connection = $this->entityManager->getConnection();
        $winner = $connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where($primaryKey . ' = :id')
            ->setParameter('id', $winnerId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($winner) || $winner === []) {
            return $this->redirectToRecordView($request, $moduleKey, $recordId, 'error', 'Nie znaleziono zgłoszenia.');
        }

        $statusIds = $this->fetchOrderedStatusIds();
        if ($statusIds === []) {
            return $this->redirectToRecordView($request, $moduleKey, $recordId, 'error', 'Brak skonfigurowanej listy statusów.');
        }

        $previousStatus = trim((string) ($winner['stan'] ?? ''));
        $newStatus = match ($target) {
            'last' => $statusIds[count($statusIds) - 1],
            'rejected' => $this->fetchStatusIdByFlag('odrzucone'),
            'client_valid' => $this->fetchStatusIdByFlag($this->prizeRequiresCourierShipment($winner) ? 'do_wysylki' : 'zrealizowane'),
            'fulfilled' => $this->fetchStatusIdByFlag('zrealizowane'),
            default => $this->resolveNextStatus($statusIds, $previousStatus),
        };

        if ($target === 'rejected' && $newStatus === '') {
            return $this->redirectToRecordView($request, $moduleKey, $recordId, 'error', 'Brak statusu z zaznaczoną opcją „Odrzucone”.');
        }

        if ($target === 'client_valid' && $newStatus === '') {
            $requiredFlag = $this->prizeRequiresCourierShipment($winner) ? 'Do wysyłki' : 'Zrealizowane';

            return $this->redirectToRecordView($request, $moduleKey, $recordId, 'error', sprintf('Brak statusu z zaznaczoną opcją „%s”.', $requiredFlag));
        }

        if ($target === 'fulfilled' && $newStatus === '') {
            return $this->redirectToRecordView($request, $moduleKey, $recordId, 'error', 'Brak statusu z zaznaczoną opcją „Zrealizowane”.');
        }

        if ($newStatus === '') {
            return $this->redirectToRecordView($request, $moduleKey, $recordId, 'error', 'Nie udało się ustalić nowego statusu.');
        }

        if ($target === 'next' && $previousStatus !== '' && $newStatus === $previousStatus) {
            return $this->redirectToRecordView($request, $moduleKey, $recordId, 'error', 'Brak kolejnego statusu na liście statusów.');
        }

        $editableResult = $this->extractViewEditableValues($request, $definition);
        if ($editableResult['errors'] !== []) {
            return $this->redirectToRecordView($request, $moduleKey, $recordId, 'error', implode(' ', $editableResult['errors']));
        }

        $updatedValues = array_merge(['stan' => $newStatus], $editableResult['values']);
        $connection->update($table, $updatedValues, [$primaryKey => $winnerId]);

        if ($target === 'rejected') {
            $this->markRelatedSubmissionAsInvalid($connection, $winner);
        }

        $postStatusMessages = $this->applyPostStatusChangeActions(
            $connection,
            $table,
            $winnerId,
            array_merge($winner, $updatedValues, ['id' => $winnerId]),
            $newStatus,
            $previousStatus
        );

        $changeAction = match ($target) {
            'last', 'rejected' => $actionPrefix . '_verification_invalid',
            'fulfilled' => 'save_shipping_data',
            default => $actionPrefix . '_verification_valid',
        };

        ($this->recordChange)(
            $request,
            $moduleKey,
            $changeAction,
            $winnerId
        );

        $message = match ($target) {
            'last' => 'Status zgłoszenia został zmieniony na ostatni z listy statusów.',
            'rejected' => 'Status zgłoszenia został zmieniony na status oznaczony jako odrzucony.',
            'client_valid' => 'Status zgłoszenia został zmieniony zgodnie z ustawieniami wysyłki nagrody.',
            'fulfilled' => 'Dane wysyłki zostały zapisane, a status zgłoszenia został zmieniony na zrealizowany.',
            default => 'Status zgłoszenia został zmieniony na kolejny z listy statusów.',
        };

        $messages = array_values(array_unique(array_merge([$message], $postStatusMessages['success'])));
        if ($postStatusMessages['error'] !== []) {
            $messages = array_merge($messages, $postStatusMessages['error']);

            return ($this->redirectBuilder)($request, $moduleKey, 'error', implode(' ', $messages));
        }

        return ($this->redirectBuilder)($request, $moduleKey, 'success', implode(' ', $messages));
    }

    /**
     * @param array<string, mixed> $winner
     */
    private function markRelatedSubmissionAsInvalid(Connection $connection, array $winner): void
    {
        $submissionId = (int) ($winner['zgloszenie'] ?? 0);
        if ($submissionId <= 0) {
            return;
        }

        $connection->update(
            'loteria_zgloszenia_entries',
            ['zgloszenie_sprawdzone' => 'niepoprawne'],
            ['id' => $submissionId]
        );
    }

    /**
     * @param array<string, mixed> $winner
     * @return array{success: array<int, string>, error: array<int, string>}
     */
    private function applyPostStatusChangeActions(
        Connection $connection,
        string $table,
        int $recordId,
        array $winner,
        string $statusId,
        string $previousStatus
    ): array
    {
        $successMessages = [];
        $errorMessages = [];
        $codeShortage = false;
        $effectiveStatusId = $statusId;

        $codeAssignmentService = new WinnerPrizeCodeAssignmentService();
        if ($codeAssignmentService->shouldAssignCodesForStatus($connection, $statusId)) {
            $assignmentResult = $codeAssignmentService->assignConfiguredCodesToWinner($connection, $winner);
            if (!$assignmentResult['success']) {
                $error = trim((string) ($assignmentResult['error'] ?? ''));
                if ($error !== '') {
                    $errorMessages[] = $error;
                }
            } else {
                $winner = $assignmentResult['winner'];
                $codeShortage = filter_var($assignmentResult['code_shortage'] ?? false, FILTER_VALIDATE_BOOLEAN);
                if ($codeShortage) {
                    $effectiveStatusId = trim((string) ($assignmentResult['effective_status_id'] ?? $winner['stan'] ?? $statusId));
                }
                $message = trim((string) ($assignmentResult['message'] ?? ''));
                if ($message !== '' && !($assignmentResult['skipped'] ?? false)) {
                    $successMessages[] = $message;
                }
            }
        }

        $canSendWinnerEmail = $errorMessages === [];
        $emailService = new WinnerStatusEmailService($this->mailer);
        if (trim($previousStatus) !== trim($effectiveStatusId)) {
            try {
                $administratorResult = $emailService->sendToAdministratorForStatus(
                    $connection,
                    $winner,
                    $recordId,
                    $effectiveStatusId
                );
                if ($administratorResult['success'] && $administratorResult['message'] !== '') {
                    $successMessages[] = $administratorResult['message'];
                }
                if (!$administratorResult['success'] && is_string($administratorResult['error']) && $administratorResult['error'] !== '') {
                    $errorMessages[] = $administratorResult['error'];
                }
            } catch (\Throwable) {
                $errorMessages[] = 'Wysyłka maila do administratora według ustawień statusu zakończyła się błędem.';
            }
        }

        if ($canSendWinnerEmail) {
            $emailResult = $emailService->sendForStatus(
                $connection,
                $winner,
                $recordId,
                $table,
                $effectiveStatusId,
                false
            );
            if ($emailResult['success'] && $emailResult['message'] !== '') {
                $successMessages[] = $emailResult['message'];
            }
            if (!$emailResult['success'] && is_string($emailResult['error']) && $emailResult['error'] !== '') {
                $errorMessages[] = $emailResult['error'];
            }
        }

        return [
            'success' => array_values(array_unique($successMessages)),
            'error' => array_values(array_unique($errorMessages)),
        ];
    }

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     * @return array{values: array<string, mixed>, errors: array<int, string>}
     */
    private function extractViewEditableValues(Request $request, array $definition): array
    {
        $configuredFields = $definition['module']['view']['editable_fields'] ?? null;
        if (!is_array($configuredFields) || $configuredFields === []) {
            return ['values' => [], 'errors' => []];
        }

        $fieldsByName = [];
        $definitionFields = $definition['fields'] ?? [];
        if (is_array($definitionFields)) {
            foreach ($definitionFields as $field) {
                if (!is_array($field)) {
                    continue;
                }

                $name = trim((string) ($field['name'] ?? ''));
                if ($name !== '') {
                    $fieldsByName[$name] = $field;
                }
            }
        }

        $values = [];
        $errors = [];
        foreach ($configuredFields as $configuredField) {
            $fieldName = is_string($configuredField)
                ? $configuredField
                : (is_array($configuredField) ? trim((string) ($configuredField['name'] ?? '')) : '');
            if ($fieldName === '' || !isset($fieldsByName[$fieldName])) {
                continue;
            }

            $field = is_array($configuredField) ? array_merge($fieldsByName[$fieldName], $configuredField) : $fieldsByName[$fieldName];
            if (($field['form'] ?? true) === false || (bool) ($field['readonly'] ?? false)) {
                continue;
            }

            $label = trim((string) ($field['label'] ?? $fieldName));
            $type = strtolower((string) ($field['type'] ?? 'text'));
            $isRequired = (bool) ($field['required'] ?? false);
            $value = $this->normalizeViewEditableValue($request, $fieldName, $type);

            if ($isRequired && $this->isEmptyViewEditableValue($value)) {
                $errors[] = sprintf('Pole „%s” musi zostać uzupełnione przed zapisem.', $label);
                continue;
            }

            $values[$fieldName] = $value;
        }

        return ['values' => $values, 'errors' => $errors];
    }

    private function normalizeViewEditableValue(Request $request, string $fieldName, string $type): mixed
    {
        if (in_array($type, ['checkbox', 'boolean', 'boolean_select'], true)) {
            return $request->request->getBoolean($fieldName);
        }

        $rawValue = $request->request->get($fieldName, '');
        if (is_array($rawValue)) {
            $normalized = array_values(array_filter(array_map(
                static fn(mixed $value): string => trim((string) $value),
                $rawValue
            ), static fn(string $value): bool => $value !== ''));

            return $normalized === [] ? null : json_encode($normalized, JSON_UNESCAPED_UNICODE);
        }

        $value = trim((string) $rawValue);

        return $value === '' ? null : $value;
    }

    private function isEmptyViewEditableValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        return $value === [] || $value === false;
    }

    /**
     * @return array<int, string>
     */
    private function fetchOrderedStatusIds(): array
    {
        $rows = $this->entityManager->getConnection()->createQueryBuilder()
            ->select('id')
            ->from('ustawienia_statusow')
            ->orderBy('position', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $statusIds = [];
        foreach ($rows as $row) {
            $statusId = trim((string) ($row['id'] ?? ''));
            if ($statusId !== '') {
                $statusIds[] = $statusId;
            }
        }

        return $statusIds;
    }

    private function fetchStatusIdByFlag(string $flag): string
    {
        if (!in_array($flag, ['do_wysylki', 'zrealizowane', 'odrzucone'], true)) {
            return '';
        }

        $statusId = $this->entityManager->getConnection()->createQueryBuilder()
            ->select('id')
            ->from('ustawienia_statusow')
            ->where($flag . ' = :enabled')
            ->setParameter('enabled', 1)
            ->orderBy('position', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_scalar($statusId) ? trim((string) $statusId) : '';
    }

    /**
     * @param array<string, mixed> $winner
     */
    private function prizeRequiresCourierShipment(array $winner): bool
    {
        $prizeTypeId = trim((string) ($winner['rodzaj_wygranej'] ?? ''));
        if ($prizeTypeId === '') {
            return false;
        }

        $requiresShipment = $this->entityManager->getConnection()->createQueryBuilder()
            ->select('wymaga_wysylki_kurierem')
            ->from('rodzaje_nagrod_entries')
            ->where('id = :id')
            ->setParameter('id', $prizeTypeId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return filter_var($requiresShipment, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param array<int, string> $statusIds
     */
    private function resolveNextStatus(array $statusIds, string $currentStatus): string
    {
        if ($currentStatus === '') {
            return $statusIds[0] ?? '';
        }

        $currentIndex = array_search($currentStatus, $statusIds, true);
        if ($currentIndex === false) {
            return $currentStatus;
        }

        return $statusIds[$currentIndex + 1] ?? $currentStatus;
    }

    private function redirectToRecordView(Request $request, string $moduleKey, string $recordId, string $status, string $message): RedirectResponse
    {
        $query = http_build_query([
            'status' => $status,
            'message' => $message,
        ]);

        return new RedirectResponse('/cms/modules/' . rawurlencode($moduleKey) . '/' . rawurlencode($recordId) . '/view?' . $query);
    }

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    public function sendWinnerEmail(Request $request, string $moduleKey, string $recordId, array $definition): ?Response
    {
        $table = $definition['database']['table'] ?? null;
        if (!is_string($table) || $table === '') {
            return ($this->redirectBuilder)($request, $moduleKey, 'error', 'Brak konfiguracji bazy danych.');
        }

        $connection = $this->entityManager->getConnection();
        $winner = $connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where('id = :id')
            ->setParameter('id', $recordId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($winner) || $winner === []) {
            return ($this->redirectBuilder)($request, $moduleKey, 'error', 'Nie znaleziono rekordu zwycięzcy.');
        }

        $statusId = trim((string) ($winner['stan'] ?? ''));
        if ($statusId === '') {
            return ($this->redirectBuilder)($request, $moduleKey, 'error', 'Rekord nie ma przypisanego statusu.');
        }

        $service = new WinnerStatusEmailService($this->mailer);
        $result = $service->sendForStatus($connection, $winner, (int) $recordId, $table, $statusId, true);

        if (!$result['success']) {
            return ($this->redirectBuilder)($request, $moduleKey, 'error', (string) $result['error']);
        }

        $sentAtValue = trim((string) ($winner['email_o_wygranej_wyslany_at'] ?? ''));
        if ($sentAtValue === '') {
            $connection->update($table, ['email_o_wygranej_wyslany_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')], ['id' => (int) $recordId]);
        }

        ($this->recordChange)($request, $moduleKey, 'send_winner_email', (int) $recordId);

        return ($this->redirectBuilder)($request, $moduleKey, 'success', 'Wiadomość e-mail została wysłana ponownie.');
    }

    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    public function sendPendingWinnerFormReminders(
        Request $request,
        string $moduleKey,
        string $recordId,
        array $definition
    ): ?Response {
        if ($moduleKey !== 'zwyciezcy') {
            return ($this->redirectBuilder)(
                $request,
                $moduleKey,
                'error',
                'Ta akcja jest dostępna tylko dla modułu Zwycięzcy.'
            );
        }

        $table = trim((string) ($definition['database']['table'] ?? ''));
        if ($table === '') {
            return ($this->redirectBuilder)($request, $moduleKey, 'error', 'Brak konfiguracji bazy danych.');
        }

        $templateId = trim((string) $request->request->get('email_template_id', ''));
        if ($templateId === '') {
            return ($this->redirectBuilder)($request, $moduleKey, 'error', 'Wybierz szablon e-mail.');
        }

        $connection = $this->entityManager->getConnection();
        $service = new WinnerFormReminderService($this->mailer);
        $template = $service->fetchTemplate($connection, $templateId);
        if (!is_array($template) || $template === []) {
            return ($this->redirectBuilder)($request, $moduleKey, 'error', 'Nie znaleziono wybranego szablonu e-mail.');
        }
        $templateContent = (string) ($template['subject'] ?? '') . "\n" . (string) ($template['body'] ?? '');
        if (preg_match('/\{\s*link_zwyciezca\s*\}/i', $templateContent) !== 1) {
            return ($this->redirectBuilder)(
                $request,
                $moduleKey,
                'error',
                'Wybrany szablon musi zawierać znacznik {link_zwyciezca}.'
            );
        }

        $settings = $this->fetchLotterySettings($connection);
        $winners = $service->findEligibleWinners($connection, $settings);
        if ($winners === []) {
            return ($this->redirectBuilder)(
                $request,
                $moduleKey,
                'success',
                'Nie znaleziono aktywnych, niewypełnionych formularzy wymagających przypomnienia.'
            );
        }

        $sentAt = new \DateTimeImmutable();
        $processedCount = 0;
        $queuedCount = 0;
        $errors = [];

        foreach ($winners as $winner) {
            $winnerId = (int) ($winner['id'] ?? 0);
            if ($winnerId <= 0) {
                continue;
            }

            $result = $service->sendReminder($winner, $template, $sentAt, $settings);
            if (!($result['success'] ?? false)) {
                $reason = trim((string) ($result['error'] ?? 'Nieznany błąd.'));
                $errors[] = '#' . $winnerId . ': ' . ($reason !== '' ? $reason : 'Nieznany błąd.');
                continue;
            }

            $processedCount++;
            if ($result['queued'] ?? false) {
                $queuedCount++;
            }

            $connection->update($table, [
                'data_ponownego_wyslania_formularza' => $sentAt->format('Y-m-d H:i:s'),
            ], ['id' => $winnerId]);
        }

        if ($processedCount > 0) {
            ($this->recordChange)(
                $request,
                $moduleKey,
                'send_pending_winner_form_reminders',
                null
            );
        }

        if ($processedCount === 0) {
            $message = 'Nie udało się wysłać żadnego przypomnienia.';
            if ($errors !== []) {
                $message .= ' ' . implode(' | ', array_slice($errors, 0, 3));
            }

            return ($this->redirectBuilder)($request, $moduleKey, 'error', $message);
        }

        $sentImmediatelyCount = $processedCount - $queuedCount;
        $parts = [sprintf('Przygotowano przypomnienia: %d.', $processedCount)];
        if ($queuedCount > 0) {
            $parts[] = sprintf('Dodano do kolejki: %d.', $queuedCount);
        }
        if ($sentImmediatelyCount > 0) {
            $parts[] = sprintf('Wysłano bezpośrednio: %d.', $sentImmediatelyCount);
        }
        if ($errors !== []) {
            $parts[] = sprintf('Błędy: %d.', count($errors));
            $parts[] = implode(' | ', array_slice($errors, 0, 3));
        }

        return ($this->redirectBuilder)(
            $request,
            $moduleKey,
            'success',
            implode(' ', $parts)
        );
    }

    /**
     * @return array<string, mixed>
     */
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
}
