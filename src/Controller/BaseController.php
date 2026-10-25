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
use App\Service\WinnerDrawingDateResolver;
use App\Service\WinnerFormReminderService;
use App\Service\WinnerPrizeCodeAssignmentService;
use App\Service\WinnerStatusEmailService;
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
        'teksty_na_stronie',
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
        if (
            !array_key_exists('systemSettings', $data)
            || !array_key_exists('recaptchaConfig', $data)
            || !array_key_exists('appName', $data)
            || !array_key_exists('cookieManagerSettings', $data)
        ) {
            $settingsProvider = new SystemSettingsProvider($this->entityManager);
        }

        if (!array_key_exists('systemSettings', $data)) {
            $data['systemSettings'] = $settingsProvider ? $settingsProvider->getSettings() : [];
        }

        if (!array_key_exists('recaptchaConfig', $data)) {
            $data['recaptchaConfig'] = $settingsProvider ? $settingsProvider->getRecaptchaConfig() : [
                'enabled' => false,
                'site_key' => '',
                'secret_key' => '',
                'use_enterprise' => false,
                'enterprise_project_id' => '',
                'enterprise_api_key' => '',
            ];
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

        if (!array_key_exists('cookieManagerSettings', $data)) {
            $data['cookieManagerSettings'] = $settingsProvider ? $settingsProvider->getCookieManagerSettings() : [];
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
     * @param array<string, mixed> $winner
     */
    protected function sendWinnerCodeEmail(array $winner, ?Request $request = null, bool $ignoreAlreadySent = false): bool
    {
        $result = $this->sendWinnerCodeEmailWithError($winner, $request, $ignoreAlreadySent);

        return $result['success'];
    }

    /**
     * @param array<string, mixed> $winner
     * @return array{success: bool, error?: string}
     */
    protected function sendWinnerCodeEmailWithError(array $winner, ?Request $request = null, bool $ignoreAlreadySent = false): array
    {
        $recipient = trim((string) ($winner['email'] ?? ''));
        if ($recipient === '') {
            return ['success' => false, 'error' => 'Brak adresu e-mail.'];
        }

        $alreadySentAt = trim((string) ($winner['email_o_poprawnej_weryfikacji_wyslany_at'] ?? ''));
        if (!$ignoreAlreadySent && !$this->isEmptyDateTimeValue($alreadySentAt)) {
            return ['success' => false, 'error' => 'E-mail z kodem wygranej został już wcześniej wysłany.'];
        }

        $winner = $this->assignWinningCodeToWinner($winner);
        $winningCode = trim((string) ($winner['pickup_code'] ?? ''));
        if ($winningCode === '') {
            return ['success' => false, 'error' => 'Nie udało się przypisać kodu wygranej do zgłoszenia.'];
        }

        $templateId = null;
        $prizeTypeId = trim((string) ($winner['rodzaj_wygranej'] ?? ''));
        if ($prizeTypeId !== '') {
            $prizeType = $this->entityManager->getConnection()->createQueryBuilder()
                ->select('prize_granted_email_template')
                ->from('rodzaje_nagrod_entries')
                ->where('id = :id')
                ->setParameter('id', $prizeTypeId)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            if (is_array($prizeType)) {
                $templateId = $prizeType['prize_granted_email_template'] ?? null;
            }
        }

        $settings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];
        if (!is_string($templateId) && !is_int($templateId)) {
            $templateId = $settings['winner_code_email_template'] ?? null;
        }
        if (!is_string($templateId) && !is_int($templateId)) {
            return ['success' => false, 'error' => 'Brak skonfigurowanego szablonu e-maila z kodem wygranej.'];
        }

        $definition = $this->resolveModuleDefinition('email_templates');
        $table = $definition['database']['table'] ?? null;
        if (!is_string($table) || $table === '') {
            return ['success' => false, 'error' => 'Brak konfiguracji modułu szablonów e-mail.'];
        }

        $connection = $this->entityManager->getConnection();
        $template = $connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where('id = :id')
            ->setParameter('id', (string) $templateId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($template) || $template === []) {
            return ['success' => false, 'error' => 'Nie znaleziono szablonu e-maila z kodem wygranej.'];
        }

        $sentAt = new \DateTimeImmutable();
        $winnerToken = trim((string) ($winner['token_formularza'] ?? ''));
        $winnerLink = '';
        if ($winnerToken !== '') {
            $winnerPath = '/loteria/zwyciezca/' . urlencode($winnerToken) . '#zglos';
            $winnerLink = $request
                ? rtrim($request->getSchemeAndHttpHost(), '/') . $winnerPath
                : $winnerPath;
        }

        $replacements = [
            $sentAt->format('d-m-Y'),
            $sentAt->format('Y-m-d H:i:s'),
            $recipient,
            trim((string) ($winner['telefon'] ?? '')),
            trim((string) ($winner['numer_dowodu_zakupu'] ?? '')),
            '',
            $winningCode,
            $winnerLink,
            $winningCode,
        ];

        $placeholderValues = [
            'data' => $replacements[0],
            'data_czas' => $replacements[1],
            'email' => $replacements[2],
            'telefon' => $replacements[3],
            'los' => $replacements[4],
            'nagroda' => $replacements[5],
            'kod_vouchera' => $replacements[6],
            'link_zwyciezca' => $replacements[7],
            'data_losowania' => $this->resolveWinnerDrawingDatePlaceholder($winner),
            'termin_formularza' => $this->resolveWinnerFormDeadlinePlaceholder($winner),
            'pickup_code' => $replacements[8],
            'kwota' => trim((string) ($winner['kwota_wygranej'] ?? '')),
        ];

        $subject = $this->replaceEmailPlaceholders((string) ($template['subject'] ?? ''), $placeholderValues);
        $body = $this->replaceEmailPlaceholders((string) ($template['body'] ?? ''), $placeholderValues);
        $winnerCodeEmailBcc = trim((string) ($settings['winner_code_email_bcc'] ?? ''));
        if ($winnerCodeEmailBcc !== '' && !filter_var($winnerCodeEmailBcc, FILTER_VALIDATE_EMAIL)) {
            $winnerCodeEmailBcc = '';
        }
        $bcc = implode(', ', array_filter([
            $this->resolveTemplateBcc($template),
            $winnerCodeEmailBcc !== '' ? $winnerCodeEmailBcc : null,
        ]));

        $mailResult = $this->mailer->sendWithError($recipient, $subject, $body, true, $bcc !== '' ? $bcc : null, false, $this->isPriorityEmailTemplate($template));
        if (!$mailResult['success']) {
            return [
                'success' => false,
                'error' => trim((string) ($mailResult['error'] ?? '')) !== ''
                    ? (string) $mailResult['error']
                    : 'Nieznany błąd podczas wysyłki e-maila.',
            ];
        }

        $winnerId = (int) ($winner['id'] ?? 0);
        if ($winnerId > 0) {
            $winnersDefinition = $this->resolveModuleDefinition('zwyciezcy');
            $winnersTable = $winnersDefinition['database']['table'] ?? null;
            if (is_string($winnersTable) && $winnersTable !== '') {
                $connection->update($winnersTable, [
                    'email_o_poprawnej_weryfikacji_wyslany_at' => $sentAt->format('Y-m-d H:i:s'),
                ], [
                    'id' => $winnerId,
                ]);
            }
        }

        return ['success' => true];
    }

    /**
     * @param array<string, mixed> $winner
     */
    protected function sendLotteryWinnerPrizeEmail(array $winner, ?Request $request = null, bool $ignoreAlreadySent = false, string $winnerModuleKey = 'zwyciezcy'): bool
    {
        $result = $this->sendLotteryWinnerPrizeEmailWithError($winner, $request, $ignoreAlreadySent, $winnerModuleKey);

        return $result['success'];
    }

    /**
     * @param array<string, mixed> $winner
     * @return array{success: bool, error?: string}
     */
    protected function sendLotteryWinnerPrizeEmailWithError(array $winner, ?Request $request = null, bool $ignoreAlreadySent = false, string $winnerModuleKey = 'zwyciezcy'): array
    {
        $recipient = trim((string) ($winner['email'] ?? ''));
        if ($recipient === '') {
            return ['success' => false, 'error' => 'Brak adresu e-mail.'];
        }

        $alreadySentAt = trim((string) ($winner['email_o_poprawnej_weryfikacji_wyslany_at'] ?? ''));
        if (!$ignoreAlreadySent && !$this->isEmptyDateTimeValue($alreadySentAt)) {
            return ['success' => false, 'error' => 'E-mail z kodami wygranej został już wcześniej wysłany.'];
        }

        $assignmentResult = $this->assignLotteryWinningCodesToWinner($winner, $winnerModuleKey);
        if (!$assignmentResult['success']) {
            return [
                'success' => false,
                'error' => (string) ($assignmentResult['error'] ?? 'Nie udało się przypisać kodów wygranej.'),
            ];
        }

        $winner = $assignmentResult['winner'];
        $prizeType = $assignmentResult['prize_type'];
        $codes500 = $assignmentResult['codes_500'];
        $codes1000 = $assignmentResult['codes_1000'];
        $codeRows = $assignmentResult['code_rows'] ?? [];

        if (filter_var($assignmentResult['code_shortage'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $winnersDefinition = $this->resolveModuleDefinition($winnerModuleKey);
            $winnersTable = $winnersDefinition['database']['table'] ?? null;
            if (!is_string($winnersTable) || $winnersTable === '') {
                return ['success' => false, 'error' => 'Brak konfiguracji modułu zwycięzców.'];
            }

            $shortageMailResult = (new WinnerStatusEmailService($this->mailer))->sendForStatus(
                $this->entityManager->getConnection(),
                $winner,
                (int) ($winner['id'] ?? 0),
                $winnersTable,
                trim((string) ($assignmentResult['effective_status_id'] ?? $winner['stan'] ?? '')),
                false
            );

            return $shortageMailResult['success']
                ? ['success' => true]
                : ['success' => false, 'error' => (string) ($shortageMailResult['error'] ?? 'Nie udało się wysłać e-maila o braku kodów.')];
        }

        $templateId = $prizeType['prize_granted_email_template'] ?? null;
        if (!is_string($templateId) && !is_int($templateId)) {
            return ['success' => false, 'error' => 'Brak skonfigurowanego szablonu e-maila o przyznaniu nagrody.'];
        }

        $definition = $this->resolveModuleDefinition('email_templates');
        $table = $definition['database']['table'] ?? null;
        if (!is_string($table) || $table === '') {
            return ['success' => false, 'error' => 'Brak konfiguracji modułu szablonów e-mail.'];
        }

        $connection = $this->entityManager->getConnection();
        $template = $connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where('id = :id')
            ->setParameter('id', (string) $templateId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($template) || $template === []) {
            return ['success' => false, 'error' => 'Nie znaleziono szablonu e-maila o przyznaniu nagrody.'];
        }

        $sentAt = new \DateTimeImmutable();
        $winnerToken = trim((string) ($winner['token_formularza'] ?? ''));
        $winnerLink = '';
        if ($winnerToken !== '') {
            $winnerPath = '/loteria/zwyciezca/' . urlencode($winnerToken) . '#zglos';
            $winnerLink = $request
                ? rtrim($request->getSchemeAndHttpHost(), '/') . $winnerPath
                : $winnerPath;
        }

        $codes500List = implode(', ', $codes500);
        $codes1000List = implode(', ', $codes1000);
        $allCodes = array_values(array_filter([$codes500List, $codes1000List], static fn(string $value): bool => $value !== ''));

        $placeholderValues = [
            'data' => $sentAt->format('d-m-Y'),
            'data_czas' => $sentAt->format('Y-m-d H:i:s'),
            'email' => $recipient,
            'telefon' => trim((string) ($winner['telefon'] ?? '')),
            'los' => trim((string) ($winner['numer_dowodu_zakupu'] ?? '')),
            'dowod_zakupu' => trim((string) ($winner['numer_dowodu_zakupu'] ?? '')),
            'nagroda' => trim((string) ($prizeType['name'] ?? '')),
            'kod_vouchera' => implode(', ', $allCodes),
            'pickup_code' => implode(', ', $allCodes),
            'link_zwyciezca' => $winnerLink,
            'data_losowania' => $this->resolveWinnerDrawingDatePlaceholder($winner),
            'termin_formularza' => $this->resolveWinnerFormDeadlinePlaceholder($winner),
            'kody_500' => $codes500List,
            'kody_1000' => $codes1000List,
            'kwota' => implode(', ', array_values(array_filter([
                $codes500List !== '' ? '500' : '',
                $codes1000List !== '' ? '1000' : '',
            ]))),
        ];

        $subjectTemplate = $this->renderWinnerCodesLoop((string) ($template['subject'] ?? ''), $codeRows);
        $bodyTemplate = $this->renderWinnerCodesLoop((string) ($template['body'] ?? ''), $codeRows, true);

        $subject = $this->replaceEmailPlaceholders($subjectTemplate, $placeholderValues);
        $body = $this->replaceEmailPlaceholders($bodyTemplate, $placeholderValues);
        $mailResult = $this->mailer->sendWithError($recipient, $subject, $body, true, $this->resolveTemplateBcc($template), false, $this->isPriorityEmailTemplate($template));
        if (!$mailResult['success']) {
            return [
                'success' => false,
                'error' => trim((string) ($mailResult['error'] ?? '')) !== ''
                    ? (string) $mailResult['error']
                    : 'Nieznany błąd podczas wysyłki e-maila.',
            ];
        }

        $winnerId = (int) ($winner['id'] ?? 0);
        if ($winnerId > 0) {
            $winnersDefinition = $this->resolveModuleDefinition($winnerModuleKey);
            $winnersTable = $winnersDefinition['database']['table'] ?? null;
            if (is_string($winnersTable) && $winnersTable !== '') {
                $connection->update($winnersTable, [
                    'email_o_poprawnej_weryfikacji_wyslany_at' => $sentAt->format('Y-m-d H:i:s'),
                ], [
                    'id' => $winnerId,
                ]);
            }
        }

        return ['success' => true];
    }

    /**
     * @param array<string, mixed> $winner
     *
     * @return array<string, mixed>
     */
    protected function assignWinningCodeToWinner(array $winner): array
    {
        $winnerId = (int) ($winner['id'] ?? 0);
        if ($winnerId <= 0) {
            return $winner;
        }

        $codesDefinition = $this->resolveModuleDefinition('lista_wygranych_kodow');
        $codesTable = $codesDefinition['database']['table'] ?? null;
        if (!is_string($codesTable) || $codesTable === '') {
            return $winner;
        }

        $connection = $this->entityManager->getConnection();
        $assignedCodeIds = json_decode((string) ($winner['kod_z_listy_wygranych'] ?? ''), true);
        if (is_array($assignedCodeIds) && $assignedCodeIds !== []) {
            $assignedCodeId = (int) ($assignedCodeIds[0] ?? 0);
            if ($assignedCodeId > 0) {
                $assignedCode = $connection->createQueryBuilder()
                    ->select('pickup_code')
                    ->from($codesTable)
                    ->where('id = :id')
                    ->setParameter('id', $assignedCodeId)
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchAssociative();

                if (is_array($assignedCode)) {
                    $winner['pickup_code'] = trim((string) ($assignedCode['pickup_code'] ?? ''));
                }
            }

            return $winner;
        }

        $freeCode = $connection->createQueryBuilder()
            ->select('*')
            ->from($codesTable)
            ->where("(kod_wykorzystany = 0 OR kod_wykorzystany IS NULL OR kod_wykorzystany = '')")
            ->andWhere("(kod_przyznany_do_zwyciezcy IS NULL OR kod_przyznany_do_zwyciezcy = 0 OR kod_przyznany_do_zwyciezcy = '')")
            ->orderBy('id', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($freeCode) || $freeCode === []) {
            return $winner;
        }

        $codeEntryId = (int) ($freeCode['id'] ?? 0);
        $pickupCode = trim((string) ($freeCode['pickup_code'] ?? ''));
        if ($codeEntryId <= 0 || $pickupCode === '') {
            return $winner;
        }

        $assignedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $updatedRows = $connection->createQueryBuilder()
            ->update($codesTable)
            ->set('kod_wykorzystany', ':used')
            ->set('kod_przyznany_do_zgloszenia', ':submissionId')
            ->set('kod_przyznany_do_losy', ':ticketId')
            ->set('kod_przyznany_do_zdrapki', ':scratchcardId')
            ->set('kod_przyznany_do_zwyciezcy', ':winnerId')
            ->set('data_i_godzina_przyznania', ':assignedAt')
            ->where('id = :codeEntryId')
            ->andWhere("(kod_wykorzystany = 0 OR kod_wykorzystany IS NULL OR kod_wykorzystany = '')")
            ->andWhere("(kod_przyznany_do_zwyciezcy IS NULL OR kod_przyznany_do_zwyciezcy = 0 OR kod_przyznany_do_zwyciezcy = '')")
            ->setParameter('used', 1)
            ->setParameter('submissionId', (int) ($winner['zgloszenie'] ?? 0) ?: null)
            ->setParameter('ticketId', (int) ($winner['kupon_w_loterii'] ?? 0) ?: null)
            ->setParameter('scratchcardId', (int) ($winner['zdrapka_w_loterii'] ?? 0) ?: null)
            ->setParameter('winnerId', $winnerId)
            ->setParameter('assignedAt', $assignedAt)
            ->setParameter('codeEntryId', $codeEntryId)
            ->executeStatement();

        if ($updatedRows !== 1) {
            return $winner;
        }

        $winnersDefinition = $this->resolveModuleDefinition('zwyciezcy');
        $winnersTable = $winnersDefinition['database']['table'] ?? null;
        if (is_string($winnersTable) && $winnersTable !== '') {
            $connection->update($winnersTable, [
                'kod_z_listy_wygranych' => json_encode([$codeEntryId]),
            ], [
                'id' => $winnerId,
            ]);
        }

        $winner['pickup_code'] = $pickupCode;
        $winner['kod_z_listy_wygranych'] = json_encode([$codeEntryId]);

        return $winner;
    }

    /**
     * @param array<string, mixed> $winner
     * @return array{success: bool, winner?: array<string, mixed>, codes_500?: array<int, string>, codes_1000?: array<int, string>, code_rows?: array<int, array{pickup_code: string, kwota: string}>, prize_type?: array<string, mixed>, code_shortage?: bool, effective_status_id?: string|null, error?: string}
     */
    protected function assignLotteryWinningCodesToWinner(array $winner, string $winnerModuleKey = 'zwyciezcy'): array
    {
        $winnerId = (int) ($winner['id'] ?? 0);
        if ($winnerId <= 0) {
            return ['success' => false, 'error' => 'Niepoprawny identyfikator zwycięzcy.'];
        }

        $prizeTypeId = (int) ($winner['rodzaj_wygranej'] ?? 0);
        if ($prizeTypeId <= 0) {
            return ['success' => false, 'error' => 'Brak typu nagrody dla zwycięzcy.'];
        }

        $connection = $this->entityManager->getConnection();
        $assignmentService = new WinnerPrizeCodeAssignmentService();
        $assignmentResult = $assignmentService->assignConfiguredCodesToWinner($connection, $winner);
        if (!$assignmentResult['success']) {
            return [
                'success' => false,
                'error' => (string) ($assignmentResult['error'] ?? 'Nie udało się przypisać kodów wygranej.'),
            ];
        }

        return [
            'success' => true,
            'winner' => $assignmentResult['winner'],
            'codes_500' => $assignmentResult['codes_500'],
            'codes_1000' => $assignmentResult['codes_1000'],
            'code_rows' => $assignmentResult['code_rows'],
            'prize_type' => $assignmentResult['prize_type'],
            'code_shortage' => $assignmentResult['code_shortage'] ?? false,
            'effective_status_id' => $assignmentResult['effective_status_id'] ?? null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAvailableLotteryCodes(string $table, int $lotteryId, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return $this->entityManager->getConnection()->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where('loteria = :loteria')
            ->andWhere("(kod_wykorzystany = 0 OR kod_wykorzystany IS NULL OR kod_wykorzystany = '')")
            ->andWhere("(kod_przyznany_do_zwyciezcy IS NULL OR kod_przyznany_do_zwyciezcy = 0 OR kod_przyznany_do_zwyciezcy = '')")
            ->orderBy('id', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('loteria', $lotteryId)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<int, string>
     */
    private function decodeRelationIds(mixed $rawValue): array
    {
        if (is_array($rawValue)) {
            return array_values(array_filter(array_map(
                static fn(mixed $value): string => trim((string) $value),
                $rawValue
            ), static fn(string $value): bool => $value !== ''));
        }

        if (!is_string($rawValue) || trim($rawValue) === '') {
            return [];
        }

        $decoded = json_decode($rawValue, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $value): string => trim((string) $value),
            $decoded
        ), static fn(string $value): bool => $value !== ''));
    }

    protected function sendPrizeRejectionEmail(array $winner, ?Request $request = null): bool
    {
        $recipient = trim((string) ($winner['email'] ?? ''));
        if ($recipient === '') {
            return false;
        }

        $settings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];
        $templateId = $settings['prize_rejection_email_template'] ?? null;
        if (!is_string($templateId) && !is_int($templateId)) {
            return false;
        }

        $definition = $this->resolveModuleDefinition('email_templates');
        $table = $definition['database']['table'] ?? null;
        if (!is_string($table) || $table === '') {
            return false;
        }

        $connection = $this->entityManager->getConnection();
        $template = $connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where('id = :id')
            ->setParameter('id', (string) $templateId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($template) || $template === []) {
            return false;
        }

        $sentAt = new \DateTimeImmutable();
        $winnerToken = trim((string) ($winner['token_formularza'] ?? ''));
        $winnerLink = '';
        if ($winnerToken !== '') {
            $winnerPath = '/loteria/zwyciezca/' . urlencode($winnerToken) . '#zglos';
            $winnerLink = $request
                ? rtrim($request->getSchemeAndHttpHost(), '/') . $winnerPath
                : $winnerPath;
        }

        $purchaseDocumentNumber = trim((string) ($winner['numer_dowodu_zakupu'] ?? ''));

        $placeholders = ['{data}', '{data_czas}', '{email}', '{telefon}', '{los}', '{dowod_zakupu}', '{nagroda}', '{kod_vouchera}', '{link_zwyciezca}', '{data_losowania}', '{termin_formularza}'];
        $replacement = [
            $sentAt->format('d-m-Y'),
            $sentAt->format('Y-m-d H:i:s'),
            $recipient,
            trim((string) ($winner['telefon'] ?? '')),
            $purchaseDocumentNumber,
            $purchaseDocumentNumber,
            '',
            '',
            $winnerLink,
            $this->resolveWinnerDrawingDatePlaceholder($winner),
            $this->resolveWinnerFormDeadlinePlaceholder($winner),
        ];

        $subject = str_replace($placeholders, $replacement, (string) ($template['subject'] ?? ''));
        $body = str_replace($placeholders, $replacement, (string) ($template['body'] ?? ''));

        $this->mailer->send($recipient, $subject, $body, true, $this->resolveTemplateBcc($template), $this->isPriorityEmailTemplate($template));

        return true;
    }

    private function isEmptyDateTimeValue(string $value): bool
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return true;
        }

        return preg_match('/^0{4}-0{2}-0{2}(?:\s+0{2}:0{2}:0{2})?$/', $normalized) === 1;
    }

    /**
     * @param array<string, mixed> $winner
     * @return array{success: bool, error?: string}
     */
    protected function sendWinnerFormLinkEmailWithError(array $winner, ?Request $request = null): array
    {
        $templateId = null;
        $formType = '';
        $prizeValue = '';
        $prizeTypeId = trim((string) ($winner['rodzaj_wygranej'] ?? ''));
        if ($prizeTypeId !== '') {
            $prizeType = $this->entityManager->getConnection()->createQueryBuilder()
                ->select('prize_info_email_template', 'form_type', 'wartosc_nagrody')
                ->from('rodzaje_nagrod_entries')
                ->where('id = :id')
                ->setParameter('id', $prizeTypeId)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            if (is_array($prizeType)) {
                $templateId = $prizeType['prize_info_email_template'] ?? null;
                $formType = trim((string) ($prizeType['form_type'] ?? ''));
                $prizeValue = trim((string) ($prizeType['wartosc_nagrody'] ?? ''));
            }
        }

        if ($formType === 'no_form') {
            return ['success' => true];
        }

        $recipient = trim((string) ($winner['email'] ?? ''));
        if ($recipient === '') {
            return ['success' => false, 'error' => 'Brak adresu e-mail.'];
        }

        $winnerToken = trim((string) ($winner['token_formularza'] ?? ''));
        if ($winnerToken === '') {
            return ['success' => false, 'error' => 'Brak tokenu formularza zwycięzcy.'];
        }

        $templateId = is_int($templateId) ? (string) $templateId : trim((string) $templateId);
        if ($templateId === '') {
            $settings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];
            $templateId = $settings['winner_email_template'] ?? null;
        }
        $templateId = is_int($templateId) ? (string) $templateId : trim((string) $templateId);
        if ($templateId === '') {
            return ['success' => false, 'error' => 'Brak skonfigurowanego szablonu e-maila zwycięzcy.'];
        }

        $definition = $this->resolveModuleDefinition('email_templates');
        $table = $definition['database']['table'] ?? null;
        if (!is_string($table) || $table === '') {
            return ['success' => false, 'error' => 'Brak konfiguracji modułu szablonów e-mail.'];
        }

        $connection = $this->entityManager->getConnection();
        $template = $connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where('id = :id')
            ->setParameter('id', $templateId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($template) || $template === []) {
            return ['success' => false, 'error' => 'Nie znaleziono szablonu e-maila zwycięzcy.'];
        }

        $sentAt = new \DateTimeImmutable();
        $winnerPath = '/loteria/zwyciezca/' . urlencode($winnerToken) . '#zglos';
        $winnerLink = $request
            ? rtrim($request->getSchemeAndHttpHost(), '/') . $winnerPath
            : $winnerPath;

        $placeholderValues = [
            'data' => $sentAt->format('d-m-Y'),
            'data_czas' => $sentAt->format('Y-m-d H:i:s'),
            'email' => $recipient,
            'telefon' => trim((string) ($winner['telefon'] ?? '')),
            'los' => trim((string) ($winner['numer_dowodu_zakupu'] ?? '')),
            'dowod_zakupu' => trim((string) ($winner['numer_dowodu_zakupu'] ?? '')),
            'nagroda' => '',
            'wartosc-nagrody' => $prizeValue,
            'kod_vouchera' => '',
            'link_zwyciezca' => $winnerLink,
            'data_losowania' => $this->resolveWinnerDrawingDatePlaceholder($winner),
            'termin_formularza' => $this->resolveWinnerFormDeadlinePlaceholder($winner, $sentAt),
        ];

        $subject = $this->replaceEmailPlaceholders((string) ($template['subject'] ?? ''), $placeholderValues);
        $body = $this->replaceEmailPlaceholders((string) ($template['body'] ?? ''), $placeholderValues);

        $mailResult = $this->mailer->sendWithError($recipient, $subject, $body, true, $this->resolveTemplateBcc($template), false, $this->isPriorityEmailTemplate($template));
        if (!$mailResult['success']) {
            return [
                'success' => false,
                'error' => trim((string) ($mailResult['error'] ?? '')) !== ''
                    ? (string) $mailResult['error']
                    : 'Nieznany błąd podczas wysyłki e-maila.',
            ];
        }

        return ['success' => true];
    }

    /** @param array<string, mixed> $winner */
    protected function resolveWinnerFormDeadlinePlaceholder(
        array $winner,
        ?\DateTimeImmutable $fallbackSentAt = null
    ): string {
        $settings = $this->fetchModuleEntry('loteria_ustawienia') ?? [];

        return (new WinnerFormReminderService($this->mailer))
            ->formatFormExpiry($winner, $settings, $fallbackSentAt);
    }

    /** @param array<string, mixed> $winner */
    protected function resolveWinnerDrawingDatePlaceholder(array $winner): string
    {
        return (new WinnerDrawingDateResolver())->resolve($this->entityManager->getConnection(), $winner);
    }

    /**
     * Splits repeated winner-code loop markup so decorative content placed before the
     * first code placeholder (for example a large beer image) is rendered once above
     * all generated EAN/Code-128 barcodes instead of being duplicated for each code.
     *
     * @return array{0: string, 1: string}
     */
    private function extractWinnerCodesLoopSharedHeader(string $rowTemplate): array
    {
        if (!preg_match('/\{\s*(?:pickup_code|kod_ean|code_128)\s*\}/i', $rowTemplate, $matches, PREG_OFFSET_CAPTURE)) {
            return ['', $rowTemplate];
        }

        $placeholderOffset = (int) $matches[0][1];
        if ($placeholderOffset <= 0) {
            return ['', $rowTemplate];
        }

        return [substr($rowTemplate, 0, $placeholderOffset), substr($rowTemplate, $placeholderOffset)];
    }

    /** @param array{pickup_code: string, kwota: string, kod_ean?: bool, code_128?: bool} $codeRow */
    private function renderPickupCodeValue(array $codeRow, bool $includeHeader = true): string
    {
        if ($this->isTruthyValue($codeRow['code_128'] ?? false)) {
            return $this->renderCode128Value($codeRow, $includeHeader);
        }

        $pickupCode = trim($codeRow['pickup_code']);
        if ($pickupCode === '' || !$this->isTruthyValue($codeRow['kod_ean'] ?? false)) {
            return htmlspecialchars($pickupCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $this->renderBarcodeImage('/kod-ean/', 'Kod EAN', $pickupCode, 420, $includeHeader);
    }

    /** @param array{pickup_code: string, kwota: string, code_128?: bool} $codeRow */
    private function renderCode128Value(array $codeRow, bool $includeHeader = true): string
    {
        $pickupCode = trim($codeRow['pickup_code']);
        if ($pickupCode === '' || !$this->isTruthyValue($codeRow['code_128'] ?? false)) {
            return htmlspecialchars($pickupCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $this->renderBarcodeImage('/kod-code-128/', 'Kod Code-128', $pickupCode, 520, $includeHeader);
    }

    private function isTruthyValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'tak', 'yes', 'on'], true);
    }

    private function renderBarcodeImage(string $pathPrefix, string $altPrefix, string $pickupCode, int $width, bool $includeHeader = true): string
    {
        $url = $this->mailer->absoluteUrl($pathPrefix . rawurlencode($pickupCode) . '.jpg' . ($includeHeader ? '' : '?header=0'));
        $imageSrc = $this->renderInlineBarcodeImageSource($pathPrefix, $pickupCode, $includeHeader) ?? $url;
        $escapedUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedImageSrc = htmlspecialchars($imageSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedCode = htmlspecialchars($pickupCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return sprintf('<a href="%1$s"><img src="%2$s" alt="%3$s %4$s" width="%5$d" style="display:block;width:%5$dpx;max-width:100%%;height:auto;border:0;"></a>', $escapedUrl, $escapedImageSrc, $altPrefix, $escapedCode, $width);
    }

    private function renderInlineBarcodeImageSource(string $pathPrefix, string $pickupCode, bool $includeHeader): ?string
    {
        try {
            if ($pathPrefix === '/kod-ean/') {
                $generator = new \App\Service\Ean13BarcodeGenerator();
                $jpeg = $generator->generateJpeg($pickupCode, includeHeader: $includeHeader);
                $filename = $generator->normalize($pickupCode) . '.jpg';
            } elseif ($pathPrefix === '/kod-code-128/') {
                $generator = new \App\Service\Code128BarcodeGenerator();
                $jpeg = $generator->generateJpeg($pickupCode, includeHeader: $includeHeader);
                $filename = (preg_replace('/[^A-Za-z0-9._-]+/', '_', $generator->normalize($pickupCode)) ?: 'code-128') . '.jpg';
            } else {
                return null;
            }
            return $this->mailer->attachInlineImage($jpeg, 'image/jpeg', $filename);
        } catch (\Throwable $exception) {
            return null;
        }
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

    /**
     * @param array<string, mixed> $settings
     */
    protected function resolveLotteryEntryGenerationMode(array $settings): string
    {
        $mode = trim((string) ($settings['entry_generation_mode'] ?? ''));

        return in_array($mode, ['tickets', 'scratchcards', 'tickets_and_scratchcards'], true)
            ? $mode
            : 'tickets_and_scratchcards';
    }

    protected function shouldGenerateLotteryTickets(string $mode): bool
    {
        return in_array($mode, ['tickets', 'tickets_and_scratchcards'], true);
    }

    protected function shouldGenerateLotteryScratchcards(string $mode): bool
    {
        return in_array($mode, ['scratchcards', 'tickets_and_scratchcards'], true);
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected function resolveLotteryTicketIdGenerationMode(array $settings): string
    {
        return trim((string) ($settings['ticket_id_generation_mode'] ?? '')) === 'sequential'
            ? 'sequential'
            : 'submission_based';
    }

    /**
     * Creates lottery entries while keeping sequential identifiers unique when
     * multiple submissions are handled at the same time.
     *
     * @param string[] $ticketTables
     * @param array<string, mixed> $settings
     * @param callable(string, int): void $createEntry
     * @param (callable(): void)|null $beforeCreate
     * @return string[]
     */
    protected function createLotteryEntriesWithIdentifiers(
        Connection $connection,
        array $ticketTables,
        int $submissionId,
        int $entryCount,
        array $settings,
        callable $createEntry,
        ?callable $beforeCreate = null
    ): array {
        if ($entryCount <= 0) {
            if ($beforeCreate !== null) {
                $beforeCreate();
            }

            return [];
        }

        $sequential = $this->resolveLotteryTicketIdGenerationMode($settings) === 'sequential';
        $lockAcquired = false;

        if ($sequential) {
            $lockAcquired = $this->acquireLotteryTicketIdSequenceLock($connection);
        }

        try {
            $firstSequentialId = $sequential
                ? $this->reserveSequentialLotteryTicketIds($connection, $ticketTables, $settings, $entryCount)
                : 0;

            if ($beforeCreate !== null) {
                $beforeCreate();
            }

            $identifiers = [];
            for ($index = 1; $index <= $entryCount; $index++) {
                $ticketId = $sequential
                    ? (string) ($firstSequentialId + $index - 1)
                    : sprintf('%s-%02d', $submissionId, $index);
                $createEntry($ticketId, $index);
                $identifiers[] = $ticketId;
            }

            return $identifiers;
        } finally {
            if ($lockAcquired) {
                $this->releaseLotteryTicketIdSequenceLock($connection);
            }
        }
    }

    private function acquireLotteryTicketIdSequenceLock(Connection $connection): bool
    {
        $platformName = strtolower($connection->getDatabasePlatform()->getName());
        if (!str_contains($platformName, 'mysql') && !str_contains($platformName, 'mariadb')) {
            return false;
        }

        $acquired = (int) $connection->fetchOne('SELECT GET_LOCK(?, 10)', ['cms_lottery_ticket_id_sequence']);
        if ($acquired !== 1) {
            throw new \RuntimeException('Nie udało się zarezerwować kolejnego numeru losu. Spróbuj ponownie.');
        }

        return true;
    }

    private function releaseLotteryTicketIdSequenceLock(Connection $connection): void
    {
        try {
            $connection->fetchOne('SELECT RELEASE_LOCK(?)', ['cms_lottery_ticket_id_sequence']);
        } catch (\Throwable $exception) {
            // A lost database connection releases its advisory locks automatically.
        }
    }

    /**
     * @param string[] $ticketTables
     * @param array<string, mixed> $settings
     */
    private function reserveSequentialLotteryTicketIds(
        Connection $connection,
        array $ticketTables,
        array $settings,
        int $entryCount
    ): int {
        $settingsDefinition = $this->resolveModuleDefinition('loteria_ustawienia');
        $settingsTable = $settingsDefinition['database']['table'] ?? null;
        $settingsPrimaryKey = $settingsDefinition['database']['primary_key'] ?? 'id';
        $settingsId = (int) ($settings[$settingsPrimaryKey] ?? 0);

        if (is_string($settingsTable)
            && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $settingsTable) === 1
            && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $settingsPrimaryKey) === 1
            && $settingsId > 0
            && $this->tableExists($settingsTable)
        ) {
            try {
                $storedValue = (int) $connection->createQueryBuilder()
                    ->select('ticket_id_sequence_value')
                    ->from($settingsTable)
                    ->where($settingsPrimaryKey . ' = :settingsId')
                    ->setParameter('settingsId', $settingsId)
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchOne();
                $highestId = $storedValue > 0
                    ? $storedValue
                    : $this->findHighestSequentialLotteryTicketId($connection, $ticketTables);
                if ($highestId > PHP_INT_MAX - $entryCount) {
                    throw new \RuntimeException('Wyczerpano zakres kolejnych numerów losów.');
                }
                $reservedThrough = $highestId + $entryCount;

                $connection->update(
                    $settingsTable,
                    ['ticket_id_sequence_value' => $reservedThrough],
                    [$settingsPrimaryKey => $settingsId]
                );

                return $highestId + 1;
            } catch (\RuntimeException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                // During a rolling deployment the counter column may not exist yet.
                // The locked table scan below remains safe as a compatibility fallback.
            }
        }

        $highestId = $this->findHighestSequentialLotteryTicketId($connection, $ticketTables);
        if ($highestId > PHP_INT_MAX - $entryCount) {
            throw new \RuntimeException('Wyczerpano zakres kolejnych numerów losów.');
        }

        return $highestId + 1;
    }

    /**
     * @param string[] $ticketTables
     */
    private function findHighestSequentialLotteryTicketId(Connection $connection, array $ticketTables): int
    {
        $platformName = strtolower($connection->getDatabasePlatform()->getName());
        $highestId = 0;

        foreach (array_values(array_unique($ticketTables)) as $table) {
            if (!is_string($table) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1 || !$this->tableExists($table)) {
                continue;
            }

            if (str_contains($platformName, 'mysql') || str_contains($platformName, 'mariadb')) {
                $value = $connection->fetchOne(sprintf(
                    "SELECT MAX(CAST(ticket_id AS UNSIGNED)) FROM %s WHERE ticket_id REGEXP '^[0-9]+$'",
                    $table
                ));
                $highestId = max($highestId, (int) $value);
                continue;
            }

            $rows = $connection->createQueryBuilder()
                ->select('ticket_id')
                ->from($table)
                ->executeQuery()
                ->fetchFirstColumn();
            foreach ($rows as $value) {
                $normalized = trim((string) $value);
                if ($normalized !== '' && ctype_digit($normalized)) {
                    $highestId = max($highestId, (int) $normalized);
                }
            }
        }

        return $highestId;
    }

    /**
     * @param array<string, scalar|null> $values
     */
    private function replaceEmailPlaceholders(string $template, array $values): string
    {
        if ($template === '' || $values === []) {
            return $template;
        }

        $normalizedValues = [];
        foreach ($values as $key => $value) {
            $normalizedValues[strtolower(trim((string) $key))] = (string) ($value ?? '');
        }

        return (string) preg_replace_callback('/\{\s*([a-zA-Z0-9_-]+)\s*\}/', static function (array $matches) use ($normalizedValues): string {
            $key = strtolower($matches[1] ?? '');

            return array_key_exists($key, $normalizedValues) ? $normalizedValues[$key] : ($matches[0] ?? '');
        }, $template);
    }

    /**
     * @param array<int, array{pickup_code: string, kwota: string, kod_ean: bool, code_128: bool}> $codeRows
     */
    private function renderWinnerCodesLoop(string $template, array $codeRows, bool $renderEanImages = false): string
    {
        if ($template === '') {
            return $template;
        }

        return (string) preg_replace_callback('/\{\s*kody\s*\}(.*?)\{\s*\/\s*kody\s*\}/si', function (array $matches) use ($codeRows, $renderEanImages): string {
            $rowTemplate = (string) ($matches[1] ?? '');
            if ($rowTemplate === '' || $codeRows === []) {
                return '';
            }

            $sharedHeader = '';
            if ($renderEanImages && count($codeRows) > 1 && $this->hasBarcodeCodeRows($codeRows)) {
                [$sharedHeader, $rowTemplate] = $this->extractWinnerCodesLoopSharedHeader($rowTemplate);
            }

            $renderCodeRow = function (array $codeRow, string $template, bool $includeHeader = true): string {
                return $this->replaceEmailPlaceholders($template, [
                    'pickup_code' => $this->renderPickupCodeValue($codeRow, $includeHeader),
                    'kod_ean' => $this->renderPickupCodeValue($codeRow, $includeHeader),
                    'code_128' => $this->renderCode128Value($codeRow, $includeHeader),
                    'kwota' => $codeRow['kwota'],
                ]);
            };

            if (!$renderEanImages) {
                $renderCodeRow = function (array $codeRow, string $template): string {
                    return $this->replaceEmailPlaceholders($template, [
                        'pickup_code' => $codeRow['pickup_code'],
                        'kod_ean' => $codeRow['pickup_code'],
                        'code_128' => $codeRow['pickup_code'],
                        'kwota' => $codeRow['kwota'],
                    ]);
                };
            }

            $renderedRows = [];
            foreach ($codeRows as $index => $codeRow) {
                $renderedRows[] = $renderCodeRow($codeRow, $rowTemplate, !$renderEanImages || $index === 0);
            }

            return ($sharedHeader !== '' ? $renderCodeRow($codeRows[0], $sharedHeader) : '') . implode('', $renderedRows);
        }, $template);
    }

    /**
     * @param array<int, array{pickup_code: string, kwota: string, kod_ean: bool, code_128: bool}> $codeRows
     */
    private function hasBarcodeCodeRows(array $codeRows): bool
    {
        foreach ($codeRows as $codeRow) {
            if ($this->isTruthyValue($codeRow['kod_ean'] ?? false) || $this->isTruthyValue($codeRow['code_128'] ?? false)) {
                return true;
            }
        }

        return false;
    }
}
