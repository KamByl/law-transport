<?php

namespace App\Service;

use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

class ModuleInstaller
{
    private EntityManagerInterface $entityManager;
    private ModuleDefinitionLoader $definitionLoader;

    public function __construct(EntityManagerInterface $entityManager, ModuleDefinitionLoader $definitionLoader)
    {
        $this->entityManager = $entityManager;
        $this->definitionLoader = $definitionLoader;
    }

    /**
     * @return string[]
     */
    public function install(): array
    {
        $connection = $this->entityManager->getConnection();
        $schemaManager = $connection->createSchemaManager();
        $platform = $connection->getDatabasePlatform();
        $executedSql = [];

        foreach ($this->definitionLoader->getInstallDefinitions() as $definition) {
            $database = $definition['database'] ?? [];
            $tableName = $database['table'] ?? null;

            if (!$tableName) {
                continue;
            }

            $fields = $definition['fields'] ?? ($definition['module']['fields'] ?? []);
            if (!is_array($fields)) {
                $fields = [];
            }

            if ($schemaManager->tablesExist([$tableName])) {
                $currentTable = $schemaManager->introspectTable($tableName);
                $updatedTable = clone $currentTable;
                $this->ensureColumns($updatedTable, $fields);

                $comparator = new Comparator();
                $diff = $comparator->compareTables($currentTable, $updatedTable);

                if (!$diff->isEmpty()) {
                    $sqlStatements = $platform->getAlterTableSQL($diff);

                    foreach ($sqlStatements as $sql) {
                        $connection->executeStatement($sql);
                        $executedSql[] = $sql;
                    }
                }

                continue;
            }

            $table = new Table($tableName);
            $this->addPrimaryKey($table, $database);
            $this->ensureColumns($table, $fields);

            $sqlStatements = $platform->getCreateTableSQL($table);
            foreach ($sqlStatements as $sql) {
                $connection->executeStatement($sql);
                $executedSql[] = $sql;
            }
        }

        LotteryStandardStatusMigration::migrate($connection);
        $this->ensureWinningCodesIndexes($executedSql);
        $this->ensureGraphicCodesIndexes($executedSql);
        $this->ensureWinnerPostCreateQueueIndexes($executedSql);
        $this->ensureDeferredWinnerCodesIndex($executedSql);
        $this->ensureLotteryDailyEntryIndexes($executedSql);

        return $executedSql;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     */
    private function ensureColumns(Table $table, array $fields): void
    {
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = $field['name'] ?? null;
            if (!$name) {
                continue;
            }

            $type = strtolower((string) ($field['type'] ?? 'text'));
            if (in_array($type, ['info', 'related_field', 'relation_field'], true)) {
                continue;
            }

            [$columnType, $options] = $this->resolveColumnDefinition($field);
            if (!$table->hasColumn($name)) {
                $table->addColumn($name, $columnType, $options);
                continue;
            }

            if ($this->shouldUpdateExistingColumn($field)) {
                $column = $table->getColumn($name);
                $column->setType(Type::getType($columnType));
                if (isset($options['length'])) {
                    $column->setLength((int) $options['length']);
                }
                if (isset($options['notnull'])) {
                    $column->setNotnull((bool) $options['notnull']);
                }
            }
        }
    }

    /** @param string[] $executedSql */
    private function ensureLotteryDailyEntryIndexes(array &$executedSql): void
    {
        $connection = $this->entityManager->getConnection();
        if (!in_array(strtolower($connection->getDatabasePlatform()->getName()), ['mysql', 'mariadb'], true)) {
            return;
        }
        $schemaManager = $connection->createSchemaManager();
        foreach (['loteria_losy_entries', 'loteria_zdrapki_entries', 'loteria_zgloszenia_entries'] as $tableName) {
            if (!$schemaManager->tablesExist([$tableName])) {
                continue;
            }
            $columns = $schemaManager->listTableColumns($tableName);
            if (!isset($columns['submitted_at'])) {
                continue;
            }
            $indexes = $schemaManager->listTableIndexes($tableName);
            $hasDayIndex = false;
            foreach ($indexes as $index) {
                if (($index->getColumns()[0] ?? null) === 'submitted_at') {
                    $hasDayIndex = true;
                    break;
                }
            }
            if (!$hasDayIndex) {
                $sql = sprintf('CREATE INDEX idx_lottery_daily_entries ON %s (submitted_at)', $tableName);
                $connection->executeStatement($sql);
                $executedSql[] = $sql;
            }
        }
    }

    /**
     * @param array<string, mixed> $field
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function resolveColumnDefinition(array $field): array
    {
        $db = $field['db'] ?? [];
        if (is_array($db) && isset($db['type'])) {
            $options = is_array($db['options'] ?? null) ? $db['options'] : [];
            $options += ['notnull' => false];

            return [$db['type'], $options];
        }

        $type = strtolower((string) ($field['type'] ?? 'text'));

        return match ($type) {
            'textarea', 'richtext', 'wysiwyg', 'json', 'json_pretty' => [Types::TEXT, ['notnull' => false]],
            'date' => [Types::DATE_MUTABLE, ['notnull' => false]],
            'datetime' => [Types::DATETIME_MUTABLE, ['notnull' => false]],
            'number', 'integer' => [Types::INTEGER, ['notnull' => false]],
            'float', 'decimal' => [Types::FLOAT, ['notnull' => false]],
            'boolean', 'checkbox', 'boolean_select' => [Types::BOOLEAN, ['notnull' => false]],
            'relation' => [
                $this->relationNeedsStringColumn($field) ? Types::STRING : (($field['multiple'] ?? false) ? Types::JSON : Types::INTEGER),
                $this->relationNeedsStringColumn($field) ? ['length' => 255, 'notnull' => false] : ['notnull' => false],
            ],
            'image', 'file', 'video' => [Types::STRING, ['length' => 255, 'notnull' => false]],
            default => [Types::STRING, ['length' => 255, 'notnull' => false]],
        };
    }


    /**
     * Relation fields normally store numeric IDs, but configured extra options may use string keys
     * (for example "predefined:..."). Single-value relations with such options must therefore
     * be stored in a string column so both database IDs and predefined values can be saved.
     *
     * @param array<string, mixed> $field
     */
    private function relationNeedsStringColumn(array $field): bool
    {
        if (($field['multiple'] ?? false) || !isset($field['extra_options']) || !is_array($field['extra_options'])) {
            return false;
        }

        foreach (array_keys($field['extra_options']) as $value) {
            if (!is_int($value) && !ctype_digit((string) $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function shouldUpdateExistingColumn(array $field): bool
    {
        $type = strtolower((string) ($field['type'] ?? 'text'));

        if (isset($field['db'])) {
            return true;
        }

        return (
            $type === 'relation'
            && (($field['multiple'] ?? false) || $this->relationNeedsStringColumn($field))
            && !isset($field['db'])
        ) || in_array($type, ['date', 'datetime'], true);
    }

    /**
     * @param array<string, mixed> $database
     */
    private function addPrimaryKey(Table $table, array $database): void
    {
        $primaryKey = $database['primary_key'] ?? 'id';
        $primaryType = $database['primary_type'] ?? Types::INTEGER;
        $options = is_array($database['primary_options'] ?? null) ? $database['primary_options'] : [];
        $autoIncrement = $database['auto_increment'] ?? true;

        $options += ['autoincrement' => $autoIncrement, 'notnull' => true];

        $table->addColumn($primaryKey, $primaryType, $options);
        $table->setPrimaryKey([$primaryKey]);
    }

    /**
     * @param string[] $executedSql
     */
    private function ensureWinningCodesIndexes(array &$executedSql): void
    {
        $connection = $this->entityManager->getConnection();
        $schemaManager = $connection->createSchemaManager();
        $tableName = 'lista_wygranych_kodow_entries';

        if (!$schemaManager->tablesExist([$tableName])) {
            return;
        }

        $platformName = strtolower($connection->getDatabasePlatform()->getName());
        if (!in_array($platformName, ['mysql', 'mariadb'], true)) {
            return;
        }

        $this->assertWinningPickupCodesAreUnique($tableName);

        $columns = array_map('strtolower', array_keys($schemaManager->listTableColumns($tableName)));
        if (!in_array('pickup_code_unique_key', $columns, true)) {
            $sql = "ALTER TABLE {$tableName} ADD COLUMN pickup_code_unique_key VARCHAR(255) GENERATED ALWAYS AS (NULLIF(TRIM(pickup_code), '')) STORED";
            $connection->executeStatement($sql);
            $executedSql[] = $sql;
        }

        $indexes = array_map('strtolower', array_keys($schemaManager->listTableIndexes($tableName)));
        $indexStatements = [
            'uq_winning_codes_pickup_code' => "ALTER TABLE {$tableName} ADD UNIQUE INDEX uq_winning_codes_pickup_code (pickup_code_unique_key)",
            'idx_winning_codes_winner' => "ALTER TABLE {$tableName} ADD INDEX idx_winning_codes_winner (kod_przyznany_do_zwyciezcy, id)",
            'idx_winning_codes_any_lookup' => "ALTER TABLE {$tableName} ADD INDEX idx_winning_codes_any_lookup (kod_wykorzystany, kod_przyznany_do_zwyciezcy, id)",
            'idx_winning_codes_lookup' => "ALTER TABLE {$tableName} ADD INDEX idx_winning_codes_lookup (wartosc_kodu, kod_wykorzystany, kod_przyznany_do_zwyciezcy, id)",
        ];

        foreach ($indexStatements as $indexName => $sql) {
            if (in_array(strtolower($indexName), $indexes, true)) {
                continue;
            }

            $connection->executeStatement($sql);
            $executedSql[] = $sql;
        }
    }

    /** @param string[] $executedSql */
    private function ensureWinnerPostCreateQueueIndexes(array &$executedSql): void
    {
        $connection = $this->entityManager->getConnection();
        $schemaManager = $connection->createSchemaManager();
        $tableName = 'loteria_zwyciezcy_entries';

        if (!$schemaManager->tablesExist([$tableName])) {
            return;
        }

        $platformName = strtolower($connection->getDatabasePlatform()->getName());
        if (!in_array($platformName, ['mysql', 'mariadb'], true)) {
            return;
        }

        $columns = array_map('strtolower', array_keys($schemaManager->listTableColumns($tableName)));
        $requiredColumns = [
            'post_create_processing_status',
            'post_create_next_attempt_at',
            'post_create_locked_at',
            'post_create_attempts',
        ];
        if (array_diff($requiredColumns, $columns) !== []) {
            return;
        }

        $indexes = array_map('strtolower', array_keys($schemaManager->listTableIndexes($tableName)));
        $indexStatements = [
            'idx_winner_post_create_pending' => "ALTER TABLE {$tableName} ADD INDEX idx_winner_post_create_pending (post_create_processing_status, post_create_next_attempt_at, post_create_attempts, id)",
            'idx_winner_post_create_stale' => "ALTER TABLE {$tableName} ADD INDEX idx_winner_post_create_stale (post_create_processing_status, post_create_locked_at, post_create_attempts, id)",
        ];

        foreach ($indexStatements as $indexName => $sql) {
            if (in_array(strtolower($indexName), $indexes, true)) {
                continue;
            }
            $connection->executeStatement($sql);
            $executedSql[] = $sql;
        }
    }

    /** @param string[] $executedSql */
    private function ensureGraphicCodesIndexes(array &$executedSql): void
    {
        $connection = $this->entityManager->getConnection();
        $schemaManager = $connection->createSchemaManager();
        $tableName = GraphicCodeImporter::TABLE;

        if (!$schemaManager->tablesExist([$tableName])) {
            return;
        }

        $platformName = strtolower($connection->getDatabasePlatform()->getName());
        if (!in_array($platformName, ['mysql', 'mariadb'], true)) {
            return;
        }

        $indexes = array_map('strtolower', array_keys($schemaManager->listTableIndexes($tableName)));
        $indexStatements = [
            'uq_graphic_codes_filename' => "ALTER TABLE {$tableName} ADD UNIQUE INDEX uq_graphic_codes_filename (nazwa_pliku)",
            'uq_graphic_codes_web_token' => "ALTER TABLE {$tableName} ADD UNIQUE INDEX uq_graphic_codes_web_token (token_www)",
            'idx_graphic_codes_winner' => "ALTER TABLE {$tableName} ADD INDEX idx_graphic_codes_winner (kod_przyznany_do_zwyciezcy, id)",
            'idx_graphic_codes_lookup' => "ALTER TABLE {$tableName} ADD INDEX idx_graphic_codes_lookup (wartosc_kodu, kod_wykorzystany, kod_przyznany_do_zwyciezcy, id)",
        ];

        foreach ($indexStatements as $indexName => $sql) {
            if (in_array(strtolower($indexName), $indexes, true)) {
                continue;
            }
            $connection->executeStatement($sql);
            $executedSql[] = $sql;
        }
    }

    /** @param string[] $executedSql */
    private function ensureDeferredWinnerCodesIndex(array &$executedSql): void
    {
        $connection = $this->entityManager->getConnection();
        $schemaManager = $connection->createSchemaManager();
        $tableName = 'loteria_zwyciezcy_entries';

        if (!$schemaManager->tablesExist([$tableName])) {
            return;
        }

        $platformName = strtolower($connection->getDatabasePlatform()->getName());
        if (!in_array($platformName, ['mysql', 'mariadb'], true)) {
            return;
        }

        $columns = array_map('strtolower', array_keys($schemaManager->listTableColumns($tableName)));
        if (array_diff(['stan', 'rodzaj_wygranej'], $columns) !== []) {
            return;
        }

        $indexName = 'idx_winners_deferred_codes';
        $indexes = array_map('strtolower', array_keys($schemaManager->listTableIndexes($tableName)));
        if (in_array($indexName, $indexes, true)) {
            return;
        }

        $sql = "ALTER TABLE {$tableName} ADD INDEX {$indexName} (stan, rodzaj_wygranej, id)";
        $connection->executeStatement($sql);
        $executedSql[] = $sql;
    }

    private function assertWinningPickupCodesAreUnique(string $tableName): void
    {
        $connection = $this->entityManager->getConnection();
        $duplicate = $connection->createQueryBuilder()
            ->select('TRIM(pickup_code) AS pickup_code', 'COUNT(*) AS duplicates_count')
            ->from($tableName)
            ->where("pickup_code IS NOT NULL")
            ->andWhere("TRIM(pickup_code) <> ''")
            ->groupBy('TRIM(pickup_code)')
            ->having('COUNT(*) > 1')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($duplicate) || $duplicate === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Nie mozna dodac unikalnego zabezpieczenia kodow wygranych, bo kod "%s" wystepuje %d razy w tabeli %s.',
            (string) ($duplicate['pickup_code'] ?? ''),
            (int) ($duplicate['duplicates_count'] ?? 0),
            $tableName
        ));
    }
}
