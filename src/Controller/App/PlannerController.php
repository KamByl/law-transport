<?php

namespace App\Controller\App;

use App\Service\Fleet\Dictionary;
use App\Service\Fleet\FleetRepository;
use App\Service\Fleet\TripRules;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Graficzny planer wyjazdów: oś czasu z pojazdami lub kierowcami, przeciąganie wyjazdów i przydziałów.
 */
class PlannerController extends AppBaseController
{
    public function index(Request $request): Response
    {
        if ($response = $this->gate($request)) {
            return $response;
        }
        $this->requireAccess($this->ctx()['viewer']);

        return $this->renderApp('app/planner.html.twig', [
            'title' => 'Planer wyjazdów',
            'canEdit' => $this->ctx()['manager'],
            'canCreate' => $this->can('wyjazdy', 'create'),
            'statuses' => Dictionary::options('wyjazdy', 'status'),
        ]);
    }

    public function data(Request $request): Response
    {
        if ($response = $this->gate($request)) {
            return $response;
        }
        $this->requireAccess($this->ctx()['viewer']);

        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('od')) ?: new \DateTimeImmutable('monday this week');
        $days = max(1, min(31, (int) $request->query->get('dni', 7)));
        $to = $from->modify("+$days days");

        $repo = $this->repo();
        $rules = new TripRules($repo);
        $trips = [];
        foreach ($repo->tripsBetween($from, $to, ['include_cancelled' => $request->query->getBoolean('anulowane')]) as $trip) {
            $trips[] = $this->tripPayload($trip, $rules->check($trip, (int) $trip['id']));
        }

        $vehicles = array_map(static fn(array $vehicle): array => [
            'id' => (int) $vehicle['id'],
            'nr' => (string) $vehicle['nr_rejestracyjny'],
            'name' => trim(($vehicle['marka'] ?? '') . ' ' . ($vehicle['model'] ?? '')),
            'rodzaj' => (string) ($vehicle['rodzaj'] ?? ''),
            'typ' => Dictionary::label('pojazdy', 'typ', $vehicle['typ'] ?? null),
            'status' => (string) ($vehicle['status'] ?? 'sprawny'),
            'kategoria' => (string) ($vehicle['wymagana_kategoria'] ?? ''),
            'kolor' => self::color($vehicle['kolor'] ?? null),
            'miejsca' => $vehicle['liczba_miejsc'] !== null ? (int) $vehicle['liczba_miejsc'] : null,
        ], $repo->vehicles());

        $drivers = array_map(static fn(array $driver): array => [
            'id' => (int) $driver['id'],
            'name' => FleetRepository::driverLabel($driver),
            'short' => trim(mb_substr((string) $driver['imie'], 0, 1) . '. ' . $driver['nazwisko']),
            'stopien' => (string) ($driver['stopien'] ?? ''),
            'initials' => mb_strtoupper(mb_substr((string) $driver['imie'], 0, 1) . mb_substr((string) $driver['nazwisko'], 0, 1)),
            'status' => (string) ($driver['status'] ?? 'dostepny'),
            'statusLabel' => Dictionary::label('kierowcy', 'status', $driver['status'] ?? 'dostepny'),
            'kategorie' => (string) ($driver['kategorie'] ?? ''),
            'telefon' => (string) ($driver['telefon'] ?? ''),
        ], $repo->drivers());

        return new JsonResponse([
            'from' => $from->format('Y-m-d'),
            'days' => $days,
            'now' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s'),
            'vehicles' => $vehicles,
            'drivers' => $drivers,
            'trips' => $trips,
        ]);
    }

    /**
     * Zapis zmiany z planera: przesunięcie w czasie, zmiana pojazdu lub kierowcy, zmiana długości.
     */
    public function save(Request $request, array $parameters): Response
    {
        if ($response = $this->gate($request)) {
            return $response;
        }
        if (!$this->ctx()['manager']) {
            return new JsonResponse(['error' => 'Nie masz uprawnień do zmiany planu.'], Response::HTTP_FORBIDDEN);
        }

        $trip = $this->repo()->trip((int) ($parameters['id'] ?? 0));
        if (!$trip) {
            return new JsonResponse(['error' => 'Nie ma takiego wyjazdu.'], Response::HTTP_NOT_FOUND);
        }
        if (in_array($trip['status'], ['w_realizacji', 'zakonczony', 'anulowany'], true)) {
            return new JsonResponse(['error' => 'Wyjazdu w realizacji, zakończonego lub anulowanego nie można przesuwać w planerze.'], Response::HTTP_CONFLICT);
        }

        $body = $this->jsonBody($request);
        $update = [];

        foreach (['data_od', 'data_do'] as $field) {
            if (array_key_exists($field, $body)) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', substr((string) $body[$field], 0, 16));
                if (!$date) {
                    return new JsonResponse(['error' => 'Niepoprawna data.'], Response::HTTP_BAD_REQUEST);
                }
                $update[$field] = $date->format('Y-m-d H:i:s');
            }
        }
        if (array_key_exists('pojazd', $body)) {
            $update['pojazd'] = $body['pojazd'] ? (int) $body['pojazd'] : null;
            if ($update['pojazd'] && !$this->repo()->vehicle($update['pojazd'])) {
                return new JsonResponse(['error' => 'Nie ma takiego pojazdu.'], Response::HTTP_BAD_REQUEST);
            }
        }
        if (array_key_exists('kierowca', $body)) {
            $update['kierowca'] = $body['kierowca'] ? (int) $body['kierowca'] : null;
            if ($update['kierowca'] && !$this->repo()->driver($update['kierowca'])) {
                return new JsonResponse(['error' => 'Nie ma takiego kierowcy.'], Response::HTTP_BAD_REQUEST);
            }
        }

        if ($update === []) {
            return new JsonResponse(['error' => 'Brak zmian.'], Response::HTTP_BAD_REQUEST);
        }

        $candidate = $update + $trip;
        $rules = new TripRules($this->repo());
        $check = $rules->check($candidate, (int) $trip['id']);
        if ($check['errors'] !== []) {
            return new JsonResponse(['error' => implode(' ', $check['errors']), 'errors' => $check['errors']], Response::HTTP_CONFLICT);
        }

        // Zmiana pojazdu, kierowcy lub terminu zatwierdzonego wyjazdu wymaga ponownego zatwierdzenia.
        $reapproval = ($trip['status'] ?? '') === 'zatwierdzony';
        if ($reapproval) {
            $update['status'] = 'planowany';
            $update['zatwierdzil'] = null;
        }

        $this->repo()->update(FleetRepository::TRIPS, (int) $trip['id'], $update);
        $saved = $this->repo()->trip((int) $trip['id']);

        return new JsonResponse([
            'trip' => $this->tripPayload($saved, $rules->check($saved, (int) $saved['id'])),
            'warnings' => $check['warnings'],
            'reapproval' => $reapproval,
        ]);
    }

    /**
     * @param array<string, mixed> $trip
     * @param array{errors: string[], warnings: string[]} $check
     * @return array<string, mixed>
     */
    private function tripPayload(array $trip, array $check): array
    {
        return [
            'id' => (int) $trip['id'],
            'numer' => (string) ($trip['numer'] ?? ''),
            'cel' => (string) ($trip['cel'] ?? ''),
            'skad' => (string) ($trip['miejsce_wyjazdu'] ?? ''),
            'dokad' => (string) ($trip['miejsce_docelowe'] ?? ''),
            'od' => str_replace(' ', 'T', (string) $trip['data_od']),
            'do' => str_replace(' ', 'T', (string) $trip['data_do']),
            'status' => (string) ($trip['status'] ?: 'planowany'),
            'statusLabel' => Dictionary::label('wyjazdy', 'status', $trip['status'] ?: 'planowany'),
            'pojazd' => $trip['pojazd'] ? (int) $trip['pojazd'] : null,
            'kierowca' => $trip['kierowca'] ? (int) $trip['kierowca'] : null,
            'pojazdNr' => (string) ($trip['pojazd_nr'] ?? ''),
            'kierowcaName' => trim(($trip['kierowca_stopien'] ?? '') . ' ' . ($trip['kierowca_imie'] ?? '') . ' ' . ($trip['kierowca_nazwisko'] ?? '')),
            'kolor' => self::color($trip['pojazd_kolor'] ?? null),
            'osoby' => $trip['liczba_osob'] !== null ? (int) $trip['liczba_osob'] : null,
            'errors' => $check['errors'],
            'warnings' => $check['warnings'],
        ];
    }

    private static function color(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : null;
    }
}
