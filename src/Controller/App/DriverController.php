<?php

namespace App\Controller\App;

use App\Service\Fleet\DeadlineService;
use App\Service\Fleet\Dictionary;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Widok kierowcy: własne wyjazdy, rozpoczęcie i zakończenie jazdy.
 */
class DriverController extends AppBaseController
{
    public function myTrips(Request $request): Response
    {
        if ($response = $this->gate($request)) {
            return $response;
        }

        $driver = $this->ctx()['driver'];
        if (!$driver) {
            return $this->renderApp('app/no_role.html.twig', ['title' => 'Moje wyjazdy', 'driverMissing' => true]);
        }

        $repo = $this->repo();
        $trips = $repo->upcomingTripsForDriver((int) $driver['id']);
        $running = null;
        $next = [];
        foreach ($trips as $trip) {
            if ($trip['status'] === 'w_realizacji' && $running === null) {
                $running = $trip;
                continue;
            }
            $next[] = $trip;
        }

        $weekStart = new \DateTimeImmutable('monday this week');
        $week = [];
        for ($i = 0; $i < 14; $i++) {
            $day = $weekStart->modify("+$i days");
            $week[$day->format('Y-m-d')] = ['date' => $day, 'trips' => []];
        }
        foreach ($repo->tripsBetween($weekStart, $weekStart->modify('+14 days'), ['kierowca' => (int) $driver['id']]) as $trip) {
            $cursor = new \DateTimeImmutable(substr((string) $trip['data_od'], 0, 10));
            $end = new \DateTimeImmutable((string) $trip['data_do']);
            while ($cursor < $end) {
                $key = $cursor->format('Y-m-d');
                if (isset($week[$key])) {
                    $week[$key]['trips'][] = $trip;
                }
                $cursor = $cursor->modify('+1 day');
            }
        }

        $vehicle = $running && $running['pojazd'] ? $repo->vehicle((int) $running['pojazd']) : null;

        return $this->renderApp('app/driver/my_trips.html.twig', [
            'title' => 'Moje wyjazdy',
            'driver' => $driver,
            'running' => $running,
            'runningVehicle' => $vehicle,
            'next' => $next,
            'week' => $week,
            'history' => $repo->recentTripsForDriver((int) $driver['id'], 8),
            'vehicleOdometers' => $this->vehicleOdometers($next),
            'sources' => Dictionary::options('tankowania', 'zrodlo'),
            'terms' => (new DeadlineService($repo))->forRecord($driver, DeadlineService::DRIVER_DEADLINES),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $trips
     * @return array<int, array{przebieg: int|null, paliwo: float|null}>
     */
    private function vehicleOdometers(array $trips): array
    {
        $result = [];
        foreach ($trips as $trip) {
            $id = (int) $trip['pojazd'];
            if ($id && !isset($result[$id])) {
                $vehicle = $this->repo()->vehicle($id);
                $result[$id] = [
                    'przebieg' => $vehicle && $vehicle['przebieg'] !== null ? (int) $vehicle['przebieg'] : null,
                    'paliwo' => $vehicle && $vehicle['stan_paliwa'] !== null ? (float) $vehicle['stan_paliwa'] : null,
                ];
            }
        }

        return $result;
    }
}
