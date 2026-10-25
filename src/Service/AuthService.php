<?php

namespace App\Service;

use App\Entity\LoginLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class AuthService
{
    private const USER_ID_SESSION_KEY = 'user_id';
    private const IMPERSONATOR_ID_SESSION_KEY = 'impersonator_id';
    private const LOGIN_RATE_LIMIT_WINDOW_SECONDS = 900;
    private const LOGIN_RATE_LIMIT_MAX_ATTEMPTS_PER_IP = 10;
    private const LOGIN_RATE_LIMIT_MAX_ATTEMPTS_PER_EMAIL = 5;

    private EntityManagerInterface $entityManager;
    private string $sessionName;

    public function __construct(EntityManagerInterface $entityManager, string $sessionName)
    {
        $this->entityManager = $entityManager;
        $this->sessionName = $sessionName;

        $this->startSession();
    }

    private function startSession(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $this->configureSessionCookie();
        session_name($this->sessionName);
        session_start();
    }

    private function configureSessionCookie(): void
    {
        $cookieParams = session_get_cookie_params();
        $domain = $this->resolveSessionCookieDomain();

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $this->isHttpsRequest() ? '1' : '0');
        ini_set('session.cookie_samesite', $cookieParams['samesite'] ?? 'Lax');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookieParams['path'] ?: '/',
            'domain' => $domain,
            'secure' => $this->isHttpsRequest(),
            'httponly' => true,
            'samesite' => $cookieParams['samesite'] ?? 'Lax',
        ]);
    }

    private function resolveSessionCookieDomain(): string
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return '';
        }

        $host = explode(':', $host)[0];

        if (filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost') {
            return '';
        }

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        if ($host === '' || !str_contains($host, '.')) {
            return '';
        }

        return '.' . $host;
    }

    private function isHttpsRequest(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }

        $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($forwardedProto === 'https') {
            return true;
        }

        return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    public function getLoginThrottle(string $email, ?string $ipAddress = null): array
    {
        $normalizedEmail = $this->normalizeEmail($email);
        $windowStart = new \DateTimeImmutable('-' . self::LOGIN_RATE_LIMIT_WINDOW_SECONDS . ' seconds');
        $retryAfterByScope = [];

        if ($normalizedEmail !== '') {
            $emailLimit = $this->countRecentFailedLoginAttempts('email', $normalizedEmail, $windowStart);
            if ($emailLimit['count'] >= self::LOGIN_RATE_LIMIT_MAX_ATTEMPTS_PER_EMAIL) {
                $retryAfterByScope[] = $this->calculateRetryAfterSeconds($emailLimit['oldestAttemptAt']);
            }
        }

        $normalizedIpAddress = $this->normalizeIpAddress($ipAddress);
        if ($normalizedIpAddress !== null) {
            $ipLimit = $this->countRecentFailedLoginAttempts('ipAddress', $normalizedIpAddress, $windowStart);
            if ($ipLimit['count'] >= self::LOGIN_RATE_LIMIT_MAX_ATTEMPTS_PER_IP) {
                $retryAfterByScope[] = $this->calculateRetryAfterSeconds($ipLimit['oldestAttemptAt']);
            }
        }

        $retryAfter = $retryAfterByScope === [] ? 0 : max($retryAfterByScope);

        return [
            'isBlocked' => $retryAfter > 0,
            'retryAfter' => $retryAfter,
        ];
    }

    public function attemptLogin(
        string $email,
        string $password,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): bool
    {
        $email = trim($email);
        $normalizedEmail = $this->normalizeEmail($email);
        $ipAddress = $this->normalizeIpAddress($ipAddress);
        $userRepository = $this->entityManager->getRepository(User::class);
        $user = $userRepository->findOneBy(['email' => $email]);

        if (!$user && $normalizedEmail !== $email) {
            $user = $userRepository->findOneBy(['email' => $normalizedEmail]);
        }

        if (!$user) {
            $this->recordLoginAttempt(null, $normalizedEmail, $ipAddress, $userAgent, false);
            return false;
        }

        if (!$user->isActive() || !password_verify($password, $user->getPasswordHash())) {
            $this->recordLoginAttempt($user, $normalizedEmail, $ipAddress, $userAgent, false);
            return false;
        }

        $this->regenerateSession();
        $_SESSION[self::USER_ID_SESSION_KEY] = $user->getId();
        unset($_SESSION[self::IMPERSONATOR_ID_SESSION_KEY]);
        $this->recordLoginAttempt($user, $normalizedEmail, $ipAddress, $userAgent, true);

        return true;
    }

    public function logout(): void
    {
        $this->clearAuthenticatedSession();
        $this->regenerateSession();
    }

    public function user(): ?User
    {
        if (!isset($_SESSION[self::USER_ID_SESSION_KEY])) {
            return null;
        }

        $user = $this->entityManager->find(User::class, $_SESSION[self::USER_ID_SESSION_KEY]);

        return $user instanceof User && $user->isActive() ? $user : null;
    }

    public function impersonator(): ?User
    {
        if (!isset($_SESSION[self::IMPERSONATOR_ID_SESSION_KEY])) {
            return null;
        }

        return $this->entityManager->find(User::class, $_SESSION[self::IMPERSONATOR_ID_SESSION_KEY]);
    }

    public function isImpersonating(): bool
    {
        return isset($_SESSION[self::IMPERSONATOR_ID_SESSION_KEY]);
    }

    public function requireAuth(): bool
    {
        return $this->user() !== null;
    }

    public function impersonate(
        User $user,
        ?User $actor = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): void {
        $this->regenerateSession();
        $_SESSION[self::USER_ID_SESSION_KEY] = $user->getId();
        if ($actor) {
            $_SESSION[self::IMPERSONATOR_ID_SESSION_KEY] = $actor->getId();
        } else {
            unset($_SESSION[self::IMPERSONATOR_ID_SESSION_KEY]);
        }

        $this->recordLoginAttempt($user, $user->getEmail(), $ipAddress, $userAgent, true);
    }

    private function clearAuthenticatedSession(): void
    {
        unset($_SESSION[self::USER_ID_SESSION_KEY], $_SESSION[self::IMPERSONATOR_ID_SESSION_KEY]);
    }

    private function regenerateSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        session_regenerate_id(true);
    }

    private function recordLoginAttempt(
        ?User $user,
        string $email,
        ?string $ipAddress,
        ?string $userAgent,
        bool $success
    ): void {
        $log = new LoginLog();
        $log->setUser($user);
        $log->setEmail($this->normalizeEmail($email));
        $log->setIpAddress($this->normalizeIpAddress($ipAddress));
        $log->setUserAgent($userAgent);
        $log->setSuccess($success);
        $log->setLoggedAt(new \DateTimeImmutable());

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    private function countRecentFailedLoginAttempts(string $field, string $value, \DateTimeImmutable $windowStart): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();
        $result = $queryBuilder
            ->select('COUNT(log.id) AS failedCount', 'MIN(log.loggedAt) AS oldestAttemptAt')
            ->from(LoginLog::class, 'log')
            ->where(sprintf('log.%s = :value', $field))
            ->andWhere('log.success = false')
            ->andWhere('log.loggedAt >= :windowStart')
            ->setParameter('value', $value)
            ->setParameter('windowStart', $windowStart)
            ->getQuery()
            ->getSingleResult();

        return [
            'count' => (int) ($result['failedCount'] ?? 0),
            'oldestAttemptAt' => $result['oldestAttemptAt'] instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($result['oldestAttemptAt'])
                : null,
        ];
    }

    private function calculateRetryAfterSeconds(?\DateTimeImmutable $oldestAttemptAt): int
    {
        if ($oldestAttemptAt === null) {
            return self::LOGIN_RATE_LIMIT_WINDOW_SECONDS;
        }

        $blockedUntil = $oldestAttemptAt->modify('+' . self::LOGIN_RATE_LIMIT_WINDOW_SECONDS . ' seconds');
        $retryAfter = $blockedUntil->getTimestamp() - time();

        return max(1, $retryAfter);
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function normalizeIpAddress(?string $ipAddress): ?string
    {
        $ipAddress = trim((string) $ipAddress);

        return $ipAddress === '' ? null : $ipAddress;
    }
}
