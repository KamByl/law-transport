<?php

namespace App\Service;

class PermissionDefinitionProvider
{
    /**
     * @return array<int, array{code: string, label: string}>
     */
    public static function getSystemDefinitions(): array
    {
        return [
            ['code' => 'users', 'label' => 'Użytkownicy'],
            ['code' => 'groups', 'label' => 'Grupy'],
            ['code' => 'permissions', 'label' => 'Uprawnienia'],
            ['code' => 'login_logs', 'label' => 'Logi logowań'],
            ['code' => 'module_change_logs', 'label' => 'Logi zmian'],
            ['code' => 'sms_logs', 'label' => 'Logi SMS'],
            ['code' => 'email_logs', 'label' => 'Logi e-mail'],
            ['code' => 'error_logs', 'label' => 'Logi błędów'],
            ['code' => 'install_db', 'label' => 'Instalacja DB'],
            ['code' => 'debug_tools', 'label' => 'Narzędzia debugowania'],
        ];
    }

    /**
     * @return array<int, array{code: string, label: string}>
     */
    public static function getCurrentDefinitions(?ModuleDefinitionLoader $definitionLoader = null): array
    {
        $definitions = self::getSystemDefinitions();

        if ($definitionLoader) {
            $definitions = array_merge($definitions, $definitionLoader->getPermissionDefinitions());
        }

        return self::deduplicateDefinitions($definitions);
    }

    /**
     * @return string[]
     */
    public static function getCurrentCodes(?ModuleDefinitionLoader $definitionLoader = null): array
    {
        return array_values(array_map(
            static fn(array $definition): string => $definition['code'],
            self::getCurrentDefinitions($definitionLoader)
        ));
    }

    /**
     * @param array<int, array{code?: string|null, label?: string|null}> $definitions
     * @return array<int, array{code: string, label: string}>
     */
    public static function deduplicateDefinitions(array $definitions): array
    {
        $seen = [];
        $result = [];

        foreach ($definitions as $definition) {
            $code = $definition['code'] ?? null;
            if (!$code || isset($seen[$code])) {
                continue;
            }

            $seen[$code] = true;
            $result[] = [
                'code' => $code,
                'label' => $definition['label'] ?? $code,
            ];
        }

        return $result;
    }
}
