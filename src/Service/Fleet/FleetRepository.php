<?php

namespace App\Service\Fleet;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Dostęp do danych floty (tabele modułów pojazdy, kierowcy, wyjazdy, tankowania, jednostka).
 */
class FleetRepository
{
    public const VEHICLES = 'pojazdy_entries';
    public const DRIVERS = 'kierowcy_entries';
    public const TRIPS = 'wyjazdy_entries';
    public const FUEL = 'tankowania_entries';
    public const UNIT = 'jednostka_entries';

    private const TRIP_SELECT = 'w.*,
        p.nr_rejestracyjny AS pojazd_nr, p.marka AS pojazd_marka, p.model AS pojazd_model, p.rodzaj AS pojazd_rodzaj,
        p.kolor AS pojazd_kolor, p.norma_zuzycia AS pojazd_norma, p.rodzaj_paliwa AS pojazd_paliwo,
        k.stopien AS kierowca_stopien, k.imie AS kierowca_imie, k.nazwisko AS kierowca_nazwisko, k.telefon AS kierowca_telefon';

    public function __construct(private Connection $connection)
    {
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    private ?bool $installed = null;
    private ?array $unit = null;

    public function isInstalled(): bool
    {
        return $this->installed ??= $this->connection->createSchemaManager()->tablesExist([self::VEHICLES, self::DRIVERS, self::TRIPS, self::FUEL, self::UNIT]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function vehicles(bool $includeRetired = false): array
    {
        $sql = 'SELECT * FROM ' . self::VEHICLES;
        if (!$includeRetired) {
            $sql .= " WHERE status IS NULL OR status <> 'wycofany'";
        }
        $sql .= " ORDER BY CASE WHEN rodzaj = 'wojskowy' THEN 0 ELSE 1 END, nr_rejestracyjny";

        return $this->connection->fetchAllAssociative($sql);
    }

    public function vehicle(int $id): ?array
    {
        return $this->connection->fetchAssociative('SELECT * FROM ' . self::VEHICLES . ' WHERE id = ?', [$id]) ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function drivers(bool $includeInactive = false): array
    {
        $sql = 'SELECT * FROM ' . self::DRIVERS;
        if (!$includeInactive) {
            $sql .= " WHERE status IS NULL OR status <> 'nieaktywny'";
        }

        return $this->connection->fetchAllAssociative($sql . ' ORDER BY nazwisko, imie');
    }

    public function driver(int $id): ?array
    {
        return $this->connection->fetchAssociative('SELECT * FROM ' . self::DRIVERS . ' WHERE id = ?', [$id]) ?: null;
    }

    public function driverByEmail(string $email): ?array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        return $this->connection->fetchAssociative(
            'SELECT * FROM ' . self::DRIVERS . ' WHERE LOWER(TRIM(email)) = ? ORDER BY id LIMIT 1',
            [$email]
        ) ?: null;
    }

    /**
     * Wyjazdy nachodzące na przedział [from, to).
     *
     * @param array{pojazd?: int|null, kierowca?: int|null, status?: string[]|null, include_cancelled?: bool} $filters
     * @return array<int, array<string, mixed>>
     */
    public function tripsBetween(\DateTimeInterface $from, \DateTimeInterface $to, array $filters = []): array
    {
        $qb = $this->tripQuery()
            ->where('w.data_od < :to')
            ->andWhere('w.data_do > :from')
            ->setParameter('from', $from->format('Y-m-d H:i:s'))
            ->setParameter('to', $to->format('Y-m-d H:i:s'))
            ->orderBy('w.data_od', 'ASC');

        $this->applyTripFilters($qb, $filters);

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param array{pojazd?: int|null, kierowca?: int|null, status?: string[]|null, include_cancelled?: bool, q?: string|null, od?: string|null, do?: string|null} $filters
     * @return array<int, array<string, mixed>>
     */
    public function searchTrips(array $filters, int $limit = 200): array
    {
        $qb = $this->tripQuery()->orderBy('w.data_od', 'DESC')->setMaxResults($limit);
        $filters['include_cancelled'] ??= true;
        $this->applyTripFilters($qb, $filters);

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $qb->andWhere('(w.numer LIKE :q OR w.cel LIKE :q OR w.miejsce_docelowe LIKE :q OR p.nr_rejestracyjny LIKE :q OR k.nazwisko LIKE :q)')
                ->setParameter('q', '%' . $q . '%');
        }
        if (!empty($filters['od'])) {
            $qb->andWhere('w.data_do >= :od')->setParameter('od', $filters['od'] . ' 00:00:00');
        }
        if (!empty($filters['do'])) {
            $qb->andWhere('w.data_od <= :do')->setParameter('do', $filters['do'] . ' 23:59:59');
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    public function trip(int $id): ?array
    {
        return $this->tripQuery()->where('w.id = :id')->setParameter('id', $id)->executeQuery()->fetchAssociative() ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function upcomingTripsForDriver(int $driverId, int $limit = 30): array
    {
        return $this->tripQuery()
            ->where('w.kierowca = :driver')
            ->andWhere("(w.status IS NULL OR w.status NOT IN ('anulowany', 'zakonczony'))")
            ->andWhere('w.data_do >= :now OR w.status = :running')
            ->setParameter('driver', $driverId)
            ->setParameter('now', (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'))
            ->setParameter('running', 'w_realizacji')
            ->orderBy('w.data_od', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentTripsForDriver(int $driverId, int $limit = 10): array
    {
        return $this->tripQuery()
            ->where('w.kierowca = :driver')
            ->andWhere("w.status = 'zakonczony'")
            ->setParameter('driver', $driverId)
            ->orderBy('w.data_od', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Wyjazdy tego samego pojazdu lub kierowcy nachodzące na podany przedział (bez anulowanych).
     *
     * @return array<int, array<string, mixed>>
     */
    public function overlappingTrips(string $from, string $to, ?int $vehicleId, ?int $driverId, ?int $excludeId = null): array
    {
        if (!$vehicleId && !$driverId) {
            return [];
        }

        $qb = $this->tripQuery()
            ->where('w.data_od < :to')
            ->andWhere('w.data_do > :from')
            ->andWhere("(w.status IS NULL OR w.status <> 'anulowany')")
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        $or = [];
        if ($vehicleId) {
            $or[] = 'w.pojazd = :vehicle';
            $qb->setParameter('vehicle', $vehicleId);
        }
        if ($driverId) {
            $or[] = 'w.kierowca = :driver';
            $qb->setParameter('driver', $driverId);
        }
        $qb->andWhere('(' . implode(' OR ', $or) . ')');

        if ($excludeId) {
            $qb->andWhere('w.id <> :exclude')->setParameter('exclude', $excludeId);
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param array<string, mixed> $values
     */
    public function insert(string $table, array $values): int
    {
        $this->connection->insert($table, $values);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param array<string, mixed> $values
     */
    public function update(string $table, int $id, array $values): void
    {
        if ($values !== []) {
            $this->connection->update($table, $values, ['id' => $id]);
        }
    }

    public function delete(string $table, int $id): void
    {
        $this->connection->delete($table, ['id' => $id]);
    }

    public function nextTripNumber(int $year): string
    {
        $numbers = $this->connection->fetchFirstColumn(
            'SELECT numer FROM ' . self::TRIPS . ' WHERE numer LIKE ?',
            ['%/' . $year]
        );
        $max = 0;
        foreach ($numbers as $number) {
            $max = max($max, (int) strtok((string) $number, '/'));
        }

        return sprintf('%04d/%d', $max + 1, $year);
    }

    /**
     * @param array{pojazd?: int|null, od?: string|null, do?: string|null} $filters
     * @return array<int, array<string, mixed>>
     */
    public function fuelEntries(array $filters = [], int $limit = 200): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('t.*', 'p.nr_rejestracyjny AS pojazd_nr', 'p.marka AS pojazd_marka', 'p.model AS pojazd_model',
                'k.stopien AS kierowca_stopien', 'k.imie AS kierowca_imie', 'k.nazwisko AS kierowca_nazwisko', 'w.numer AS wyjazd_numer')
            ->from(self::FUEL, 't')
            ->leftJoin('t', self::VEHICLES, 'p', 'p.id = t.pojazd')
            ->leftJoin('t', self::DRIVERS, 'k', 'k.id = t.kierowca')
            ->leftJoin('t', self::TRIPS, 'w', 'w.id = t.wyjazd')
            ->orderBy('t.data', 'DESC')
            ->setMaxResults($limit);

        if (!empty($filters['pojazd'])) {
            $qb->andWhere('t.pojazd = :pojazd')->setParameter('pojazd', (int) $filters['pojazd']);
        }
        if (!empty($filters['od'])) {
            $qb->andWhere('t.data >= :od')->setParameter('od', $filters['od']);
        }
        if (!empty($filters['do'])) {
            $qb->andWhere('t.data < :do')->setParameter('do', $filters['do']);
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * @return array<string, mixed>
     */
    public function unit(): array
    {
        if (!$this->isInstalled()) {
            return [];
        }

        return $this->unit ??= $this->connection->fetchAssociative('SELECT * FROM ' . self::UNIT . ' ORDER BY id LIMIT 1') ?: [];
    }

    /**
     * @param int[] $ids
     * @return array<int, int>
     */
    public function tripCountsByVehicle(array $ids, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT pojazd, COUNT(*) AS cnt FROM ' . self::TRIPS . " WHERE pojazd IN (?) AND data_od < ? AND data_do > ? AND (status IS NULL OR status <> 'anulowany') GROUP BY pojazd",
            [$ids, $to->format('Y-m-d H:i:s'), $from->format('Y-m-d H:i:s')],
            [ArrayParameterType::INTEGER]
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['pojazd']] = (int) $row['cnt'];
        }

        return $counts;
    }

    public static function vehicleLabel(?array $vehicle): string
    {
        if (!$vehicle) {
            return '';
        }

        return trim(sprintf('%s %s %s', $vehicle['nr_rejestracyjny'] ?? '', $vehicle['marka'] ?? '', $vehicle['model'] ?? ''));
    }

    public static function driverLabel(?array $driver): string
    {
        if (!$driver) {
            return '';
        }

        return trim(preg_replace('/\s+/', ' ', sprintf('%s %s %s', $driver['stopien'] ?? '', $driver['imie'] ?? '', $driver['nazwisko'] ?? '')) ?? '');
    }

    private function tripQuery(): \Doctrine\DBAL\Query\QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(self::TRIP_SELECT)
            ->from(self::TRIPS, 'w')
            ->leftJoin('w', self::VEHICLES, 'p', 'p.id = w.pojazd')
            ->leftJoin('w', self::DRIVERS, 'k', 'k.id = w.kierowca');
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyTripFilters(\Doctrine\DBAL\Query\QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['pojazd'])) {
            $qb->andWhere('w.pojazd = :f_pojazd')->setParameter('f_pojazd', (int) $filters['pojazd']);
        }
        if (!empty($filters['kierowca'])) {
            $qb->andWhere('w.kierowca = :f_kierowca')->setParameter('f_kierowca', (int) $filters['kierowca']);
        }
        if (!empty($filters['status']) && is_array($filters['status'])) {
            $qb->andWhere('w.status IN (:f_status)')->setParameter('f_status', array_values($filters['status']), ArrayParameterType::STRING);
        } elseif (empty($filters['include_cancelled'])) {
            $qb->andWhere("(w.status IS NULL OR w.status <> 'anulowany')");
        }
    }
}
