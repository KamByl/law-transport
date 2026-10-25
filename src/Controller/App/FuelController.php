<?php

namespace App\Controller\App;

use App\Service\Fleet\Dictionary;
use App\Service\Fleet\FleetRepository;
use App\Service\Fleet\FuelSettlement;
use App\Service\Fleet\ModuleForm;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class FuelController extends AppBaseController
{
    public function index(Request $request): Response
    {
        if ($response = $this->gate($request)) {
            return $response;
        }
        $this->requireAccess($this->can('tankowania', 'view'));

        $month = $this->month($request);
        $from = \DateTimeImmutable::createFromFormat('!Y-m', $month);
        $vehicleId = (int) $request->query->get('pojazd', 0) ?: null;
        $form = new ModuleForm($this->repo(), 'tankowania', [], ['wyjazd']);
        $values = ['data' => (new \DateTimeImmutable())->format('Y-m-d H:i:00'), 'zrodlo' => 'stacja_wojskowa', 'pojazd' => $vehicleId];
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->requireAccess($this->can('tankowania', 'create'));
            $result = $form->extract($request);
            $values = $result['values'];
            $errors = $result['errors'];
            if ($errors === [] && ($values['ilosc'] ?? 0) <= 0) {
                $errors['ilosc'] = 'Ilość paliwa musi być większa od zera.';
            }
            if ($errors === []) {
                $this->repo()->insert(FleetRepository::FUEL, $values);
                $this->flash('success', 'Zapisano tankowanie.');

                return new RedirectResponse('/paliwo?miesiac=' . substr((string) $values['data'], 0, 7));
            }
        }

        $entries = $this->repo()->fuelEntries([
            'pojazd' => $vehicleId,
            'od' => $from->format('Y-m-d 00:00:00'),
            'do' => $from->modify('+1 month')->format('Y-m-d 00:00:00'),
        ], 500);

        $byVehicle = [];
        foreach ($entries as $entry) {
            $key = (int) $entry['pojazd'];
            $byVehicle[$key] ??= ['nr' => $entry['pojazd_nr'], 'litry' => 0.0, 'koszt' => 0.0, 'ile' => 0];
            $byVehicle[$key]['litry'] += (float) $entry['ilosc'];
            $byVehicle[$key]['koszt'] += (float) $entry['koszt'];
            $byVehicle[$key]['ile']++;
        }
        uasort($byVehicle, static fn(array $a, array $b): int => $b['litry'] <=> $a['litry']);

        return $this->renderApp('app/fuel/index.html.twig', [
            'title' => 'Paliwo',
            'month' => $month,
            'monthDate' => $from,
            'vehicleId' => $vehicleId,
            'vehicles' => $this->repo()->vehicles(true),
            'entries' => $entries,
            'byVehicle' => $byVehicle,
            'total' => array_sum(array_column($byVehicle, 'litry')),
            'sections' => $form->sections(),
            'values' => $values,
            'errors' => $errors,
            'sources' => Dictionary::options('tankowania', 'zrodlo'),
        ], $errors !== [] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    public function settlement(Request $request, array $parameters): Response
    {
        if ($response = $this->gate($request)) {
            return $response;
        }
        $this->requireAccess($this->can('tankowania', 'view') && $this->can('pojazdy', 'view'));

        $vehicleId = (int) ($parameters['id'] ?? 0);
        try {
            $settlement = (new FuelSettlement($this->repo()))->settle($vehicleId, $this->month($request));
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }

        return $this->renderApp('app/print/rozliczenie_paliwa.html.twig', [
            'title' => 'Karta pracy pojazdu ' . $settlement['vehicle']['nr_rejestracyjny'] . ' ' . $settlement['month']->format('m.Y'),
            's' => $settlement,
            'sources' => Dictionary::options('tankowania', 'zrodlo'),
        ]);
    }

    private function month(Request $request): string
    {
        $month = (string) $request->query->get('miesiac', '');

        return \DateTimeImmutable::createFromFormat('!Y-m', $month) ? $month : (new \DateTimeImmutable())->format('Y-m');
    }
}
