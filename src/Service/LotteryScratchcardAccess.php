<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class LotteryScratchcardAccess
{
    private Connection $connection;
    private string $submissionTable;
    private string $scratchTable;

    public function __construct(Connection $connection, string $submissionTable, string $scratchTable)
    {
        foreach ([$submissionTable, $scratchTable] as $table) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table)) {
                throw new \InvalidArgumentException('Nieprawidłowa tabela loterii.');
            }
        }
        $this->connection = $connection;
        $this->submissionTable = $submissionTable;
        $this->scratchTable = $scratchTable;
    }

    public function emailForToken(string $token): ?string
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }
        $email = $this->connection->createQueryBuilder()->select('email')->from($this->submissionTable)
            ->where('scratch_link_token = :token')->setParameter('token', $token)
            ->setMaxResults(1)->executeQuery()->fetchOne();
        $email = mb_strtolower(trim((string) $email));
        return $email !== '' ? $email : null;
    }

    public function ownsSubmission(string $token, int $submissionId): bool
    {
        $email = $this->emailForToken($token);
        return $email !== null && (int) $this->connection->createQueryBuilder()
            ->select('COUNT(*)')->from($this->submissionTable)
            ->where('id = :id')->andWhere('LOWER(TRIM(email)) = :email')
            ->setParameter('id', $submissionId)->setParameter('email', $email)->executeQuery()->fetchOne() === 1;
    }

    /** @return array<int, array{ticket_id: string, submission_id: int}> */
    public function pendingEntries(string $token): array
    {
        $email = $this->emailForToken($token);
        if ($email === null) {
            return [];
        }
        $rows = $this->connection->createQueryBuilder()->select('t.ticket_id', 't.zgloszenie')
            ->from($this->scratchTable, 't')->innerJoin('t', $this->submissionTable, 's', 's.id = t.zgloszenie')
            ->where('LOWER(TRIM(s.email)) = :email')->andWhere('t.win_checked_at IS NULL')
            ->setParameter('email', $email)->orderBy('t.id', 'ASC')->executeQuery()->fetchAllAssociative();
        return array_map(static function (array $row): array {
            return ['ticket_id' => (string) $row['ticket_id'], 'submission_id' => (int) $row['zgloszenie']];
        }, $rows);
    }

    /** Serialize repeated clicks on the same card, including retries after a lost response. */
    public function withActivationLock(int $submissionId, string $ticketId, callable $activate)
    {
        $mysql = in_array(strtolower($this->connection->getDatabasePlatform()->getName()), ['mysql', 'mariadb'], true);
        $lock = 'scratch-check-' . substr(hash('sha256', $this->scratchTable . ':' . $submissionId . ':' . $ticketId), 0, 48);
        if ($mysql && (int) $this->connection->fetchOne('SELECT GET_LOCK(?, 5)', [$lock]) !== 1) {
            throw new \RuntimeException('Nie udało się teraz otworzyć e-zdrapki. Spróbuj ponownie.');
        }
        try {
            return $activate();
        } finally {
            if ($mysql) {
                try { $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]); }
                catch (\Throwable $exception) { /* A disconnected session releases its locks. */ }
            }
        }
    }
}
