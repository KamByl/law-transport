<?php

namespace App\Controller\App;

use App\Service\Fleet\DeadlineService;
use App\Service\Fleet\Dictionary;
use App\Service\Fleet\FleetRepository;
use App\Service\Fleet\ModuleForm;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ewidencja pojazdów i kierowców oraz zestawienie terminów.
 */
class FleetController extends AppBaseController
{
    private const TYPES = [
        'pojazdy' => [
            'table' => FleetRepository::VEHICLES,
            'path' => '/pojazdy',
            'singular' => 'pojazd',
            'deadlines' => DeadlineService::VEHICLE_DEADLINES,
            'form_except' => [],
        ],
        'kierowcy' => [
            'table' => FleetRepository::DRIVERS,
            'path' => '/kierowcy',
            'singular' => 'kierowca',
            'deadlines' => DeadlineService::DRIVER_DEADLINES,
            'form_except' => [],
        ],
    ];

    public function vehicles(Request $request): Response
    {
        return $this->listPage($request, 'pojazdy');
    }

    public function drivers(Request $request): Response
    {
        return $this->listPage($request, 'kierowcy');
    }

    public function vehicle(Request $request, array $parameters): Response
    {
        return $this->showPage($request, 'pojazdy', (int) ($parameters['id'] ?? 0));
    }

    public function driver(Request $request, array $parameters): Response
    {
        return $this->showPage($request, 'kierowcy', (int) ($parameters['id'] ?? 0));
    }

    public function vehicleForm(Request $request, array $parameters): Response
    {
        return $this->formPage($request, 'pojazdy', isset($parameters['id']) ? (int) $parameters['id'] : null);
    }

    public function driverForm(Request $request, array $parameters): Response
    {
        return $this->formPage($request, 'kierowcy', isset($parameters['id']) ? (int) $parameters['id'] : null);
    }

    public function deadlines(Request $request): Response
    {
        if ($response = $this->gate($request)) {
            return $response;
        }
        $this->requireAccess($this->ctx()['viewer']);

        $service = new DeadlineService($this->repo());
        $days = max(1, min(365, (int) $request->query->get('dni', 60)));

        return $this->renderApp('app/fleet/deadlines.html.twig', [
            'title' => 'Terminy i alerty',
            'items' => $service->upcoming($days),
            'days' => $days,
            'warningDays' => $service->warningDays(),
        ]);
    }

    private function listPage(Request $request, string $module): Response
    {
        if ($response = $this->gate($request)) {
            return $response;
        }
        $this->requireAccess($this->can($module, 'view'));

        $repo = $this->repo();
        $rows = $module === 'pojazdy' ? $repo->vehicles(true) : $repo->drivers(true);
        $deadlines = new DeadlineService($repo);
        $now = new \DateTimeImmutable();
        $busy = [];
        foreach ($repo->tripsBetween($now, $now->modify('+1 second')) as $trip) {
            $busy[$module === 'pojazdy' ? (int) $trip['pojazd'] : (int) $trip['kierowca']] = $trip;
        }

        $q = mb_strtolower(trim((string) $request->query->get('q', '')));
        $items = [];
        foreach ($rows as $row) {
            $label = $module === 'pojazdy' ? FleetRepository::vehicleLabel($row) . ' ' . ($row['nr_ewidencyjny'] ?? '') : FleetRepository::driverLabel($row) . ' ' . ($row['pododdzial'] ?? '');
            if ($q !== '' && !str_contains(mb_strtolower($label), $q)) {
                continue;
            }
            $terms = $deadlines->forRecord($row, self::TYPES[$module]['deadlines']);
            $worst = 'ok';
            foreach ($terms as $term) {
                if ($term['level'] === 'expired') {
                    $worst = 'expired';
                    break;
                }
                if ($term['level'] === 'soon') {
                    $worst = 'soon';
                }
            }
            $items[] = ['row' => $row, 'terms' => $terms, 'worst' => $worst, 'busy' => $busy[(int) $row['id']] ?? null];
        }

        return $this->renderApp('app/fleet/' . $module . '.html.twig', [
            'title' => $module === 'pojazdy' ? 'Pojazdy' : 'Kierowcy',
            'items' => $items,
            'q' => $q,
            'options' => [
                'status' => Dictionary::options($module, 'status'),
                'typ' => Dictionary::options('pojazdy', 'typ'),
                'rodzaj' => Dictionary::options('pojazdy', 'rodzaj'),
            ],
        ]);
    }

    private function showPage(Request $request, string $module, int $id): Response
    {
        if ($response = $this->gate($request)) {
            return $response;
        }
        $this->requireAccess($this->can($module, 'view'));

        $repo = $this->repo();
        $row = $module === 'pojazdy' ? $repo->vehicle($id) : $repo->driver($id);
        if (!$row) {
            throw $this->createNotFoundException();
        }

        $filterKey = $module === 'pojazdy' ? 'pojazd' : 'kierowca';
        $today = new \DateTimeImmutable('today');
        $upcoming = $repo->tripsBetween($today, $today->modify('+60 days'), [$filterKey => $id]);
        $history = $repo->searchTrips([$filterKey => $id, 'status' => ['zakonczony']], 15);

        $fields = [];
        foreach (Dictionary::fields($module) as $field) {
            $type = strtolower((string) ($field['type'] ?? 'text'));
            if ($type === 'tab') {
                $fields[] = ['section' => (string) $field['label']];
                continue;
            }
            $name = (string) ($field['name'] ?? '');
            if ($name === '' || in_array($name, ['kolor'], true)) {
                continue;
            }
            $value = $row[$name] ?? null;
            if ($type === 'select') {
                $value = Dictionary::label($module, $name, $value);
            }
            $fields[] = ['label' => (string) $field['label'], 'value' => $value, 'type' => $type];
        }

        return $this->renderApp('app/fleet/show.html.twig', [
            'title' => $module === 'pojazdy' ? FleetRepository::vehicleLabel($row) : FleetRepository::driverLabel($row),
            'module' => $module,
            'row' => $row,
            'fields' => $fields,
            'terms' => (new DeadlineService($repo))->forRecord($row, self::TYPES[$module]['deadlines']),
            'upcoming' => $upcoming,
            'history' => $history,
            'fuel' => $module === 'pojazdy' ? $repo->fuelEntries(['pojazd' => $id], 10) : [],
            'statusLabel' => Dictionary::label($module, 'status', $row['status'] ?? null),
            'sources' => Dictionary::options('tankowania', 'zrodlo'),
        ]);
    }

    private function formPage(Request $request, string $module, ?int $id): Response
    {
        if ($response = $this->gate($request)) {
            return $response;
        }
        $this->requireAccess($this->can($module, $id ? 'edit' : 'create'));

        $type = self::TYPES[$module];
        $row = null;
        if ($id) {
            $row = $module === 'pojazdy' ? $this->repo()->vehicle($id) : $this->repo()->driver($id);
            if (!$row) {
                throw $this->createNotFoundException();
            }
        }

        $form = new ModuleForm($this->repo(), $module, [], $type['form_except']);
        $values = $row ?? ($module === 'pojazdy' ? ['status' => 'sprawny', 'rodzaj' => 'wojskowy'] : ['status' => 'dostepny']);
        $errors = [];

        if ($request->isMethod('POST')) {
            $result = $form->extract($request);
            $values = $result['values'] + ($row ?? []);
            $errors = $result['errors'];

            if ($module === 'pojazdy' && $errors === [] && $values['nr_rejestracyjny']) {
                $values['nr_rejestracyjny'] = mb_strtoupper(preg_replace('/\s+/', ' ', (string) $values['nr_rejestracyjny']));
                $result['values']['nr_rejestracyjny'] = $values['nr_rejestracyjny'];
                $duplicate = $this->repo()->connection()->fetchOne(
                    'SELECT id FROM ' . FleetRepository::VEHICLES . ' WHERE nr_rejestracyjny = ? AND id <> ?',
                    [$values['nr_rejestracyjny'], $id ?? 0]
                );
                if ($duplicate) {
                    $errors['nr_rejestracyjny'] = 'Pojazd o tym numerze rejestracyjnym już istnieje.';
                }
            }
            if ($module === 'kierowcy' && $errors === [] && $values['email']) {
                $values['email'] = mb_strtolower((string) $values['email']);
                $result['values']['email'] = $values['email'];
                if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                    $errors['email'] = 'Niepoprawny adres e-mail.';
                }
            }

            if ($errors === []) {
                if ($id) {
                    $this->repo()->update($type['table'], $id, $result['values']);
                } else {
                    $id = $this->repo()->insert($type['table'], $result['values']);
                }
                $this->flash('success', 'Zapisano.');

                return new RedirectResponse($type['path'] . '/' . $id);
            }
        }

        $title = $module === 'pojazdy'
            ? ($row ? 'Edycja pojazdu ' . $row['nr_rejestracyjny'] : 'Nowy pojazd')
            : ($row ? 'Edycja: ' . FleetRepository::driverLabel($row) : 'Nowy kierowca');

        return $this->renderApp('app/fleet/form.html.twig', [
            'title' => $title,
            'module' => $module,
            'row' => $row,
            'sections' => $form->sections(),
            'values' => $values,
            'errors' => $errors,
            'cancel' => $row ? $type['path'] . '/' . $row['id'] : $type['path'],
        ], $errors !== [] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }
}
