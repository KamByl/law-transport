<?php

namespace App\Controller;

use App\Entity\DashboardPreference;
use App\Entity\User;
use App\Service\ModuleDefinitionLoader;
use App\Service\ModuleInstaller;
use App\Service\SchemaManager;
use App\Service\InstallationSeeder;
use App\Service\PermissionService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class BackendController extends BaseController
{
    /**
     * @var array<string, string>
     */
    private const DASHBOARD_STATS_PERMISSION_MAP = [];

    public function dashboard(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        $definitionLoader = new ModuleDefinitionLoader();
        $definitions = $definitionLoader->getInstallDefinitions();
        $modules = [];
        $dashboardModuleKeys = [];
        $dashboardStatsKeys = [];
        $dashboardModuleDefaults = [];
        $dashboardStatsDefaults = [];
        $listModuleEntries = [];
        $moduleAccess = [];
        $moduleStats = [];
        $moduleStatsAvailability = [];
        $user = $this->authService->user();

        foreach ($definitions as $definition) {
            $key = $definition['key'] ?? null;
            $module = $definition['module'] ?? null;

            if (!is_string($key) || $key === '' || !is_array($module)) {
                continue;
            }

            $permissionActions = $module['list']['permissions'] ?? ($module['permissions'] ?? []);
            $canView = in_array(PermissionService::ACTION_VIEW, $permissionActions, true)
                && $this->permissionService->can($user, $key, PermissionService::ACTION_VIEW);
            $canCreate = in_array(PermissionService::ACTION_CREATE, $permissionActions, true)
                && $this->permissionService->can($user, $key, PermissionService::ACTION_CREATE);
            $canViewStats = $this->canViewDashboardStats($user, $key, $canView);

            if (!$canView && !$canViewStats) {
                continue;
            }

            $modules[$key] = $module;

            if ($canView) {
                $dashboardModuleKeys[] = $key;
                $dashboardModuleDefaults[$key] = $this->resolveDashboardModuleDefault($module, count($dashboardModuleKeys));
            }

            if ($canView) {
                $moduleAccess[$key] = [
                    'canView' => $canView,
                    'canCreate' => $canCreate,
                ];
            }

            $moduleType = strtolower((string) ($module['type'] ?? 'list'));
            if ($moduleType !== 'list') {
                $moduleStatsAvailability[$key] = false;
                continue;
            }
            $statsEnabled = (bool) ($module['list']['stats_enabled'] ?? false);

            $database = $definition['database'] ?? [];
            $primaryKey = $database['primary_key'] ?? 'id';
            $dateField = $this->resolveModuleStatsDateField($module);
            $fields = $module['fields'] ?? [];
            $columns = $module['list']['columns'] ?? array_map(
                static fn(array $field): string => (string) ($field['name'] ?? ''),
                $fields
            );
            $columns = array_values(array_filter($columns, static fn(string $column): bool => $column !== ''));
            $summaryField = $columns[0] ?? $primaryKey;

            $entries = [];
            if ($canView) {
                $entries = $this->fetchModuleEntries($key, [], [
                    'orderBy' => $primaryKey,
                    'direction' => 'desc',
                    'limit' => 3,
                ]);
            }

            $listModuleEntries[$key] = [
                'entries' => $entries,
                'summaryField' => $summaryField,
                'primaryKey' => $primaryKey,
            ];

            if ($statsEnabled && $canViewStats && $dateField && !empty($database['table'])) {
                $dashboardStatsKeys[] = $key;
                $dashboardStatsDefaults[$key] = $this->resolveDashboardStatsDefault($module, count($dashboardStatsKeys));
                $moduleStatsAvailability[$key] = true;
                $moduleStats[$key] = array_merge(
                    ['label' => $module['label'] ?? $key],
                    $this->buildModuleStats($key, (string) $database['table'], $dateField)
                );
            } else {
                $moduleStatsAvailability[$key] = false;
            }
        }

        $dashboardPreferences = $this->resolveDashboardPreferences(
            $dashboardModuleKeys,
            $dashboardStatsKeys,
            $moduleStatsAvailability,
            $user,
            $dashboardModuleDefaults,
            $dashboardStatsDefaults
        );
        $dashboardLayoutItems = $this->buildDashboardLayoutItems(
            $dashboardPreferences,
            $moduleStatsAvailability
        );

        return $this->render('backend/dashboard.html.twig', [
            'user' => $user,
            'modules' => $modules,
            'listModuleEntries' => $listModuleEntries,
            'moduleAccess' => $moduleAccess,
            'moduleStats' => $moduleStats,
            'dashboardPreferences' => $dashboardPreferences,
            'dashboardLayoutItems' => $dashboardLayoutItems,
            'dashboardStatsAvailability' => $moduleStatsAvailability,
        ]);
    }

    public function dashboardPreferences(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        $definitionLoader = new ModuleDefinitionLoader();
        $definitions = $definitionLoader->getInstallDefinitions();
        $modules = [];
        $dashboardModuleKeys = [];
        $dashboardStatsKeys = [];
        $dashboardModuleDefaults = [];
        $dashboardStatsDefaults = [];
        $moduleStatsAvailability = [];
        $user = $this->authService->user();

        foreach ($definitions as $definition) {
            $key = $definition['key'] ?? null;
            $module = $definition['module'] ?? null;

            if (!is_string($key) || $key === '' || !is_array($module)) {
                continue;
            }

            $permissionActions = $module['list']['permissions'] ?? ($module['permissions'] ?? []);
            $canView = in_array(PermissionService::ACTION_VIEW, $permissionActions, true)
                && $this->permissionService->can($user, $key, PermissionService::ACTION_VIEW);
            $canViewStats = $this->canViewDashboardStats($user, $key, $canView);

            if (!$canView && !$canViewStats) {
                continue;
            }

            $modules[$key] = $module;
            if ($canView) {
                $dashboardModuleKeys[] = $key;
                $dashboardModuleDefaults[$key] = $this->resolveDashboardModuleDefault($module, count($dashboardModuleKeys));
            }

            $moduleType = strtolower((string) ($module['type'] ?? 'list'));
            if ($moduleType !== 'list') {
                $moduleStatsAvailability[$key] = false;
                continue;
            }
            $statsEnabled = (bool) ($module['list']['stats_enabled'] ?? false);

            $database = $definition['database'] ?? [];
            $dateField = $this->resolveModuleStatsDateField($module);
            $moduleStatsAvailability[$key] = $statsEnabled && $canViewStats && $dateField && !empty($database['table']);
            if ($moduleStatsAvailability[$key]) {
                $dashboardStatsKeys[] = $key;
                $dashboardStatsDefaults[$key] = $this->resolveDashboardStatsDefault($module, count($dashboardStatsKeys));
            }
        }

        $dashboardPreferences = $this->resolveDashboardPreferences(
            $dashboardModuleKeys,
            $dashboardStatsKeys,
            $moduleStatsAvailability,
            $user,
            $dashboardModuleDefaults,
            $dashboardStatsDefaults
        );

        $dashboardLayoutItems = $this->buildDashboardLayoutItems(
            $dashboardPreferences,
            $moduleStatsAvailability,
            true
        );

        return $this->render('backend/dashboard-preferences.html.twig', [
            'user' => $user,
            'modules' => $modules,
            'dashboardPreferences' => $dashboardPreferences,
            'dashboardStatsAvailability' => $moduleStatsAvailability,
            'dashboardLayoutItems' => $dashboardLayoutItems,
            'menuPreferences' => $this->resolveMenuPreferences($dashboardModuleKeys, $user),
        ]);
    }

    public function updateDashboardPreferences(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        $definitionLoader = new ModuleDefinitionLoader();
        $definitions = $definitionLoader->getInstallDefinitions();
        $moduleKeys = [];
        $statsKeys = [];
        $moduleDefaults = [];
        $statsDefaults = [];
        $user = $this->authService->user();

        foreach ($definitions as $definition) {
            $key = $definition['key'] ?? null;
            $module = $definition['module'] ?? null;

            if (!is_string($key) || $key === '' || !is_array($module)) {
                continue;
            }

            $permissionActions = $module['list']['permissions'] ?? ($module['permissions'] ?? []);
            $canView = in_array(PermissionService::ACTION_VIEW, $permissionActions, true)
                && $this->permissionService->can($user, $key, PermissionService::ACTION_VIEW);
            $canViewStats = $this->canViewDashboardStats($user, $key, $canView);

            if (!$canView && !$canViewStats) {
                continue;
            }

            if ($canView) {
                $moduleKeys[] = $key;
                $moduleDefaults[$key] = $this->resolveDashboardModuleDefault($module, count($moduleKeys));
            }

            $moduleType = strtolower((string) ($module['type'] ?? 'list'));
            $statsEnabled = (bool) ($module['list']['stats_enabled'] ?? false);
            $database = $definition['database'] ?? [];
            $dateField = $this->resolveModuleStatsDateField($module);
            if ($moduleType === 'list' && $statsEnabled && $canViewStats && $dateField && !empty($database['table'])) {
                $statsKeys[] = $key;
                $statsDefaults[$key] = $this->resolveDashboardStatsDefault($module, count($statsKeys));
            }
        }

        $moduleSettings = $request->request->all('module_settings');
        $statsSettings = $request->request->all('stats_settings');
        $existingPreferences = $this->fetchDashboardPreferences($user);
        $preferences = [
            'modules' => [],
            'stats' => [],
            'menu' => is_array($existingPreferences['menu'] ?? null) ? $existingPreferences['menu'] : [],
        ];
        $modulePosition = 1;
        $statsPosition = 1;

        foreach ($moduleKeys as $key) {
            $settings = $moduleSettings[$key] ?? [];
            $defaultOrder = $moduleDefaults[$key]['order'] ?? $modulePosition;
            $orderRaw = $settings['order'] ?? $defaultOrder;
            $order = filter_var($orderRaw, FILTER_VALIDATE_INT, [
                'options' => [
                    'default' => $defaultOrder,
                    'min_range' => 1,
                ],
            ]);
            $defaultEnabled = $moduleDefaults[$key]['enabled'] ?? true;

            $preferences['modules'][$key] = [
                'enabled' => array_key_exists('enabled', $settings)
                    ? true
                    : (!$defaultEnabled ? false : !array_key_exists($key, $moduleSettings)),
                'order' => $order,
            ];
            $modulePosition++;
        }

        foreach ($statsKeys as $key) {
            $statsSettingsForKey = $statsSettings[$key] ?? [];
            $defaultStatsOrder = $statsDefaults[$key]['order'] ?? $statsPosition;
            $statsOrderRaw = $statsSettingsForKey['order'] ?? $defaultStatsOrder;
            $statsOrder = filter_var($statsOrderRaw, FILTER_VALIDATE_INT, [
                'options' => [
                    'default' => $defaultStatsOrder,
                    'min_range' => 1,
                ],
            ]);
            $defaultStatsEnabled = $statsDefaults[$key]['enabled'] ?? false;
            $preferences['stats'][$key] = [
                'enabled' => array_key_exists('enabled', $statsSettingsForKey)
                    ? true
                    : (!$defaultStatsEnabled ? false : !array_key_exists($key, $statsSettings)),
                'order' => $statsOrder,
            ];

            $statsPosition++;
        }


        $preferenceEntity = $this->entityManager->getRepository(DashboardPreference::class)
            ->findOneBy(['user' => $user]);

        if (!$preferenceEntity) {
            $preferenceEntity = new DashboardPreference();
            $preferenceEntity->setUser($user);
            $this->entityManager->persist($preferenceEntity);
        }

        $preferenceEntity->setPreferences($preferences);
        $this->entityManager->flush();

        return new RedirectResponse('/cms/dashboard/preferences');
    }

    public function menuPreferences(): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        $definitionLoader = new ModuleDefinitionLoader();
        $definitions = $definitionLoader->getInstallDefinitions();
        $modules = [];
        $moduleKeys = [];
        $user = $this->authService->user();

        foreach ($definitions as $definition) {
            $key = $definition['key'] ?? null;
            $module = $definition['module'] ?? null;

            if (!is_string($key) || $key === '' || !is_array($module)) {
                continue;
            }

            $permissionActions = $module['list']['permissions'] ?? ($module['permissions'] ?? []);
            $canView = in_array(PermissionService::ACTION_VIEW, $permissionActions, true)
                && $this->permissionService->can($user, $key, PermissionService::ACTION_VIEW);

            if (!$canView) {
                continue;
            }

            $modules[$key] = $module;
            $moduleKeys[] = $key;
        }

        $menuPreferences = $this->resolveMenuPreferences($moduleKeys, $user);

        uasort($modules, static function (array $left, array $right) use ($menuPreferences): int {
            $leftKey = (string) ($left['key'] ?? '');
            $rightKey = (string) ($right['key'] ?? '');

            $leftPref = is_array($menuPreferences[$leftKey] ?? null) ? $menuPreferences[$leftKey] : [];
            $rightPref = is_array($menuPreferences[$rightKey] ?? null) ? $menuPreferences[$rightKey] : [];

            $leftOrder = max(1, (int) ($leftPref['order'] ?? ($left['menu']['position'] ?? 999)));
            $rightOrder = max(1, (int) ($rightPref['order'] ?? ($right['menu']['position'] ?? 999)));

            if ($leftOrder === $rightOrder) {
                return strcmp((string) ($left['label'] ?? $leftKey), (string) ($right['label'] ?? $rightKey));
            }

            return $leftOrder <=> $rightOrder;
        });

        $storedPreferences = $this->fetchDashboardPreferences($user);
        $groupLabels = [];
        $storedGroupOrder = is_array($storedPreferences['menu_groups'] ?? null) ? $storedPreferences['menu_groups'] : [];

        foreach ($storedGroupOrder as $storedGroupLabel) {
            $label = trim((string) $storedGroupLabel);
            if ($label === '' || in_array($label, $groupLabels, true)) {
                continue;
            }
            $groupLabels[] = $label;
        }

        foreach (($this->config['menu']['module_groups'] ?? []) as $groupConfig) {
            if (!is_array($groupConfig)) {
                continue;
            }
            $label = trim((string) ($groupConfig['label'] ?? ''));
            if ($label !== '' && !in_array($label, $groupLabels, true)) {
                $groupLabels[] = $label;
            }
        }

        foreach ($menuPreferences as $preference) {
            $group = trim((string) ($preference['group'] ?? ''));
            if ($group !== '' && !in_array($group, $groupLabels, true)) {
                $groupLabels[] = $group;
            }
        }

        return $this->render('backend/menu-preferences.html.twig', [
            'user' => $user,
            'modules' => $modules,
            'menuPreferences' => $menuPreferences,
            'menuGroupLabels' => $groupLabels,
        ]);
    }

    public function updateMenuPreferences(Request $request): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        $definitionLoader = new ModuleDefinitionLoader();
        $definitions = $definitionLoader->getInstallDefinitions();
        $moduleKeys = [];
        $user = $this->authService->user();

        foreach ($definitions as $definition) {
            $key = $definition['key'] ?? null;
            $module = $definition['module'] ?? null;

            if (!is_string($key) || $key === '' || !is_array($module)) {
                continue;
            }

            $permissionActions = $module['list']['permissions'] ?? ($module['permissions'] ?? []);
            $canView = in_array(PermissionService::ACTION_VIEW, $permissionActions, true)
                && $this->permissionService->can($user, $key, PermissionService::ACTION_VIEW);

            if (!$canView) {
                continue;
            }

            $moduleKeys[] = $key;
        }

        $menuSettings = $request->request->all('menu_settings');
        $existingPreferences = $this->fetchDashboardPreferences($user);
        $menuGroupsRaw = $request->request->all('menu_groups');
        $menuGroups = [];
        if (is_array($menuGroupsRaw)) {
            foreach ($menuGroupsRaw as $menuGroupLabel) {
                $label = trim((string) $menuGroupLabel);
                if ($label === '' || in_array($label, $menuGroups, true)) {
                    continue;
                }
                $menuGroups[] = $label;
            }
        }

        $preferences = [
            'modules' => is_array($existingPreferences['modules'] ?? null) ? $existingPreferences['modules'] : [],
            'stats' => is_array($existingPreferences['stats'] ?? null) ? $existingPreferences['stats'] : [],
            'menu' => [],
            'menu_groups' => $menuGroups,
        ];

        $position = 1;
        foreach ($moduleKeys as $key) {
            $settings = $menuSettings[$key] ?? [];
            $orderRaw = $settings['order'] ?? $position;
            $order = filter_var($orderRaw, FILTER_VALIDATE_INT, [
                'options' => [
                    'default' => $position,
                    'min_range' => 1,
                ],
            ]);

            $preferences['menu'][$key] = [
                'enabled' => array_key_exists('enabled', $settings),
                'order' => $order,
                'group' => trim((string) ($settings['group'] ?? '')),
            ];
            $position++;
        }

        $preferenceEntity = $this->entityManager->getRepository(DashboardPreference::class)
            ->findOneBy(['user' => $user]);

        if (!$preferenceEntity) {
            $preferenceEntity = new DashboardPreference();
            $preferenceEntity->setUser($user);
            $this->entityManager->persist($preferenceEntity);
        }

        $preferenceEntity->setPreferences($preferences);
        $this->entityManager->flush();

        return new RedirectResponse('/cms/menu/preferences');
    }

    public function resetMenuPreferences(): Response
    {
        if (!$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }

        $user = $this->authService->user();
        $preferenceEntity = $this->entityManager->getRepository(DashboardPreference::class)
            ->findOneBy(['user' => $user]);

        if ($preferenceEntity) {
            $preferences = $preferenceEntity->getPreferences();
            if (is_array($preferences)) {
                unset($preferences['menu'], $preferences['menu_groups']);
                $preferenceEntity->setPreferences($preferences);
                $this->entityManager->flush();
            }
        }

        return new RedirectResponse('/cms/menu/preferences');
    }

    public function install(Request $request): Response
    {
        if (!($this->config['schema']['allow_auto_update'] ?? false)) {
            return new Response('Auto update is disabled.', Response::HTTP_FORBIDDEN);
        }

        $schemaManager = new SchemaManager($this->entityManager);
        $hasUsersTable = $schemaManager->hasTable('users');
        $isInitialInstallation = !$hasUsersTable;
        $executedSql = [];
        $errors = [];
        $adminEmail = trim((string) $request->request->get('admin_email', ''));

        if ($hasUsersTable) {
            $executedSql = $schemaManager->updateSchema();
        }

        if ($hasUsersTable && !$this->authService->requireAuth()) {
            return new RedirectResponse('/cms/login');
        }
        if ($hasUsersTable) {
            $denyResponse = $this->denyUnlessPermission('install_db', PermissionService::ACTION_VIEW);
            if ($denyResponse) {
                return $denyResponse;
            }
        }

        $adminUser = null;
        if ($isInitialInstallation) {
            if (!$request->isMethod('POST')) {
                return $this->render('backend/install_public.html.twig', [
                    'user' => null,
                    'executedSql' => [],
                    'errors' => [],
                    'initialInstallation' => true,
                    'adminEmail' => '',
                    'installationCompleted' => false,
                ]);
            }

            $adminPassword = (string) $request->request->get('admin_password', '');
            $adminPasswordConfirmation = (string) $request->request->get('admin_password_confirmation', '');

            if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Podaj poprawny adres e-mail głównego użytkownika.';
            }
            if ($adminPassword === '' || mb_strlen($adminPassword) < 12) {
                $errors[] = 'Hasło głównego użytkownika musi mieć co najmniej 12 znaków.';
            }
            if ($adminPassword !== $adminPasswordConfirmation) {
                $errors[] = 'Hasła głównego użytkownika muszą być identyczne.';
            }

            if ($errors !== []) {
                return $this->render('backend/install_public.html.twig', [
                    'user' => null,
                    'executedSql' => [],
                    'errors' => $errors,
                    'initialInstallation' => true,
                    'adminEmail' => $adminEmail,
                    'installationCompleted' => false,
                ]);
            }

            $adminUser = [
                'email' => $adminEmail,
                'password' => $adminPassword,
            ];
            $executedSql = $schemaManager->updateSchema();
        }

        $definitionLoader = new ModuleDefinitionLoader();
        $moduleInstaller = new ModuleInstaller($this->entityManager, $definitionLoader);
        try {
            $executedSql = array_merge($executedSql, $moduleInstaller->install());
            $seeder = new InstallationSeeder($this->entityManager, $definitionLoader);
            $seeder->seed($adminUser);
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();

            return $this->render($isInitialInstallation ? 'backend/install_public.html.twig' : 'backend/install.html.twig', [
                'user' => $hasUsersTable ? $this->authService->user() : null,
                'executedSql' => $executedSql,
                'errors' => $errors,
                'initialInstallation' => $isInitialInstallation,
                'adminEmail' => $adminEmail,
                'installationCompleted' => false,
            ]);
        }

        return $this->render('backend/install.html.twig', [
            'user' => $hasUsersTable ? $this->authService->user() : null,
            'executedSql' => $executedSql,
            'errors' => [],
            'initialInstallation' => $isInitialInstallation,
            'adminEmail' => $adminEmail,
            'installationCompleted' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $module
     */
    private function resolveModuleStatsDateField(array $module): ?string
    {
        $configuredField = $module['list']['stats_date_field'] ?? null;
        if (is_string($configuredField) && $configuredField !== '') {
            return $configuredField;
        }

        $fields = $module['fields'] ?? [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $type = strtolower((string) ($field['type'] ?? ''));
            if (!in_array($type, ['datetime', 'date'], true)) {
                continue;
            }
            $name = $field['name'] ?? null;
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $moduleKeys
     * @param array<int, string> $statsKeys
     * @param array<string, bool> $statsAvailability
     * @param array<string, array{enabled: bool, order: int}> $moduleDefaults
     * @param array<string, array{enabled: bool, order: int}> $statsDefaults
     *
     * @return array{modules: array<string, array{enabled: bool, order: int}>, stats: array<string, array{enabled: bool, order: int}>}
     */
    private function resolveDashboardPreferences(
        array $moduleKeys,
        array $statsKeys,
        array $statsAvailability,
        User $user,
        array $moduleDefaults = [],
        array $statsDefaults = []
    ): array {
        $storedPreferences = $this->fetchDashboardPreferences($user);
        $storedModules = $storedPreferences['modules'] ?? [];
        $storedStats = $storedPreferences['stats'] ?? [];
        $existingPreferences = $this->fetchDashboardPreferences($user);
        $preferences = [
            'modules' => [],
            'stats' => [],
            'menu' => is_array($existingPreferences['menu'] ?? null) ? $existingPreferences['menu'] : [],
        ];
        $modulePosition = 1;
        $statsPosition = 1;

        foreach ($moduleKeys as $key) {
            $stored = $storedModules[$key] ?? [];
            $default = $moduleDefaults[$key] ?? ['enabled' => true, 'order' => $modulePosition];
            $enabled = $stored['enabled'] ?? ($default['enabled'] ?? true);
            $order = $stored['order'] ?? ($default['order'] ?? $modulePosition);

            $preferences['modules'][$key] = [
                'enabled' => filter_var($enabled, FILTER_VALIDATE_BOOLEAN),
                'order' => max(1, (int) $order),
            ];
            $modulePosition++;
        }

        foreach ($statsKeys as $key) {
            $stored = $storedModules[$key] ?? [];
            $storedStatsEntry = $storedStats[$key] ?? [];
            $legacyShowStats = $stored['showStats'] ?? null;
            $defaultStats = $statsDefaults[$key] ?? ['enabled' => ($statsAvailability[$key] ?? false), 'order' => $statsPosition];
            $statsEnabled = $storedStatsEntry['enabled']
                ?? $legacyShowStats
                ?? ($defaultStats['enabled'] ?? ($statsAvailability[$key] ?? false));
            $statsOrder = $storedStatsEntry['order'] ?? $stored['order'] ?? ($defaultStats['order'] ?? $statsPosition);
            $preferences['stats'][$key] = [
                'enabled' => ($statsAvailability[$key] ?? false)
                    && filter_var($statsEnabled, FILTER_VALIDATE_BOOLEAN),
                'order' => max(1, (int) $statsOrder),
            ];

            $statsPosition++;
        }

        return $preferences;
    }

    /**
     * @param array<string, mixed> $module
     *
     * @return array{enabled: bool, order: int}
     */
    private function resolveDashboardModuleDefault(array $module, int $fallbackOrder): array
    {
        $dashboard = is_array($module['dashboard'] ?? null) ? $module['dashboard'] : [];
        $menu = is_array($module['menu'] ?? null) ? $module['menu'] : [];
        $enabled = filter_var($dashboard['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $order = max(1, (int) ($dashboard['order'] ?? $menu['position'] ?? $fallbackOrder));

        return [
            'enabled' => $enabled,
            'order' => $order,
        ];
    }

    /**
     * @param array<string, mixed> $module
     *
     * @return array{enabled: bool, order: int}
     */
    private function resolveDashboardStatsDefault(array $module, int $fallbackOrder): array
    {
        $dashboard = is_array($module['dashboard'] ?? null) ? $module['dashboard'] : [];
        $stats = is_array($dashboard['stats'] ?? null) ? $dashboard['stats'] : [];
        $enabled = filter_var($stats['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $order = max(1, (int) ($stats['order'] ?? ($dashboard['order'] ?? $fallbackOrder)));

        return [
            'enabled' => $enabled,
            'order' => $order,
        ];
    }

    private function canViewDashboardStats(User $user, string $moduleKey, bool $canViewModule): bool
    {
        if ($canViewModule) {
            return true;
        }

        $permissionCode = self::DASHBOARD_STATS_PERMISSION_MAP[$moduleKey] ?? $moduleKey;

        return $this->permissionService->can($user, $permissionCode, PermissionService::ACTION_STATS);
    }

    /**
     * @param array<string, array{enabled: bool, order: int}> $preferences
     *
     * @return array<int, string>
     */
    private function sortDashboardModules(array $preferences): array
    {
        $keys = array_keys($preferences);
        usort($keys, static function (string $left, string $right) use ($preferences): int {
            return ($preferences[$left]['order'] ?? 0) <=> ($preferences[$right]['order'] ?? 0);
        });

        return array_values(array_filter($keys, static function (string $key) use ($preferences): bool {
            return $preferences[$key]['enabled'] ?? false;
        }));
    }

    /**
     * @param array<string, array{enabled: bool, order: int}> $preferences
     *
     * @return array<int, string>
     */
    private function sortDashboardStats(array $preferences): array
    {
        $keys = array_keys($preferences);
        usort($keys, static function (string $left, string $right) use ($preferences): int {
            return ($preferences[$left]['order'] ?? 0) <=> ($preferences[$right]['order'] ?? 0);
        });

        return array_values(array_filter($keys, static function (string $key) use ($preferences): bool {
            return $preferences[$key]['enabled'] ?? false;
        }));
    }

    /**
     * @param array<string, array{enabled: bool, order: int}> $preferences
     *
     * @return array<int, string>
     */
    private function sortDashboardKeysByOrder(array $preferences): array
    {
        $keys = array_keys($preferences);
        usort($keys, static function (string $left, string $right) use ($preferences): int {
            return ($preferences[$left]['order'] ?? 0) <=> ($preferences[$right]['order'] ?? 0);
        });

        return array_values($keys);
    }

    /**
     * @param array{modules: array<string, array{enabled: bool, order: int}>, stats: array<string, array{enabled: bool, order: int}>} $preferences
     * @param array<string, bool> $statsAvailability
     *
     * @return array<int, array{type: string, key: string, order: int}>
     */
    private function buildDashboardLayoutItems(
        array $preferences,
        array $statsAvailability,
        bool $includeDisabled = false
    ): array {
        $items = [];

        foreach ($preferences['modules'] as $key => $modulePreference) {
            if (!$includeDisabled && !($modulePreference['enabled'] ?? false)) {
                continue;
            }

            $items[] = [
                'type' => 'module',
                'key' => $key,
                'order' => $modulePreference['order'] ?? 0,
            ];
        }

        foreach ($preferences['stats'] as $key => $statsPreference) {
            if (!($statsAvailability[$key] ?? false)) {
                continue;
            }
            if (!$includeDisabled && !($statsPreference['enabled'] ?? false)) {
                continue;
            }

            $items[] = [
                'type' => 'stats',
                'key' => $key,
                'order' => $statsPreference['order'] ?? 0,
            ];
        }

        usort($items, static function (array $left, array $right): int {
            $orderComparison = ($left['order'] ?? 0) <=> ($right['order'] ?? 0);
            if ($orderComparison !== 0) {
                return $orderComparison;
            }

            $typeComparison = strcmp((string) ($left['type'] ?? ''), (string) ($right['type'] ?? ''));
            if ($typeComparison !== 0) {
                return $typeComparison;
            }

            return strcmp((string) ($left['key'] ?? ''), (string) ($right['key'] ?? ''));
        });

        return array_values($items);
    }

    /**
     * @param array<int, string> $moduleKeys
     * @return array<string, array{enabled: bool, order: int, group: string}>
     */
    private function resolveMenuPreferences(array $moduleKeys, User $user): array
    {
        $stored = $this->fetchDashboardPreferences($user);
        $storedMenu = is_array($stored['menu'] ?? null) ? $stored['menu'] : [];
        $defaultGroups = $this->resolveDefaultModuleGroups();
        $result = [];
        $position = 1;
        foreach ($moduleKeys as $key) {
            $entry = is_array($storedMenu[$key] ?? null) ? $storedMenu[$key] : [];
            $defaultGroup = trim((string) ($defaultGroups[$key] ?? ''));
            $result[$key] = [
                'enabled' => array_key_exists('enabled', $entry) ? (bool) $entry['enabled'] : true,
                'order' => max(1, (int) ($entry['order'] ?? $position)),
                'group' => trim((string) ($entry['group'] ?? $defaultGroup)),
            ];
            $position++;
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function resolveDefaultModuleGroups(): array
    {
        $groups = [];
        $groupsConfig = $this->config['menu']['module_groups'] ?? [];

        if (!is_array($groupsConfig)) {
            return $groups;
        }

        foreach ($groupsConfig as $groupKey => $groupConfig) {
            if (!is_array($groupConfig)) {
                continue;
            }

            $label = trim((string) ($groupConfig['label'] ?? $groupKey));
            if ($label === '') {
                continue;
            }

            $moduleKeys = $groupConfig['modules'] ?? [];
            if (!is_array($moduleKeys)) {
                continue;
            }

            foreach ($moduleKeys as $moduleKey) {
                if (!is_string($moduleKey) || $moduleKey === '' || isset($groups[$moduleKey])) {
                    continue;
                }

                $groups[$moduleKey] = $label;
            }
        }

        return $groups;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDashboardPreferences(User $user): array
    {

        $preferenceEntity = $this->entityManager->getRepository(DashboardPreference::class)
            ->findOneBy(['user' => $user]);

        if (!$preferenceEntity) {
            return [];
        }

        return $preferenceEntity->getPreferences();
    }

    /**
     * @return array<string, array{labels: array<int, string>, values: array<int, int>}>
     */
    private function buildModuleStats(string $moduleKey, string $table, string $dateField): array
    {
        $connection = $this->entityManager->getConnection();
        $dateExpression = $this->buildStatsDateExpression($connection, $moduleKey, $table, $dateField);
        $today = new \DateTimeImmutable('today');
        $firstDataAt = $this->fetchFirstDataDate($connection, $table, $dateExpression);

        if ($firstDataAt === null) {
            return [
                'day' => $this->buildPeriodSeriesPayload([]),
                'month' => $this->buildPeriodSeriesPayload([]),
                'year' => $this->buildPeriodSeriesPayload([]),
            ];
        }

        $defaultStatsStart = $firstDataAt;
        $lotteryStatsStart = $this->resolveLotterySubmissionStatsStart($connection, $moduleKey, $today);
        if ($lotteryStatsStart !== null) {
            $defaultStatsStart = $lotteryStatsStart;
        }

        $dayStart = $this->maxDate(
            $defaultStatsStart->setTime(0, 0),
            $today->modify('-365 days')->setTime(0, 0)
        );
        $monthStart = $this->maxDate(
            $defaultStatsStart->modify('first day of this month')->setTime(0, 0),
            $today->modify('-60 months')->modify('first day of this month')->setTime(0, 0)
        );
        $yearStart = $this->maxDate(
            $defaultStatsStart->setDate((int) $defaultStatsStart->format('Y'), 1, 1)->setTime(0, 0),
            $today->modify('-20 years')->setDate((int) $today->modify('-20 years')->format('Y'), 1, 1)->setTime(0, 0)
        );

        $seriesDefinitions = [
            [
                'key' => 'all',
                'label' => 'Wszystkie wpisy',
                'filters' => [],
            ],
            ...$this->buildModuleStatsSeriesDefinitions($connection, $moduleKey, $table),
        ];

        $daySeries = [];
        $monthSeries = [];
        $yearSeries = [];

        foreach ($seriesDefinitions as $seriesDefinition) {
            $filters = $seriesDefinition['filters'];

            $dayCounts = $this->fetchPeriodCounts($connection, $table, $dateExpression, 'day', $dayStart, $filters);
            $monthCounts = $this->fetchPeriodCounts($connection, $table, $dateExpression, 'month', $monthStart, $filters);
            $yearCounts = $this->fetchPeriodCounts($connection, $table, $dateExpression, 'year', $yearStart, $filters);

            $daySeries[] = [
                'key' => $seriesDefinition['key'],
                'label' => $seriesDefinition['label'],
                'data' => $this->buildDaySeries($dayStart, $today, $dayCounts),
            ];
            $monthSeries[] = [
                'key' => $seriesDefinition['key'],
                'label' => $seriesDefinition['label'],
                'data' => $this->buildMonthSeries($monthStart, $today->modify('first day of this month'), $monthCounts),
            ];
            $yearSeries[] = [
                'key' => $seriesDefinition['key'],
                'label' => $seriesDefinition['label'],
                'data' => $this->buildYearSeries($yearStart, $today->setDate((int) $today->format('Y'), 1, 1), $yearCounts),
            ];
        }

        return [
            'day' => $this->buildPeriodSeriesPayload($daySeries),
            'month' => $this->buildPeriodSeriesPayload($monthSeries),
            'year' => $this->buildPeriodSeriesPayload($yearSeries),
        ];
    }


    private function resolveLotterySubmissionStatsStart(
        \Doctrine\DBAL\Connection $connection,
        string $moduleKey,
        \DateTimeImmutable $today
    ): ?\DateTimeImmutable {
        if ($moduleKey !== 'loteria_zgloszenia') {
            return null;
        }

        $settings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];
        $candidateStart = $settings['loteria_data_startu'] ?? null;
        if (!is_scalar($candidateStart) || trim((string) $candidateStart) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable((string) $candidateStart);
        } catch (\Throwable) {
            return null;
        }
    }

    private function maxDate(\DateTimeImmutable $left, \DateTimeImmutable $right): \DateTimeImmutable
    {
        return $left >= $right ? $left : $right;
    }

    private function buildStatsDateExpression(
        \Doctrine\DBAL\Connection $connection,
        string $moduleKey,
        string $table,
        string $dateField
    ): string {
        if ($table !== 'loteria_zwyciezcy_entries' || $dateField !== 'data_wyslania_zgloszenia') {
            return $dateField;
        }

        $submissionDateExpression = sprintf(
            "(SELECT NULLIF(TRIM(CAST(loteria_zgloszenia_entries.submitted_at AS CHAR)), '') FROM loteria_zgloszenia_entries WHERE loteria_zgloszenia_entries.id = %s.zgloszenie LIMIT 1)",
            $table
        );

        try {
            $tableDetails = $connection->createSchemaManager()->introspectTable($table);
        } catch (\Throwable) {
            return $submissionDateExpression;
        }

        if (!$tableDetails->hasColumn('zgloszenie')) {
            return $submissionDateExpression;
        }

        $dateExpressions = [$submissionDateExpression];

        if ($tableDetails->hasColumn('data_zgloszenia')) {
            $dateExpressions[] = "NULLIF(TRIM(CAST(data_zgloszenia AS CHAR)), '')";
        }

        return count($dateExpressions) === 1
            ? $dateExpressions[0]
            : sprintf('COALESCE(%s)', implode(', ', $dateExpressions));
    }

    private function fetchFirstDataDate(\Doctrine\DBAL\Connection $connection, string $table, string $dateExpression): ?\DateTimeImmutable
    {
        $queryBuilder = $connection->createQueryBuilder();
        $value = $queryBuilder
            ->select(sprintf('MIN(%s)', $dateExpression))
            ->from($table)
            ->where($queryBuilder->expr()->isNotNull($dateExpression))
            ->executeQuery()
            ->fetchOne();

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, array{key: string, label: string, filters: array<string, string>}>
     */
    private function buildModuleStatsSeriesDefinitions(\Doctrine\DBAL\Connection $connection, string $moduleKey, string $table): array
    {
        if ($table === 'loteria_zwyciezcy_entries') {
            return $this->buildPrizeTypeSeriesDefinitions($connection, $table);
        }

        return $this->buildCenterSeriesDefinitions($connection, $table);
    }

    /**
     * @return array<int, array{key: string, label: string, filters: array<string, string>}>
     */
    private function buildPrizeTypeSeriesDefinitions(\Doctrine\DBAL\Connection $connection, string $table): array
    {
        try {
            $tableDetails = $connection->createSchemaManager()->introspectTable($table);
        } catch (\Throwable) {
            return [];
        }

        if (!$tableDetails->hasColumn('rodzaj_wygranej')) {
            return [];
        }

        $usedPrizeTypeIds = $this->fetchUsedPrizeTypeIds($connection, $table);
        if ($usedPrizeTypeIds === []) {
            return [];
        }

        $prizeTypeMap = $this->fetchPrizeTypeLabels($connection, $usedPrizeTypeIds);
        $seriesDefinitions = [];
        foreach ($usedPrizeTypeIds as $prizeTypeId) {
            $seriesDefinitions[] = [
                'key' => 'prize_' . preg_replace('/[^a-zA-Z0-9_\-]+/', '_', (string) $prizeTypeId),
                'label' => $prizeTypeMap[$prizeTypeId] ?? ('Nagroda ' . $prizeTypeId),
                'filters' => ['rodzaj_wygranej' => (string) $prizeTypeId],
            ];
        }

        return $seriesDefinitions;
    }

    /**
     * @return array<int, string>
     */
    private function fetchUsedPrizeTypeIds(\Doctrine\DBAL\Connection $connection, string $table): array
    {
        try {
            $rows = $connection->createQueryBuilder()
                ->select('DISTINCT rodzaj_wygranej AS prize_type_id')
                ->from($table)
                ->where('rodzaj_wygranej IS NOT NULL')
                ->andWhere("TRIM(CAST(rodzaj_wygranej AS CHAR)) <> ''")
                ->orderBy('rodzaj_wygranej', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Throwable) {
            return [];
        }

        $prizeTypeIds = [];
        foreach ($rows as $row) {
            $prizeTypeId = trim((string) ($row['prize_type_id'] ?? ''));
            if ($prizeTypeId === '') {
                continue;
            }

            $prizeTypeIds[] = $prizeTypeId;
        }

        return $prizeTypeIds;
    }

    /**
     * @param array<int, string> $prizeTypeIds
     *
     * @return array<string, string>
     */
    private function fetchPrizeTypeLabels(\Doctrine\DBAL\Connection $connection, array $prizeTypeIds): array
    {
        if ($prizeTypeIds === []) {
            return [];
        }

        try {
            $queryBuilder = $connection->createQueryBuilder();
            $rows = $queryBuilder
                ->select('id', 'name')
                ->from('rodzaje_nagrod_entries')
                ->where($queryBuilder->expr()->in('id', ':prizeTypeIds'))
                ->setParameter('prizeTypeIds', $prizeTypeIds, \Doctrine\DBAL\ArrayParameterType::STRING)
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Throwable) {
            return [];
        }

        $prizeTypeMap = [];
        foreach ($rows as $row) {
            $prizeTypeId = trim((string) ($row['id'] ?? ''));
            if ($prizeTypeId === '') {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $prizeTypeMap[$prizeTypeId] = $name !== '' ? $name : $prizeTypeId;
        }

        return $prizeTypeMap;
    }

    /**
     * @return array<int, array{key: string, label: string, filters: array<string, string>}>
     */
    private function buildCenterSeriesDefinitions(\Doctrine\DBAL\Connection $connection, string $table): array
    {
        try {
            $tableDetails = $connection->createSchemaManager()->introspectTable($table);
        } catch (\Throwable) {
            return [];
        }

        if (!$tableDetails->hasColumn('centrum_handlowe')) {
            return [];
        }

        $centerMap = $this->fetchCenterLabels($connection);
        if ($centerMap === []) {
            return [];
        }

        $seriesDefinitions = [];
        foreach ($centerMap as $centerId => $centerLabel) {
            $seriesDefinitions[] = [
                'key' => 'center_' . preg_replace('/[^a-zA-Z0-9_\-]+/', '_', (string) $centerId),
                'label' => $centerLabel,
                'filters' => ['centrum_handlowe' => (string) $centerId],
            ];
        }

        return $seriesDefinitions;
    }

    /**
     * @return array<string, string>
     */
    private function fetchCenterLabels(\Doctrine\DBAL\Connection $connection): array
    {
        try {
            $rows = $connection->createQueryBuilder()
                ->select('id', 'name', 'city')
                ->from('centra_handlowe_entries')
                ->orderBy('position', 'ASC')
                ->addOrderBy('name', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Throwable) {
            return [];
        }

        $centerMap = [];
        foreach ($rows as $row) {
            $centerId = trim((string) ($row['id'] ?? ''));
            if ($centerId === '') {
                continue;
            }

            $city = trim((string) ($row['city'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $centerMap[$centerId] = $city !== '' ? $city : ($name !== '' ? $name : $centerId);
        }

        return $centerMap;
    }

    /**
     * @return array<string, int>
     */
    private function fetchPeriodCounts(
        \Doctrine\DBAL\Connection $connection,
        string $table,
        string $dateField,
        string $period,
        \DateTimeImmutable $start,
        array $filters = []
    ): array {
        $dateValueExpression = sprintf('TRIM(CAST(%s AS CHAR))', $dateField);
        $dateValueWithTimePattern = "'^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$'";
        $dateValuePattern = "'^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$'";
        $normalizedDateExpression = sprintf(
            'CASE
                WHEN %1$s REGEXP %2$s THEN LEFT(%1$s, 10)
                WHEN %1$s REGEXP %3$s THEN %1$s
                ELSE NULL
            END',
            $dateValueExpression,
            $dateValueWithTimePattern,
            $dateValuePattern
        );

        $periodExpression = match ($period) {
            'month' => sprintf('LEFT(%s, 7)', $normalizedDateExpression),
            'year' => sprintf('LEFT(%s, 4)', $normalizedDateExpression),
            default => $normalizedDateExpression,
        };

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select(sprintf('%s AS period', $periodExpression), 'COUNT(*) AS total')
            ->from($table)
            ->where($queryBuilder->expr()->isNotNull($dateField))
            ->andWhere(sprintf('%s IS NOT NULL', $normalizedDateExpression))
            ->andWhere(sprintf('%s >= :start', $normalizedDateExpression))
            ->setParameter('start', $start->format('Y-m-d'))
            ->groupBy('period')
            ->orderBy('period', 'ASC');

        foreach ($filters as $field => $value) {
            $parameterName = sprintf('filter_%s', $field);
            if (is_array($value)) {
                if ($value === []) {
                    continue;
                }

                $queryBuilder
                    ->andWhere(sprintf('%s IN (:%s)', $field, $parameterName))
                    ->setParameter($parameterName, $value, \Doctrine\DBAL\ArrayParameterType::STRING);

                continue;
            }

            $queryBuilder
                ->andWhere(sprintf('%s = :%s', $field, $parameterName))
                ->setParameter($parameterName, $value);
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
        $counts = [];
        foreach ($rows as $row) {
            $periodKey = (string) ($row['period'] ?? '');
            if ($periodKey === '') {
                continue;
            }
            $counts[$periodKey] = (int) ($row['total'] ?? 0);
        }

        return $counts;
    }

    /**
     * @return array{first_time: array<int, string>, not_first_time: array<int, string>}
     */
    private function resolvePitFirstTimeFilterMap(\Doctrine\DBAL\Connection $connection, string $table): array
    {
        $defaultMap = [
            'first_time' => ['1', 'first_time'],
            'not_first_time' => ['0', 'not_first_time'],
        ];

        try {
            $tableDetails = $connection->createSchemaManager()->introspectTable($table);
        } catch (\Throwable) {
            return $defaultMap;
        }

        if (!$tableDetails->hasColumn('pit_first_time')) {
            return $defaultMap;
        }

        $columnType = $tableDetails->getColumn('pit_first_time')->getType()->getName();
        if (!in_array($columnType, ['boolean', 'smallint', 'integer', 'bigint'], true)) {
            return $defaultMap;
        }

        return [
            'first_time' => ['1'],
            'not_first_time' => ['0'],
        ];
    }

    /**
     * @param array<int, array{key: string, label: string, data: array{labels: array<int, string>, values: array<int, int>}}> $periodSeries
     *
     * @return array{labels: array<int, string>, values: array<int, int>, series: array<int, array{key: string, label: string, values: array<int, int>}>}
     */
    private function buildPeriodSeriesPayload(array $periodSeries): array
    {
        if ($periodSeries === []) {
            return [
                'labels' => [],
                'values' => [],
                'series' => [],
            ];
        }

        $baseData = $periodSeries[0]['data'];
        $series = array_map(static function (array $seriesDefinition): array {
            return [
                'key' => $seriesDefinition['key'],
                'label' => $seriesDefinition['label'],
                'values' => $seriesDefinition['data']['values'],
            ];
        }, $periodSeries);

        return [
            'labels' => $baseData['labels'],
            'values' => $baseData['values'],
            'series' => $series,
        ];
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function buildDaySeries(\DateTimeImmutable $start, \DateTimeImmutable $end, array $counts): array
    {
        $labels = [];
        $values = [];
        $current = $start;

        while ($current <= $end) {
            $label = $current->format('Y-m-d');
            $labels[] = $label;
            $values[] = $counts[$label] ?? 0;
            $current = $current->modify('+1 day');
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function buildMonthSeries(\DateTimeImmutable $start, \DateTimeImmutable $end, array $counts): array
    {
        $labels = [];
        $values = [];
        $current = $start;

        while ($current <= $end) {
            $label = $current->format('Y-m');
            $labels[] = $label;
            $values[] = $counts[$label] ?? 0;
            $current = $current->modify('+1 month');
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function buildYearSeries(\DateTimeImmutable $start, \DateTimeImmutable $end, array $counts): array
    {
        $labels = [];
        $values = [];
        $current = $start;

        while ($current <= $end) {
            $label = $current->format('Y');
            $labels[] = $label;
            $values[] = $counts[$label] ?? 0;
            $current = $current->modify('+1 year');
        }

        return ['labels' => $labels, 'values' => $values];
    }
}
