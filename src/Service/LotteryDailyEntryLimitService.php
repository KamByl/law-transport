<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class LotteryDailyEntryLimitService
{
    private const LOCK_NAME = 'cms_lottery_entry_allocation';
    private const LIMIT_FIELDS = [
        'tickets' => 'daily_ticket_limit',
        'scratchcards' => 'daily_scratchcard_limit',
    ];

    private Connection $connection;
    private \DateTimeZone $timezone;

    public function __construct(Connection $connection, string $timezone = 'Europe/Warsaw')
    {
        $this->connection = $connection;
        $this->timezone = new \DateTimeZone($timezone);
    }

    /**
     * The callback writes the submission and exactly the allocated entries in one transaction.
     * A shared lock also covers unlimited allocations and identifier reservations, until commit.
     *
     * @param array<string, mixed> $settings
     * @param array<string, string|null> $tables
     * @param array<string, int> $requested
     * @param array{email?: string|null, phone?: string|null} $participant
     * @param callable(array<string, int>): void $createEntries
     * @param array{table: string, promoted_products_count: int, other_products_count: int}|null $purchase
     * @param array{table: string, purchase_document_number: string, store_nip: string}|null $receipt
     * @return array{tickets: int, scratchcards: int, limited: bool}
     */
    public function allocate(
        array $settings,
        array $tables,
        array $requested,
        \DateTimeInterface $grantedAt,
        array $participant,
        callable $createEntries,
        ?int $excludeSubmissionId = null,
        ?array $purchase = null,
        ?array $receipt = null
    ): array {
        if ($this->connection->isTransactionActive()) {
            throw new \LogicException('Przydział losów musi rozpocząć własną transakcję.');
        }

        $participant = [
            'email' => mb_strtolower(trim((string) ($participant['email'] ?? ''))),
            'phone' => LotteryRegistrationValidator::normalizePhone((string) ($participant['phone'] ?? '')),
        ];
        $limits = [];
        foreach (self::LIMIT_FIELDS as $type => $field) {
            $raw = $settings[$field] ?? null;
            if ($raw === null || $raw === '') {
                $limits[$type] = null;
            } elseif (!is_scalar($raw) || !preg_match('/^[0-9]+$/D', (string) $raw) || (float) $raw > 2147483647) {
                throw new LotteryDailyLimitException('Nieprawidłowa konfiguracja dziennych limitów losów lub e-zdrapek.');
            } else {
                $limits[$type] = (int) $raw > 0 ? (int) $raw : null;
            }
            if (($requested[$type] ?? 0) < 0) {
                throw new \InvalidArgumentException('Liczba przyznawanych losów i e-zdrapek nie może być ujemna.');
            }
            if ($limits[$type] !== null && ($requested[$type] ?? 0) > 0
                && ($participant['email'] === '' || $participant['phone'] === '')
            ) {
                throw new LotteryDailyLimitException('Do sprawdzenia dziennego limitu wymagane są adres e-mail i numer telefonu uczestnika.');
            }
        }
        $productLimit = $purchase !== null ? LotteryProductAllocation::setting($settings, 'daily_product_limit', 4) : 0;
        $productCount = $purchase !== null ? array_sum(LotteryProductAllocation::quantities($purchase)) : 0;
        if ($productLimit > 0 && ($participant['email'] === '' || $participant['phone'] === '')) {
            throw new LotteryDailyLimitException('Do sprawdzenia dziennego limitu produktów wymagane są adres e-mail i numer telefonu uczestnika.');
        }

        $platform = strtolower($this->connection->getDatabasePlatform()->getName());
        $mysql = in_array($platform, ['mysql', 'mariadb'], true);
        if (!$mysql && $platform !== 'sqlite' && (array_filter($limits) !== [] || $productLimit > 0 || $receipt !== null)) {
            throw new LotteryDailyLimitException('Dzienny limit wymaga bazy obsługującej bezpieczny przydział losów.');
        }
        if ($mysql && (int) $this->connection->fetchOne('SELECT GET_LOCK(?, 5)', [self::LOCK_NAME]) !== 1) {
            throw new LotteryDailyLimitException('Nie udało się teraz przyznać losów i e-zdrapek. Spróbuj ponownie za chwilę.');
        }

        try {
            return $this->connection->transactional(function () use ($settings, $tables, $requested, $limits, $grantedAt, $participant, $createEntries, $excludeSubmissionId, $purchase, $productLimit, $productCount, $receipt): array {
                if ($receipt !== null) {
                    LotteryReceiptValidator::assertAvailable($this->connection, $receipt['table'], $receipt, $excludeSubmissionId);
                }
                $dayStart = (new \DateTimeImmutable($grantedAt->format('Y-m-d H:i:s.u'), $grantedAt->getTimezone()))
                    ->setTimezone($this->timezone)->setTime(0, 0);
                $nextDayStart = $dayStart->modify('+1 day');
                $counts = ['tickets' => 0, 'scratchcards' => 0, 'limited' => false];

                if ($productLimit > 0) {
                    $submissionTable = $purchase['table'];
                    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $submissionTable)) {
                        throw new \InvalidArgumentException('Nieprawidłowa tabela zgłoszeń.');
                    }
                    $productQuery = $this->connection->createQueryBuilder()
                        ->select('COALESCE(SUM(COALESCE(allocated_promoted_products_count, promoted_products_count, 0) + COALESCE(allocated_other_products_count, other_products_count, 0)), 0)')
                        ->from($submissionTable)
                        ->where('submitted_at >= :dayStart')->andWhere('submitted_at < :nextDayStart')
                        ->setParameter('dayStart', $dayStart->format('Y-m-d H:i:s'))
                        ->setParameter('nextDayStart', $nextDayStart->format('Y-m-d H:i:s'));
                    if ($excludeSubmissionId !== null) {
                        $productQuery->andWhere('id <> :submissionId')->setParameter('submissionId', $excludeSubmissionId);
                    }
                    $remainingProducts = max(0, $productLimit - $this->participantUsage($productQuery, $participant));
                }
                if ($purchase !== null) {
                    $allocatedProducts = LotteryProductAllocation::prioritize($purchase, $productLimit > 0 ? $remainingProducts : null);
                    $allocatedTotal = array_sum($allocatedProducts);
                    if ($excludeSubmissionId === null && $productCount === 0) {
                        throw new LotteryDailyLimitException('Zgłoszenie musi zawierać co najmniej jeden zakupiony produkt.');
                    }
                    if ($excludeSubmissionId === null && $allocatedTotal === 0) {
                        throw new LotteryDailyLimitException('Twój dzienny limit produktów do naliczania został wyczerpany. Kolejne zgłoszenie będzie możliwe jutro.');
                    }
                    $counts['allocated_promoted_products_count'] = $allocatedProducts['promoted_products_count'];
                    $counts['allocated_other_products_count'] = $allocatedProducts['other_products_count'];
                    $counts['limited'] = $allocatedTotal < $productCount;
                    if (LotteryProductAllocation::isProductBased($settings)) {
                        $productEntries = $allocatedTotal > 0 ? LotteryProductAllocation::entryCount($allocatedProducts, $settings) : 0;
                        foreach (self::LIMIT_FIELDS as $type => $_field) {
                            if (($requested[$type] ?? 0) > 0) {
                                $requested[$type] = $productEntries;
                            }
                        }
                    }
                }

                foreach (self::LIMIT_FIELDS as $type => $_field) {
                    $wanted = (int) ($requested[$type] ?? 0);
                    $counts[$type] = $wanted;
                    if ($wanted === 0 || $limits[$type] === null) {
                        continue;
                    }
                    $table = $tables[$type] ?? null;
                    if (!is_string($table) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table)) {
                        throw new \InvalidArgumentException('Brak tabeli losów lub e-zdrapek.');
                    }
                    $query = $this->connection->createQueryBuilder()
                        ->select('COUNT(*)')
                        ->from($table)
                        ->where('submitted_at >= :dayStart')
                        ->andWhere('submitted_at < :nextDayStart')
                        ->setParameter('dayStart', $dayStart->format('Y-m-d H:i:s'))
                        ->setParameter('nextDayStart', $nextDayStart->format('Y-m-d H:i:s'));
                    if ($excludeSubmissionId !== null) {
                        $query->andWhere('(zgloszenie IS NULL OR zgloszenie <> :submissionId)')
                            ->setParameter('submissionId', $excludeSubmissionId);
                    }
                    // Both identifiers have their own cap; changing either one cannot reset it.
                    $used = $this->participantUsage($query, $participant);
                    $counts[$type] = min($wanted, max(0, $limits[$type] - $used));
                    $counts['limited'] = $counts['limited'] || $counts[$type] < $wanted;
                }

                if ($excludeSubmissionId === null
                    && array_sum($requested) > 0
                    && $counts['tickets'] === 0
                    && $counts['scratchcards'] === 0
                ) {
                    throw new LotteryDailyLimitException('Twój dzienny limit przyznawania losów i e-zdrapek został wyczerpany. Kolejne zgłoszenie będzie możliwe jutro.');
                }

                $createEntries(array_diff_key($counts, ['limited' => true]));

                return $counts;
            });
        } finally {
            if ($mysql) {
                try {
                    $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
                } catch (\Throwable $exception) {
                    // A lost connection releases its advisory locks automatically.
                }
            }
        }
    }

    private function participantUsage(\Doctrine\DBAL\Query\QueryBuilder $query, array $participant): int
    {
        $emailQuery = clone $query;
        $emailCount = (int) $emailQuery->andWhere('LOWER(TRIM(email)) = :email')
            ->setParameter('email', $participant['email'])->executeQuery()->fetchOne();
        $phoneQuery = clone $query;
        $phoneCount = (int) $phoneQuery->andWhere('TRIM(phone) = :phone')
            ->setParameter('phone', $participant['phone'])->executeQuery()->fetchOne();
        return max($emailCount, $phoneCount);
    }
}
