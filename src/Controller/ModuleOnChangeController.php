<?php

namespace App\Controller;

use App\Service\Mailer;
use Doctrine\ORM\EntityManagerInterface;

abstract class ModuleOnChangeController
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly Mailer $mailer
    ) {
    }

    /**
     * @param array<string, mixed> $newRow
     * @param array<string, mixed> $oldRow
     */
    public function handle(
        string $handlerName,
        string $moduleKey,
        string $fieldName,
        mixed $oldValue,
        mixed $newValue,
        int $recordId,
        array $newRow,
        array $oldRow
    ): array {
        if ($handlerName === '' || !method_exists($this, $handlerName)) {
            return ['success' => [], 'error' => []];
        }

        $result = $this->{$handlerName}($moduleKey, $fieldName, $oldValue, $newValue, $recordId, $newRow, $oldRow);
        if (
            is_array($result)
            && array_key_exists('success', $result)
            && array_key_exists('error', $result)
            && is_array($result['success'])
            && is_array($result['error'])
        ) {
            return $result;
        }

        return ['success' => [], 'error' => []];
    }
}
