<?php

namespace App\Controller;

use App\Entity\ErrorLog;
use App\Service\FtpMediaStorage;
use App\Service\ModuleDefinitionLoader;
use App\Service\SystemSettingsProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

class FrontendController extends BaseController
{
    public function metaJson(Request $request): Response
    {
        $metaPath = dirname(__DIR__, 2) . '/public_html/meta.json';
        if (!is_file($metaPath)) {
            return new JsonResponse([
                'error' => 'meta.json not found',
            ], Response::HTTP_NOT_FOUND);
        }

        $content = (string) file_get_contents($metaPath);

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    public function home(Request $request): Response
    {
        return new RedirectResponse('/cms');
    }

    public function downloadPrettyFile(Request $request, array $parameters = []): Response
    {
        $slug = trim((string) ($parameters['slug'] ?? ''));
        if ($slug === '') {
            throw $this->createNotFoundException('Nie znaleziono pliku.');
        }

        $filesBySlug = $this->buildPrettyFilesMap();
        $relativePath = $filesBySlug[$slug] ?? '';
        if ($relativePath === '') {
            throw $this->createNotFoundException('Nie znaleziono pliku.');
        }

        $absolutePath = $this->resolvePublicFileAbsolutePath($relativePath);
        if ($absolutePath === null) {
            throw $this->createNotFoundException('Nie znaleziono pliku.');
        }

        return $this->createInlineFileResponse($absolutePath);
    }

    public function runConfiguredCronTasks(Request $request): Response
    {
        $connection = $this->entityManager->getConnection();
        $table = 'cron_entries';

        if (!$connection->createSchemaManager()->tablesExist([$table])) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Tabela CRON nie istnieje. Uruchom instalację/aktualizację DB w CMS.',
            ], 500);
        }

        $this->seedDefaultCronTasks($table);
        $cronColumns = array_keys($connection->createSchemaManager()->listTableColumns($table));

        $now = new \DateTimeImmutable();
        $rows = $connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where('enabled = :enabled')
            ->andWhere('(next_run_at IS NULL OR next_run_at <= :now OR last_run_at IS NULL)')
            ->andWhere('(running IS NULL OR running = 0 OR locked_at < :staleLock)')
            ->orderBy('next_run_at', 'ASC')
            ->setParameter('enabled', 1)
            ->setParameter('now', $now->format('Y-m-d H:i:s'))
            ->setParameter('staleLock', $now->sub(new \DateInterval('PT30M'))->format('Y-m-d H:i:s'))
            ->executeQuery()
            ->fetchAllAssociative();

        $results = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $claimed = $connection->createQueryBuilder()
                ->update($table)
                ->set('running', '1')
                ->set('locked_at', ':locked_at')
                ->where('id = :id')
                ->andWhere('(next_run_at IS NULL OR next_run_at <= :now OR last_run_at IS NULL)')
                ->andWhere('(running IS NULL OR running = 0 OR locked_at < :stale_lock)')
                ->setParameter('locked_at', $now->format('Y-m-d H:i:s'))
                ->setParameter('now', $now->format('Y-m-d H:i:s'))
                ->setParameter('stale_lock', $now->sub(new \DateInterval('PT30M'))->format('Y-m-d H:i:s'))
                ->setParameter('id', $id)
                ->executeStatement();
            if ($claimed !== 1) {
                continue;
            }

            $result = $this->executeConfiguredCronTask($request, $row);
            $intervalMinutes = max(1, (int) ($row['interval_minutes'] ?? 5));
            $nextRunAt = (new \DateTimeImmutable())->add(new \DateInterval(sprintf('PT%dM', $intervalMinutes)));

            $cronUpdate = [
                'running' => 0,
                'locked_at' => null,
                'last_run_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'next_run_at' => $nextRunAt->format('Y-m-d H:i:s'),
                'last_status' => $result['success'] ? 'OK' : 'ERROR',
                'last_message' => mb_substr($result['message'], 0, 1000),
            ];
            if (in_array('remaining_items', $cronColumns, true) && $result['remainingItems'] !== null) {
                $cronUpdate['remaining_items'] = $result['remainingItems'];
            }
            $connection->update($table, $cronUpdate, ['id' => $id]);

            $results[] = $result + [
                'id' => $id,
                'name' => (string) ($row['name'] ?? ''),
                'nextRunAt' => $nextRunAt->format('Y-m-d H:i:s'),
            ];
        }

        return new JsonResponse([
            'success' => true,
            'checkedAt' => $now->format('Y-m-d H:i:s'),
            'executed' => count($results),
            'results' => $results,
        ]);
    }

    public function processEmailQueue(Request $request): Response
    {
        if (!$this->isAutomationRequestAuthorized($request)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Brak autoryzacji do uruchomienia kolejki e-mail.',
            ], 403);
        }

        $limit = max(1, (int) $request->query->get('limit', 20));
        $delaySeconds = max(0, (int) $request->query->get('delaySeconds', 0));
        $result = $this->mailer->processQueue($limit, $delaySeconds);

        return new JsonResponse([
            'success' => true,
            'processed' => $result['processed'],
            'sent' => $result['sent'],
            'failed' => $result['failed'],
            'pending' => $result['pending'],
        ]);
    }

    public function sendErrorSummary(Request $request): Response
    {
        if (!$this->isAutomationRequestAuthorized($request)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Brak autoryzacji do wysyłki podsumowania błędów.',
            ], 403);
        }

        if (!$this->isErrorEmailNotificationEnabled()) {
            return new JsonResponse([
                'success' => true,
                'message' => 'Powiadomienia e-mail o błędach są wyłączone.',
                'sent' => false,
                'count' => 0,
            ]);
        }

        $adminEmail = trim((string) ($this->config['mail']['admin'] ?? ''));
        if ($adminEmail === '') {
            return new JsonResponse([
                'success' => false,
                'message' => 'Brak adresu e-mail administratora w konfiguracji.',
            ], 500);
        }

        $windowMinutes = max(1, (int) $request->query->get('windowMinutes', 15));
        $to = new \DateTimeImmutable();
        $from = $to->sub(new \DateInterval(sprintf('PT%dM', $windowMinutes)));

        $logsQueryBuilder = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(ErrorLog::class, 'e')
            ->where('e.createdAt >= :from')
            ->andWhere('e.createdAt < :to')
            ->orderBy('e.createdAt', 'DESC')
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        if ($this->isError404ExcludedFromSummary()) {
            $logsQueryBuilder
                ->andWhere('e.statusCode != :status404')
                ->setParameter('status404', 404);
        }

        $logs = $logsQueryBuilder
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        if ($logs === []) {
            return new JsonResponse([
                'success' => true,
                'message' => sprintf('Brak błędów z ostatnich %d minut.', $windowMinutes),
                'sent' => false,
                'count' => 0,
            ]);
        }

        $countsByStatus = [];
        $itemsHtml = [];

        foreach ($logs as $log) {
            if (!$log instanceof ErrorLog) {
                continue;
            }

            $statusCode = $log->getStatusCode();
            $countsByStatus[$statusCode] = ($countsByStatus[$statusCode] ?? 0) + 1;
            $itemsHtml[] = sprintf(
                '<li><strong>%s</strong> [%d] %s %s<br><small>IP: %s | %s</small></li>',
                htmlspecialchars($log->getCreatedAt()?->format('Y-m-d H:i:s') ?? '-', ENT_QUOTES, 'UTF-8'),
                $statusCode,
                htmlspecialchars((string) $log->getMethod(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $log->getUrl(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $log->getIpAddress(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $log->getExceptionMessage(), ENT_QUOTES, 'UTF-8')
            );
        }

        if ($itemsHtml === []) {
            return new JsonResponse([
                'success' => true,
                'message' => 'Brak poprawnych wpisów błędów do podsumowania.',
                'sent' => false,
                'count' => 0,
            ]);
        }

        ksort($countsByStatus);
        $statusSummary = [];
        foreach ($countsByStatus as $statusCode => $count) {
            $statusSummary[] = sprintf('%d: %d', (int) $statusCode, (int) $count);
        }

        $appName = (string) ($this->config['app']['name'] ?? 'CMS2');
        $subject = sprintf(
            '[%s] Podsumowanie błędów (%d min): %d',
            $appName,
            $windowMinutes,
            count($itemsHtml)
        );

        $body = sprintf(
            '<h2>Podsumowanie błędów z ostatnich %d minut</h2>
            <p><strong>Zakres:</strong> %s - %s</p>
            <p><strong>Liczba błędów:</strong> %d</p>
            <p><strong>Statusy:</strong> %s</p>
            <h3>Ostatnie wpisy</h3>
            <ol>%s</ol>',
            $windowMinutes,
            htmlspecialchars($from->format('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($to->format('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8'),
            count($itemsHtml),
            htmlspecialchars(implode(', ', $statusSummary), ENT_QUOTES, 'UTF-8'),
            implode('', $itemsHtml)
        );

        $result = $this->mailer->sendWithError($adminEmail, $subject, $body, false, null, true);
        if (!($result['success'] ?? false)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Nie udało się wysłać podsumowania błędów.',
                'error' => $result['error'] ?? null,
            ], 500);
        }

        return new JsonResponse([
            'success' => true,
            'sent' => true,
            'count' => count($itemsHtml),
            'windowMinutes' => $windowMinutes,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{commandKey: string, success: bool, httpStatus: int, message: string, remainingItems: int|null}
     */
    private function executeConfiguredCronTask(Request $baseRequest, array $row): array
    {
        $commandKey = trim((string) ($row['command_key'] ?? ''));
        $task = $this->getCronTaskDefinitions()[$commandKey] ?? null;
        if ($task === null) {
            return [
                'commandKey' => $commandKey,
                'success' => false,
                'httpStatus' => 0,
                'message' => 'Nieznana komenda CRON.',
                'remainingItems' => null,
            ];
        }

        parse_str(str_replace(["\r\n", "\r", "\n"], '&', trim((string) ($row['parameters'] ?? ''))), $parameters);
        $parameters = is_array($parameters) ? $parameters : [];
        if ($commandKey === 'email_queue') {
            $emailQueueLimit = (int) ($row['email_queue_limit'] ?? 0);
            if ($emailQueueLimit > 0) {
                $parameters['limit'] = $emailQueueLimit;
            }

            $parameters['delaySeconds'] = max(0, (int) ($row['email_queue_delay_seconds'] ?? 0));
        }

        $path = (string) $task['path'];
        $query = array_merge($baseRequest->query->all(), $parameters);
        unset($query['secret']);

        $taskRequest = Request::create($path . ($query !== [] ? '?' . http_build_query($query) : ''), 'GET', [], [], [], [
            'HTTP_HOST' => $baseRequest->getHost() ?: 'localhost',
            'REQUEST_SCHEME' => $baseRequest->getScheme(),
            'HTTPS' => $baseRequest->isSecure() ? 'on' : 'off',
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        try {
            $maxRuns = 1;
            $success = true;
            $httpStatus = 200;
            $messages = [];
            $remainingItems = null;

            for ($run = 1; $run <= $maxRuns; $run++) {
                $response = $this->{$task['method']}($taskRequest);
                $httpStatus = $response->getStatusCode();
                $payload = json_decode($response->getContent(), true);
                $runSuccess = $httpStatus < 400 && (!is_array($payload) || (bool) ($payload['success'] ?? true));
                $messages[] = is_array($payload)
                    ? (string) ($payload['message'] ?? json_encode($payload, JSON_UNESCAPED_UNICODE))
                    : $response->getContent();
                if (is_array($payload) && array_key_exists('remaining', $payload)) {
                    $remainingItems = max(0, (int) $payload['remaining']);
                } elseif (is_array($payload) && array_key_exists('pending', $payload)) {
                    $remainingItems = max(0, (int) $payload['pending']);
                }
                $success = $success && $runSuccess;

                if (!$runSuccess) {
                    break;
                }
            }

            return [
                'commandKey' => $commandKey,
                'success' => $success,
                'httpStatus' => $httpStatus,
                'message' => mb_substr(implode(' | ', array_filter($messages)), 0, 1000),
                'remainingItems' => $remainingItems,
            ];
        } catch (\Throwable $exception) {
            return [
                'commandKey' => $commandKey,
                'success' => false,
                'httpStatus' => 500,
                'message' => mb_substr($exception->getMessage(), 0, 1000),
                'remainingItems' => null,
            ];
        }
    }

    private function seedDefaultCronTasks(string $table): void
    {
        $connection = $this->entityManager->getConnection();
        $existing = (int) $connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($table)
            ->executeQuery()
            ->fetchOne();

        $existingCommandKeys = [];
        if ($existing > 0) {
            $rows = $connection->createQueryBuilder()
                ->select('command_key')
                ->from($table)
                ->executeQuery()
                ->fetchFirstColumn();
            $existingCommandKeys = array_fill_keys(array_map('strval', $rows), true);
        }

        foreach ($this->getCronTaskDefinitions() as $commandKey => $task) {
            if (isset($existingCommandKeys[$commandKey])) {
                continue;
            }
            $connection->insert($table, [
                'name' => $task['name'],
                'command_key' => $commandKey,
                'enabled' => (int) $task['enabled'],
                'interval_minutes' => $task['interval'],
                'parameters' => $task['parameters'],
                'email_queue_limit' => $task['email_queue_limit'] ?? null,
                'email_queue_delay_seconds' => $task['email_queue_delay_seconds'] ?? null,
                'next_run_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'last_status' => 'NEW',
                'running' => 0,
            ]);
        }
    }

    public function processFtpMediaOffload(Request $request): Response
    {
        if (!$this->isAutomationRequestAuthorized($request)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Brak autoryzacji do przenoszenia mediów na FTP.',
            ], 403);
        }

        $storage = new FtpMediaStorage((new SystemSettingsProvider($this->entityManager))->getFtpConfig());
        if (!$storage->isConfigured()) {
            return new JsonResponse([
                'success' => true,
                'message' => 'Przenoszenie mediów na FTP jest wyłączone albo konfiguracja FTP jest niepełna.',
                'processed' => 0,
                'moved' => 0,
                'failed' => 0,
            ]);
        }

        $limit = max(1, (int) $request->query->get('limit', 10));
        $rootDir = dirname(__DIR__, 2);
        $connection = $this->entityManager->getConnection();
        $schemaManager = $connection->createSchemaManager();
        $processed = 0;
        $moved = 0;
        $failed = 0;

        foreach ((new ModuleDefinitionLoader())->getInstallDefinitions() as $definition) {
            if ($processed >= $limit) {
                break;
            }

            $moduleKey = trim((string) ($definition['key'] ?? ''));
            $table = trim((string) ($definition['database']['table'] ?? ''));
            $fields = $definition['fields'] ?? ($definition['module']['fields'] ?? []);
            if ($moduleKey === '' || $table === '' || !is_array($fields) || !$schemaManager->tablesExist([$table])) {
                continue;
            }

            $columns = $schemaManager->listTableColumns($table);
            foreach ($fields as $field) {
                if ($processed >= $limit) {
                    break 2;
                }
                if (!is_array($field) || !($field['external_ftp'] ?? false)) {
                    continue;
                }

                $fieldName = trim((string) ($field['name'] ?? ''));
                if ($fieldName === '' || !array_key_exists(strtolower($fieldName), $columns)) {
                    continue;
                }

                $rows = $connection->createQueryBuilder()
                    ->select($fieldName)
                    ->from($table)
                    ->where($fieldName . ' IS NOT NULL')
                    ->andWhere($fieldName . " != ''")
                    ->setMaxResults($limit - $processed)
                    ->executeQuery()
                    ->fetchFirstColumn();

                foreach ($rows as $storedPath) {
                    $filename = $this->extractModuleUploadFilename((string) $storedPath, $moduleKey);
                    if ($filename === null) {
                        continue;
                    }

                    $localPath = $rootDir . '/var/uploads/modules/' . $moduleKey . '/' . $filename;
                    if (!is_file($localPath)) {
                        continue;
                    }

                    $processed++;
                    if ($storage->uploadAndRemoveLocal($localPath, 'modules/' . $moduleKey . '/' . $filename)) {
                        $moved++;
                    } else {
                        $failed++;
                    }

                    if ($processed >= $limit) {
                        break 2;
                    }
                }
            }
        }

        return new JsonResponse([
            'success' => $failed === 0,
            'message' => sprintf('Przetworzono %d plików, przeniesiono %d, błędy: %d.', $processed, $moved, $failed),
            'processed' => $processed,
            'moved' => $moved,
            'failed' => $failed,
        ], $failed === 0 ? 200 : 500);
    }

    private function extractModuleUploadFilename(string $storedPath, string $moduleKey): ?string
    {
        $path = parse_url($storedPath, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $prefix = '/media/modules/' . rawurlencode($moduleKey) . '/';
        if (!str_starts_with($path, $prefix)) {
            return null;
        }

        $filename = basename(rawurldecode(substr($path, strlen($prefix))));
        if ($filename === '' || str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return null;
        }

        return $filename;
    }

    /**
     * @return array<string, array{name: string, path: string, method: string, enabled: bool, interval: int, parameters: string, email_queue_limit?: int, email_queue_delay_seconds?: int}>
     */
    private function getCronTaskDefinitions(): array
    {
        return [
            'email_queue' => [
                'name' => 'Kolejka e-maili',
                'path' => '/internal/cron/email-queue/process',
                'method' => 'processEmailQueue',
                'enabled' => true,
                'interval' => 2,
                'parameters' => '',
                'email_queue_limit' => 20,
                'email_queue_delay_seconds' => 0,
            ],
            'error_summary' => [
                'name' => 'Podsumowanie błędów',
                'path' => '/internal/cron/error-summary/send',
                'method' => 'sendErrorSummary',
                'enabled' => true,
                'interval' => 15,
                'parameters' => 'windowMinutes=15',
            ],
            'ftp_media_offload' => [
                'name' => 'Przenoszenie mediów na FTP',
                'path' => '/internal/cron/ftp-media/offload',
                'method' => 'processFtpMediaOffload',
                'enabled' => true,
                'interval' => 5,
                'parameters' => 'limit=10',
            ],
        ];
    }

    private function isErrorEmailNotificationEnabled(): bool
    {
        $settings = $this->fetchModuleEntry('system_settings') ?? [];

        if (!array_key_exists('error_email_notifications_enabled', $settings)) {
            return true;
        }

        return filter_var($settings['error_email_notifications_enabled'], FILTER_VALIDATE_BOOLEAN);
    }

    private function isError404ExcludedFromSummary(): bool
    {
        $settings = $this->fetchModuleEntry('system_settings') ?? [];

        if (!array_key_exists('error_email_skip_404', $settings)) {
            return false;
        }

        return filter_var($settings['error_email_skip_404'], FILTER_VALIDATE_BOOLEAN);
    }

    private function isAutomationRequestAuthorized(Request $request): bool
    {
        $ipAddress = trim((string) $request->getClientIp());
        if ($ipAddress === '127.0.0.1' || $ipAddress === '::1') {
            return true;
        }

        $settings = $this->fetchModuleEntry('system_settings') ?? [];
        $expectedSecret = trim((string) ($settings['automation_secret'] ?? ''));
        if ($expectedSecret === '') {
            return false;
        }

        $providedSecret = trim((string) $request->headers->get('X-Automation-Secret', ''));
        if ($providedSecret === '') {
            $providedSecret = trim((string) $request->query->get('secret', ''));
        }

        return $providedSecret !== '' && hash_equals($expectedSecret, $providedSecret);
    }

    private function slugify(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $map = [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
        ];
        $value = strtr($value, $map);
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';

        return trim($value, '-');
    }

    /**
     * @return array<string, string>
     */
    private function buildPrettyFilesMap(): array
    {
        $entries = $this->fetchModuleEntries('pliki');
        $seenSlugs = [];
        $filesBySlug = [];

        foreach ($entries as $entry) {
            $relativePath = trim((string) ($entry['plik'] ?? ''));
            if ($relativePath === '') {
                continue;
            }

            $slug = $this->buildPrettyFileSlug($entry, $seenSlugs);
            $filesBySlug[$slug] = $relativePath;
        }

        return $filesBySlug;
    }

    /**
     * @param array<string, int> $seenSlugs
     */
    private function buildPrettyFileSlug(array $fileEntry, array &$seenSlugs): string
    {
        $relativePath = trim((string) ($fileEntry['plik'] ?? ''));
        $path = (string) parse_url($relativePath, PHP_URL_PATH);
        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $nameFromCms = trim((string) ($fileEntry['nazwa'] ?? ''));
        $fallbackName = pathinfo($path, PATHINFO_FILENAME);
        $baseName = $this->slugify($nameFromCms !== '' ? $nameFromCms : (string) $fallbackName);
        if ($baseName === '') {
            $baseName = 'plik';
        }

        $candidate = $baseName;
        if ($extension !== '') {
            $candidate .= '.' . $extension;
        }

        $occurrences = ($seenSlugs[$candidate] ?? 0) + 1;
        $seenSlugs[$candidate] = $occurrences;
        if ($occurrences <= 1) {
            return $candidate;
        }

        if ($extension === '') {
            return sprintf('%s-%d', $baseName, $occurrences);
        }

        return sprintf('%s-%d.%s', $baseName, $occurrences, $extension);
    }

    private function resolvePublicFileAbsolutePath(string $relativePath): ?string
    {
        return $this->resolveStoredFileAbsolutePath($relativePath);
    }

    private function resolveStoredFileAbsolutePath(string $relativePath): ?string
    {
        $relativePath = trim($relativePath);
        if ($relativePath === '') {
            return null;
        }

        $rootDir = dirname(__DIR__, 2);
        if (str_starts_with($relativePath, '/uploads/')) {
            $absolutePath = $rootDir . '/public_html' . $relativePath;

            return is_file($absolutePath) ? $absolutePath : null;
        }

        if (preg_match('#^/media/modules/([A-Za-z0-9_-]+)/([^/]+)$#', $relativePath, $matches) === 1) {
            $moduleKey = $matches[1];
            $filename = rawurldecode($matches[2]);
            if ($filename === '' || str_contains($filename, '..') || str_contains($filename, '\\')) {
                return null;
            }

            $storagePath = $rootDir . '/var/uploads/modules/' . $moduleKey . '/' . $filename;
            if (is_file($storagePath)) {
                return $storagePath;
            }

            $legacyPath = $rootDir . '/public_html/uploads/modules/' . $moduleKey . '/' . $filename;

            return is_file($legacyPath) ? $legacyPath : null;
        }

        return null;
    }
}
