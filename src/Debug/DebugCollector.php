<?php

namespace App\Debug;

class DebugCollector
{
    private const REDIRECT_HISTORY_SESSION_KEY = '_cms2_debug_redirect_history';
    private const CURRENT_REQUEST_SESSION_KEY = '_cms2_debug_current_request_payload';
    private const REQUEST_COUNTER_SESSION_KEY = '_cms2_debug_request_counter';
    private const REDIRECT_HISTORY_LIMIT = 10;
    private static bool $enabled = false;
    private static int $requestCounter = 0;

    /**
     * @var array<int, array{sql: string, params: string, durationMs: float|null, startedAt: string}>
     */
    private static array $queries = [];

    /**
     * @var array<int, array{label: string|null, payload: string, loggedAt: string}>
     */
    private static array $dumps = [];

    public static function startRequest(bool $enabled): void
    {
        self::$enabled = $enabled;
        self::$queries = [];
        self::$dumps = [];
        self::$requestCounter = 0;

        if ($enabled && session_status() === PHP_SESSION_ACTIVE) {
            $counter = (int) ($_SESSION[self::REQUEST_COUNTER_SESSION_KEY] ?? 0);
            $counter++;
            $_SESSION[self::REQUEST_COUNTER_SESSION_KEY] = $counter;
            self::$requestCounter = $counter;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::CURRENT_REQUEST_SESSION_KEY]);
        }

        self::persistCurrentPayloadToSession();
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * @param array<int|string, mixed>|null $params
     */
    public static function addQuery(string $sql, ?array $params = null, ?float $durationMs = null): void
    {
        if (!self::$enabled) {
            return;
        }

        self::$queries[] = [
            'sql' => $sql,
            'params' => self::stringify($params ?? []),
            'durationMs' => $durationMs,
            'startedAt' => (new \DateTimeImmutable())->format('H:i:s.u'),
        ];

        self::persistCurrentPayloadToSession();
    }

    public static function addDump(mixed $value, ?string $label = null): void
    {
        if (!self::$enabled) {
            return;
        }

        $resolvedLabel = self::resolveDumpLabel($label);

        self::$dumps[] = [
            'label' => $resolvedLabel,
            'payload' => self::stringify($value),
            'loggedAt' => (new \DateTimeImmutable())->format('H:i:s.u'),
        ];

        self::persistCurrentPayloadToSession();
    }

    /**
     * @return array{enabled: bool, requestCounter: int, queryCount: int, dumpCount: int, queries: array<int, array{sql: string, params: string, durationMs: float|null, startedAt: string}>, dumps: array<int, array{label: string|null, payload: string, loggedAt: string}>}
     */
    public static function getPayload(): array
    {
        return [
            'enabled' => self::$enabled,
            'requestCounter' => self::$requestCounter,
            'queryCount' => count(self::$queries),
            'dumpCount' => count(self::$dumps),
            'queries' => self::$queries,
            'dumps' => self::$dumps,
        ];
    }

    public static function storeRedirectPayload(string $path, int $statusCode): void
    {
        if (!self::$enabled || session_status() !== PHP_SESSION_ACTIVE || $statusCode < 300 || $statusCode >= 400) {
            return;
        }

        $history = $_SESSION[self::REDIRECT_HISTORY_SESSION_KEY] ?? [];
        if (!is_array($history)) {
            $history = [];
        }

        $payload = self::getCurrentPayloadFromSession() ?? self::getPayload();

        $history[] = [
            'path' => $path,
            'statusCode' => $statusCode,
            'loggedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'payload' => $payload,
        ];

        $_SESSION[self::REDIRECT_HISTORY_SESSION_KEY] = array_slice($history, -self::REDIRECT_HISTORY_LIMIT);
        unset($_SESSION[self::CURRENT_REQUEST_SESSION_KEY]);
    }

    /**
     * @return array<int, array{path: string, statusCode: int, loggedAt: string, payload: array{enabled: bool, requestCounter: int, queryCount: int, dumpCount: int, queries: array<int, array{sql: string, params: string, durationMs: float|null, startedAt: string}>, dumps: array<int, array{label: string|null, payload: string, loggedAt: string}>}}>
     */
    public static function consumeRedirectHistory(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }

        $history = $_SESSION[self::REDIRECT_HISTORY_SESSION_KEY] ?? [];
        unset($_SESSION[self::REDIRECT_HISTORY_SESSION_KEY]);

        if (!is_array($history)) {
            return [];
        }

        return array_values(array_filter($history, static fn(mixed $entry): bool => is_array($entry)));
    }

    private static function persistCurrentPayloadToSession(): void
    {
        if (!self::$enabled || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION[self::CURRENT_REQUEST_SESSION_KEY] = self::getPayload();
    }

    /**
     * @return array{enabled: bool, requestCounter: int, queryCount: int, dumpCount: int, queries: array<int, array{sql: string, params: string, durationMs: float|null, startedAt: string}>, dumps: array<int, array{label: string|null, payload: string, loggedAt: string}>}|null
     */
    private static function getCurrentPayloadFromSession(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $payload = $_SESSION[self::CURRENT_REQUEST_SESSION_KEY] ?? null;
        if (!is_array($payload)) {
            return null;
        }

        return $payload;
    }

    private static function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value) || $value === null) {
            return var_export($value, true);
        }

        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json) && $json !== '') {
            return $json;
        }

        return print_r($value, true);
    }

    private static function resolveDumpLabel(?string $label): string
    {
        $trimmedLabel = $label !== null ? trim($label) : '';
        if ($trimmedLabel !== '') {
            return $trimmedLabel;
        }

        $fallback = 'dump';
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = $trace[2] ?? null;
        if (!is_array($caller)) {
            return $fallback;
        }

        $file = $caller['file'] ?? null;
        $line = $caller['line'] ?? null;
        if (!is_string($file) || !is_int($line) || !is_file($file)) {
            return $fallback;
        }

        $sourceLine = @file($file)[$line - 1] ?? '';
        if (is_string($sourceLine) && preg_match('/dump\(\s*(.+?)(?:,\s*.+)?\)\s*;?/', $sourceLine, $matches) === 1) {
            $expression = trim($matches[1]);
            if ($expression !== '') {
                return $expression;
            }
        }

        return sprintf('%s:%d', basename($file), $line);
    }
}
