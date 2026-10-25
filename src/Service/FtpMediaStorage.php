<?php

namespace App\Service;

class FtpMediaStorage
{
    /** @param array{enabled: bool, media_offload_enabled?: bool, host: string, port: ?int, username: string, password: string, root_path: string, passive_mode: bool, ssl_enabled: bool, timeout: ?int} $config */
    public function __construct(private array $config)
    {
    }

    public function isConfigured(): bool
    {
        return ($this->config['enabled'] ?? false) === true
            && ($this->config['media_offload_enabled'] ?? false) === true
            && trim((string) ($this->config['host'] ?? '')) !== ''
            && trim((string) ($this->config['username'] ?? '')) !== '';
    }


    /**
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        if (!function_exists('ftp_connect')) {
            return [
                'success' => false,
                'message' => 'Rozszerzenie PHP FTP nie jest zainstalowane lub włączone.',
            ];
        }

        $host = trim((string) ($this->config['host'] ?? ''));
        if ($host === '') {
            return [
                'success' => false,
                'message' => 'Podaj host FTP.',
            ];
        }

        $username = trim((string) ($this->config['username'] ?? ''));
        if ($username === '') {
            return [
                'success' => false,
                'message' => 'Podaj użytkownika FTP.',
            ];
        }

        $port = (int) (($this->config['port'] ?? null) ?: 21);
        $timeout = (int) (($this->config['timeout'] ?? null) ?: 90);
        $useSsl = (bool) ($this->config['ssl_enabled'] ?? false);

        if ($useSsl && !function_exists('ftp_ssl_connect')) {
            return [
                'success' => false,
                'message' => 'Rozszerzenie PHP FTP nie obsługuje połączeń FTPS (SSL/TLS).',
            ];
        }

        $connection = $useSsl
            ? @ftp_ssl_connect($host, $port, $timeout)
            : @ftp_connect($host, $port, $timeout);

        if (!$connection) {
            return [
                'success' => false,
                'message' => sprintf('Nie udało się połączyć z serwerem %s:%d.', $host, $port),
            ];
        }

        try {
            if (!@ftp_login($connection, $username, (string) ($this->config['password'] ?? ''))) {
                return [
                    'success' => false,
                    'message' => 'Połączenie nawiązane, ale logowanie nie powiodło się. Sprawdź użytkownika i hasło.',
                ];
            }

            @ftp_pasv($connection, (bool) ($this->config['passive_mode'] ?? false));

            $rootPath = trim((string) ($this->config['root_path'] ?? ''));
            if ($rootPath !== '' && !@ftp_chdir($connection, $rootPath)) {
                return [
                    'success' => false,
                    'message' => 'Logowanie powiodło się, ale katalog główny FTP nie istnieje albo nie masz do niego dostępu.',
                ];
            }

            return [
                'success' => true,
                'message' => 'Połączenie FTP działa poprawnie. Dane logowania i katalog główny zostały zweryfikowane.',
            ];
        } finally {
            @ftp_close($connection);
        }
    }

    public function uploadAndRemoveLocal(string $localPath, string $remoteRelativePath): bool
    {
        if (!is_file($localPath) || !$this->upload($localPath, $remoteRelativePath)) {
            return false;
        }

        @unlink($localPath);

        return !is_file($localPath);
    }

    public function download(string $remoteRelativePath, string $localPath): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $directory = dirname($localPath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return false;
        }

        $tmpPath = $localPath . '.ftp-download-' . bin2hex(random_bytes(4));
        $connection = $this->connect();
        if ($connection === null) {
            return false;
        }

        $remotePath = $this->buildRemotePath($remoteRelativePath);
        $ok = @ftp_get($connection, $tmpPath, $remotePath, FTP_BINARY);
        @ftp_close($connection);

        if (!$ok || !is_file($tmpPath)) {
            @unlink($tmpPath);
            return false;
        }

        if (!@rename($tmpPath, $localPath)) {
            @unlink($tmpPath);
            return false;
        }

        return true;
    }

    private function upload(string $localPath, string $remoteRelativePath): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $connection = $this->connect();
        if ($connection === null) {
            return false;
        }

        $remotePath = $this->buildRemotePath($remoteRelativePath);
        $this->ensureRemoteDirectory($connection, dirname($remotePath));
        $ok = @ftp_put($connection, $remotePath, $localPath, FTP_BINARY);
        @ftp_close($connection);

        return $ok;
    }

    /** @return resource|null */
    private function connect()
    {
        if (!function_exists('ftp_connect')) {
            return null;
        }

        $host = trim((string) ($this->config['host'] ?? ''));
        $port = (int) (($this->config['port'] ?? null) ?: 21);
        $timeout = (int) (($this->config['timeout'] ?? null) ?: 90);
        $connection = ($this->config['ssl_enabled'] ?? false) && function_exists('ftp_ssl_connect')
            ? @ftp_ssl_connect($host, $port, $timeout)
            : @ftp_connect($host, $port, $timeout);

        if (!$connection) {
            return null;
        }

        if (!@ftp_login($connection, (string) ($this->config['username'] ?? ''), (string) ($this->config['password'] ?? ''))) {
            @ftp_close($connection);
            return null;
        }

        @ftp_pasv($connection, (bool) ($this->config['passive_mode'] ?? false));

        return $connection;
    }

    private function buildRemotePath(string $relativePath): string
    {
        $root = trim((string) ($this->config['root_path'] ?? ''), "/ \t\n\r\0\x0B");
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

        return ($root !== '' ? '/' . $root . '/' : '/') . $relativePath;
    }

    /** @param resource $connection */
    private function ensureRemoteDirectory($connection, string $directory): void
    {
        $directory = trim(str_replace('\\', '/', $directory), '/');
        if ($directory === '') {
            return;
        }

        $path = '';
        foreach (explode('/', $directory) as $part) {
            if ($part === '') {
                continue;
            }
            $path .= '/' . $part;
            @ftp_mkdir($connection, $path);
        }
    }
}
