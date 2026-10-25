<?php

namespace App\Controller;

use App\Service\WinnerPrizeCodeAssignmentService;
use App\Service\WinnerStatusEmailService;

final class ZwyciezcyModuleOnChangeController extends ModuleOnChangeController
{
    /**
     * @param array<string, mixed> $newRow
     * @param array<string, mixed> $oldRow
     */
    protected function onWinnerStatusChange(
        string $moduleKey,
        string $fieldName,
        mixed $oldValue,
        mixed $newValue,
        int $recordId,
        array $newRow,
        array $oldRow
    ): array {
        $statusId = trim((string) $newValue);
        if ($statusId === '') {
            return ['success' => [], 'error' => []];
        }

        $moduleTable = match (trim($moduleKey)) {
            'zwyciezcy', 'weryfikacja_administrator', 'weryfikacja_klient', 'wysylka_nagrod' => 'loteria_zwyciezcy_entries',
            default => '',
        };
        if ($moduleTable === '') {
            return ['success' => [], 'error' => []];
        }

        $connection = $this->entityManager->getConnection();
        $successMessages = [];
        $errorMessages = [];
        $isInitialCreation = array_key_exists('_send_status_email_after_creation', $newRow);
        $sendEmailAfterCreation = $isInitialCreation
            && filter_var($newRow['_send_status_email_after_creation'], FILTER_VALIDATE_BOOLEAN);
        $forceEmailQueue = filter_var($newRow['_force_status_email_queue'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $codeAssignmentFailed = false;
        $codeShortage = false;
        $effectiveStatusId = $statusId;

        $codeAssignmentService = new WinnerPrizeCodeAssignmentService();
        if ($codeAssignmentService->shouldAssignCodesForStatus($connection, $statusId)) {
            $assignmentResult = $codeAssignmentService->assignConfiguredCodesToWinner($connection, array_merge($newRow, ['id' => $recordId]));
            if (!$assignmentResult['success']) {
                $codeAssignmentFailed = true;
                $error = trim((string) ($assignmentResult['error'] ?? ''));
                if ($error !== '') {
                    $errorMessages[] = $error;
                }
            } else {
                $newRow = $assignmentResult['winner'];
                $codeShortage = filter_var($assignmentResult['code_shortage'] ?? false, FILTER_VALIDATE_BOOLEAN);
                if ($codeShortage) {
                    $effectiveStatusId = trim((string) ($assignmentResult['effective_status_id'] ?? $newRow['stan'] ?? $statusId));
                }
                $message = trim((string) ($assignmentResult['message'] ?? ''));
                if ($message !== '' && !($assignmentResult['skipped'] ?? false)) {
                    $successMessages[] = $message;
                }
            }
        }

        $service = new WinnerStatusEmailService($this->mailer);
        try {
            $administratorResult = $service->sendToAdministratorForStatus(
                $connection,
                $newRow,
                $recordId,
                $effectiveStatusId,
                $forceEmailQueue
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

        if ($codeAssignmentFailed || ($isInitialCreation && !$sendEmailAfterCreation)) {
            return [
                'success' => array_values(array_unique($successMessages)),
                'error' => array_values(array_unique($errorMessages)),
            ];
        }

        try {
            $result = $service->sendForStatus(
                $connection,
                $newRow,
                $recordId,
                $moduleTable,
                $effectiveStatusId,
                false,
                $sendEmailAfterCreation,
                $forceEmailQueue
            );
        } catch (\Throwable) {
            $errorMessages[] = $isInitialCreation
                ? 'Zwycięzca został zapisany, ale wysyłka maila według ustawień statusu zakończyła się błędem.'
                : 'Wysyłka maila według ustawień statusu zakończyła się błędem.';

            return [
                'success' => array_values(array_unique($successMessages)),
                'error' => array_values(array_unique($errorMessages)),
            ];
        }

        if ($result['success'] && $result['message'] !== '') {
            $successMessages[] = $result['message'];
        }
        if (!$result['success'] && is_string($result['error']) && $result['error'] !== '') {
            $errorMessages[] = $result['error'];
        }

        return [
            'success' => array_values(array_unique($successMessages)),
            'error' => array_values(array_unique($errorMessages)),
        ];
    }
}
