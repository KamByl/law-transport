<?php

namespace App\Controller;

final class LoteriaZgloszeniaModuleDeleteActionController extends ModuleDeleteActionController
{
    /**
     * @param array<string, mixed> $definition
     */
    public function beforeDelete(string $moduleKey, int $recordId, array $definition, bool $deleteRelated): void
    {
        if (!$deleteRelated) {
            return;
        }

        $lotteryTicketsDefinition = $this->resolveModuleDefinition('loteria_losy');
        $scratchcardsDefinition = $this->resolveModuleDefinition('loteria_zdrapki');
        $ticketTable = is_array($lotteryTicketsDefinition) ? ($lotteryTicketsDefinition['database']['table'] ?? null) : null;
        $scratchTable = is_array($scratchcardsDefinition) ? ($scratchcardsDefinition['database']['table'] ?? null) : null;

        if (is_string($ticketTable) && $ticketTable !== '') {
            $this->connection()->delete($ticketTable, ['zgloszenie' => $recordId]);
        }

        if (is_string($scratchTable) && $scratchTable !== '') {
            $this->connection()->delete($scratchTable, ['zgloszenie' => $recordId]);
        }
    }

    /**
     * @return array{module: array<string, mixed>, database: array<string, mixed>}|null
     */
    private function resolveModuleDefinition(string $moduleKey): ?array
    {
        $normalizedModuleKey = trim($moduleKey);
        if ($normalizedModuleKey === '') {
            return null;
        }

        $definitionLoader = new \App\Service\ModuleDefinitionLoader();
        foreach ($definitionLoader->getInstallDefinitions() as $definition) {
            if (($definition['key'] ?? null) !== $normalizedModuleKey) {
                continue;
            }

            $module = $definition['module'] ?? null;
            $database = $definition['database'] ?? null;

            if (!is_array($module) || !is_array($database)) {
                return null;
            }

            return [
                'module' => $module,
                'database' => $database,
            ];
        }

        return null;
    }
}
