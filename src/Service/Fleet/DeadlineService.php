<?php

namespace App\Service\Fleet;

/**
 * Terminy ważności dokumentów pojazdów i kierowców (przeglądy, OC, prawo jazdy, badania, pozwolenia).
 */
class DeadlineService
{
    public const VEHICLE_DEADLINES = [
        'przeglad_do' => 'Badanie techniczne',
        'obsluga_do' => 'Obsługa techniczna',
        'ubezpieczenie_do' => 'Ubezpieczenie OC',
    ];

    public const DRIVER_DEADLINES = [
        'prawo_jazdy_do' => 'Prawo jazdy',
        'pozwolenie_wojskowe_do' => 'Pozwolenie wojskowe',
        'badania_lekarskie_do' => 'Badania lekarskie',
        'badania_psychologiczne_do' => 'Badania psychologiczne',
    ];

    public function __construct(private FleetRepository $repository)
    {
    }

    public function warningDays(): int
    {
        $days = (int) ($this->repository->unit()['prog_alertu_dni'] ?? 0);

        return $days > 0 ? $days : 30;
    }

    /**
     * @return array<int, array{type: string, id: int, name: string, label: string, date: string, days: int, level: string}>
     */
    public function upcoming(?int $withinDays = null): array
    {
        $withinDays ??= $this->warningDays();
        $today = new \DateTimeImmutable('today');
        $items = [];

        foreach ($this->repository->vehicles() as $vehicle) {
            foreach (self::VEHICLE_DEADLINES as $field => $label) {
                $item = $this->item('pojazd', $vehicle, FleetRepository::vehicleLabel($vehicle), $field, $label, $today, $withinDays);
                if ($item) {
                    $items[] = $item;
                }
            }
        }

        foreach ($this->repository->drivers() as $driver) {
            foreach (self::DRIVER_DEADLINES as $field => $label) {
                $item = $this->item('kierowca', $driver, FleetRepository::driverLabel($driver), $field, $label, $today, $withinDays);
                if ($item) {
                    $items[] = $item;
                }
            }
        }

        usort($items, static fn(array $a, array $b): int => $a['days'] <=> $b['days']);

        return $items;
    }

    /**
     * Wszystkie terminy jednego rekordu (do karty pojazdu lub kierowcy).
     *
     * @param array<string, mixed> $row
     * @param array<string, string> $definitions
     * @return array<int, array{label: string, date: string|null, days: int|null, level: string}>
     */
    public function forRecord(array $row, array $definitions): array
    {
        $today = new \DateTimeImmutable('today');
        $warning = $this->warningDays();
        $items = [];
        foreach ($definitions as $field => $label) {
            $date = $row[$field] ?? null;
            if (!$date) {
                $items[] = ['label' => $label, 'date' => null, 'days' => null, 'level' => 'none'];
                continue;
            }
            $days = (int) $today->diff(new \DateTimeImmutable($date))->format('%r%a');
            $items[] = ['label' => $label, 'date' => $date, 'days' => $days, 'level' => self::level($days, $warning)];
        }

        return $items;
    }

    public static function level(int $days, int $warning): string
    {
        if ($days < 0) {
            return 'expired';
        }

        return $days <= $warning ? 'soon' : 'ok';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function item(string $type, array $row, string $name, string $field, string $label, \DateTimeImmutable $today, int $withinDays): ?array
    {
        $date = $row[$field] ?? null;
        if (!$date) {
            return null;
        }

        $days = (int) $today->diff(new \DateTimeImmutable($date))->format('%r%a');
        if ($days > $withinDays) {
            return null;
        }

        return [
            'type' => $type,
            'id' => (int) $row['id'],
            'name' => $name,
            'label' => $label,
            'date' => $date,
            'days' => $days,
            'level' => self::level($days, $withinDays),
        ];
    }
}
