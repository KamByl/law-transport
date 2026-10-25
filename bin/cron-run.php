#!/usr/bin/env php
<?php

declare(strict_types=1);

$options = getopt('', ['base-url::', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, "Użycie:\n");
    fwrite(STDOUT, "  php bin/cron-run.php [--base-url=https://twojadomena.pl]\n");
    fwrite(STDOUT, "\nW crontab ustaw jedną komendę, np. co minutę:\n");
    fwrite(STDOUT, "  * * * * /usr/bin/php /sciezka/do/projektu/bin/cron-run.php --base-url=https://twojadomena.pl >> /var/log/cms2-cron.log 2>&1\n");
    exit(0);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Bootstrap;
use Symfony\Component\HttpFoundation\Request;

$baseUrl = trim((string) ($options['base-url'] ?? ''));
if ($baseUrl === '') {
    $baseUrl = 'http://localhost';
}

$parsedBaseUrl = parse_url($baseUrl);
$scheme = (string) ($parsedBaseUrl['scheme'] ?? 'http');
$host = (string) ($parsedBaseUrl['host'] ?? 'localhost');
$port = isset($parsedBaseUrl['port']) ? ':' . (int) $parsedBaseUrl['port'] : '';

$bootstrap = new Bootstrap();
$request = Request::create(
    '/cron/run',
    'GET',
    [],
    [],
    [],
    [
        'HTTP_HOST' => $host . $port,
        'REQUEST_SCHEME' => $scheme,
        'HTTPS' => $scheme === 'https' ? 'on' : 'off',
        'REMOTE_ADDR' => '127.0.0.1',
    ]
);

$response = $bootstrap->handleRequest($request);
$payload = json_decode($response->getContent(), true);
if (!is_array($payload)) {
    fwrite(STDERR, "Nieprawidłowa odpowiedź (HTTP {$response->getStatusCode()}): " . $response->getContent() . "\n");
    exit(2);
}

if ($response->getStatusCode() >= 400 || !(bool) ($payload['success'] ?? false)) {
    $message = trim((string) ($payload['message'] ?? ''));
    fwrite(STDERR, "Błąd harmonogramu (HTTP {$response->getStatusCode()}): " . ($message !== '' ? $message : json_encode($payload, JSON_UNESCAPED_UNICODE)) . "\n");
    exit(3);
}

fwrite(STDOUT, 'CRON: uruchomiono ' . (int) ($payload['executed'] ?? 0) . " zadań.\n");
foreach (($payload['results'] ?? []) as $result) {
    if (!is_array($result)) {
        continue;
    }
    fwrite(STDOUT, sprintf(
        "- [%s] %s: %s (HTTP %d), następne: %s\n",
        ((bool) ($result['success'] ?? false)) ? 'OK' : 'ERROR',
        (string) ($result['name'] ?? $result['commandKey'] ?? 'zadanie'),
        (string) ($result['message'] ?? ''),
        (int) ($result['httpStatus'] ?? 0),
        (string) ($result['nextRunAt'] ?? '-')
    ));
}

exit(0);
