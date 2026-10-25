<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

class SchemaManager
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Creates missing tables and columns without removing existing ones.
     *
     * @return string[] SQL statements that were executed.
     */
    public function updateSchema(): array
    {
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        if ($metadata === []) {
            return [];
        }

        $schemaTool = new SchemaTool($this->entityManager);
        $sqlStatements = $schemaTool->getUpdateSchemaSql($metadata, true);

        if ($sqlStatements !== []) {
            $schemaTool->updateSchema($metadata, true);
        }

        return $sqlStatements;
    }

    public function hasTable(string $tableName): bool
    {
        $schemaManager = $this->entityManager->getConnection()->createSchemaManager();

        return $schemaManager->tablesExist([$tableName]);
    }
}
