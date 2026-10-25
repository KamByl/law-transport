<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

abstract class ModuleDeleteActionController
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function beforeDelete(string $moduleKey, int $recordId, array $definition, bool $deleteRelated): void
    {
    }

    protected function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
