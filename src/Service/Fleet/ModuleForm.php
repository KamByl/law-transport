<?php

namespace App\Service\Fleet;

use Symfony\Component\HttpFoundation\Request;

/**
 * Formularze frontendu budowane z definicji modułów CMS (config/module-installations).
 * Dodanie pola w definicji modułu dodaje je też do formularza we frontendzie.
 */
class ModuleForm
{
    private const SUPPORTED = ['text', 'textarea', 'number', 'decimal', 'date', 'datetime', 'select', 'relation'];

    /**
     * @param string[] $only pola do pokazania (puste = wszystkie)
     * @param string[] $except pola do pominięcia
     */
    public function __construct(
        private FleetRepository $repository,
        private string $moduleKey,
        private array $only = [],
        private array $except = []
    ) {
    }

    /**
     * Sekcje formularza (zakładki z definicji modułu) z polami i opcjami.
     *
     * @return array<int, array{label: string, fields: array<int, array<string, mixed>>}>
     */
    public function sections(): array
    {
        $sections = [];
        $current = ['label' => '', 'fields' => []];

        foreach (Dictionary::fields($this->moduleKey) as $field) {
            $type = strtolower((string) ($field['type'] ?? 'text'));
            if ($type === 'tab' || $type === 'section') {
                if ($current['fields'] !== []) {
                    $sections[] = $current;
                }
                $current = ['label' => (string) ($field['label'] ?? ''), 'fields' => []];
                continue;
            }
            if (!$this->accepts($field)) {
                continue;
            }
            if ($type === 'relation') {
                $field['options'] = $this->relationOptions((string) ($field['module'] ?? ''));
            }
            $field['type'] = $type;
            $current['fields'][] = $field;
        }

        if ($current['fields'] !== []) {
            $sections[] = $current;
        }

        return $sections;
    }

    /**
     * Wartości z żądania, zrzutowane na typy kolumn.
     *
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    public function extract(Request $request): array
    {
        $values = [];
        $errors = [];

        foreach (Dictionary::fields($this->moduleKey) as $field) {
            if (!$this->accepts($field)) {
                continue;
            }

            $name = (string) $field['name'];
            $type = strtolower((string) ($field['type'] ?? 'text'));
            $raw = trim((string) $request->request->get($name, ''));
            $label = (string) ($field['label'] ?? $name);
            $value = null;

            if ($raw !== '') {
                switch ($type) {
                    case 'number':
                    case 'relation':
                        if (!preg_match('/^-?\d+$/', $raw)) {
                            $errors[$name] = sprintf('Pole „%s” musi być liczbą całkowitą.', $label);
                            break;
                        }
                        $value = (int) $raw;
                        break;
                    case 'decimal':
                        $normalized = str_replace([' ', ','], ['', '.'], $raw);
                        if (!is_numeric($normalized)) {
                            $errors[$name] = sprintf('Pole „%s” musi być liczbą.', $label);
                            break;
                        }
                        $value = (float) $normalized;
                        break;
                    case 'date':
                        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
                        if (!$date) {
                            $errors[$name] = sprintf('Pole „%s” ma niepoprawną datę.', $label);
                            break;
                        }
                        $value = $date->format('Y-m-d');
                        break;
                    case 'datetime':
                        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', substr($raw, 0, 16))
                            ?: \DateTimeImmutable::createFromFormat('!Y-m-d H:i', substr($raw, 0, 16));
                        if (!$date) {
                            $errors[$name] = sprintf('Pole „%s” ma niepoprawną datę i godzinę.', $label);
                            break;
                        }
                        $value = $date->format('Y-m-d H:i:s');
                        break;
                    case 'select':
                        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
                        if (!array_key_exists($raw, $options)) {
                            $errors[$name] = sprintf('Pole „%s” ma niedozwoloną wartość.', $label);
                            break;
                        }
                        $value = $raw;
                        break;
                    default:
                        $value = mb_substr($raw, 0, $type === 'textarea' ? 65000 : 255);
                }
            } elseif (!empty($field['required'])) {
                $errors[$name] = sprintf('Pole „%s” jest wymagane.', $label);
            }

            $values[$name] = $value;
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $field
     */
    private function accepts(array $field): bool
    {
        $name = $field['name'] ?? null;
        $type = strtolower((string) ($field['type'] ?? 'text'));
        if (!is_string($name) || $name === '' || !in_array($type, self::SUPPORTED, true) || ($field['form'] ?? true) === false) {
            return false;
        }
        if ($this->only !== [] && !in_array($name, $this->only, true)) {
            return false;
        }

        return !in_array($name, $this->except, true);
    }

    /**
     * @return array<string, string>
     */
    private function relationOptions(string $moduleKey): array
    {
        $options = [];
        if ($moduleKey === 'pojazdy') {
            foreach ($this->repository->vehicles() as $vehicle) {
                $options[(string) $vehicle['id']] = FleetRepository::vehicleLabel($vehicle);
            }
        } elseif ($moduleKey === 'kierowcy') {
            foreach ($this->repository->drivers() as $driver) {
                $options[(string) $driver['id']] = FleetRepository::driverLabel($driver);
            }
        }

        return $options;
    }
}
