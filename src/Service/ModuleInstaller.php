<?php

namespace App\Service;

use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

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
                $this->ensureIndexes($updatedTable, $database);

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
            $this->ensureIndexes($table, $database);

            $sqlStatements = $platform->getCreateTableSQL($table);
            foreach ($sqlStatements as $sql) {
                $connection->executeStatement($sql);
                $executedSql[] = $sql;
            }
        }

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

    /**
     * Indeksy z definicji modułu, np.:
     * 'database' => ['table' => 'x', 'indexes' => ['idx_x_pojazd' => ['pojazd', 'data'], 'uniq_x_numer' => ['columns' => ['numer'], 'unique' => true]]]
     *
     * @param array<string, mixed> $database
     */
    private function ensureIndexes(Table $table, array $database): void
    {
        $indexes = $database['indexes'] ?? [];
        if (!is_array($indexes)) {
            return;
        }

        foreach ($indexes as $name => $index) {
            $columns = is_array($index) && array_is_list($index) ? $index : (array) ($index['columns'] ?? []);
            $unique = is_array($index) && !array_is_list($index) && (bool) ($index['unique'] ?? false);
            $columns = array_values(array_filter($columns, static fn($column): bool => is_string($column) && $table->hasColumn($column)));
            if (!is_string($name) || $columns === [] || $table->hasIndex($name)) {
                continue;
            }

            if ($unique) {
                $table->addUniqueIndex($columns, $name);
            } else {
                $table->addIndex($columns, $name);
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

}
