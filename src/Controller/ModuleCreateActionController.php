<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

abstract class ModuleCreateActionController
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * @param array<string, mixed> $values
     * @return array{values: array<string, mixed>, errors: array<int, string>}
     */
    public function handle(string $handlerName, Connection $connection, array $values, string $moduleKey): array
    {
        if ($handlerName === '' || !method_exists($this, $handlerName)) {
            return ['values' => $values, 'errors' => []];
        }

        $result = $this->{$handlerName}($connection, $values, $moduleKey);
        if (
            is_array($result)
            && array_key_exists('values', $result)
            && array_key_exists('errors', $result)
            && is_array($result['values'])
            && is_array($result['errors'])
        ) {
            return $result;
        }

        return ['values' => $values, 'errors' => []];
    }
}

