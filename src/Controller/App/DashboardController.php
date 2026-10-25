<?php

namespace App\Controller\App;

use App\Service\Fleet\DeadlineService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends AppBaseController
{
    public function index(Request $request): Response
    {
        if ($response = $this->gate($request)) {
            return $response;
        }

        $ctx = $this->ctx();
        if (!$ctx['viewer']) {
            if ($ctx['driver']) {
                return new RedirectResponse('/moje-wyjazdy');
            }

            return $this->renderApp('app/no_role.html.twig');
        }

        $repo = $this->repo();
        $now = new \DateTimeImmutable();
        $today = new \DateTimeImmutable('today');
        $tomorrowEnd = $today->modify('+2 days');
        $weekStart = $today->modify('monday this week');
        $monthStart = $today->modify('first day of this month');

        $vehicles = $repo->vehicles();
        $drivers = $repo->drivers();
        $upcoming = $repo->tripsBetween($today, $tomorrowEnd);
        $week = $repo->tripsBetween($weekStart, $weekStart->modify('+7 days'));
        $month = $repo->tripsBetween($monthStart, $monthStart->modify('+1 month'), ['status' => ['zakonczony']]);

        $busyVehicles = [];
        $busyDrivers = [];
        $running = [];
        foreach ($upcoming as $trip) {
            $active = $trip['data_od'] <= $now->format('Y-m-d H:i:s') && $trip['data_do'] > $now->format('Y-m-d H:i:s');
            if ($active || $trip['status'] === 'w_realizacji') {
                $running[] = $trip;
                if ($trip['pojazd']) {
                    $busyVehicles[(int) $trip['pojazd']] = true;
                }
                if ($trip['kierowca']) {
                    $busyDrivers[(int) $trip['kierowca']] = true;
                }
            }
        }

        $vehicleStates = ['sprawny' => 0, 'w_trasie' => 0, 'w_obsludze' => 0];
        foreach ($vehicles as $vehicle) {
            if (isset($busyVehicles[(int) $vehicle['id']])) {
                $vehicleStates['w_trasie']++;
            } elseif (($vehicle['status'] ?? '') === 'w_obsludze') {
                $vehicleStates['w_obsludze']++;
            } else {
                $vehicleStates['sprawny']++;
            }
        }

        $availableDrivers = count(array_filter(
            $drivers,
            static fn(array $driver): bool => ($driver['status'] ?? 'dostepny') === 'dostepny' && !isset($busyDrivers[(int) $driver['id']])
        ));

        $kmMonth = 0;
        foreach ($month as $trip) {
            if ($trip['licznik_start'] !== null && $trip['licznik_stop'] !== null) {
                $kmMonth += max(0, (int) $trip['licznik_stop'] - (int) $trip['licznik_start']);
            }
        }

        $toApprove = array_values(array_filter($week, static fn(array $trip): bool => ($trip['status'] ?? 'planowany') === 'planowany'));
        $unassigned = array_values(array_filter($week, static fn(array $trip): bool => !$trip['pojazd'] || !$trip['kierowca']));

        $weekDays = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $weekStart->modify("+$i days");
            $weekDays[$day->format('Y-m-d')] = ['date' => $day, 'count' => 0];
        }
        foreach ($week as $trip) {
            $key = substr((string) $trip['data_od'], 0, 10);
            if (isset($weekDays[$key])) {
                $weekDays[$key]['count']++;
            }
        }

        return $this->renderApp('app/dashboard.html.twig', [
            'title' => 'Pulpit',
            'upcoming' => $upcoming,
            'running' => $running,
            'vehicleCount' => count($vehicles),
            'vehicleStates' => $vehicleStates,
            'driverCount' => count($drivers),
            'availableDrivers' => $availableDrivers,
            'weekTrips' => count($week),
            'weekDays' => $weekDays,
            'kmMonth' => $kmMonth,
            'toApprove' => $toApprove,
            'unassigned' => $unassigned,
            'alerts' => array_slice((new DeadlineService($repo))->upcoming(), 0, 8),
            'now' => $now,
        ]);
    }
}
