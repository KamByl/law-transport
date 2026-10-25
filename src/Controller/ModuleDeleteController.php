<?php

namespace App\Controller;

use App\Service\PermissionService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ModuleDeleteController extends ModuleController
{
    /** @var array<string, ModuleDeleteActionController|null> */
    private array $deleteActionControllers = [];

    public function delete(Request $request, array $parameters): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        if (!$request->isMethod('POST')) {
            return new Response('Niepoprawna metoda.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        $moduleKey = (string) ($parameters['moduleKey'] ?? '');
        $definition = $this->loadModuleDefinition($moduleKey);

        if (!$definition) {
            return new Response('Nie znaleziono modułu.', Response::HTTP_NOT_FOUND);
        }

        if ($response = $this->denyUnlessPermission($moduleKey, PermissionService::ACTION_DELETE)) {
            return $response;
        }

        $database = $definition['database'];
        $table = $database['table'] ?? null;
        $primaryKey = $database['primary_key'] ?? 'id';
        $id = (int) ($parameters['id'] ?? 0);

        if (!$table) {
            return new Response('Brak konfiguracji bazy danych.', Response::HTTP_BAD_REQUEST);
        }

        $connection = $this->entityManager->getConnection();
        $deleteRelatedLotteryItems = $request->request->getBoolean('delete_related_lottery_items');

        $deleteActionController = $this->resolveDeleteActionController($moduleKey);
        if ($deleteActionController !== null) {
            $deleteActionController->beforeDelete($moduleKey, $id, $definition, $deleteRelatedLotteryItems);
        }

        $connection->delete($table, [$primaryKey => $id]);
        $this->recordModuleChange($request, $moduleKey, 'delete', $id);

        return new RedirectResponse($this->getModuleListUrl($request, $moduleKey));
    }

    private function resolveDeleteActionController(string $moduleKey): ?ModuleDeleteActionController
    {
        if (array_key_exists($moduleKey, $this->deleteActionControllers)) {
            return $this->deleteActionControllers[$moduleKey];
        }

        $controllerClass = $this->resolveModuleScopedControllerClass($moduleKey, 'ModuleDeleteActionController', ModuleDeleteActionController::class);
        $controller = null;

        if ($controllerClass !== null) {
            $instance = new $controllerClass($this->entityManager);
            $controller = $instance instanceof ModuleDeleteActionController ? $instance : null;
        }

        $this->deleteActionControllers[$moduleKey] = $controller;

        return $controller;
    }

}
