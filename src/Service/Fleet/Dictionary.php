<?php

namespace App\Service\Fleet;

use App\Service\ModuleDefinitionLoader;

/**
 * Słowniki (opcje pól select) brane z definicji modułów, żeby frontend i /cms miały jedno źródło.
 */
class Dictionary
{
    public const TRIP_STATUS_COLORS = [
        'planowany' => 'secondary',
        'zatwierdzony' => 'primary',
        'w_realizacji' => 'warning',
        'zakonczony' => 'success',
        'anulowany' => 'danger',
    ];

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $definitions = null;

    /**
     * @return array<string, string>
     */
    public static function options(string $moduleKey, string $fieldName): array
    {
        $field = self::field($moduleKey, $fieldName);
        $options = $field['options'] ?? [];

        return is_array($options) ? array_map('strval', $options) : [];
    }

    public static function label(string $moduleKey, string $fieldName, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return self::options($moduleKey, $fieldName)[$value] ?? $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function field(string $moduleKey, string $fieldName): ?array
    {
        foreach (self::fields($moduleKey) as $field) {
            if (($field['name'] ?? null) === $fieldName) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function fields(string $moduleKey): array
    {
        $definition = self::definitions()[$moduleKey] ?? null;
        $fields = $definition['module']['fields'] ?? [];

        return is_array($fields) ? array_values(array_filter($fields, 'is_array')) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function definition(string $moduleKey): ?array
    {
        return self::definitions()[$moduleKey] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function definitions(): array
    {
        if (self::$definitions === null) {
            self::$definitions = [];
            foreach ((new ModuleDefinitionLoader())->getInstallDefinitions() as $definition) {
                $key = $definition['key'] ?? null;
                if (is_string($key) && $key !== '') {
                    self::$definitions[$key] = $definition;
                }
            }
        }

        return self::$definitions;
    }
}
