<?php

namespace App\Service\Fleet;

/**
 * Miesięczne rozliczenie paliwa pojazdu: zużycie normatywne (km × norma) vs zużycie rzeczywiste
 * (stan początkowy + pobrane − stan końcowy).
 */
class FuelSettlement
{
    public function __construct(private FleetRepository $repository)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function settle(int $vehicleId, string $month): array
    {
        $vehicle = $this->repository->vehicle($vehicleId);
        if (!$vehicle) {
            throw new \InvalidArgumentException('Nie ma takiego pojazdu.');
        }

        $from = \DateTimeImmutable::createFromFormat('!Y-m', $month) ?: new \DateTimeImmutable('first day of this month 00:00');
        $to = $from->modify('+1 month');

        $trips = array_values(array_filter(
            $this->repository->tripsBetween($from, $to, ['pojazd' => $vehicleId, 'status' => ['zakonczony']]),
            static fn(array $trip): bool => $trip['data_od'] >= $from->format('Y-m-d H:i:s')
        ));
        $fuel = array_reverse($this->repository->fuelEntries([
            'pojazd' => $vehicleId,
            'od' => $from->format('Y-m-d H:i:s'),
            'do' => $to->format('Y-m-d H:i:s'),
        ], 1000));

        $norm = (float) ($vehicle['norma_zuzycia'] ?? 0);
        $rows = [];
        $totalKm = 0;
        foreach ($trips as $trip) {
            $km = null;
            if ($trip['licznik_start'] !== null && $trip['licznik_stop'] !== null) {
                $km = max(0, (int) $trip['licznik_stop'] - (int) $trip['licznik_start']);
                $totalKm += $km;
            }
            $rows[] = [
                'trip' => $trip,
                'km' => $km,
                'norm_l' => $km !== null ? round($km * $norm / 100, 2) : null,
            ];
        }

        $refuelled = array_sum(array_map(static fn(array $entry): float => (float) $entry['ilosc'], $fuel));
        $startLevel = $trips !== [] && $trips[0]['paliwo_start'] !== null ? (float) $trips[0]['paliwo_start'] : null;
        $last = $trips !== [] ? $trips[count($trips) - 1] : null;
        $endLevel = $last && $last['paliwo_stop'] !== null ? (float) $last['paliwo_stop'] : null;

        $normUsage = round($totalKm * $norm / 100, 2);
        $actualUsage = $startLevel !== null && $endLevel !== null ? round($startLevel + $refuelled - $endLevel, 2) : null;

        return [
            'vehicle' => $vehicle,
            'month' => $from,
            'trips' => $rows,
            'fuel' => $fuel,
            'norm' => $norm,
            'total_km' => $totalKm,
            'refuelled' => round($refuelled, 2),
            'start_level' => $startLevel,
            'end_level' => $endLevel,
            'norm_usage' => $normUsage,
            'actual_usage' => $actualUsage,
            'difference' => $actualUsage !== null ? round($normUsage - $actualUsage, 2) : null,
            'limit_km' => $vehicle['limit_km_miesiac'] !== null ? (int) $vehicle['limit_km_miesiac'] : null,
        ];
    }
}
