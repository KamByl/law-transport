<?php

namespace App;

use App\Debug\DebugCollector;
use App\Debug\SqlDebugLogger;
use App\Entity\ErrorLog;
use App\Service\AuthService;
use App\Service\CsrfService;
use App\Service\Mailer;
use App\Service\PermissionService;
use App\Service\SmsApiClient;
use App\Service\SystemSettingsProvider;
use App\Twig\UrlExtension;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Throwable;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class Bootstrap
{
    private array $config;
    private Environment $twig;
    private EntityManager $entityManager;
    private AuthService $authService;
    private CsrfService $csrfService;
    private PermissionService $permissionService;
    private Mailer $mailer;
    private SmsApiClient $smsApiClient;
    private SystemSettingsProvider $systemSettingsProvider;
    private UrlExtension $urlExtension;

    public function __construct()
    {
        $this->config = require dirname(__DIR__) . '/config/app.php';
        date_default_timezone_set($this->config['app']['timezone']);

        $this->urlExtension = new UrlExtension();
        $this->twig = $this->createTwig();
        $this->entityManager = $this->createEntityManager();
        $this->authService = new AuthService($this->entityManager, $this->config['security']['session_name']);
        $this->csrfService = new CsrfService();
        $this->permissionService = new PermissionService($this->entityManager);
        $this->systemSettingsProvider = new SystemSettingsProvider($this->entityManager);
        $this->mailer = new Mailer($this->config['mail'], $this->systemSettingsProvider, $this->entityManager);
        $this->smsApiClient = new SmsApiClient($this->systemSettingsProvider);
    }

    public function handle(): Response
    {
        return $this->handleRequest(Request::createFromGlobals());
    }

    public function handleRequest(Request $request): Response
    {
        $this->attachNativeSessionToRequest($request);

        $debugEnabled = $this->permissionService->can($this->authService->user(), 'debug_tools', PermissionService::ACTION_VIEW);
        DebugCollector::startRequest($debugEnabled);
        $this->configureSqlDebugLogger($debugEnabled);

        $this->mailer->setBaseUrlFromRequest($request);
        $this->mailer->setRequestContext($request, $this->authService->user());
        $this->urlExtension->setRequest($request);
        $context = (new RequestContext())->fromRequest($request);

        $routes = require dirname(__DIR__) . '/config/routes.php';
        $matcher = new UrlMatcher($routes, $context);

        try {
            $parameters = $matcher->match($request->getPathInfo());
            $controllerSpec = $parameters['_controller'] ?? null;

            if (!$controllerSpec) {
                return $this->applySecurityHeaders($request, new Response('Controller not found', Response::HTTP_NOT_FOUND));
            }

            [$class, $method] = explode('::', $controllerSpec);

            if ($this->shouldShowMaintenancePage($class, $request)) {
                return $this->applySecurityHeaders($request, $this->renderMaintenancePage());
            }

            if ($this->isAdminCsrfProtectionRequired($request) && !$this->csrfService->isTokenValid($request)) {
                return $this->applySecurityHeaders($request, new Response('Nieprawidłowy token CSRF.', Response::HTTP_FORBIDDEN));
            }

            if ($this->isAutomationRequest($request) && !$this->isAutomationRequestAuthorized($request)) {
                return $this->applySecurityHeaders($request, new Response('Brak autoryzacji do endpointu automatyzacji.', Response::HTTP_FORBIDDEN));
            }

            $controller = new $class(
                $this->twig,
                $this->entityManager,
                $this->authService,
                $this->permissionService,
                $this->mailer,
                $this->smsApiClient,
                $this->csrfService,
                $this->config
            );

            $response = $controller->$method($request, $parameters);
            DebugCollector::storeRedirectPayload(
                (string) ($response->headers->get('Location') ?? $request->getRequestUri()),
                $response->getStatusCode()
            );

            return $this->applySecurityHeaders($request, $response);
        } catch (MethodNotAllowedException $exception) {
            $allowed = $exception->getAllowedMethods();
            $this->logError($exception, $request, Response::HTTP_METHOD_NOT_ALLOWED);

            $response = $this->renderErrorPage(
                Response::HTTP_METHOD_NOT_ALLOWED,
                'error_405_title',
                'error_405_message',
                'Metoda niedozwolona',
                'Ta metoda żądania nie jest dozwolona dla wybranego adresu.'
            );
            $response->headers->set('Allow', implode(', ', $allowed));

            return $this->applySecurityHeaders($request, $response);
        } catch (ResourceNotFoundException $exception) {
            $this->logError($exception, $request, Response::HTTP_NOT_FOUND);

            return $this->applySecurityHeaders($request, $this->renderErrorPage(
                Response::HTTP_NOT_FOUND,
                'error_404_title',
                'error_404_message',
                'Strona nie została znaleziona',
                'Adres, którego szukasz, nie istnieje lub został przeniesiony.'
            ));
        } catch (AccessDeniedException $exception) {
            $this->logError($exception, $request, Response::HTTP_FORBIDDEN);

            return $this->applySecurityHeaders($request, $this->renderErrorPage(
                Response::HTTP_FORBIDDEN,
                'error_403_title',
                'error_403_message',
                'Brak dostępu',
                'Nie masz uprawnień, aby wyświetlić tę stronę.'
            ));
        } catch (HttpExceptionInterface $exception) {
            $statusCode = $exception->getStatusCode();
            $this->logError($exception, $request, $statusCode);

            if ($statusCode === Response::HTTP_NOT_FOUND) {
                return $this->applySecurityHeaders($request, $this->renderErrorPage(
                    Response::HTTP_NOT_FOUND,
                    'error_404_title',
                    'error_404_message',
                    'Strona nie została znaleziona',
                    'Adres, którego szukasz, nie istnieje lub został przeniesiony.'
                ));
            }

            if ($statusCode === Response::HTTP_FORBIDDEN) {
                return $this->applySecurityHeaders($request, $this->renderErrorPage(
                    Response::HTTP_FORBIDDEN,
                    'error_403_title',
                    'error_403_message',
                    'Brak dostępu',
                    'Nie masz uprawnień, aby wyświetlić tę stronę.'
                ));
            }

            if ($statusCode === Response::HTTP_METHOD_NOT_ALLOWED) {
                $response = $this->renderErrorPage(
                    Response::HTTP_METHOD_NOT_ALLOWED,
                    'error_405_title',
                    'error_405_message',
                    'Metoda niedozwolona',
                    'Ta metoda żądania nie jest dozwolona dla wybranego adresu.'
                );
                if (method_exists($exception, 'getHeaders')) {
                    $headers = $exception->getHeaders();
                    if (isset($headers['Allow'])) {
                        $response->headers->set('Allow', (string) $headers['Allow']);
                    }
                }

                return $this->applySecurityHeaders($request, $response);
            }

            return $this->applySecurityHeaders($request, $this->renderErrorPage(
                $statusCode,
                'error_500_title',
                'error_500_message',
                'Wystąpił błąd serwera',
                'Przepraszamy, spróbuj ponownie za chwilę.'
            ));
        } catch (Throwable $exception) {
            $this->logError($exception, $request, Response::HTTP_INTERNAL_SERVER_ERROR);

            return $this->applySecurityHeaders($request, $this->renderErrorPage(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'error_500_title',
                'error_500_message',
                'Wystąpił błąd serwera',
                'Przepraszamy, spróbuj ponownie za chwilę.'
            ));
        }
    }

    private function attachNativeSessionToRequest(Request $request): void
    {
        if ($request->hasSession() || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $request->setSession(new Session(new NativeSessionStorage()));
    }



    private function applySecurityHeaders(Request $request, Response $response): Response
    {
        $headers = $this->config['security']['headers'] ?? [];

        if (($headers['x_frame_options'] ?? '') !== '') {
            $response->headers->set('X-Frame-Options', (string) $headers['x_frame_options']);
        }

        if (($headers['x_content_type_options'] ?? '') !== '') {
            $response->headers->set('X-Content-Type-Options', (string) $headers['x_content_type_options']);
        }

        if (($headers['referrer_policy'] ?? '') !== '') {
            $response->headers->set('Referrer-Policy', (string) $headers['referrer_policy']);
        }

        if (($headers['permissions_policy'] ?? '') !== '') {
            $response->headers->set('Permissions-Policy', (string) $headers['permissions_policy']);
        }

        if (($headers['cross_origin_opener_policy'] ?? '') !== '') {
            $response->headers->set('Cross-Origin-Opener-Policy', (string) $headers['cross_origin_opener_policy']);
        }

        if (($headers['cross_origin_resource_policy'] ?? '') !== '') {
            $response->headers->set('Cross-Origin-Resource-Policy', (string) $headers['cross_origin_resource_policy']);
        }

        if ($request->isSecure() && ($headers['strict_transport_security'] ?? '') !== '') {
            $response->headers->set('Strict-Transport-Security', (string) $headers['strict_transport_security']);
        }

        if (($headers['content_security_policy'] ?? '') !== '') {
            $response->headers->set('Content-Security-Policy', (string) $headers['content_security_policy']);
        }

        return $response;
    }


    private function isAutomationRequest(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/cron/');
    }

    private function isAutomationRequestAuthorized(Request $request): bool
    {
        if ($this->isLoopbackRequest($request)) {
            return true;
        }

        $settings = $this->systemSettingsProvider->getSettings();
        $secret = trim((string) ($settings['automation_secret'] ?? ''));
        if ($secret === '') {
            return false;
        }

        $providedSecret = trim((string) ($request->headers->get('X-Automation-Secret') ?? $request->query->get('secret', '')));
        if ($providedSecret === '') {
            return false;
        }

        return hash_equals($secret, $providedSecret);
    }

    private function isLoopbackRequest(Request $request): bool
    {
        $clientIp = (string) $request->getClientIp();
        if (in_array($clientIp, ['127.0.0.1', '::1'], true)) {
            return true;
        }

        $host = mb_strtolower(trim((string) $request->getHost()));

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function shouldShowMaintenancePage(string $controllerClass, Request $request): bool
    {
        if ($controllerClass !== 'App\Controller\FrontendController') {
            return false;
        }

        if (str_starts_with($request->getPathInfo(), '/cron/')) {
            return false;
        }

        if ($this->authService->requireAuth()) {
            return false;
        }

        $settings = $this->systemSettingsProvider->getSettings();

        return filter_var($settings['maintenance_mode_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function isAdminCsrfProtectionRequired(Request $request): bool
    {
        if (!$request->isMethod('POST')) {
            return false;
        }

        return str_starts_with($request->getPathInfo(), '/cms');
    }

    private function renderMaintenancePage(): Response
    {
        $settings = $this->systemSettingsProvider->getSettings();
        $appName = trim((string) ($settings['app_name'] ?? ''));
        $logoPath = trim((string) ($settings['error_page_logo'] ?? ''));

        if ($appName === '') {
            $appName = (string) ($this->config['app']['name'] ?? 'CMS2');
        }

        return new Response(
            $this->twig->render('frontend/maintenance.html.twig', [
                'title' => 'Strona w budowie',
                'appName' => $appName,
                'logoPath' => $logoPath,
                'siteSettings' => $this->fetchSiteSettings(),
            ]),
            Response::HTTP_SERVICE_UNAVAILABLE
        );
    }


    private function renderErrorPage(
        int $statusCode,
        string $titleSettingKey,
        string $messageSettingKey,
        string $defaultTitle,
        string $defaultMessage
    ): Response {
        $settings = $this->systemSettingsProvider->getSettings();
        $appName = trim((string) ($settings['app_name'] ?? ''));

        if ($appName === '') {
            $appName = (string) ($this->config['app']['name'] ?? 'CMS2');
        }

        $title = trim((string) ($settings[$titleSettingKey] ?? ''));
        $message = trim((string) ($settings[$messageSettingKey] ?? ''));
        $generalMessage = trim((string) ($settings['error_page_general_message'] ?? ''));
        $logoPath = trim((string) ($settings['error_page_logo'] ?? ''));

        if ($title === '') {
            $title = $defaultTitle;
        }

        if ($message === '') {
            $message = $defaultMessage;
        }

        return new Response(
            $this->twig->render('frontend/error.html.twig', [
                'title' => $title,
                'message' => $message,
                'generalMessage' => $generalMessage,
                'logoPath' => $logoPath,
                'appName' => $appName,
                'siteSettings' => $this->fetchSiteSettings(),
            ]),
            $statusCode
        );
    }


    /**
     * @return array<string, mixed>
     */
    private function fetchSiteSettings(): array
    {
        try {
            $connection = $this->entityManager->getConnection();
            $schemaManager = $connection->createSchemaManager();

            if (!$schemaManager->tablesExist(['site_settings'])) {
                return [];
            }

            $settings = $connection
                ->createQueryBuilder()
                ->select('*')
                ->from('site_settings')
                ->orderBy('id', 'asc')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            return is_array($settings) ? $settings : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function logError(Throwable $exception, Request $request, int $statusCode): void
    {
        if ($statusCode === Response::HTTP_NOT_FOUND && $request->getPathInfo() === '/meta.json') {
            return;
        }

        try {
            $errorLog = new ErrorLog();
            $errorLog->setUser($this->authService->user());
            $errorLog->setStatusCode($statusCode);
            $errorLog->setExceptionClass($exception::class);
            $errorLog->setExceptionMessage($exception->getMessage());
            $errorLog->setUrl($request->getUri());
            $errorLog->setMethod($request->getMethod());
            $errorLog->setIpAddress($request->getClientIp());
            $errorLog->setUserAgent($request->headers->get('User-Agent'));
            $errorLog->setStackTrace($exception->getTraceAsString());
            $errorLog->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($errorLog);
            $this->entityManager->flush();
        } catch (Throwable $logException) {
            $this->logErrorToFile($exception, $request, $statusCode, $logException);
        }
    }

    private function logErrorToFile(Throwable $exception, Request $request, int $statusCode, Throwable $logException): void
    {
        $logDir = dirname(__DIR__) . '/var/log';
        $logFile = $logDir . '/application-error.log';

        if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
            return;
        }

        $timestamp = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $clientIp = $request->getClientIp() ?? '-';
        $method = $request->getMethod();
        $uri = $request->getUri();

        $message = sprintf(
            "[%s] status=%d ip=%s method=%s url=%s exception=%s message=%s log_exception=%s log_message=%s
",
            $timestamp,
            $statusCode,
            $clientIp,
            $method,
            $uri,
            $exception::class,
            $exception->getMessage(),
            $logException::class,
            $logException->getMessage()
        );

        $message .= sprintf("Trace:
%s

", $exception->getTraceAsString());

        @file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
    }


    private function configureSqlDebugLogger(bool $enabled): void
    {
        $configuration = $this->entityManager->getConnection()->getConfiguration();

        if (!method_exists($configuration, 'setSQLLogger')) {
            return;
        }

        $configuration->setSQLLogger($enabled ? new SqlDebugLogger() : null);
    }

    private function createTwig(): Environment
    {
        $loader = new FilesystemLoader(dirname(__DIR__) . '/templates');
        $twigConfig = $this->config['twig'] ?? [];
        $cacheEnabled = (bool) ($twigConfig['cache_enabled'] ?? false);
        $cacheDir = $twigConfig['cache_dir'] ?? dirname(__DIR__) . '/var/cache/twig';

        if ($cacheEnabled && !is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            throw new RuntimeException(sprintf('Nie udało się utworzyć katalogu cache Twig: %s', $cacheDir));
        }

        $twig = new Environment($loader, [
            'cache' => $cacheEnabled ? $cacheDir : false,
        ]);

        $twig->addExtension($this->urlExtension);

        return $twig;
    }

    private function createEntityManager(): EntityManager
    {
        $config = require dirname(__DIR__) . '/config/doctrine.php';
        $paths = $config['paths'];

        $ormConfig = ORMSetup::createAttributeMetadataConfiguration(
            $paths,
            $config['dev_mode'],
            $config['proxy_dir']
        );

        return EntityManager::create($config['db'], $ormConfig);
    }
}
