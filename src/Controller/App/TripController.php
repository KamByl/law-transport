<?php

namespace App\Controller\App;

use App\Service\Fleet\Dictionary;
use App\Service\Fleet\FleetRepository;
use App\Service\Fleet\ModuleForm;
use App\Service\Fleet\TripRules;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TripController extends AppBaseController
{
    /**
     * Pola rozliczenia wypełniane przy rozpoczęciu i zakończeniu jazdy, nie w formularzu planowania.
     */
    private const FORM_EXCEPT = ['numer', 'status', 'licznik_start', 'licznik_stop', 'paliwo_start', 'paliwo_stop', 'faktyczny_wyjazd', 'faktyczny_powrot', 'zatwierdzil'];

    public function index(Request $request): Response
    {
        if ($response = $this->gate($request)) {
            return $response;
        }
        $this->requireAccess($this->ctx()['viewer']);

        $filters = [
            'q' => trim((string) $request->query->get('q', '')),
            'pojazd' => (int) $request->query->get('pojazd', 0) ?: null,
            'kierowca' => (int) $request->query->get('kierowca', 0) ?: null,
            'od' => $this->dateParam($request->query->get('od')),
            'do' => $this->dateParam($request->query->get('do')),
        ];
        $status = (string) $request->query->get('status', '');
        if ($status !== '' && array_key_exists($status, Dictionary::options('wyjazdy', 'status'))) {
            $filters['status'] = [$status];
        }

        $data = [
            'title' => 'Wyjazdy',
            'trips' => $this->repo()->searchTrips($filters),
            'filters' => $filters + ['status' => $status],
            'statuses' => Dictionary::options('wyjazdy', 'status'),
            'vehicles' => $this->repo()->vehicles(true),
            'drivers' => $this->repo()->drivers(true),
        ];

        if ($request->headers->has('HX-Request')) {
            return $this->renderApp('app/trips/_table.html.twig', $data);
        }

        return $this->renderApp('app/trips/index.html.twig', $data);
    }

    public function create(Request $request): Response
    {
        if ($response = $this->gate($request)) {
            return $response;
        }
        $this->requireAccess($this->can('wyjazdy', 'create'));

        $values = [
            'pojazd' => $request->query->get('pojazd'),
            'kierowca' => $request->query->get('kierowca'),
            'data_od' => $this->dateTimeParam($request->query->get('od')),
            'data_do' => $this->dateTimeParam($request->query->get('do')),
        ];
        if (!$values['data_od']) {
            $start = new \DateTimeImmutable('tomorrow 07:00');
            $values['data_od'] = $start->format('Y-m-d H:i:s');
        }
        if (!$values['data_do']) {
            $values['data_do'] = (new \DateTimeImmutable($values['data_od']))->modify('+8 hours')->format('Y-m-d H:i:s');
        }

        return $this->handleForm($request, null, $values);
    }

    public function edit(Request $request, array $parameters): Response
    {
        if ($response = $this->gate($request)) {
            return $response;
        }
        $this->requireAccess($this->can('wyjazdy', 'edit'));
        $trip = $this->findTrip($parameters);

        return $this->handleForm($request, $trip, $trip);
    }

    public function show(Request $request, array $parameters): Response
    {
        if ($response = $this->gate($request)) {
            return $response;
        }
        $trip = $this->findTrip($parameters);
        $this->requireAccess($this->canSeeTrip($trip));

        $rules = (new TripRules($this->repo()))->check($trip, (int) $trip['id']);

        return $this->renderApp($request->query->getBoolean('panel') ? 'app/trips/_panel.html.twig' : 'app/trips/show.html.twig', [
            'title' => 'Wyjazd ' . ($trip['numer'] ?: '#' . $trip['id']),
            'trip' => $trip,
            'check' => $rules,
            'fuel' => $this->repo()->connection()->fetchAllAssociative('SELECT * FROM ' . FleetRepository::FUEL . ' WHERE wyjazd = ? ORDER BY data', [$trip['id']]),
            'isOwnTrip' => $this->isOwnTrip($trip),
            'vehicle' => $trip['pojazd'] ? $this->repo()->vehicle((int) $trip['pojazd']) : null,
            'driver' => $trip['kierowca'] ? $this->repo()->driver((int) $trip['kierowca']) : null,
            'sources' => Dictionary::options('tankowania', 'zrodlo'),
            'back' => $this->safeReturnPath($request->query->get('powrot'), ''),
        ]);
    }

    public function changeStatus(Request $request, array $parameters): Response
    {
        if ($response = $this->gate($request)) {
            return $response;
        }
        $this->requireAccess($this->ctx()['manager']);
        $trip = $this->findTrip($parameters);
        $action = (string) $request->request->get('akcja');
        $current = $trip['status'] ?: 'planowany';

        $transitions = [
            'zatwierdz' => [['planowany'], 'zatwierdzony', 'Wyjazd zatwierdzony.'],
            'cofnij' => [['zatwierdzony'], 'planowany', 'Cofnięto zatwierdzenie.'],
            'anuluj' => [['planowany', 'zatwierdzony'], 'anulowany', 'Wyjazd anulowany.'],
            'przywroc' => [['anulowany'], 'planowany', 'Wyjazd przywrócony jako planowany.'],
        ];

        if (!isset($transitions[$action]) || !in_array($current, $transitions[$action][0], true)) {
            $this->flash('danger', 'Tej zmiany statusu nie można wykonać.');

            return $this->back($request, $trip);
        }

        [, $target, $message] = $transitions[$action];
        $update = ['status' => $target];

        if ($target === 'zatwierdzony') {
            $check = (new TripRules($this->repo()))->check(['status' => $target] + $trip, (int) $trip['id']);
            if (!$trip['pojazd'] || !$trip['kierowca']) {
                $check['errors'][] = 'Przed zatwierdzeniem przydziel pojazd i kierowcę.';
            }
            if ($check['errors'] !== []) {
                $this->flash('danger', implode(' ', $check['errors']));

                return $this->back($request, $trip);
            }
            $update['zatwierdzil'] = $this->userDisplayName();
        }
        if ($target === 'planowany' && $action === 'przywroc') {
            $check = (new TripRules($this->repo()))->check(['status' => 'planowany'] + $trip, (int) $trip['id']);
            if ($check['errors'] !== []) {
                $this->flash('danger', implode(' ', $check['errors']));

                return $this->back($request, $trip);
            }
        }
        if ($action === 'cofnij') {
            $update['zatwierdzil'] = null;
        }

        $this->repo()->update(FleetRepository::TRIPS, (int) $trip['id'], $update);
        $this->flash('success', $message);

        return $this->back($request, $trip);
    }

    public function start(Request $request, array $parameters): Response
    {
        if ($response = $this->gate($request)) {
            return $response;
        }
        $trip = $this->findTrip($parameters);
        $this->requireAccess($this->ctx()['manager'] || $this->isOwnTrip($trip));

        if (($trip['status'] ?? '') !== 'zatwierdzony') {
            $this->flash('danger', 'Rozpocząć można tylko zatwierdzony wyjazd.');

            return $this->back($request, $trip);
        }

        $odometer = $this->intInput($request, 'licznik_start');
        $fuel = $this->floatInput($request, 'paliwo_start');
        if ($odometer === null) {
            $this->flash('danger', 'Podaj stan licznika przy wyjeździe.');

            return $this->back($request, $trip);
        }

        $vehicle = $trip['pojazd'] ? $this->repo()->vehicle((int) $trip['pojazd']) : null;
        if ($vehicle && $vehicle['przebieg'] !== null && $odometer < (int) $vehicle['przebieg']) {
            $this->flash('warning', sprintf('Podany licznik (%d km) jest mniejszy niż ostatni zapisany przebieg pojazdu (%d km).', $odometer, $vehicle['przebieg']));
        }

        $this->repo()->update(FleetRepository::TRIPS, (int) $trip['id'], [
            'status' => 'w_realizacji',
            'licznik_start' => $odometer,
            'paliwo_start' => $fuel,
            'faktyczny_wyjazd' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $this->flash('success', 'Wyjazd rozpoczęty. Szerokiej drogi!');

        return $this->back($request, $trip);
    }

    public function finish(Request $request, array $parameters): Response
    {
        if ($response = $this->gate($request)) {
            return $response;
        }
        $trip = $this->findTrip($parameters);
        $this->requireAccess($this->ctx()['manager'] || $this->isOwnTrip($trip));

        if (($trip['status'] ?? '') !== 'w_realizacji') {
            $this->flash('danger', 'Zakończyć można tylko wyjazd w realizacji.');

            return $this->back($request, $trip);
        }

        $odometer = $this->intInput($request, 'licznik_stop');
        $fuel = $this->floatInput($request, 'paliwo_stop');
        $refuel = $this->floatInput($request, 'tankowanie_ilosc');
        if ($odometer === null || ($trip['licznik_start'] !== null && $odometer < (int) $trip['licznik_start'])) {
            $this->flash('danger', 'Stan licznika przy powrocie musi być większy lub równy stanowi przy wyjeździe.');

            return $this->back($request, $trip);
        }

        $connection = $this->repo()->connection();
        $connection->transactional(function () use ($trip, $odometer, $fuel, $refuel, $request): void {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            if ($refuel !== null && $refuel > 0 && $trip['pojazd']) {
                $this->repo()->insert(FleetRepository::FUEL, [
                    'data' => $now,
                    'pojazd' => (int) $trip['pojazd'],
                    'kierowca' => $trip['kierowca'] ? (int) $trip['kierowca'] : null,
                    'wyjazd' => (int) $trip['id'],
                    'ilosc' => $refuel,
                    'rodzaj_paliwa' => $trip['pojazd_paliwo'] ?: null,
                    'zrodlo' => array_key_exists((string) $request->request->get('tankowanie_zrodlo'), Dictionary::options('tankowania', 'zrodlo'))
                        ? (string) $request->request->get('tankowanie_zrodlo')
                        : null,
                    'nr_dokumentu' => mb_substr(trim((string) $request->request->get('tankowanie_dokument', '')), 0, 255) ?: null,
                    'licznik' => $odometer,
                ]);
            }

            $this->repo()->update(FleetRepository::TRIPS, (int) $trip['id'], [
                'status' => 'zakonczony',
                'licznik_stop' => $odometer,
                'paliwo_stop' => $fuel,
                'faktyczny_powrot' => $now,
                'uwagi' => $this->appendNote($trip['uwagi'] ?? null, (string) $request->request->get('uwagi_kierowcy', '')),
            ]);

            if ($trip['pojazd']) {
                $vehicleUpdate = ['przebieg' => $odometer];
                if ($fuel !== null) {
                    $vehicleUpdate['stan_paliwa'] = $fuel;
                }
                $this->repo()->update(FleetRepository::VEHICLES, (int) $trip['pojazd'], $vehicleUpdate);
            }
        });

        $this->flash('success', 'Wyjazd zakończony i rozliczony.');

        return $this->back($request, $trip);
    }

    public function delete(Request $request, array $parameters): Response
    {
        if ($response = $this->gate($request)) {
            return $response;
        }
        $this->requireAccess($this->can('wyjazdy', 'delete'));
        $trip = $this->findTrip($parameters);

        if (in_array($trip['status'], ['w_realizacji', 'zakonczony'], true)) {
            $this->flash('danger', 'Nie można usunąć wyjazdu, który się rozpoczął. Użyj anulowania lub poproś administratora.');

            return new RedirectResponse('/wyjazdy/' . $trip['id']);
        }

        $this->repo()->delete(FleetRepository::TRIPS, (int) $trip['id']);
        $this->flash('success', 'Wyjazd usunięty.');

        return new RedirectResponse($this->safeReturnPath($request->request->get('powrot'), '/wyjazdy'));
    }

    public function document(Request $request, array $parameters): Response
    {
        if ($response = $this->gate($request)) {
            return $response;
        }
        $trip = $this->findTrip($parameters);
        $this->requireAccess($this->canSeeTrip($trip));

        $type = (string) ($parameters['type'] ?? '');
        $templates = [
            'rozkaz-wyjazdu' => 'app/print/rozkaz_wyjazdu.html.twig',
            'karta-drogowa' => 'app/print/karta_drogowa.html.twig',
        ];
        if (!isset($templates[$type])) {
            throw $this->createNotFoundException();
        }

        return $this->renderApp($templates[$type], [
            'title' => ($type === 'rozkaz-wyjazdu' ? 'Rozkaz wyjazdu ' : 'Karta drogowa ') . ($trip['numer'] ?: '#' . $trip['id']),
            'trip' => $trip,
            'vehicle' => $trip['pojazd'] ? $this->repo()->vehicle((int) $trip['pojazd']) : null,
            'driver' => $trip['kierowca'] ? $this->repo()->driver((int) $trip['kierowca']) : null,
            'fuel' => $this->repo()->connection()->fetchAllAssociative('SELECT * FROM ' . FleetRepository::FUEL . ' WHERE wyjazd = ? ORDER BY data', [$trip['id']]),
        ]);
    }

    /**
     * @param array<string, mixed>|null $trip
     * @param array<string, mixed> $values
     */
    private function handleForm(Request $request, ?array $trip, array $values): Response
    {
        $form = new ModuleForm($this->repo(), 'wyjazdy', [], self::FORM_EXCEPT);
        $errors = [];
        $warnings = [];
        $partial = $request->query->getBoolean('panel') || $request->request->getBoolean('panel');
        $return = $this->safeReturnPath($request->query->get('powrot', $request->request->get('powrot')), '');

        if ($request->isMethod('POST')) {
            $result = $form->extract($request);
            $values = $result['values'] + ($trip ?? []);
            $errors = $result['errors'];

            if ($errors === []) {
                $check = (new TripRules($this->repo()))->check($values + ['status' => $trip['status'] ?? 'planowany'], $trip ? (int) $trip['id'] : null);
                $errors = $check['errors'];
                $warnings = $check['warnings'];
            }

            if ($errors === []) {
                $save = $result['values'];
                if ($trip) {
                    $this->repo()->update(FleetRepository::TRIPS, (int) $trip['id'], $save);
                    $id = (int) $trip['id'];
                    $this->flash('success', 'Zapisano zmiany wyjazdu.');
                } else {
                    $save['numer'] = $this->repo()->nextTripNumber((int) substr((string) $save['data_od'], 0, 4));
                    $save['status'] = 'planowany';
                    $id = $this->repo()->insert(FleetRepository::TRIPS, $save);
                    $this->flash('success', sprintf('Utworzono wyjazd %s.', $save['numer']));
                }
                foreach ($warnings as $warning) {
                    $this->flash('warning', $warning);
                }

                $target = $return !== '' ? $return : '/wyjazdy/' . $id;
                if ($request->headers->has('HX-Request')) {
                    return new Response('', Response::HTTP_OK, ['HX-Redirect' => $target]);
                }

                return new RedirectResponse($target);
            }
        }

        return $this->renderApp($partial ? 'app/trips/_form.html.twig' : 'app/trips/form.html.twig', [
            'title' => $trip ? 'Edycja wyjazdu ' . ($trip['numer'] ?: '#' . $trip['id']) : 'Nowy wyjazd',
            'trip' => $trip,
            'sections' => $form->sections(),
            'values' => $values,
            'errors' => $errors,
            'warnings' => $warnings,
            'partial' => $partial,
            'return' => $return,
        ], $errors !== [] && !$partial ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    /**
     * @return array<string, mixed>
     */
    private function findTrip(array $parameters): array
    {
        $trip = $this->repo()->trip((int) ($parameters['id'] ?? 0));
        if (!$trip) {
            throw $this->createNotFoundException('Nie ma takiego wyjazdu.');
        }

        return $trip;
    }

    /**
     * @param array<string, mixed> $trip
     */
    private function isOwnTrip(array $trip): bool
    {
        $driver = $this->ctx()['driver'];

        return $driver !== null && $trip['kierowca'] !== null && (int) $trip['kierowca'] === (int) $driver['id'];
    }

    /**
     * @param array<string, mixed> $trip
     */
    private function canSeeTrip(array $trip): bool
    {
        return $this->ctx()['viewer'] || $this->isOwnTrip($trip);
    }

    /**
     * @param array<string, mixed> $trip
     */
    private function back(Request $request, array $trip): RedirectResponse
    {
        return new RedirectResponse($this->safeReturnPath($request->request->get('powrot'), '/wyjazdy/' . $trip['id']));
    }

    private function intInput(Request $request, string $name): ?int
    {
        $raw = trim((string) $request->request->get($name, ''));

        return preg_match('/^\d+$/', $raw) ? (int) $raw : null;
    }

    private function floatInput(Request $request, string $name): ?float
    {
        $raw = str_replace([' ', ','], ['', '.'], trim((string) $request->request->get($name, '')));

        return $raw !== '' && is_numeric($raw) && (float) $raw >= 0 ? (float) $raw : null;
    }

    private function appendNote(?string $current, string $note): ?string
    {
        $note = trim($note);
        if ($note === '') {
            return $current;
        }

        return trim(($current ? $current . "\n" : '') . 'Kierowca: ' . mb_substr($note, 0, 2000));
    }

    private function dateParam(mixed $value): ?string
    {
        $value = (string) $value;

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ? $value : null;
    }

    private function dateTimeParam(mixed $value): ?string
    {
        $value = (string) $value;
        foreach (['!Y-m-d\TH:i', '!Y-m-d H:i:s', '!Y-m-d H:i'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        return null;
    }
}
