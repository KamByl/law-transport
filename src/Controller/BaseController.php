<?php

namespace App\Controller;

use App\Entity\Permission;
use App\Entity\DashboardPreference;
use App\Entity\SmsLog;
use App\Debug\DebugCollector;
use App\Entity\User;
use App\Service\AuthService;
use App\Service\CsrfService;
use App\Service\Mailer;
use App\Service\ModuleDefinitionLoader;
use App\Service\PermissionDefinitionProvider;
use App\Service\PermissionService;
use App\Service\SmsApiClient;
use App\Service\SystemSettingsProvider;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

abstract class BaseController
{
    protected Environment $twig;
    protected EntityManagerInterface $entityManager;
    protected AuthService $authService;
    protected PermissionService $permissionService;
    protected Mailer $mailer;
    protected SmsApiClient $smsApiClient;
    protected CsrfService $csrfService;
    protected array $config;
    /**
     * @var string[]
     */
    private const DEFAULT_PUBLIC_FILE_SOURCES = [
        'pliki',
        'settings',
        'system_settings',
    ];

    public function __construct(
        Environment $twig,
        EntityManagerInterface $entityManager,
        AuthService $authService,
        PermissionService $permissionService,
        Mailer $mailer,
        SmsApiClient $smsApiClient,
        CsrfService $csrfService,
        array $config
    ) {
        $this->twig = $twig;
        $this->entityManager = $entityManager;
        $this->authService = $authService;
        $this->permissionService = $permissionService;
        $this->mailer = $mailer;
        $this->smsApiClient = $smsApiClient;
        $this->csrfService = $csrfService;
        $this->config = $config;
    }

    /**
     * @return string[]
     */
    protected function getCurrentPermissionCodes(): array
    {
        return PermissionDefinitionProvider::getCurrentCodes(new ModuleDefinitionLoader());
    }

    /**
     * @return Permission[]
     */
    protected function findCurrentPermissions(): array
    {
        $currentCodes = $this->getCurrentPermissionCodes();

        if ($currentCodes === []) {
            return [];
        }

        return $this->entityManager->getRepository(Permission::class)->findBy(
            ['code' => $currentCodes],
            ['label' => 'asc']
        );
    }

    protected function createInlineFileResponse(string $absolutePath): BinaryFileResponse
    {
        $response = new BinaryFileResponse($absolutePath);
        $mimeType = MimeTypes::getDefault()->guessMimeType($absolutePath);
        if (!is_string($mimeType) || $mimeType === '') {
            $detectedMimeType = @mime_content_type($absolutePath);
            if (is_string($detectedMimeType) && $detectedMimeType !== '') {
                $mimeType = $detectedMimeType;
            }
        }

        if (is_string($mimeType) && $mimeType !== '') {
            $response->headers->set('Content-Type', $mimeType);
        }

        $response->setContentDisposition('inline', basename($absolutePath));

        return $response;
    }

    protected function createNotFoundException(string $message = 'Not Found', ?\Throwable $previous = null): NotFoundHttpException
    {
        return new NotFoundHttpException($message, $previous);
    }

    protected function sendSms(string $to, string $message, ?Request $request = null, ?string $context = null): array
    {
        $result = $this->smsApiClient->send($to, $message);

        $user = $this->authService->user();
        $log = new SmsLog();
        $log->setUser($user);
        $log->setEmail($user?->getEmail() ?? '');
        $log->setRecipient($to);
        $log->setMessage($message);
        $log->setSuccess((bool) ($result['success'] ?? false));
        $error = $result['error'] ?? null;
        $log->setError(is_string($error) && $error !== '' ? $error : null);
        $messageId = $result['message_id'] ?? null;
        $log->setSmsapiId(is_string($messageId) && $messageId !== '' ? $messageId : null);
        $log->setContext($context ?? '');
        $log->setIpAddress($request?->getClientIp());
        $log->setUserAgent($request?->headers->get('User-Agent'));
        $log->setSentAt(new \DateTimeImmutable());

        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return $result;
    }

    private ?bool $permissionsTableExists = null;

    private function permissionsTableExists(): bool
    {
        if ($this->permissionsTableExists !== null) {
            return $this->permissionsTableExists;
        }

        try {
            $schemaManager = $this->entityManager->getConnection()->createSchemaManager();
            $this->permissionsTableExists = $schemaManager->tablesExist(['permissions']);
        } catch (Exception $exception) {
            $this->permissionsTableExists = false;
        }

        return $this->permissionsTableExists;
    }

    /**
     * @var array<string, bool>
     */
    private array $tableExistsCache = [];

    private function tableExists(string $table): bool
    {
        if (isset($this->tableExistsCache[$table])) {
            return $this->tableExistsCache[$table];
        }

        try {
            $schemaManager = $this->entityManager->getConnection()->createSchemaManager();
            $this->tableExistsCache[$table] = $schemaManager->tablesExist([$table]);
        } catch (Exception $exception) {
            $this->tableExistsCache[$table] = false;
        }

        return $this->tableExistsCache[$table];
    }

    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        if (!array_key_exists('permissionService', $data)) {
            $data['permissionService'] = $this->permissionService;
        }

        if (!array_key_exists('modules', $data)) {
            $definitionLoader = new ModuleDefinitionLoader();
            $data['modules'] = $definitionLoader->getModules();
        }

        if (!array_key_exists('menuModules', $data)) {
            $user = $data['user'] ?? $this->authService->user();
            $permissionRepository = null;
            if ($this->permissionsTableExists()) {
                $permissionRepository = $this->entityManager->getRepository(Permission::class);
            }

            $menuModules = [];
            $hiddenModules = $this->config['menu']['hidden_modules'] ?? [];
            $hiddenModules = is_array($hiddenModules) ? array_flip($hiddenModules) : [];

            foreach ($data['modules'] as $key => $module) {
                if (!is_array($module) || empty($module['menu'])) {
                    continue;
                }

                if (isset($hiddenModules[$key])) {
                    continue;
                }

                if ($permissionRepository === null) {
                    $menuModules[$key] = $module;

                    continue;
                }

                $permission = $permissionRepository->findOneBy(['code' => $key]);
                if ($permission && !$this->permissionService->can($user, $key, PermissionService::ACTION_VIEW)) {
                    continue;
                }

                $menuModules[$key] = $module;
            }

            uasort($menuModules, static function (array $left, array $right): int {
                $leftPosition = $left['menu']['position'] ?? 999;
                $rightPosition = $right['menu']['position'] ?? 999;

                return $leftPosition <=> $rightPosition;
            });

            $menuPreferences = [];
            if ($user instanceof User) {
                $preference = $this->entityManager->getRepository(DashboardPreference::class)->findOneBy(['user' => $user]);
                $prefs = $preference?->getPreferences() ?? [];
                $menuPreferences = is_array($prefs['menu'] ?? null) ? $prefs['menu'] : [];

                if ($menuPreferences !== []) {
                    foreach ($menuModules as $key => $module) {
                        $pref = is_array($menuPreferences[$key] ?? null) ? $menuPreferences[$key] : [];
                        if (array_key_exists('enabled', $pref) && !$pref['enabled']) {
                            unset($menuModules[$key]);
                            continue;
                        }
                        $menuModules[$key]['_menu_user_order'] = max(1, (int) ($pref['order'] ?? 999));
                        if (array_key_exists('group', $pref)) {
                            $menuModules[$key]['_menu_has_user_group_preference'] = true;
                            $menuModules[$key]['_menu_user_group'] = trim((string) $pref['group']);
                        } else {
                            $menuModules[$key]['_menu_user_group'] = '';
                        }
                    }

                    uasort($menuModules, static function (array $left, array $right): int {
                        $leftOrder = $left['_menu_user_order'] ?? 999;
                        $rightOrder = $right['_menu_user_order'] ?? 999;
                        $cmp = $leftOrder <=> $rightOrder;
                        if ($cmp !== 0) {
                            return $cmp;
                        }

                        $leftPosition = $left['menu']['position'] ?? 999;
                        $rightPosition = $right['menu']['position'] ?? 999;

                        return $leftPosition <=> $rightPosition;
                    });
                }
            }

            $data['menuModules'] = $menuModules;
        }

        if (!array_key_exists('menuModuleGroups', $data)) {
            $menuModuleGroups = [];
            $menuModules = $data['menuModules'] ?? [];
            $groupsConfig = $this->config['menu']['module_groups'] ?? [];
            $defaultGroupLabel = trim((string) ($this->config['menu']['module_group_default_label'] ?? 'Moduły'));

            if ($menuModules !== []) {
                $dynamicGroups = [];
                foreach ($menuModules as $moduleKey => $module) {
                    $group = trim((string) ($module['_menu_user_group'] ?? ''));
                    if ($group === '') {
                        continue;
                    }
                    $dynamicGroupKey = 'user:' . $group;
                    if (!isset($dynamicGroups[$dynamicGroupKey])) {
                        $dynamicGroups[$dynamicGroupKey] = ['label' => $group, 'modules' => [], '_user_defined' => true];
                    }
                    $dynamicGroups[$dynamicGroupKey]['modules'][] = $moduleKey;
                }

                if ($dynamicGroups !== []) {
                    $groupsConfig = array_merge($dynamicGroups, is_array($groupsConfig) ? $groupsConfig : []);
                }
            }

            if ($user instanceof User) {
                $preference = $this->entityManager->getRepository(DashboardPreference::class)->findOneBy(['user' => $user]);
                $prefs = $preference?->getPreferences() ?? [];
                $menuGroupOrder = is_array($prefs['menu_groups'] ?? null) ? $prefs['menu_groups'] : [];

                if ($menuGroupOrder !== []) {
                    $orderedGroups = [];
                    foreach ($menuGroupOrder as $groupLabel) {
                        $label = trim((string) $groupLabel);
                        if ($label === '') {
                            continue;
                        }

                        foreach ($groupsConfig as $groupKey => $groupConfig) {
                            if (!is_array($groupConfig)) {
                                continue;
                            }
                            $configuredLabel = trim((string) ($groupConfig['label'] ?? $groupKey));
                            if ($configuredLabel === $label && !isset($orderedGroups[$groupKey])) {
                                $orderedGroups[$groupKey] = $groupConfig;
                            }
                        }
                    }

                    foreach ($groupsConfig as $groupKey => $groupConfig) {
                        if (!isset($orderedGroups[$groupKey])) {
                            $orderedGroups[$groupKey] = $groupConfig;
                        }
                    }

                    $groupsConfig = $orderedGroups;
                }
            }

            if (is_array($groupsConfig) && $groupsConfig !== []) {
                $assigned = [];

                foreach ($groupsConfig as $groupKey => $groupConfig) {
                    if (!is_array($groupConfig)) {
                        continue;
                    }

                    $labelSource = $groupConfig['label'] ?? $groupKey;
                    $label = trim((string) $labelSource);
                    $moduleKeys = $groupConfig['modules'] ?? [];

                    if (!is_array($moduleKeys) || $moduleKeys === []) {
                        continue;
                    }

                    $groupModules = [];

                    foreach ($moduleKeys as $moduleKey) {
                        if (!is_string($moduleKey) || !isset($menuModules[$moduleKey])) {
                            continue;
                        }
                        if (isset($assigned[$moduleKey])) {
                            continue;
                        }
                        if (empty($groupConfig['_user_defined']) && !empty($menuModules[$moduleKey]['_menu_has_user_group_preference'])) {
                            continue;
                        }

                        $groupModules[$moduleKey] = $menuModules[$moduleKey];
                        $assigned[$moduleKey] = true;
                    }

                    if ($groupModules !== []) {
                        $menuModuleGroups[] = [
                            'label' => $label !== '' ? $label : 'Moduły',
                            'modules' => $groupModules,
                        ];
                    }
                }

                $ungrouped = array_diff_key($menuModules, $assigned);
                if ($ungrouped !== []) {
                    $menuModuleGroups[] = [
                        'label' => $defaultGroupLabel !== '' ? $defaultGroupLabel : 'Moduły',
                        'modules' => $ungrouped,
                    ];
                }
            }

            $data['menuModuleGroups'] = $menuModuleGroups;
        }

        if (!array_key_exists('impersonatableUsers', $data)) {
            $impersonatableUsers = [];
            $user = $data['user'] ?? $this->authService->user();
            if ($user && $user->canImpersonate()) {
                $impersonatableUsers = $this->entityManager
                    ->getRepository(User::class)
                    ->findBy(['active' => true], [
                        'lastName' => 'asc',
                        'firstName' => 'asc',
                    ]);
                $impersonatableUsers = array_values(array_filter(
                    $impersonatableUsers,
                    static fn(User $candidate): bool => $candidate->getId() !== $user->getId()
                ));
            }
            $data['impersonatableUsers'] = $impersonatableUsers;
        }

        if (!array_key_exists('impersonatorUser', $data)) {
            $data['impersonatorUser'] = $this->authService->impersonator();
        }

        if (!array_key_exists('debugToolbar', $data)) {
            $currentUser = $data['user'] ?? $this->authService->user();
            $canViewDebugToolbar = $this->permissionService->can($currentUser, 'debug_tools', PermissionService::ACTION_VIEW);
            if ($canViewDebugToolbar) {
                $payload = DebugCollector::getPayload();
                $payload['redirectHistory'] = DebugCollector::consumeRedirectHistory();
                $data['debugToolbar'] = $payload;
            } else {
                $data['debugToolbar'] = null;
            }
        }

        $settingsProvider = null;
        if (!array_key_exists('systemSettings', $data) || !array_key_exists('appName', $data)) {
            $settingsProvider = new SystemSettingsProvider($this->entityManager);
        }

        if (!array_key_exists('systemSettings', $data)) {
            $data['systemSettings'] = $settingsProvider ? $settingsProvider->getSettings() : [];
        }

        if (!array_key_exists('appName', $data)) {
            $settings = $data['systemSettings'] ?? [];
            $appName = trim((string) ($settings['app_name'] ?? ''));
            if ($appName === '') {
                $appName = (string) ($this->config['app']['name'] ?? 'CMS2');
            }
            $data['appName'] = $appName;
        }

        if (!array_key_exists('siteSettings', $data)) {
            $data['siteSettings'] = $this->fetchModuleEntry('settings') ?? [];
        }

        if (!array_key_exists('currentUser', $data)) {
            $data['currentUser'] = $data['user'] ?? $this->authService->user();
        }

        if (!array_key_exists('csrf_token', $data)) {
            $data['csrf_token'] = $this->csrfService->getToken();
        }

        if (!array_key_exists('appProjectRoot', $data)) {
            $data['appProjectRoot'] = dirname(__DIR__, 2);
        }

        return new Response($this->twig->render($template, $data), $status);
    }

    /**
     * Pobiera listę wpisów modułu do użytku na froncie (np. FAQ, ustawienia strony).
     *
     * @param array<string, mixed> $criteria
     * @param array{orderBy?: string, direction?: string, limit?: int} $options
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchModuleEntries(string $moduleKey, array $criteria = [], array $options = []): array
    {
        $definition = $this->resolveModuleDefinition($moduleKey);

        if (!$definition) {
            return [];
        }

        $database = $definition['database'];
        $table = $database['table'] ?? null;

        if (!$table || !$this->tableExists((string) $table)) {
            return [];
        }

        $module = $definition['module'];
        $orderBy = $options['orderBy'] ?? null;
        $direction = strtolower((string) ($options['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        if ($orderBy === null) {
            $manualSort = $module['list']['manual_sort'] ?? false;
            $sortColumn = $module['list']['sort_column'] ?? null;

            if ($manualSort && is_string($sortColumn) && $sortColumn !== '') {
                $orderBy = $sortColumn;
                $direction = 'asc';
            }
        }

        $limit = $options['limit'] ?? null;
        $connection = $this->entityManager->getConnection();
        $queryBuilder = $connection->createQueryBuilder()
            ->select('*')
            ->from($table);

        $criteriaIndex = 0;
        foreach ($criteria as $column => $value) {
            $parameter = 'criteria_' . $criteriaIndex;
            if (is_array($value)) {
                $queryBuilder->andWhere($queryBuilder->expr()->in($column, ':' . $parameter));
                $queryBuilder->setParameter($parameter, $value, ArrayParameterType::STRING);
            } else {
                $queryBuilder->andWhere($queryBuilder->expr()->eq($column, ':' . $parameter));
                $queryBuilder->setParameter($parameter, $value);
            }
            $criteriaIndex++;
        }

        if ($orderBy !== null) {
            $queryBuilder->orderBy($orderBy, $direction);
        }

        if (is_int($limit) && $limit > 0) {
            $queryBuilder->setMaxResults($limit);
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * Pobiera pojedynczy wpis modułu (np. ustawienia strony).
     *
     * @param array<string, mixed> $criteria
     *
     * @return array<string, mixed>|null
     */
    protected function fetchModuleEntry(string $moduleKey, array $criteria = []): ?array
    {
        $entries = $this->fetchModuleEntries($moduleKey, $criteria, ['limit' => 1]);

        return $entries[0] ?? null;
    }

    protected function denyUnlessPermission(string $code, string $action): ?Response
    {
        $user = $this->authService->user();

        if (!$this->permissionService->can($user, $code, $action)) {
            return new Response('Brak uprawnień.', Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    protected function isPublicFileSource(string $sourceKey): bool
    {
        $sourceKey = trim($sourceKey);
        if ($sourceKey === '') {
            return false;
        }

        $settingsProvider = new SystemSettingsProvider($this->entityManager);
        $settings = $settingsProvider->getSettings();
        $configuredSources = $this->normalizePublicFileSources($settings['public_file_sources'] ?? null);

        if ($configuredSources === []) {
            $configuredSources = self::DEFAULT_PUBLIC_FILE_SOURCES;
        }

        return in_array($sourceKey, $configuredSources, true);
    }

    /**
     * @return string[]
     */
    private function normalizePublicFileSources(mixed $rawValue): array
    {
        if (is_array($rawValue)) {
            $rawValue = implode(',', array_map(static fn(mixed $value): string => trim((string) $value), $rawValue));
        }

        if (!is_string($rawValue) || trim($rawValue) === '') {
            return [];
        }

        $values = preg_split('/[\s,;]+/', mb_strtolower($rawValue)) ?: [];
        $values = array_map(static fn(string $value): string => trim($value), $values);
        $values = array_values(array_unique(array_filter($values, static fn(string $value): bool => $value !== '')));

        return $values;
    }

    /**
     * @return array{module: array<string, mixed>, database: array<string, mixed>}|null
     */
    protected function resolveModuleDefinition(string $moduleKey): ?array
    {
        if ($moduleKey === '') {
            return null;
        }

        $definitionLoader = new ModuleDefinitionLoader();
        foreach ($definitionLoader->getInstallDefinitions() as $definition) {
            if (($definition['key'] ?? null) !== $moduleKey) {
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

    /**
     * @param array<string, mixed> $template
     */
    protected function isPriorityEmailTemplate(array $template): bool
    {
        return filter_var($template['priority_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string, mixed> $template
     */
    protected function resolveTemplateBcc(array $template): ?string
    {
        $bcc = trim((string) ($template['bcc'] ?? ''));

        return $bcc !== '' ? $bcc : null;
    }

}
