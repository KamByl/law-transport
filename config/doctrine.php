<?php

// Dane dostępowe do bazy ustawiasz w zmiennych środowiskowych albo w config/doctrine.local.php
// (plik lokalny nie trafia do repozytorium, wzór: config/doctrine.local.php.dist).
$local = is_file(__DIR__ . '/doctrine.local.php') ? require __DIR__ . '/doctrine.local.php' : [];

return [
    'db' => array_merge([
        'driver' => 'pdo_mysql',
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'dbname' => getenv('DB_NAME') ?: 'law_transport',
        'user' => getenv('DB_USER') ?: '',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ], $local['db'] ?? []),
    'paths' => [__DIR__ . '/../src/Entity'],
    'dev_mode' => true,
    'proxy_dir' => __DIR__ . '/../var/doctrine/proxies',
];
