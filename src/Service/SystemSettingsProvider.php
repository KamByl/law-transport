<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

class SystemSettingsProvider
{
    private EntityManagerInterface $entityManager;
    private ModuleDefinitionLoader $definitionLoader;
    private ?array $cachedSettings = null;
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $moduleSettingsCache = [];

    public function __construct(EntityManagerInterface $entityManager, ?ModuleDefinitionLoader $definitionLoader = null)
    {
        $this->entityManager = $entityManager;
        $this->definitionLoader = $definitionLoader ?? new ModuleDefinitionLoader();
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        if ($this->cachedSettings !== null) {
            return $this->cachedSettings;
        }

        $this->cachedSettings = $this->getModuleSettings('system_settings');

        return $this->cachedSettings;
    }

    /**
     * @return array<string, mixed>
     */
    public function getModuleSettings(string $moduleKey): array
    {
        $moduleKey = trim($moduleKey);
        if ($moduleKey === '') {
            return [];
        }

        if (array_key_exists($moduleKey, $this->moduleSettingsCache)) {
            return $this->moduleSettingsCache[$moduleKey];
        }

        $table = $this->resolveTableName($moduleKey);
        if (!$table) {
            $this->moduleSettingsCache[$moduleKey] = [];

            return $this->moduleSettingsCache[$moduleKey];
        }

        $connection = $this->entityManager->getConnection();
        $schemaManager = $connection->createSchemaManager();
        if (!$schemaManager->tablesExist([$table])) {
            $this->moduleSettingsCache[$moduleKey] = [];

            return $this->moduleSettingsCache[$moduleKey];
        }

        $row = $connection
            ->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        $this->moduleSettingsCache[$moduleKey] = is_array($row) ? $row : [];

        return $this->moduleSettingsCache[$moduleKey];
    }

    /**
     * @return array{
     *     token: string,
     *     from: string,
     *     endpoint: string,
     *     timeout: ?int,
     *     test: bool,
     *     delivery_url: string,
     *     delivery_token: string
     * }
     */
    public function getSmsApiConfig(): array
    {
        $settings = $this->getSettings();
        $timeoutRaw = $settings['smsapi_timeout'] ?? null;
        $timeout = is_numeric($timeoutRaw) ? (int) $timeoutRaw : null;

        return [
            'token' => trim((string) ($settings['smsapi_token'] ?? '')),
            'from' => trim((string) ($settings['smsapi_from'] ?? '')),
            'endpoint' => trim((string) ($settings['smsapi_endpoint'] ?? '')),
            'timeout' => $timeout,
            'test' => filter_var($settings['smsapi_test'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'delivery_url' => trim((string) ($settings['smsapi_delivery_url'] ?? '')),
            'delivery_token' => trim((string) ($settings['smsapi_delivery_token'] ?? '')),
        ];
    }

    /**
     * @return array{enabled: bool, media_offload_enabled: bool, host: string, port: ?int, username: string, password: string, root_path: string, passive_mode: bool, ssl_enabled: bool, timeout: ?int}
     */
    public function getFtpConfig(): array
    {
        $settings = $this->getSettings();
        $portRaw = $settings['ftp_port'] ?? null;
        $timeoutRaw = $settings['ftp_timeout'] ?? null;

        return [
            'enabled' => filter_var($settings['ftp_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'media_offload_enabled' => filter_var($settings['ftp_media_offload_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'host' => trim((string) ($settings['ftp_host'] ?? '')),
            'port' => is_numeric($portRaw) ? (int) $portRaw : null,
            'username' => trim((string) ($settings['ftp_username'] ?? '')),
            'password' => trim((string) ($settings['ftp_password'] ?? '')),
            'root_path' => trim((string) ($settings['ftp_root_path'] ?? '')),
            'passive_mode' => filter_var($settings['ftp_passive_mode'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'ssl_enabled' => filter_var($settings['ftp_ssl_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'timeout' => is_numeric($timeoutRaw) ? (int) $timeoutRaw : null,
        ];
    }

    /**
     * @return array{host: string, port: ?int, username: string, password: string, encryption: string, from_email: string, from_name: string}
     */
    public function getSmtpConfig(): array
    {
        $settings = $this->getSettings();
        $portRaw = $settings['smtp_port'] ?? null;
        $port = is_numeric($portRaw) ? (int) $portRaw : null;

        return [
            'host' => trim((string) ($settings['smtp_host'] ?? '')),
            'port' => $port,
            'username' => trim((string) ($settings['smtp_username'] ?? '')),
            'password' => trim((string) ($settings['smtp_password'] ?? '')),
            'encryption' => trim((string) ($settings['smtp_encryption'] ?? '')),
            'from_email' => trim((string) ($settings['smtp_from_email'] ?? '')),
            'from_name' => trim((string) ($settings['smtp_from_name'] ?? '')),
        ];
    }

    /**
     * @return array{
     *     host: string,
     *     port: ?int,
     *     username: string,
     *     password: string,
     *     encryption: string,
     *     from_email: string,
     *     from_name: string,
     *     domains: string[]
     * }
     */
    public function getSecondarySmtpConfig(): array
    {
        $settings = $this->getSettings();
        $portRaw = $settings['smtp_secondary_port'] ?? null;
        $port = is_numeric($portRaw) ? (int) $portRaw : null;

        $rawDomains = (string) ($settings['smtp_secondary_domains'] ?? '');
        $domainItems = preg_split('/[\r\n,;]+/', $rawDomains) ?: [];
        $domains = [];

        foreach ($domainItems as $domainItem) {
            $domain = strtolower(trim((string) $domainItem));
            if ($domain === '') {
                continue;
            }

            if (str_starts_with($domain, '@')) {
                $domain = ltrim($domain, '@');
            }

            $domain = trim($domain, '.');
            if ($domain === '') {
                continue;
            }

            $domains[] = $domain;
        }

        return [
            'host' => trim((string) ($settings['smtp_secondary_host'] ?? '')),
            'port' => $port,
            'username' => trim((string) ($settings['smtp_secondary_username'] ?? '')),
            'password' => trim((string) ($settings['smtp_secondary_password'] ?? '')),
            'encryption' => trim((string) ($settings['smtp_secondary_encryption'] ?? '')),
            'from_email' => trim((string) ($settings['smtp_secondary_from_email'] ?? '')),
            'from_name' => trim((string) ($settings['smtp_secondary_from_name'] ?? '')),
            'domains' => array_values(array_unique($domains)),
        ];
    }

    /**
     * @return array{enabled: bool, retry_delay_seconds: int, max_attempts: int, blocked_domains: string[]}
     */
    public function getEmailQueueConfig(): array
    {
        $settings = $this->getSettings();

        $retryDelayRaw = $settings['email_queue_retry_delay_seconds'] ?? 120;
        $retryDelay = is_numeric($retryDelayRaw) ? (int) $retryDelayRaw : 120;
        if ($retryDelay < 5) {
            $retryDelay = 5;
        }

        $maxAttemptsRaw = $settings['email_queue_max_attempts'] ?? 5;
        $maxAttempts = is_numeric($maxAttemptsRaw) ? (int) $maxAttemptsRaw : 5;
        if ($maxAttempts <= 0) {
            $maxAttempts = 5;
        }

        $rawBlockedDomains = (string) ($settings['email_queue_blocked_domains'] ?? '');
        $blockedDomainItems = preg_split('/[\r\n,;]+/', $rawBlockedDomains) ?: [];
        $blockedDomains = [];
        foreach ($blockedDomainItems as $blockedDomainItem) {
            $domain = strtolower(trim((string) $blockedDomainItem));
            if ($domain === '') {
                continue;
            }

            if (str_starts_with($domain, '@')) {
                $domain = ltrim($domain, '@');
            }

            $domain = trim($domain, '.');
            if ($domain === '') {
                continue;
            }

            $blockedDomains[] = $domain;
        }

        $blockedDomains = array_values(array_unique($blockedDomains));

        return [
            'enabled' => filter_var($settings['email_queue_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'retry_delay_seconds' => $retryDelay,
            'max_attempts' => $maxAttempts,
            'blocked_domains' => $blockedDomains,
        ];
    }

    private function resolveTableName(string $moduleKey): ?string
    {
        foreach ($this->definitionLoader->getInstallDefinitions() as $definition) {
            if (($definition['key'] ?? null) !== $moduleKey) {
                continue;
            }

            $database = $definition['database'] ?? [];
            $table = $database['table'] ?? null;
            if (is_string($table) && $table !== '') {
                return $table;
            }
        }

        return null;
    }

}
