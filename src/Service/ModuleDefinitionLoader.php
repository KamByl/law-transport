<?php

namespace App\Service;

class ModuleDefinitionLoader
{
    private string $rootDir;

    public function __construct(?string $rootDir = null)
    {
        $this->rootDir = $rootDir ?? dirname(__DIR__, 2);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getInstallDefinitions(): array
    {
        $directory = $this->rootDir . '/config/module-installations';

        if (!is_dir($directory)) {
            return [];
        }

        $definitions = [];
        $files = glob($directory . '/*.php') ?: [];

        foreach ($files as $file) {
            $definition = require $file;

            if (!is_array($definition)) {
                continue;
            }

            $definition['__file'] = $file;
            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getModules(): array
    {
        $modules = [];

        foreach ($this->getInstallDefinitions() as $definition) {
            $key = $definition['key'] ?? null;
            $module = $definition['module'] ?? null;

            if (!$key || !is_array($module)) {
                continue;
            }

            $modules[$key] = $module;
        }

        return $modules;
    }

    /**
     * @return array<int, array{code: string, label: string}>
     */
    public function getPermissionDefinitions(): array
    {
        $definitions = [];

        foreach ($this->getInstallDefinitions() as $definition) {
            $permission = $definition['permission'] ?? null;
            $module = $definition['module'] ?? [];

            if (is_string($permission)) {
                $permission = ['code' => $permission];
            }

            if (!is_array($permission) || empty($permission['code'])) {
                continue;
            }

            $definitions[] = [
                'code' => $permission['code'],
                'label' => $permission['label'] ?? $module['label'] ?? $permission['code'],
            ];
        }

        return $definitions;
    }
}
