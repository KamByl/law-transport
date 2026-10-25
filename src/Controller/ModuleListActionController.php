<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

abstract class ModuleListActionController
{
    /**
     * @param array{module: array<string, mixed>, database: array<string, mixed>} $definition
     */
    public function handle(
        string $handlerName,
        Request $request,
        string $moduleKey,
        string $recordId,
        array $definition
    ): ?Response {
        if ($handlerName === '' || !method_exists($this, $handlerName)) {
            return null;
        }

        $result = $this->{$handlerName}($request, $moduleKey, $recordId, $definition);

        return $result instanceof Response ? $result : null;
    }
}

