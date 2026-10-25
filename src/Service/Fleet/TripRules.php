<?php

namespace App\Service\Fleet;

/**
 * Reguły przydziału pojazdów i kierowców do wyjazdów.
 * Błędy blokują zapis, ostrzeżenia tylko informują dyspozytora.
 */
class TripRules
{
    public function __construct(private FleetRepository $repository)
    {
    }

    /**
     * @param array<string, mixed> $trip wymagane: data_od, data_do; opcjonalnie pojazd, kierowca, status
     * @return array{errors: string[], warnings: string[]}
     */
    public function check(array $trip, ?int $excludeId = null): array
    {
        $errors = [];
        $warnings = [];

        $from = (string) ($trip['data_od'] ?? '');
        $to = (string) ($trip['data_do'] ?? '');
        if ($from === '' || $to === '') {
            return ['errors' => ['Podaj datę wyjazdu i powrotu.'], 'warnings' => []];
        }
        if (strtotime($to) <= strtotime($from)) {
            return ['errors' => ['Powrót musi być później niż wyjazd.'], 'warnings' => []];
        }

        if (($trip['status'] ?? null) === 'anulowany') {
            return ['errors' => [], 'warnings' => []];
        }

        $vehicleId = (int) ($trip['pojazd'] ?? 0) ?: null;
        $driverId = (int) ($trip['kierowca'] ?? 0) ?: null;
        $vehicle = $vehicleId ? $this->repository->vehicle($vehicleId) : null;
        $driver = $driverId ? $this->repository->driver($driverId) : null;

        foreach ($this->repository->overlappingTrips($from, $to, $vehicleId, $driverId, $excludeId) as $other) {
            $when = self::formatRange($other['data_od'], $other['data_do']);
            if ($vehicleId && (int) $other['pojazd'] === $vehicleId) {
                $errors[] = sprintf('Pojazd %s jest już zajęty: wyjazd %s (%s).', $vehicle['nr_rejestracyjny'] ?? '', $other['numer'] ?: '#' . $other['id'], $when);
            }
            if ($driverId && (int) $other['kierowca'] === $driverId) {
                $errors[] = sprintf('Kierowca %s ma już wyjazd %s (%s).', FleetRepository::driverLabel($driver), $other['numer'] ?: '#' . $other['id'], $when);
            }
        }

        $tripEnd = substr($to, 0, 10);

        if ($vehicle) {
            if (($vehicle['status'] ?? '') === 'w_obsludze') {
                $warnings[] = sprintf('Pojazd %s jest oznaczony jako „w obsłudze / naprawie”.', $vehicle['nr_rejestracyjny']);
            }
            if (($vehicle['status'] ?? '') === 'wycofany') {
                $errors[] = sprintf('Pojazd %s jest wycofany z eksploatacji.', $vehicle['nr_rejestracyjny']);
            }
            foreach (['przeglad_do' => 'badanie techniczne', 'ubezpieczenie_do' => 'ubezpieczenie OC'] as $field => $label) {
                if (!empty($vehicle[$field]) && $vehicle[$field] < $tripEnd) {
                    $warnings[] = sprintf('Pojazd %s: %s ważne tylko do %s.', $vehicle['nr_rejestracyjny'], $label, self::formatDate($vehicle[$field]));
                }
            }
        }

        if ($driver) {
            $status = (string) ($driver['status'] ?? 'dostepny');
            if ($status !== '' && $status !== 'dostepny') {
                $warnings[] = sprintf('Kierowca %s ma status „%s”.', FleetRepository::driverLabel($driver), Dictionary::label('kierowcy', 'status', $status));
            }
            $checks = [
                'prawo_jazdy_do' => 'prawo jazdy',
                'badania_lekarskie_do' => 'badania lekarskie',
                'badania_psychologiczne_do' => 'badania psychologiczne',
            ];
            if (($vehicle['rodzaj'] ?? '') === 'wojskowy') {
                $checks['pozwolenie_wojskowe_do'] = 'pozwolenie wojskowe';
                if (trim((string) ($driver['nr_pozwolenia_wojskowego'] ?? '')) === '' && empty($driver['pozwolenie_wojskowe_do'])) {
                    $warnings[] = sprintf('Kierowca %s nie ma wpisanego pozwolenia do kierowania pojazdami wojskowymi.', FleetRepository::driverLabel($driver));
                }
            }
            foreach ($checks as $field => $label) {
                if (!empty($driver[$field]) && $driver[$field] < $tripEnd) {
                    $warnings[] = sprintf('Kierowca %s: %s ważne tylko do %s.', FleetRepository::driverLabel($driver), $label, self::formatDate($driver[$field]));
                }
            }

            $required = self::normalizeCategories((string) ($vehicle['wymagana_kategoria'] ?? ''));
            if ($required !== []) {
                $held = self::normalizeCategories((string) ($driver['kategorie'] ?? ''));
                $missing = array_diff($required, $held);
                if ($missing !== []) {
                    $warnings[] = sprintf(
                        'Kierowca %s nie ma kategorii %s wymaganej dla pojazdu %s.',
                        FleetRepository::driverLabel($driver),
                        implode(', ', $missing),
                        $vehicle['nr_rejestracyjny'] ?? ''
                    );
                }
            }
        }

        return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * @return string[]
     */
    public static function normalizeCategories(string $value): array
    {
        $parts = preg_split('/[\s,;]+/', mb_strtoupper((string) preg_replace('/\s*\+\s*/', '+', $value))) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $parts), static fn(string $part): bool => $part !== '')));
    }

    public static function formatDate(?string $value): string
    {
        if (!$value) {
            return '';
        }

        return date('d.m.Y', strtotime($value));
    }

    public static function formatRange(?string $from, ?string $to): string
    {
        if (!$from || !$to) {
            return '';
        }

        $fromTs = strtotime($from);
        $toTs = strtotime($to);
        if (date('Y-m-d', $fromTs) === date('Y-m-d', $toTs)) {
            return date('d.m.Y H:i', $fromTs) . '–' . date('H:i', $toTs);
        }

        return date('d.m.Y H:i', $fromTs) . ' – ' . date('d.m.Y H:i', $toTs);
    }
}
