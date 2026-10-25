<?php

namespace App\Controller\App;

use App\Controller\BaseController;
use App\Entity\User;
use App\Service\Fleet\FleetRepository;
use App\Service\PermissionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Wspólna baza kontrolerów frontendu LAW Transport (dyspozytorzy, kierowcy, podgląd).
 */
abstract class AppBaseController extends BaseController
{
    private const FLASH_KEY = '_app_flash';

    private ?FleetRepository $fleetRepository = null;
    private ?array $context = null;

    protected function repo(): FleetRepository
    {
        return $this->fleetRepository ??= new FleetRepository($this->entityManager->getConnection());
    }

    /**
     * Kim jest zalogowany użytkownik we frontendzie.
     *
     * @return array{user: User|null, admin: bool, manager: bool, viewer: bool, driver: array<string, mixed>|null, can: array<string, array<string, bool>>}
     */
    protected function ctx(): array
    {
        if ($this->context !== null) {
            return $this->context;
        }

        $user = $this->authService->user();
        $can = [];
        foreach (['pojazdy', 'kierowcy', 'wyjazdy', 'tankowania', 'jednostka'] as $code) {
            foreach ([PermissionService::ACTION_VIEW, PermissionService::ACTION_CREATE, PermissionService::ACTION_EDIT, PermissionService::ACTION_DELETE] as $action) {
                $can[$code][$action] = $user !== null && $this->permissionService->can($user, $code, $action);
            }
        }

        $driver = null;
        if ($user !== null && $this->repo()->isInstalled()) {
            $driver = $this->repo()->driverByEmail($user->getEmail());
        }

        return $this->context = [
            'user' => $user,
            'admin' => $user !== null && $this->permissionService->isAdministrator($user),
            'manager' => $can['wyjazdy']['edit'] ?? false,
            'viewer' => $can['wyjazdy']['view'] ?? false,
            'driver' => $driver,
            'can' => $can,
        ];
    }

    /**
     * Zwraca odpowiedź, jeśli użytkownik nie może wejść na stronę (brak logowania lub instalacji).
     */
    protected function gate(Request $request): ?Response
    {
        if ($this->authService->user() === null) {
            if ($request->headers->has('HX-Request') || $this->wantsJson($request)) {
                return new JsonResponse(['error' => 'Sesja wygasła. Zaloguj się ponownie.'], Response::HTTP_UNAUTHORIZED);
            }

            return new RedirectResponse('/logowanie?next=' . rawurlencode($request->getRequestUri()));
        }

        if (!$this->repo()->isInstalled()) {
            return $this->renderApp('app/not_installed.html.twig', [], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return null;
    }

    protected function requireAccess(bool $allowed): void
    {
        if (!$allowed) {
            throw new AccessDeniedHttpException('Brak uprawnień.');
        }
    }

    protected function can(string $code, string $action): bool
    {
        return $this->ctx()['can'][$code][$action] ?? false;
    }

    protected function flash(string $type, string $message): void
    {
        $_SESSION[self::FLASH_KEY][] = ['type' => $type, 'message' => $message];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function renderApp(string $template, array $data = [], int $status = 200): Response
    {
        $ctx = $this->ctx();
        $flashes = $_SESSION[self::FLASH_KEY] ?? [];
        unset($_SESSION[self::FLASH_KEY]);

        return $this->render($template, $data + [
            'ctx' => $ctx,
            'nav' => $this->navigation($ctx),
            'unit' => $this->repo()->isInstalled() ? $this->repo()->unit() : [],
            'flashes' => $flashes,
            'impersonating' => $this->authService->isImpersonating(),
            'impersonator' => $this->authService->impersonator(),
            'currentPath' => $_SERVER['REQUEST_URI'] ?? '/',
            // Frontend nie korzysta z menu panelu /cms.
            'modules' => [],
            'menuModules' => [],
            'menuModuleGroups' => [],
        ], $status);
    }

    protected function wantsJson(Request $request): bool
    {
        return str_contains((string) $request->headers->get('Accept'), 'application/json')
            || $request->getContentTypeFormat() === 'json';
    }

    /**
     * @return array<string, mixed>
     */
    protected function jsonBody(Request $request): array
    {
        $data = json_decode((string) $request->getContent(), true);

        return is_array($data) ? $data : [];
    }

    protected function safeReturnPath(?string $path, string $fallback): string
    {
        $path = (string) $path;

        // Tylko ścieżki wewnętrzne: bez „//host” i „/\host”, które przeglądarki traktują jak adres zewnętrzny.
        return $path !== '' && str_starts_with($path, '/') && !str_starts_with($path, '//') && !str_contains($path, '\\')
            ? $path
            : $fallback;
    }

    protected function userDisplayName(): string
    {
        $user = $this->ctx()['user'];
        if (!$user instanceof User) {
            return '';
        }

        $driver = $this->ctx()['driver'];
        if ($driver) {
            return FleetRepository::driverLabel($driver);
        }

        return trim($user->getFirstName() . ' ' . $user->getLastName());
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<int, array{href: string, label: string, icon: string}>
     */
    private function navigation(array $ctx): array
    {
        if (!$ctx['user']) {
            return [];
        }

        $items = [['href' => '/', 'label' => 'Pulpit', 'icon' => 'bi-grid-1x2']];
        if ($ctx['viewer']) {
            $items[] = ['href' => '/planer', 'label' => 'Planer wyjazdów', 'icon' => 'bi-calendar3-range'];
            $items[] = ['href' => '/wyjazdy', 'label' => 'Wyjazdy', 'icon' => 'bi-signpost-split'];
        }
        if ($ctx['driver']) {
            $items[] = ['href' => '/moje-wyjazdy', 'label' => 'Moje wyjazdy', 'icon' => 'bi-person-badge'];
        }
        if ($ctx['can']['pojazdy']['view'] ?? false) {
            $items[] = ['href' => '/pojazdy', 'label' => 'Pojazdy', 'icon' => 'bi-truck'];
        }
        if ($ctx['can']['kierowcy']['view'] ?? false) {
            $items[] = ['href' => '/kierowcy', 'label' => 'Kierowcy', 'icon' => 'bi-people'];
        }
        if ($ctx['can']['tankowania']['view'] ?? false) {
            $items[] = ['href' => '/paliwo', 'label' => 'Paliwo', 'icon' => 'bi-fuel-pump'];
        }
        if ($ctx['viewer']) {
            $items[] = ['href' => '/terminy', 'label' => 'Terminy', 'icon' => 'bi-alarm'];
        }

        return $items;
    }
}
