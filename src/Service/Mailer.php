<?php

namespace App\Service;

use App\Entity\EmailLog;
use App\Entity\EmailQueue;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

class Mailer
{
    private array $config;
    private SystemSettingsProvider $settingsProvider;
    private string $baseUrl = '';
    private ?EntityManagerInterface $entityManager;
    private ?string $requestIp = null;
    private ?string $requestUserAgent = null;
    private ?User $requestUser = null;

    /** @var array<int, array{cid: string, filename: string, content_type: string, data: string}> */
    private array $pendingInlineAttachments = [];
    private bool $emailQueueSchemaChecked = false;

    public function __construct(array $config, SystemSettingsProvider $settingsProvider, ?EntityManagerInterface $entityManager = null)
    {
        $this->config = $config;
        $this->settingsProvider = $settingsProvider;
        $this->entityManager = $entityManager;
    }

    public function setBaseUrlFromRequest(Request $request): void
    {
        $this->baseUrl = rtrim($request->getSchemeAndHttpHost(), '/');
    }

    public function setRequestContext(Request $request, ?User $user = null): void
    {
        $this->requestIp = $request->getClientIp();
        $this->requestUserAgent = $request->headers->get('User-Agent');
        $this->requestUser = $user;
    }

    public function send(string $to, string $subject, string $body, bool $decorateBody = true, ?string $bcc = null, bool $priority = false): bool
    {
        $result = $this->sendWithError($to, $subject, $body, $decorateBody, $bcc, false, $priority);

        return $result['success'];
    }

    public function absoluteUrl(string $path): string
    {
        return $this->normalizeAssetUrl($path);
    }

    public function attachInlineImage(string $binaryData, string $contentType, string $filename): string
    {
        $cid = sprintf('cms-%s@inline.local', bin2hex(random_bytes(16)));
        $this->pendingInlineAttachments[] = [
            'cid' => $cid,
            'filename' => $filename,
            'content_type' => $contentType,
            'data' => base64_encode($binaryData),
        ];

        return 'cid:' . $cid;
    }

    public function attachInlineFile(string $path, string $contentType, string $filename): string
    {
        $resolvedPath = realpath($path);
        if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
            throw new \RuntimeException('Nie można odczytać obrazu osadzanego w wiadomości: ' . $filename);
        }

        $cid = sprintf('cms-%s@inline.local', bin2hex(random_bytes(16)));
        $this->pendingInlineAttachments[] = [
            'cid' => $cid,
            'filename' => basename($filename) !== '' ? basename($filename) : basename($resolvedPath),
            'content_type' => trim($contentType) !== '' ? trim($contentType) : 'application/octet-stream',
            'path' => $resolvedPath,
            'disposition' => 'inline',
        ];

        return 'cid:' . $cid;
    }

    public function attachFile(string $path, string $contentType, string $filename): void
    {
        $resolvedPath = realpath($path);
        if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
            throw new \RuntimeException('Nie można odczytać załącznika: ' . $filename);
        }

        $this->pendingInlineAttachments[] = [
            'cid' => '',
            'filename' => basename($filename) !== '' ? basename($filename) : basename($resolvedPath),
            'content_type' => trim($contentType) !== '' ? trim($contentType) : 'application/octet-stream',
            'path' => $resolvedPath,
            'disposition' => 'attachment',
        ];
    }

    public function ensureQueueSchemaReady(): void
    {
        $this->ensureEmailQueueInlineAttachmentsColumn();
    }

    /**
     * @return array{success: bool, error: string|null, queued?: bool}
     */
    public function sendWithError(string $to, string $subject, string $body, bool $decorateBody = true, ?string $bcc = null, bool $forceImmediate = false, bool $priority = false, bool $forceQueue = false): array
    {
        $inlineAttachments = $this->consumePendingInlineAttachments();

        try {
            if (!$forceImmediate && ($forceQueue || $this->isQueueEnabled()) && $this->entityManager) {
                return $this->enqueue($to, $subject, $body, $decorateBody, $bcc, $priority, $inlineAttachments);
            }

            return $this->deliverNow($to, $subject, $body, $decorateBody, $bcc, $inlineAttachments);
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array{processed: int, sent: int, failed: int, pending: int}
     */
    public function processQueue(?int $limit = null, int $delaySeconds = 0): array
    {
        if (!$this->entityManager) {
            return [
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
                'pending' => 0,
            ];
        }

        $this->ensureEmailQueueInlineAttachmentsColumn();

        $queueConfig = $this->settingsProvider->getEmailQueueConfig();
        $batchLimit = $limit ?? 20;
        if ($batchLimit <= 0) {
            $batchLimit = 1;
        }
        $delaySeconds = max(0, $delaySeconds);
        $now = new \DateTimeImmutable();
        $createdBefore = $delaySeconds > 0
            ? $now->sub(new \DateInterval(sprintf('PT%dS', $delaySeconds)))
            : $now;

        $connection = $this->entityManager->getConnection();
        $lockedAtColumn = $this->resolveEmailQueueColumn($connection, ['lockedAt', 'locked_at']);
        if ($lockedAtColumn !== null) {
            $quotedLockedAtColumn = $connection->quoteIdentifier($lockedAtColumn);
            $connection->executeStatement(
                "UPDATE email_queue SET status = 'pending', {$quotedLockedAtColumn} = NULL WHERE status = 'processing' AND ({$quotedLockedAtColumn} IS NULL OR {$quotedLockedAtColumn} < :stale_lock)",
                ['stale_lock' => $now->sub(new \DateInterval('PT15M'))->format('Y-m-d H:i:s')]
            );
        }

        $repository = $this->entityManager->getRepository(EmailQueue::class);

        $processed = 0;
        $sent = 0;
        $failed = 0;
        /** @var EmailQueue[] $items */
        $items = $repository->createQueryBuilder('q')
            ->where('q.status = :status')
            ->andWhere('q.availableAt <= :now')
            ->andWhere('q.createdAt <= :createdBefore')
            ->setParameter('status', 'pending')
            ->setParameter('now', $now)
            ->setParameter('createdBefore', $createdBefore)
            ->orderBy('q.priority', 'DESC')
            ->addOrderBy('q.id', 'ASC')
            ->setMaxResults($batchLimit)
            ->getQuery()
            ->getResult();

        foreach ($items as $item) {
            $itemId = (int) ($item->getId() ?? 0);
            $lockedAt = new \DateTimeImmutable();
            $claimed = $itemId > 0 && $lockedAtColumn !== null ? $connection->executeStatement(
                "UPDATE email_queue SET status = 'processing', " . $connection->quoteIdentifier($lockedAtColumn) . " = :locked_at WHERE id = :id AND status = 'pending'",
                [
                    'locked_at' => $lockedAt->format('Y-m-d H:i:s'),
                    'id' => $itemId,
                ]
            ) : 0;
            if ($claimed !== 1) {
                $this->entityManager->detach($item);
                continue;
            }

            $item->setStatus('processing');
            $item->setLockedAt($lockedAt);
            $recipientDomain = $this->extractRecipientDomain($item->getRecipient());
            if ($this->isDomainBlocked($recipientDomain, $queueConfig['blocked_domains'] ?? [])) {
                $retryDelay = max(5, (int) $queueConfig['retry_delay_seconds']);
                $item->setStatus('pending');
                $item->setLockedAt(null);
                $item->setLastError(sprintf('Wysyłka chwilowo wstrzymana dla domeny "%s".', $recipientDomain ?? 'nieznana'));
                $item->setAvailableAt((new \DateTimeImmutable())->add(new \DateInterval(sprintf('PT%dS', $retryDelay))));
                $this->entityManager->flush();
                continue;
            }

            $result = $this->deliverNow(
                $item->getRecipient(),
                $item->getSubject(),
                $item->getBody(),
                $item->shouldDecorateBody(),
                $item->getBcc(),
                $item->getInlineAttachments()
            );

            $processed++;
            if ($result['success']) {
                $item->setStatus('sent');
                $item->setSentAt(new \DateTimeImmutable());
                $item->setLastError(null);
                $item->setLockedAt(null);
                $sent++;
            } else {
                $attempts = $item->getAttempts() + 1;
                $item->setAttempts($attempts);
                $item->setLastError($result['error']);
                $item->setLockedAt(null);

                if ($attempts >= $item->getMaxAttempts()) {
                    $item->setStatus('failed');
                    $failed++;
                } else {
                    $retryDelay = max(5, (int) $queueConfig['retry_delay_seconds']);
                    $item->setStatus('pending');
                    $item->setAvailableAt((new \DateTimeImmutable())->add(new \DateInterval(sprintf('PT%dS', $retryDelay))));
                }
            }

            $this->entityManager->flush();

            if ($processed >= $batchLimit) {
                break;
            }
        }

        $pending = (int) $repository->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->where('q.status = :status')
            ->setParameter('status', 'pending')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'processed' => $processed,
            'sent' => $sent,
            'failed' => $failed,
            'pending' => $pending,
        ];
    }

    /**
     * @return array{success: bool, error: string|null, queued: bool}
     */
    /** @param array<int, array{cid: string, filename: string, content_type: string, data: string}> $inlineAttachments */
    private function enqueue(string $to, string $subject, string $body, bool $decorateBody, ?string $bcc, bool $priority = false, array $inlineAttachments = []): array
    {
        $this->ensureEmailQueueInlineAttachmentsColumn();

        $queueConfig = $this->settingsProvider->getEmailQueueConfig();
        $item = new EmailQueue();
        $item->setRecipient($to);
        $item->setSubject($subject);
        $item->setBody($body);
        $item->setDecorateBody($decorateBody);
        $item->setPriority($priority);
        $item->setInlineAttachments($inlineAttachments);

        $normalizedBcc = implode(', ', $this->normalizeBccAddresses($bcc));
        $item->setBcc($normalizedBcc !== '' ? $normalizedBcc : null);
        $item->setStatus('pending');
        $item->setAttempts(0);
        $item->setMaxAttempts($queueConfig['max_attempts']);

        $now = new \DateTimeImmutable();
        $item->setCreatedAt($now);
        $item->setAvailableAt($now);

        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return [
            'success' => true,
            'error' => null,
            'queued' => true,
        ];
    }

    private function ensureEmailQueueInlineAttachmentsColumn(): void
    {
        if ($this->emailQueueSchemaChecked || !$this->entityManager) {
            return;
        }

        $this->emailQueueSchemaChecked = true;

        try {
            $connection = $this->entityManager->getConnection();
            $schemaManager = $connection->createSchemaManager();
            if (!$schemaManager->tablesExist(['email_queue'])) {
                return;
            }

            foreach ($schemaManager->listTableColumns('email_queue') as $name => $column) {
                if (in_array(strtolower((string) $name), ['inlineattachments', 'inline_attachments'], true)) {
                    return;
                }
            }

            $platformName = strtolower($connection->getDatabasePlatform()->getName());
            if (str_contains($platformName, 'mysql') || str_contains($platformName, 'mariadb')) {
                $connection->executeStatement("ALTER TABLE email_queue ADD inline_attachments LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)'");
                return;
            }

            if (str_contains($platformName, 'sqlite')) {
                $connection->executeStatement('ALTER TABLE email_queue ADD inline_attachments CLOB DEFAULT NULL');
                return;
            }

            $connection->executeStatement('ALTER TABLE email_queue ADD inline_attachments CLOB DEFAULT NULL');
        } catch (\Throwable $exception) {
            // If the schema cannot be updated here, sendWithError() will report the queueing error without breaking the request.
        }
    }

    /** @param string[] $candidates */
    private function resolveEmailQueueColumn(\Doctrine\DBAL\Connection $connection, array $candidates): ?string
    {
        $normalizedCandidates = array_map('strtolower', $candidates);
        foreach (array_keys($connection->createSchemaManager()->listTableColumns('email_queue')) as $columnName) {
            if (in_array(strtolower((string) $columnName), $normalizedCandidates, true)) {
                return (string) $columnName;
            }
        }

        return null;
    }

    /**
     * @return array{success: bool, error: string|null}
     */
    /** @param array<int, array{cid: string, filename: string, content_type: string, data: string}> $inlineAttachments */
    private function deliverNow(string $to, string $subject, string $body, bool $decorateBody = true, ?string $bcc = null, array $inlineAttachments = []): array
    {
        $lastError = null;
        $smtpConfig = $this->resolveSmtpConfigForRecipient($to);
        $mailer = $this->createMailer($smtpConfig);
        $fromAddress = $this->resolveFromAddress($smtpConfig);
        $messageBody = $decorateBody ? $this->decorateBody($body) : $body;

        $message = (new Email())
            ->from($fromAddress)
            ->to($to)
            ->subject($subject)
            ->html($messageBody);

        $hasRegularAttachments = false;
        $attachmentError = null;
        foreach ($inlineAttachments as $inlineAttachment) {
            $disposition = strtolower(trim((string) ($inlineAttachment['disposition'] ?? 'inline')));
            $storedPath = trim((string) ($inlineAttachment['path'] ?? ''));
            if ($storedPath !== '') {
                $data = is_file($storedPath) && is_readable($storedPath) ? file_get_contents($storedPath) : false;
            } else {
                $data = base64_decode((string) ($inlineAttachment['data'] ?? ''), true);
            }
            $cid = trim((string) ($inlineAttachment['cid'] ?? ''));
            $filename = trim((string) ($inlineAttachment['filename'] ?? 'barcode.jpg'));
            $contentType = trim((string) ($inlineAttachment['content_type'] ?? 'image/jpeg'));
            if ($data === false || $data === '') {
                if ($disposition === 'attachment') {
                    $attachmentError = 'Nie można odczytać załącznika wiadomości: ' . $filename;
                }
                continue;
            }

            $part = new DataPart($data, $filename !== '' ? $filename : 'zalacznik', $contentType !== '' ? $contentType : 'application/octet-stream');
            if ($disposition === 'attachment') {
                $hasRegularAttachments = true;
            } else {
                if ($cid === '') {
                    continue;
                }
                $part = $part->asInline();
                $part->setContentId($cid);
            }
            $message->addPart($part);
        }

        if ($attachmentError !== null) {
            $this->logEmail($to, $subject, $body, null, false, $attachmentError);

            return ['success' => false, 'error' => $attachmentError];
        }

        $bccAddresses = $this->normalizeBccAddresses($bcc);
        $normalizedBcc = implode(', ', $bccAddresses);
        if ($bccAddresses !== []) {
            $message->bcc(...$bccAddresses);
        }

        if ($mailer) {
            try {
                $mailer->send($message);
                $success = true;
            } catch (TransportExceptionInterface $exception) {
                $success = false;
                $lastError = $exception->getMessage();
            } catch (\Throwable $exception) {
                $success = false;
                $lastError = $exception->getMessage();
            }
        } elseif ($hasRegularAttachments) {
            $success = false;
            $lastError = 'Wysyłka załączników wymaga skonfigurowanego transportu SMTP.';
        } else {
            $headers = [
                'From: ' . $fromAddress->toString(),
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
            ];
            if ($normalizedBcc !== '') {
                $headers[] = 'Bcc: ' . $normalizedBcc;
            }
            $handler = static function (int $errno, string $errstr) use (&$lastError): bool {
                $lastError = $errstr;

                return true;
            };

            set_error_handler($handler);
            $success = mail($to, $subject, $messageBody, implode("\r\n", $headers));
            restore_error_handler();
            if (!$success && $lastError === null) {
                $lastError = 'Nieznany błąd podczas wysyłki.';
            }
        }

        $this->logEmail($to, $subject, $body, $normalizedBcc !== '' ? $normalizedBcc : null, $success, $lastError);

        return [
            'success' => $success,
            'error' => $lastError,
        ];
    }

    /** @return array<int, array{cid: string, filename: string, content_type: string, data: string}> */
    private function consumePendingInlineAttachments(): array
    {
        $inlineAttachments = $this->pendingInlineAttachments;
        $this->pendingInlineAttachments = [];

        return $inlineAttachments;
    }

    private function normalizeBccAddresses(?string $bcc): array
    {
        $normalizedBcc = trim((string) $bcc);
        if ($normalizedBcc === '') {
            return [];
        }

        $addresses = [];
        foreach (preg_split('/[\s,;]+/', $normalizedBcc) ?: [] as $address) {
            $address = trim((string) $address);
            if ($address === '' || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $addresses[] = $address;
        }

        return array_values(array_unique($addresses));
    }

    private function isQueueEnabled(): bool
    {
        return $this->settingsProvider->getEmailQueueConfig()['enabled'];
    }

    private function extractRecipientDomain(string $recipient): ?string
    {
        $recipient = strtolower(trim($recipient));
        if ($recipient === '') {
            return null;
        }

        if (preg_match('/<([^>]+)>/', $recipient, $matches) === 1) {
            $recipient = trim((string) ($matches[1] ?? ''));
        }

        $atPosition = strrpos($recipient, '@');
        if ($atPosition === false) {
            return null;
        }

        $domain = trim(substr($recipient, $atPosition + 1));
        if ($domain === '') {
            return null;
        }

        return trim($domain, '.');
    }

    /**
     * @param array<int, string> $blockedDomains
     */
    private function isDomainBlocked(?string $recipientDomain, array $blockedDomains): bool
    {
        if ($recipientDomain === null || $recipientDomain === '' || $blockedDomains === []) {
            return false;
        }

        $recipientDomain = strtolower($recipientDomain);

        foreach ($blockedDomains as $blockedDomain) {
            $blockedDomain = strtolower(trim((string) $blockedDomain));
            if ($blockedDomain === '') {
                continue;
            }

            if ($recipientDomain === $blockedDomain || str_ends_with($recipientDomain, '.' . $blockedDomain)) {
                return true;
            }
        }

        return false;
    }

    private function logEmail(string $to, string $subject, string $body, ?string $bcc, bool $success, ?string $error): void
    {
        if (!$this->entityManager) {
            return;
        }

        try {
            $log = new EmailLog();
            $log->setUser($this->requestUser);
            $log->setEmail($this->requestUser?->getEmail() ?? '');
            $log->setRecipient($to);
            $log->setSubject($subject);
            $log->setBody($body);
            $log->setBcc($bcc);
            $log->setSuccess($success);
            $log->setError(is_string($error) && $error !== '' ? $error : null);
            $log->setContext($this->resolveEmailContext());
            $log->setIpAddress($this->requestIp);
            $log->setUserAgent($this->requestUserAgent);
            $log->setSentAt(new \DateTimeImmutable());

            $this->entityManager->persist($log);
            $this->entityManager->flush();
        } catch (\Throwable) {
            // Intencjonalnie ignorujemy błędy logowania, aby nie blokować wysyłki maila.
        }
    }

    private function resolveEmailContext(): string
    {
        if ($this->requestUser) {
            return 'cms';
        }

        return 'system';
    }

    /**
     * @param array{host: string, port: ?int, username: string, password: string, encryption: string, from_email: string, from_name: string} $smtpConfig
     */
    private function createMailer(array $smtpConfig): ?SymfonyMailer
    {
        if ($smtpConfig['host'] === '') {
            return null;
        }

        $dsn = $this->buildSmtpDsn($smtpConfig);
        $transport = Transport::fromDsn($dsn);

        return new SymfonyMailer($transport);
    }

    /**
     * @return array{host: string, port: ?int, username: string, password: string, encryption: string, from_email: string, from_name: string, use_local_domain: bool}
     */
    private function resolveSmtpConfigForRecipient(string $recipient): array
    {
        $primarySmtpConfig = $this->settingsProvider->getSmtpConfig();
        $secondarySmtpConfig = $this->settingsProvider->getSecondarySmtpConfig();
        $recipientDomain = $this->extractRecipientDomain($recipient);

        if (
            $secondarySmtpConfig['host'] !== ''
            && $this->isDomainBlocked($recipientDomain, $secondarySmtpConfig['domains'])
        ) {
            return [
                'host' => $secondarySmtpConfig['host'],
                'port' => $secondarySmtpConfig['port'],
                'username' => $secondarySmtpConfig['username'],
                'password' => $secondarySmtpConfig['password'],
                'encryption' => $secondarySmtpConfig['encryption'],
                'from_email' => $secondarySmtpConfig['from_email'],
                'from_name' => $secondarySmtpConfig['from_name'],
                'use_local_domain' => false,
            ];
        }

        return [
            'host' => $primarySmtpConfig['host'],
            'port' => $primarySmtpConfig['port'],
            'username' => $primarySmtpConfig['username'],
            'password' => $primarySmtpConfig['password'],
            'encryption' => $primarySmtpConfig['encryption'],
            'from_email' => $primarySmtpConfig['from_email'],
            'from_name' => $primarySmtpConfig['from_name'],
            'use_local_domain' => true,
        ];
    }

    /**
     * @param array{host: string, port: ?int, username: string, password: string, encryption: string, from_email: string, from_name: string, use_local_domain: bool} $smtpConfig
     */
    private function buildSmtpDsn(array $smtpConfig): string
    {
        $credentials = '';
        if ($smtpConfig['username'] !== '' || $smtpConfig['password'] !== '') {
            $credentials = rawurlencode($smtpConfig['username']) . ':' . rawurlencode($smtpConfig['password']) . '@';
        }

        $host = $smtpConfig['host'];
        $port = $smtpConfig['port'];
        $dsn = 'smtp://' . $credentials . $host;
        if ($port !== null) {
            $dsn .= ':' . $port;
        }

        $query = [];
        $encryption = strtolower($smtpConfig['encryption']);
        if ($encryption !== '') {
            $query['encryption'] = $encryption;
        }
        if ($smtpConfig['use_local_domain']) {
            $query['local_domain'] = 'mail.m1loteria.pl';
        }

        if ($query !== []) {
            $dsn .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $dsn;
    }

    private function decorateBody(string $body): string
    {
        $settings = $this->settingsProvider->getModuleSettings('settings');
        $headerImage = $this->normalizeAssetUrl((string) ($settings['email_header_image'] ?? ''));
        $footerImage = $this->normalizeAssetUrl((string) ($settings['email_footer_image'] ?? ''));

        if ($headerImage === '' && $footerImage === '') {
            return $body;
        }

        $parts = [];

        if ($headerImage !== '') {
            $parts[] = sprintf(
                '<div style="margin-bottom:20px;"><img src="%s" alt="Nagłówek maila" style="display:block;max-width:100%%;height:auto;"></div>',
                htmlspecialchars($headerImage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );
        }

        $parts[] = $body;

        if ($footerImage !== '') {
            $parts[] = sprintf(
                '<div style="margin-top:20px;"><img src="%s" alt="Stopka maila" style="display:block;max-width:100%%;height:auto;"></div>',
                htmlspecialchars($footerImage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );
        }

        return implode("\n", $parts);
    }

    private function normalizeAssetUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $path) === 1 || str_starts_with($path, 'data:')) {
            return $path;
        }

        if ($this->baseUrl === '') {
            return $path;
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        return $this->baseUrl . $path;
    }

    /**
     * @param array{host: string, port: ?int, username: string, password: string, encryption: string, from_email: string, from_name: string} $smtpConfig
     */
    private function resolveFromAddress(array $smtpConfig): Address
    {
        if ($smtpConfig['from_email'] !== '') {
            $name = $smtpConfig['from_name'] !== '' ? $smtpConfig['from_name'] : null;
            if ($name !== null) {
                return new Address($smtpConfig['from_email'], $name);
            }

            return new Address($smtpConfig['from_email']);
        }

        return new Address($this->config['from']);
    }
}
