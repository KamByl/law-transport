<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final class WinnerPrizeCodeAssignmentService
{
    private const WINNERS_TABLE = 'loteria_zwyciezcy_entries';
    private const PRIZE_TYPES_TABLE = 'rodzaje_nagrod_entries';
    private const CODES_TABLE = 'lista_wygranych_kodow_entries';
    private const GRAPHIC_CODES_TABLE = 'kody_graficzne_entries';
    private const STATUSES_TABLE = 'ustawienia_statusow';
    private const SCRATCHCARDS_TABLE = 'loteria_zdrapki_entries';
    private const TICKETS_TABLE = 'loteria_losy_entries';
    private const SCHEDULE_TABLE = 'godziny_wygranych_entries';

    /**
     * @param array<string, mixed> $winner
     * @return array{success: bool, assigned: bool, skipped: bool, message: string, winner: array<string, mixed>, prize_type?: array<string, mixed>, codes_500: array<int, string>, codes_1000: array<int, string>, code_rows: array<int, array{pickup_code: string, kwota: string, kod_ean: bool, code_128: bool}>, code_shortage?: bool, effective_status_id?: string, error?: string, failure_reason?: string}
     */
    public function assignConfiguredCodesToWinner(Connection $connection, array $winner): array
    {
        $winnerId = (int) ($winner['id'] ?? 0);
        if ($winnerId <= 0) {
            return $this->failure($winner, 'Niepoprawny identyfikator zwycięzcy.');
        }

        $prizeTypeId = (int) ($winner['rodzaj_wygranej'] ?? 0);
        $prizeType = $prizeTypeId > 0
            ? $this->fetchPrizeType($connection, $prizeTypeId)
            : $this->resolvePrizeTypeFromWinnerRelations($connection, $winner);
        if ($prizeType === null) {
            return $this->failure($winner, 'Nie znaleziono typu nagrody.');
        }
        $resolvedPrizeTypeId = (int) ($prizeType['id'] ?? 0);
        if ($prizeTypeId <= 0 && $resolvedPrizeTypeId > 0) {
            $winner['rodzaj_wygranej'] = $resolvedPrizeTypeId;
            $this->syncWinnerPrizeType($connection, $winnerId, $resolvedPrizeTypeId);
        }

        $codesTable = $this->resolveCodesTable($prizeType);

        $requirements = $this->parseCodeRequirements($prizeType['granted_codes'] ?? null);
        if ($requirements === [] && trim((string) ($prizeType['prize_granted_email_template'] ?? '')) !== '') {
            return $this->assignSingleFallbackCode($connection, $winner, $prizeType, $codesTable);
        }

        if ($requirements === []) {
            return $this->success($winner, $prizeType, false, true, 'Nagroda nie ma skonfigurowanych kodów do przydzielenia.');
        }

        if (!$this->tableExists($connection, $codesTable)) {
            return $this->failure($winner, 'Brak tabeli z pulą kodów dla wybranego formatu.', $prizeType);
        }

        $codeColumns = $this->listColumns($connection, $codesTable);
        $winnerColumns = $this->tableExists($connection, self::WINNERS_TABLE)
            ? $this->listColumns($connection, self::WINNERS_TABLE)
            : [];

        $assignedRows = $this->fetchAssignedCodeRows($connection, $winnerId, $codeColumns, $codesTable);
        $missingRequirements = $this->calculateMissingRequirements($requirements, $assignedRows);
        if ($missingRequirements !== []) {
            $assignedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $newRows = [];
            $ownsTransaction = !$connection->isTransactionActive();

            if ($ownsTransaction) {
                $connection->beginTransaction();
            }
            try {
                $freeRowsByAmount = [];
                foreach ($missingRequirements as $amount => $count) {
                    $freeRows = $this->fetchFreeCodeRows($connection, $codeColumns, $amount, $count, $winner, $codesTable);
                    if (count($freeRows) < $count) {
                        if ($ownsTransaction) {
                            $connection->rollBack();
                        }

                        return $this->handleCodeShortage(
                            $connection,
                            $winner,
                            sprintf('Brak wolnych kodów o wartości %s dla nagrody (wymagane: %d, dostępne: %d).', $amount, $count, count($freeRows)),
                            $prizeType
                        );
                    }

                    $freeRowsByAmount[$amount] = $freeRows;
                }

                foreach ($freeRowsByAmount as $freeRows) {
                    foreach ($freeRows as $freeRow) {
                        $codeId = (int) ($freeRow['id'] ?? 0);
                        if ($codeId <= 0) {
                            continue;
                        }

                        if (!$this->claimCodeRow($connection, $codeColumns, $codeId, $winner, $winnerId, $assignedAt, $codesTable)) {
                            if ($ownsTransaction) {
                                $connection->rollBack();
                            }

                            return $this->failure(
                                $winner,
                                'Nie udalo sie przydzielic kodu, poniewaz zostal wlasnie zajety przez inne zgloszenie. Sprobuj ponownie.',
                                $prizeType
                            );
                        }

                        $freeRow['kod_przyznany_do_zwyciezcy'] = $winnerId;
                        $freeRow['data_i_godzina_przyznania'] = $assignedAt;
                        $newRows[] = $freeRow;
                    }
                }

                $assignedRows = array_merge($assignedRows, $newRows);
                $winner = $this->syncWinnerWithAssignedCodes($connection, $winner, $winnerColumns, $assignedRows, $codesTable);
                if ($ownsTransaction) {
                    $connection->commit();
                }
            } catch (\Throwable $exception) {
                if ($ownsTransaction && $connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                return $this->failure($winner, 'Nie udało się przydzielić kodów: ' . $exception->getMessage(), $prizeType);
            }
        } else {
            $winner = $this->syncWinnerWithAssignedCodes($connection, $winner, $winnerColumns, $assignedRows, $codesTable);
        }

        $codeRows = $this->buildEmailCodeRows($assignedRows, $codesTable);
        $codes500 = $this->extractCodesByAmount($codeRows, '500');
        $codes1000 = $this->extractCodesByAmount($codeRows, '1000');

        return [
            'success' => true,
            'assigned' => $missingRequirements !== [],
            'skipped' => false,
            'message' => $missingRequirements !== [] ? 'Przydzielono kody do zwycięzcy.' : 'Kody były już przydzielone do zwycięzcy.',
            'winner' => $winner,
            'prize_type' => $prizeType,
            'codes_500' => $codes500,
            'codes_1000' => $codes1000,
            'code_rows' => $codeRows,
        ];
    }

    public function isFulfilledStatus(Connection $connection, mixed $statusId): bool
    {
        $statusValue = trim((string) $statusId);
        if ($statusValue === '') {
            return false;
        }

        if (!$this->tableExists($connection, self::STATUSES_TABLE)) {
            return $statusValue === 'zrealizowane';
        }

        $queryBuilder = $connection->createQueryBuilder()
            ->select('*')
            ->from(self::STATUSES_TABLE)
            ->setMaxResults(1)
            ->setParameter('status', $statusValue);

        $queryBuilder->where('id = :status OR name = :status');

        $status = $queryBuilder->executeQuery()->fetchAssociative();

        if (!is_array($status)) {
            return $statusValue === 'zrealizowane';
        }

        return $this->isTruthy($status['zrealizowane'] ?? false);
    }

    public function shouldAssignCodesForStatus(Connection $connection, mixed $statusId): bool
    {
        $statusValue = trim((string) $statusId);
        if ($statusValue === '') {
            return false;
        }

        if ($statusValue === 'predefined:prize_granted_email_template') {
            return true;
        }

        if (!$this->tableExists($connection, self::STATUSES_TABLE)) {
            return $statusValue === 'zrealizowane';
        }

        $status = $connection->createQueryBuilder()
            ->select('*')
            ->from(self::STATUSES_TABLE)
            ->where('id = :status OR name = :status')
            ->setParameter('status', $statusValue)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($status)) {
            return $statusValue === 'zrealizowane';
        }

        if ($this->isTruthy($status['zrealizowane'] ?? false)) {
            return true;
        }

        if ($this->isTruthy($status['do_wysylki'] ?? false)) {
            return true;
        }

        return trim((string) ($status['email_template'] ?? '')) === 'predefined:prize_granted_email_template';
    }

    /** @return array{success: bool, error: string} */
    public function validateAvailableCodesForWinners(Connection $connection, int $prizeTypeId, int $winnersCount): array
    {
        if ($winnersCount <= 0) {
            return ['success' => true, 'error' => ''];
        }

        $prizeType = $this->fetchPrizeType($connection, $prizeTypeId);
        if ($prizeType === null) {
            return ['success' => false, 'error' => 'Nie znaleziono rodzaju nagrody do sprawdzenia puli kodów.'];
        }
        $requirements = $this->parseCodeRequirements($prizeType['granted_codes'] ?? null);
        $codesTable = $this->resolveCodesTable($prizeType);
        $requiresFallbackCode = $requirements === []
            && trim((string) ($prizeType['prize_granted_email_template'] ?? '')) !== '';
        if ($requirements === [] && !$requiresFallbackCode) {
            return ['success' => true, 'error' => ''];
        }
        if (!$this->tableExists($connection, $codesTable)) {
            return ['success' => false, 'error' => 'Brak tabeli z pulą kodów dla wybranego formatu.'];
        }

        $columns = $this->listColumns($connection, $codesTable);
        if ($requiresFallbackCode) {
            $available = $this->countFreeCodes($connection, $columns, $codesTable);
            if ($available < $winnersCount) {
                if ($this->hasCodeShortageStatus($prizeType)) {
                    return ['success' => true, 'error' => ''];
                }

                return [
                    'success' => false,
                    'error' => sprintf(
                        'Za mało wolnych kodów dla importu: wymagane %d, dostępne %d.',
                        $winnersCount,
                        $available
                    ),
                ];
            }

            return ['success' => true, 'error' => ''];
        }

        foreach ($requirements as $amount => $countPerWinner) {
            $required = $countPerWinner * $winnersCount;
            $available = $this->countFreeCodes($connection, $columns, $codesTable, $amount);
            if ($available < $required) {
                if ($this->hasCodeShortageStatus($prizeType)) {
                    return ['success' => true, 'error' => ''];
                }

                return [
                    'success' => false,
                    'error' => sprintf(
                        'Za mało wolnych kodów o wartości %s dla importu: wymagane %d, dostępne %d.',
                        $amount,
                        $required,
                        $available
                    ),
                ];
            }
        }

        return ['success' => true, 'error' => ''];
    }

    /** @return array<string, mixed>|null */
    private function fetchPrizeType(Connection $connection, int $prizeTypeId): ?array
    {
        if ($prizeTypeId <= 0 || !$this->tableExists($connection, self::PRIZE_TYPES_TABLE)) {
            return null;
        }

        $row = $connection->createQueryBuilder()
            ->select('*')
            ->from(self::PRIZE_TYPES_TABLE)
            ->where('id = :id')
            ->setParameter('id', $prizeTypeId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) && $row !== [] ? $row : null;
    }

    /** @param array<string, mixed> $prizeType */
    private function resolveCodesTable(array $prizeType): string
    {
        return mb_strtolower(trim((string) ($prizeType['typ_kodow'] ?? ''))) === 'graficzne'
            ? self::GRAPHIC_CODES_TABLE
            : self::CODES_TABLE;
    }

    /** @param array<string, mixed> $winner */
    private function resolvePrizeTypeFromWinnerRelations(Connection $connection, array $winner): ?array
    {
        $ticket = null;
        $scratchcardId = (int) ($winner['zdrapka_w_loterii'] ?? 0);
        if ($scratchcardId > 0) {
            $ticket = $this->fetchRowById($connection, self::SCRATCHCARDS_TABLE, $scratchcardId);
        }

        if ($ticket === null) {
            $ticketId = (int) ($winner['kupon_w_loterii'] ?? 0);
            if ($ticketId > 0) {
                $ticket = $this->fetchRowById($connection, self::TICKETS_TABLE, $ticketId);
            }
        }

        if ($ticket === null) {
            return null;
        }

        $schedulePrizeTypeId = $this->resolvePrizeTypeIdFromTicketSchedule($connection, $ticket);
        if ($schedulePrizeTypeId > 0) {
            return $this->fetchPrizeType($connection, $schedulePrizeTypeId);
        }

        $ticketPrizeName = trim((string) ($ticket['prize_name'] ?? ''));
        if ($ticketPrizeName === '') {
            return null;
        }

        return $this->fetchPrizeTypeByName(
            $connection,
            $ticketPrizeName,
            trim((string) ($ticket['loteria'] ?? ($winner['loteria'] ?? '')))
        );
    }

    /** @return array<string, mixed>|null */
    private function fetchRowById(Connection $connection, string $table, int $id): ?array
    {
        if ($id <= 0 || !$this->tableExists($connection, $table)) {
            return null;
        }

        $row = $connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where('id = :id')
            ->setParameter('id', $id)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) && $row !== [] ? $row : null;
    }

    /** @param array<string, mixed> $ticket */
    private function resolvePrizeTypeIdFromTicketSchedule(Connection $connection, array $ticket): int
    {
        if (!$this->tableExists($connection, self::SCHEDULE_TABLE)) {
            return 0;
        }

        $scheduleId = (int) ($ticket['win_schedule_id'] ?? 0);
        if ($scheduleId > 0) {
            $prizeTypeId = $connection->createQueryBuilder()
                ->select('rodzaj_nagrody')
                ->from(self::SCHEDULE_TABLE)
                ->where('id = :id')
                ->setParameter('id', $scheduleId)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            if (is_scalar($prizeTypeId) && (int) $prizeTypeId > 0) {
                return (int) $prizeTypeId;
            }
        }

        $ticketEntryId = (int) ($ticket['id'] ?? 0);
        if ($ticketEntryId <= 0) {
            return 0;
        }

        $prizeTypeId = $connection->createQueryBuilder()
            ->select('rodzaj_nagrody')
            ->from(self::SCHEDULE_TABLE)
            ->where('granted_to_ticket_id = :ticketEntryId')
            ->setParameter('ticketEntryId', $ticketEntryId)
            ->orderBy('id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_scalar($prizeTypeId) && (int) $prizeTypeId > 0 ? (int) $prizeTypeId : 0;
    }

    private function fetchPrizeTypeByName(Connection $connection, string $prizeName, string $lotteryId = ''): ?array
    {
        if (!$this->tableExists($connection, self::PRIZE_TYPES_TABLE)) {
            return null;
        }

        $normalizedPrizeName = $this->normalizeName($prizeName);
        if ($normalizedPrizeName === '') {
            return null;
        }

        $rows = $connection->createQueryBuilder()
            ->select('*')
            ->from(self::PRIZE_TYPES_TABLE)
            ->executeQuery()
            ->fetchAllAssociative();

        $candidates = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $assignedTo = mb_strtolower(trim((string) ($row['rodzaj_nagrody'] ?? '')));
            if ($assignedTo !== '' && !in_array($assignedTo, ['zdrapki', 'losy'], true)) {
                continue;
            }

            $rowLotteryId = trim((string) ($row['loteria'] ?? ''));
            if ($lotteryId !== '' && $rowLotteryId !== '' && $rowLotteryId !== $lotteryId) {
                continue;
            }

            $normalizedDisplayName = $this->normalizeName((string) ($row['nazwa_nagrody_do_wyswietlenia'] ?? ''));
            $normalizedName = $this->normalizeName((string) ($row['name'] ?? ''));
            if ($normalizedPrizeName === $normalizedDisplayName || $normalizedPrizeName === $normalizedName) {
                return $row;
            }

            $candidates[] = $row;
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function syncWinnerPrizeType(Connection $connection, int $winnerId, int $prizeTypeId): void
    {
        if ($winnerId <= 0 || $prizeTypeId <= 0 || !$this->tableExists($connection, self::WINNERS_TABLE)) {
            return;
        }

        $winnerColumns = $this->listColumns($connection, self::WINNERS_TABLE);
        if (!isset($winnerColumns['rodzaj_wygranej'])) {
            return;
        }

        $connection->update(self::WINNERS_TABLE, ['rodzaj_wygranej' => $prizeTypeId], ['id' => $winnerId]);
    }

    /** @return array<string, int> */
    private function parseCodeRequirements(mixed $rawValue): array
    {
        if (is_string($rawValue)) {
            $decoded = json_decode($rawValue, true);
        } elseif (is_array($rawValue)) {
            $decoded = $rawValue;
        } else {
            $decoded = null;
        }

        if (!is_array($decoded)) {
            return [];
        }

        $requirements = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }

            $count = (int) ($item['codes_count'] ?? 0);
            $amount = $this->normalizeAmount($item['code_amount'] ?? '');
            if ($count <= 0 || $amount === '') {
                continue;
            }

            $requirements[$amount] = ($requirements[$amount] ?? 0) + $count;
        }

        return $requirements;
    }

    /** @param array<int, array<string, mixed>> $assignedRows @param array<string, int> $requirements @return array<string, int> */
    private function calculateMissingRequirements(array $requirements, array $assignedRows): array
    {
        $assignedCounts = [];
        foreach ($assignedRows as $row) {
            $amount = $this->normalizeAmount($row['wartosc_kodu'] ?? '');
            if ($amount === '') {
                continue;
            }

            $assignedCounts[$amount] = ($assignedCounts[$amount] ?? 0) + 1;
        }

        $missing = [];
        foreach ($requirements as $amount => $count) {
            $remaining = $count - ($assignedCounts[$amount] ?? 0);
            if ($remaining > 0) {
                $missing[$amount] = $remaining;
            }
        }

        return $missing;
    }

    /** @param array<string, bool> $columns @return array<int, array<string, mixed>> */
    private function fetchAssignedCodeRows(Connection $connection, int $winnerId, array $columns, string $codesTable): array
    {
        if (!isset($columns['kod_przyznany_do_zwyciezcy'])) {
            return [];
        }

        return $connection->createQueryBuilder()
            ->select('*')
            ->from($codesTable)
            ->where('kod_przyznany_do_zwyciezcy = :winnerId')
            ->setParameter('winnerId', $winnerId)
            ->orderBy('id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /** @param array<string, bool> $columns @param array<string, mixed> $winner @return array<int, array<string, mixed>> */
    private function fetchFreeCodeRows(Connection $connection, array $columns, string $amount, int $count, array $winner, string $codesTable): array
    {
        if (!isset($columns['wartosc_kodu']) || $count <= 0) {
            return [];
        }

        $amountVariants = $this->buildAmountVariants($amount);
        if ($amountVariants === []) {
            return [];
        }

        $queryBuilder = $connection->createQueryBuilder()
            ->select('*')
            ->from($codesTable)
            ->where("(kod_wykorzystany = 0 OR kod_wykorzystany IS NULL OR kod_wykorzystany = '')")
            ->orderBy('id', 'ASC')
            ->setMaxResults($count);

        if (isset($columns['kod_przyznany_do_zwyciezcy'])) {
            $queryBuilder->andWhere("(kod_przyznany_do_zwyciezcy IS NULL OR kod_przyznany_do_zwyciezcy = 0 OR kod_przyznany_do_zwyciezcy = '')");
        }

        $amountConditions = [];
        foreach ($amountVariants as $index => $amountVariant) {
            $parameterName = 'amount' . $index;
            $amountConditions[] = 'wartosc_kodu = :' . $parameterName;
            $queryBuilder->setParameter($parameterName, $amountVariant);
        }
        $queryBuilder->andWhere('(' . implode(' OR ', $amountConditions) . ')');

        return $this->executeCodeLookupQuery($connection, $queryBuilder);
    }

    /** @param array<string, mixed> $winner @param array<string, bool> $columns @return array<string, mixed> */
    private function buildCodeUpdateData(array $winner, array $columns, int $winnerId, string $assignedAt): array
    {
        $data = [];
        $candidateValues = [
            'kod_wykorzystany' => 1,
            'kod_przyznany_do_zgloszenia' => (int) ($winner['zgloszenie'] ?? 0) ?: null,
            'kod_przyznany_do_losu' => (int) ($winner['kupon_w_loterii'] ?? 0) ?: null,
            'kod_przyznany_do_losy' => (int) ($winner['kupon_w_loterii'] ?? 0) ?: null,
            'kod_przyznany_do_zdrapki' => (int) ($winner['zdrapka_w_loterii'] ?? 0) ?: null,
            'kod_przyznany_do_zwyciezcy' => $winnerId,
            'data_i_godzina_przyznania' => $assignedAt,
        ];

        foreach ($candidateValues as $column => $value) {
            if (isset($columns[$column])) {
                $data[$column] = $value;
            }
        }

        return $data;
    }

    /** @param array<string, bool> $columns @param array<string, mixed> $winner */
    private function claimCodeRow(Connection $connection, array $columns, int $codeId, array $winner, int $winnerId, string $assignedAt, string $codesTable): bool
    {
        if ($codeId <= 0 || $winnerId <= 0) {
            return false;
        }

        $updateData = $this->buildCodeUpdateData($winner, $columns, $winnerId, $assignedAt);
        if ($updateData === []) {
            return false;
        }

        $queryBuilder = $connection->createQueryBuilder()
            ->update($codesTable)
            ->where('id = :codeId')
            ->andWhere("(kod_wykorzystany = 0 OR kod_wykorzystany IS NULL OR kod_wykorzystany = '')")
            ->setParameter('codeId', $codeId);

        if (isset($columns['kod_przyznany_do_zwyciezcy'])) {
            $queryBuilder->andWhere("(kod_przyznany_do_zwyciezcy IS NULL OR kod_przyznany_do_zwyciezcy = 0 OR kod_przyznany_do_zwyciezcy = '')");
        }

        foreach ($updateData as $column => $value) {
            if (!isset($columns[$column])) {
                continue;
            }

            $parameterName = 'set_' . $column;
            $queryBuilder->set($column, ':' . $parameterName);
            $queryBuilder->setParameter($parameterName, $value);
        }

        return $queryBuilder->executeStatement() === 1;
    }

    /** @param array<string, mixed> $winner @param array<string, mixed> $prizeType */
    private function assignSingleFallbackCode(Connection $connection, array $winner, array $prizeType, string $codesTable): array
    {
        if (!$this->tableExists($connection, $codesTable)) {
            return $this->failure($winner, 'Brak tabeli z listÄ… kodĂłw wygranych.', $prizeType);
        }

        $winnerId = (int) ($winner['id'] ?? 0);
        $codeColumns = $this->listColumns($connection, $codesTable);
        $winnerColumns = $this->tableExists($connection, self::WINNERS_TABLE)
            ? $this->listColumns($connection, self::WINNERS_TABLE)
            : [];
        $assignedRows = $this->fetchAssignedCodeRows($connection, $winnerId, $codeColumns, $codesTable);

        if ($assignedRows === []) {
            $freeRows = $this->fetchFreeAnyCodeRows($connection, $codeColumns, 1, $codesTable);
            if ($freeRows === []) {
                return $this->handleCodeShortage(
                    $connection,
                    $winner,
                    'Brak wolnych kodów do przydzielenia dla nagrody.',
                    $prizeType
                );
            }

            $assignedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $ownsTransaction = !$connection->isTransactionActive();
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }
            try {
                $freeRow = $freeRows[0];
                if (!$this->claimCodeRow($connection, $codeColumns, (int) $freeRow['id'], $winner, $winnerId, $assignedAt, $codesTable)) {
                    if ($ownsTransaction) {
                        $connection->rollBack();
                    }

                    return $this->failure($winner, 'Nie udalo sie przydzielic kodu, poniewaz zostal wlasnie zajety przez inne zgloszenie. Sprobuj ponownie.', $prizeType);
                }

                $freeRow['kod_przyznany_do_zwyciezcy'] = $winnerId;
                $freeRow['data_i_godzina_przyznania'] = $assignedAt;
                $assignedRows[] = $freeRow;
                $winner = $this->syncWinnerWithAssignedCodes($connection, $winner, $winnerColumns, $assignedRows, $codesTable);
                if ($ownsTransaction) {
                    $connection->commit();
                }
            } catch (\Throwable $exception) {
                if ($ownsTransaction && $connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                return $this->failure($winner, 'Nie udaĹ‚o siÄ™ przydzieliÄ‡ kodu: ' . $exception->getMessage(), $prizeType);
            }
        } else {
            $winner = $this->syncWinnerWithAssignedCodes($connection, $winner, $winnerColumns, $assignedRows, $codesTable);
        }

        $codeRows = $this->buildEmailCodeRows($assignedRows, $codesTable);

        return [
            'success' => true,
            'assigned' => true,
            'skipped' => false,
            'message' => 'Przydzielono kod do zwyciÄ™zcy.',
            'winner' => $winner,
            'prize_type' => $prizeType,
            'codes_500' => $this->extractCodesByAmount($codeRows, '500'),
            'codes_1000' => $this->extractCodesByAmount($codeRows, '1000'),
            'code_rows' => $codeRows,
        ];
    }

    /**
     * Brak kodów jest obsługiwany jako osobna, prawidłowa ścieżka tylko wtedy,
     * gdy rodzaj nagrody ma wskazany status awaryjny. Dzięki temu błędy
     * konfiguracji nadal zatrzymują proces, a wyczerpana pula nie uruchamia
     * kolejnych prób CRON-u.
     *
     * @param array<string, mixed> $winner
     * @param array<string, mixed> $prizeType
     * @return array<string, mixed>
     */
    private function handleCodeShortage(
        Connection $connection,
        array $winner,
        string $shortageMessage,
        array $prizeType
    ): array {
        $shortageStatus = trim((string) ($prizeType['status_po_wygraniu_nagrody_gdy_brak_kodow'] ?? ''));
        if ($shortageStatus === '') {
            return $this->failure($winner, $shortageMessage, $prizeType, 'insufficient_codes');
        }

        $winnerId = (int) ($winner['id'] ?? 0);
        if (
            $winnerId <= 0
            || !$this->tableExists($connection, self::WINNERS_TABLE)
            || !isset($this->listColumns($connection, self::WINNERS_TABLE)['stan'])
        ) {
            return $this->failure(
                $winner,
                $shortageMessage . ' Nie udało się ustawić statusu dla braku kodów.',
                $prizeType,
                'insufficient_codes'
            );
        }

        $connection->update(self::WINNERS_TABLE, ['stan' => $shortageStatus], ['id' => $winnerId]);
        $winner['stan'] = $shortageStatus;

        return [
            'success' => true,
            'assigned' => false,
            'skipped' => false,
            'code_shortage' => true,
            'effective_status_id' => $shortageStatus,
            'message' => $shortageMessage . ' Ustawiono status skonfigurowany dla braku kodów.',
            'winner' => $winner,
            'prize_type' => $prizeType,
            'codes_500' => [],
            'codes_1000' => [],
            'code_rows' => [],
        ];
    }

    /** @param array<string, mixed> $prizeType */
    private function hasCodeShortageStatus(array $prizeType): bool
    {
        return trim((string) ($prizeType['status_po_wygraniu_nagrody_gdy_brak_kodow'] ?? '')) !== '';
    }

    /** @param array<string, bool> $columns @return array<int, array<string, mixed>> */
    private function fetchFreeAnyCodeRows(Connection $connection, array $columns, int $count, string $codesTable): array
    {
        if ($count <= 0) {
            return [];
        }

        $queryBuilder = $connection->createQueryBuilder()
            ->select('*')
            ->from($codesTable)
            ->where("(kod_wykorzystany = 0 OR kod_wykorzystany IS NULL OR kod_wykorzystany = '')")
            ->orderBy('id', 'ASC')
            ->setMaxResults($count);

        if (isset($columns['kod_przyznany_do_zwyciezcy'])) {
            $queryBuilder->andWhere("(kod_przyznany_do_zwyciezcy IS NULL OR kod_przyznany_do_zwyciezcy = 0 OR kod_przyznany_do_zwyciezcy = '')");
        }

        return $this->executeCodeLookupQuery($connection, $queryBuilder);
    }

    /** @param array<string, bool> $columns */
    private function countFreeCodes(Connection $connection, array $columns, string $codesTable, ?string $amount = null): int
    {
        $queryBuilder = $connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($codesTable)
            ->where("(kod_wykorzystany = 0 OR kod_wykorzystany IS NULL OR kod_wykorzystany = '')");

        if (isset($columns['kod_przyznany_do_zwyciezcy'])) {
            $queryBuilder->andWhere("(kod_przyznany_do_zwyciezcy IS NULL OR kod_przyznany_do_zwyciezcy = 0 OR kod_przyznany_do_zwyciezcy = '')");
        }

        if ($amount !== null) {
            if (!isset($columns['wartosc_kodu'])) {
                return 0;
            }
            $amountConditions = [];
            foreach ($this->buildAmountVariants($amount) as $index => $variant) {
                $parameter = 'capacity_amount_' . $index;
                $amountConditions[] = 'wartosc_kodu = :' . $parameter;
                $queryBuilder->setParameter($parameter, $variant);
            }
            if ($amountConditions === []) {
                return 0;
            }
            $queryBuilder->andWhere('(' . implode(' OR ', $amountConditions) . ')');
        }

        return (int) $queryBuilder->executeQuery()->fetchOne();
    }

    /** @return array<int, array<string, mixed>> */
    private function executeCodeLookupQuery(Connection $connection, QueryBuilder $queryBuilder): array
    {
        $sql = $queryBuilder->getSQL();
        if ($connection->isTransactionActive() && $this->supportsSelectForUpdate($connection)) {
            $sql .= ' FOR UPDATE';
        }

        return $connection
            ->executeQuery($sql, $queryBuilder->getParameters(), $queryBuilder->getParameterTypes())
            ->fetchAllAssociative();
    }

    private function supportsSelectForUpdate(Connection $connection): bool
    {
        return in_array(strtolower($connection->getDatabasePlatform()->getName()), ['mysql', 'mariadb', 'postgresql'], true);
    }

    /** @param array<string, mixed> $winner @param array<string, bool> $winnerColumns @param array<int, array<string, mixed>> $assignedRows @return array<string, mixed> */
    private function syncWinnerWithAssignedCodes(Connection $connection, array $winner, array $winnerColumns, array $assignedRows, string $codesTable): array
    {
        if ($assignedRows === [] || $winnerColumns === []) {
            return $winner;
        }

        $firstRow = $assignedRows[0];
        $assignedCodeIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int) ($row['id'] ?? 0),
            $assignedRows
        ), static fn(int $id): bool => $id > 0));
        $updateData = [];
        $candidateValues = $codesTable === self::GRAPHIC_CODES_TABLE
            ? ['kod_z_listy_kodow_graficznych' => $assignedCodeIds !== [] ? json_encode($assignedCodeIds) : null]
            : [
                'pickup_code' => trim((string) ($firstRow['pickup_code'] ?? '')),
                'kod_z_listy_wygranych' => $assignedCodeIds !== [] ? json_encode($assignedCodeIds) : null,
            ];

        foreach ($candidateValues as $column => $value) {
            if (isset($winnerColumns[$column])) {
                $updateData[$column] = $value;
                $winner[$column] = $value;
            }
        }

        if ($updateData !== []) {
            $connection->update(self::WINNERS_TABLE, $updateData, ['id' => (int) $winner['id']]);
        }

        return $winner;
    }

    /** @param array<int, array<string, mixed>> $assignedRows @return array<int, array{pickup_code: string, kwota: string, kod_ean: bool, code_128: bool}> */
    private function buildEmailCodeRows(array $assignedRows, string $codesTable): array
    {
        $rows = [];
        foreach ($assignedRows as $row) {
            $isGraphic = $codesTable === self::GRAPHIC_CODES_TABLE;
            $pickupCode = trim((string) ($isGraphic ? ($row['nazwa_pliku'] ?? '') : ($row['pickup_code'] ?? '')));
            if ($pickupCode === '') {
                continue;
            }

            $rows[] = [
                'pickup_code' => $pickupCode,
                'kwota' => $this->normalizeAmount($row['wartosc_kodu'] ?? ''),
                'kod_ean' => $this->isTruthy($row['kod_ean'] ?? false),
                'code_128' => $this->isTruthy($row['code_128'] ?? false),
                'graphic_path' => $isGraphic ? trim((string) ($row['sciezka_pliku'] ?? '')) : '',
                'graphic_filename' => $isGraphic ? trim((string) ($row['nazwa_pliku'] ?? '')) : '',
                'graphic_mime' => $isGraphic ? trim((string) ($row['typ_pliku'] ?? '')) : '',
            ];
        }

        return $rows;
    }

    /** @param array<int, array{pickup_code: string, kwota: string, kod_ean: bool, code_128: bool}> $codeRows @return array<int, string> */
    private function extractCodesByAmount(array $codeRows, string $amount): array
    {
        return array_values(array_map(
            static fn(array $row): string => $row['pickup_code'],
            array_filter($codeRows, static fn(array $row): bool => $row['kwota'] === $amount)
        ));
    }

    private function normalizeAmount(mixed $value): string
    {
        $normalized = trim(str_replace(',', '.', (string) $value));
        if ($normalized === '') {
            return '';
        }

        if (is_numeric($normalized)) {
            $floatValue = (float) $normalized;
            return floor($floatValue) === $floatValue ? (string) (int) $floatValue : rtrim(rtrim(number_format($floatValue, 2, '.', ''), '0'), '.');
        }

        return mb_strtolower($normalized);
    }

    /** @return array<int, string> */
    private function buildAmountVariants(mixed $value): array
    {
        $raw = trim((string) $value);
        $normalized = $this->normalizeAmount($raw);
        if ($normalized === '') {
            return [];
        }

        $variants = [$raw, $normalized, str_replace('.', ',', $normalized)];
        if (is_numeric(str_replace(',', '.', $raw))) {
            $floatValue = (float) str_replace(',', '.', $raw);
            $variants[] = number_format($floatValue, 2, '.', '');
            $variants[] = number_format($floatValue, 2, ',', '');
            if (floor($floatValue) === $floatValue) {
                $variants[] = (string) (int) $floatValue;
            }
        }

        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $variant): string => trim((string) $variant),
            $variants
        ), static fn(string $variant): bool => $variant !== '')));
    }

    private function normalizeName(string $value): string
    {
        $value = strip_tags($value);
        $value = str_replace(["\xc2\xa0", '&nbsp;'], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value));
    }

    /** @return array<string, bool> */
    private function listColumns(Connection $connection, string $table): array
    {
        $columns = [];
        foreach ($connection->createSchemaManager()->listTableColumns($table) as $name => $column) {
            $columns[strtolower((string) $name)] = true;
        }

        return $columns;
    }

    private function tableExists(Connection $connection, string $table): bool
    {
        return $connection->createSchemaManager()->tablesExist([$table]);
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'tak', 'yes', 'on'], true);
    }

    /** @param array<string, mixed> $winner @param array<string, mixed>|null $prizeType @return array{success: false, assigned: false, skipped: false, message: string, winner: array<string, mixed>, prize_type?: array<string, mixed>, codes_500: array<int, string>, codes_1000: array<int, string>, code_rows: array<int, array{pickup_code: string, kwota: string, kod_ean: bool, code_128: bool}>, error: string} */
    private function failure(array $winner, string $error, ?array $prizeType = null, ?string $failureReason = null): array
    {
        $result = [
            'success' => false,
            'assigned' => false,
            'skipped' => false,
            'message' => '',
            'winner' => $winner,
            'codes_500' => [],
            'codes_1000' => [],
            'code_rows' => [],
            'error' => $error,
        ];

        if ($prizeType !== null) {
            $result['prize_type'] = $prizeType;
        }
        if ($failureReason !== null) {
            $result['failure_reason'] = $failureReason;
        }

        return $result;
    }

    /** @param array<string, mixed> $winner @param array<string, mixed> $prizeType @return array{success: true, assigned: bool, skipped: bool, message: string, winner: array<string, mixed>, prize_type: array<string, mixed>, codes_500: array<int, string>, codes_1000: array<int, string>, code_rows: array<int, array{pickup_code: string, kwota: string, kod_ean: bool, code_128: bool}>} */
    private function success(array $winner, array $prizeType, bool $assigned, bool $skipped, string $message): array
    {
        return [
            'success' => true,
            'assigned' => $assigned,
            'skipped' => $skipped,
            'message' => $message,
            'winner' => $winner,
            'prize_type' => $prizeType,
            'codes_500' => [],
            'codes_1000' => [],
            'code_rows' => [],
        ];
    }
}
