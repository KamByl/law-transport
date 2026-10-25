<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

final class GraphicCodeImporter
{
    public const TABLE = 'kody_graficzne_entries';

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => true,
        'image/png' => true,
        'image/gif' => true,
        'application/pdf' => true,
    ];

    private string $rootDirectory;
    private string $inputDirectory;

    public function __construct(?string $projectDirectory = null, ?string $inputDirectory = null)
    {
        $projectDirectory = $projectDirectory ?? dirname(__DIR__, 2);
        $this->rootDirectory = rtrim($projectDirectory, '/\\') . '/var/kody-graficzne';
        $this->inputDirectory = $inputDirectory !== null && trim($inputDirectory) !== ''
            ? rtrim(trim($inputDirectory), '/\\')
            : $this->rootDirectory . '/do-importu';
    }

    public function inputDirectory(): string
    {
        return $this->inputDirectory;
    }

    public function storageDirectory(): string
    {
        return $this->rootDirectory . '/przechowywane';
    }

    public function resolveStoredPath(string $relativePath): ?string
    {
        $relativePath = str_replace('\\', '/', trim($relativePath));
        if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains($relativePath, '../')) {
            return null;
        }

        $base = realpath($this->storageDirectory());
        $candidate = realpath($this->storageDirectory() . '/' . $relativePath);
        if ($base === false || $candidate === false || !is_file($candidate)) {
            return null;
        }

        $basePrefix = rtrim(str_replace('\\', '/', $base), '/') . '/';
        $normalizedCandidate = str_replace('\\', '/', $candidate);
        if (!str_starts_with($normalizedCandidate, $basePrefix)) {
            return null;
        }

        return $candidate;
    }

    /**
     * @return array{success: bool, imported: int, duplicates: int, rejected: int, failed: int, remaining: int, input_directory: string, message: string}
     */
    public function import(Connection $connection, int $limit = 250, int $minimumAgeSeconds = 60): array
    {
        $limit = min(2000, max(1, $limit));
        $minimumAgeSeconds = max(0, $minimumAgeSeconds);
        $this->ensureDirectories();

        if (!$this->canListInputDirectory()) {
            return $this->result(false, 0, 0, 0, 1, 0,
                'Katalog wejściowy istnieje, ale proces PHP nie może odczytać jego zawartości. Sprawdź właściciela oraz uprawnienia katalogów nadrzędnych.');
        }

        $unreadableValueDirectories = [];
        foreach ($this->valueDirectories() as $valueDirectory) {
            if (!$this->canListDirectory($valueDirectory)) {
                $unreadableValueDirectories[] = basename($valueDirectory);
            }
        }
        if ($unreadableValueDirectories !== []) {
            return $this->result(false, 0, 0, 0, 1, 0, sprintf(
                'Proces PHP nie może odczytać katalogów wartości: %s. Sprawdź ich właściciela i uprawnienia.',
                implode(', ', array_slice($unreadableValueDirectories, 0, 10))
            ));
        }

        if (!$connection->createSchemaManager()->tablesExist([self::TABLE])) {
            return $this->result(false, 0, 0, 0, 1, $this->countPendingFiles(),
                'Tabela kodów graficznych nie istnieje. Uruchom instalację/aktualizację bazy danych w CMS.');
        }

        $files = $this->pendingFiles($minimumAgeSeconds, $limit);
        $imported = 0;
        $duplicates = 0;
        $rejected = 0;
        $failed = 0;
        $errors = [];

        foreach ($files as $file) {
            $sourcePath = $file['path'];
            $filename = $file['filename'];
            $amount = $this->normalizeAmount($file['amount']);
            if ($amount === '') {
                ++$rejected;
                $this->moveAside($sourcePath, 'odrzucone/niepoprawna-wartosc', $filename);
                $errors[] = sprintf('%s: nazwa katalogu nie jest poprawną wartością kodu.', $filename);
                continue;
            }

            $imageInfo = @getimagesize($sourcePath);
            $mimeType = is_array($imageInfo) ? strtolower(trim((string) ($imageInfo['mime'] ?? ''))) : '';
            if ($mimeType === '' && $this->isPdfFile($sourcePath)) {
                $mimeType = 'application/pdf';
            }
            if ($mimeType === '' || !isset(self::ALLOWED_MIME_TYPES[$mimeType])) {
                ++$rejected;
                $this->moveAside($sourcePath, 'odrzucone/' . $this->safeDirectoryName($amount), $filename);
                $errors[] = sprintf('%s: dozwolone są pliki JPG, PNG, GIF i PDF.', $filename);
                continue;
            }

            $alreadyExists = (int) $connection->createQueryBuilder()
                ->select('COUNT(*)')
                ->from(self::TABLE)
                ->where('nazwa_pliku = :filename')
                ->setParameter('filename', $filename)
                ->executeQuery()
                ->fetchOne() > 0;
            if ($alreadyExists) {
                ++$duplicates;
                $this->moveAside($sourcePath, 'duplikaty/' . $this->safeDirectoryName($amount), $filename);
                continue;
            }

            $amountDirectory = $this->safeDirectoryName($amount);
            $destinationDirectory = $this->storageDirectory() . '/' . $amountDirectory;
            $this->createDirectory($destinationDirectory);
            $destinationPath = $destinationDirectory . '/' . $filename;
            if (file_exists($destinationPath)) {
                ++$failed;
                $errors[] = sprintf('%s: plik docelowy już istnieje, ale nie ma go w bazie.', $filename);
                continue;
            }

            if (!@rename($sourcePath, $destinationPath)) {
                ++$failed;
                $errors[] = sprintf('%s: nie udało się przenieść pliku do katalogu przechowywanych kodów.', $filename);
                continue;
            }

            try {
                $connection->insert(self::TABLE, [
                    'nazwa_pliku' => $filename,
                    'sciezka_pliku' => $amountDirectory . '/' . $filename,
                    'typ_pliku' => $mimeType,
                    'rozmiar_pliku' => max(0, (int) @filesize($destinationPath)),
                    'token_www' => bin2hex(random_bytes(32)),
                    'wartosc_kodu' => $amount,
                    'kod_wykorzystany' => 0,
                    'data_i_godzina_przyznania' => null,
                    'data_importu' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);
                ++$imported;
            } catch (\Throwable $exception) {
                ++$failed;
                if (!@rename($destinationPath, $sourcePath)) {
                    $errors[] = sprintf('%s: błąd zapisu w bazie, plik pozostał w katalogu przechowywanych.', $filename);
                } else {
                    $errors[] = sprintf('%s: błąd zapisu w bazie (%s).', $filename, $exception->getMessage());
                }
            }
        }

        $remaining = $this->countPendingFiles();
        $message = sprintf(
            'Zaimportowano: %d, duplikaty: %d, odrzucono: %d, błędy: %d, pozostało: %d.',
            $imported,
            $duplicates,
            $rejected,
            $failed,
            $remaining
        );
        if ($errors !== []) {
            $message .= ' ' . implode(' ', array_slice($errors, 0, 5));
        }

        return $this->result($failed === 0, $imported, $duplicates, $rejected, $failed, $remaining, $message);
    }

    private function ensureDirectories(): void
    {
        foreach ([$this->inputDirectory(), $this->storageDirectory(), $this->rootDirectory . '/odrzucone', $this->rootDirectory . '/duplikaty'] as $directory) {
            $this->createDirectory($directory);
        }
    }

    private function createDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Nie udało się utworzyć katalogu: ' . $directory);
        }
    }

    /** @return array<int, array{path: string, filename: string, amount: string}> */
    private function pendingFiles(int $minimumAgeSeconds, int $limit): array
    {
        $threshold = time() - $minimumAgeSeconds;
        $files = [];

        foreach ($this->valueDirectories() as $amountDirectory) {
            $amount = basename($amountDirectory);
            foreach ($this->recursiveEntries($amountDirectory) as $entry) {
                if (!$this->isImportCandidate($entry, $threshold)) {
                    continue;
                }

                $files[] = [
                    'path' => $entry->getPathname(),
                    'filename' => $entry->getFilename(),
                    'amount' => $amount,
                ];
                if (count($files) >= $limit) {
                    break 2;
                }
            }
        }

        return $files;
    }

    private function countPendingFiles(): int
    {
        $count = 0;
        foreach ($this->valueDirectories() as $amountDirectory) {
            foreach ($this->recursiveEntries($amountDirectory) as $entry) {
                if ($this->isImportCandidate($entry)) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    /** @return array<int, string> */
    private function valueDirectories(): array
    {
        $directories = [];
        try {
            foreach (new \DirectoryIterator($this->inputDirectory()) as $entry) {
                if (!$entry->isDot() && $entry->isDir()) {
                    $directories[] = $entry->getPathname();
                }
            }
        } catch (\UnexpectedValueException $exception) {
            return [];
        }

        natcasesort($directories);

        return array_values($directories);
    }

    /** @return \RecursiveIteratorIterator<\RecursiveDirectoryIterator> */
    private function recursiveEntries(
        string $directory,
        int $mode = \RecursiveIteratorIterator::LEAVES_ONLY
    ): \RecursiveIteratorIterator
    {
        return new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            $mode,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );
    }

    private function isImportCandidate(\SplFileInfo $entry, ?int $maximumModificationTime = null): bool
    {
        $filename = $entry->getFilename();
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        return $entry->isFile()
            && !$entry->isLink()
            && !str_starts_with($filename, '.')
            && !in_array($extension, ['part', 'tmp', 'upload'], true)
            && ($maximumModificationTime === null || $entry->getMTime() <= $maximumModificationTime);
    }

    private function isPdfFile(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 1024);
        fclose($handle);

        return is_string($header) && strpos($header, '%PDF-') !== false;
    }

    private function moveAside(string $sourcePath, string $relativeDirectory, string $filename): void
    {
        $directory = $this->rootDirectory . '/' . trim($relativeDirectory, '/');
        $this->createDirectory($directory);
        $destination = $directory . '/' . $filename;
        if (file_exists($destination)) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $base = pathinfo($filename, PATHINFO_FILENAME);
            $destination = $directory . '/' . $base . '-' . bin2hex(random_bytes(4)) . ($extension !== '' ? '.' . $extension : '');
        }
        @rename($sourcePath, $destination);
    }

    private function normalizeAmount(string $value): string
    {
        $value = trim(str_replace(',', '.', $value));
        if ($value === '' || !is_numeric($value) || (float) $value < 0) {
            return '';
        }

        $number = (float) $value;
        return floor($number) === $number
            ? (string) (int) $number
            : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    private function safeDirectoryName(string $value): string
    {
        return str_replace('.', '_', $value);
    }

    private function canListInputDirectory(): bool
    {
        return $this->canListDirectory($this->inputDirectory());
    }

    private function canListDirectory(string $directory): bool
    {
        $handle = @opendir($directory);
        if ($handle === false) {
            return false;
        }

        closedir($handle);

        return true;
    }

    /**
     * @return array{success: bool, imported: int, duplicates: int, rejected: int, failed: int, remaining: int, input_directory: string, message: string}
     */
    private function result(bool $success, int $imported, int $duplicates, int $rejected, int $failed, int $remaining, string $message): array
    {
        $inputDirectory = $this->inputDirectory();
        $valueDirectories = $this->valueDirectories();
        $valueDirectoryCount = count($valueDirectories);
        $regularFileCount = 0;
        $candidateFileCount = 0;
        $nestedDirectoryCount = 0;
        $symbolicLinkCount = 0;
        $unreadableValueDirectoryCount = 0;
        foreach ($valueDirectories as $valueDirectory) {
            if (!$this->canListDirectory($valueDirectory)) {
                ++$unreadableValueDirectoryCount;
                continue;
            }
            foreach ($this->recursiveEntries($valueDirectory, \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                if ($entry->isLink()) {
                    ++$symbolicLinkCount;
                } elseif ($entry->isDir()) {
                    ++$nestedDirectoryCount;
                } elseif ($entry->isFile()) {
                    ++$regularFileCount;
                    if ($this->isImportCandidate($entry)) {
                        ++$candidateFileCount;
                    }
                }
            }
        }

        $message = rtrim($message) . sprintf(
            ' Skanowany katalog: %s (katalogi wartości: %d, bez odczytu: %d, pliki regularne: %d, kandydaci do importu: %d, dalsze katalogi: %d, dowiązania: %d, odczyt katalogu głównego: %s).',
            $inputDirectory,
            $valueDirectoryCount,
            $unreadableValueDirectoryCount,
            $regularFileCount,
            $candidateFileCount,
            $nestedDirectoryCount,
            $symbolicLinkCount,
            $this->canListInputDirectory() ? 'tak' : 'nie'
        );

        return compact('success', 'imported', 'duplicates', 'rejected', 'failed', 'remaining', 'message') + [
            'input_directory' => $inputDirectory,
        ];
    }
}
