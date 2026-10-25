<?php

return [
    'key' => 'pojazdy',
    'module' => [
        'label' => 'Pojazdy',
        'type' => 'list',
        'menu' => [
            'icon' => 'bi-truck',
            'position' => 10,
        ],
        'fields' => [
            ['type' => 'tab', 'label' => 'Dane pojazdu'],
            ['name' => 'nr_rejestracyjny', 'label' => 'Nr rejestracyjny', 'type' => 'text', 'required' => true],
            [
                'name' => 'rodzaj',
                'label' => 'Rodzaj',
                'type' => 'select',
                'options' => [
                    'wojskowy' => 'Wojskowy',
                    'cywilny' => 'Cywilny',
                ],
                'required' => true,
            ],
            [
                'name' => 'typ',
                'label' => 'Typ',
                'type' => 'select',
                'options' => [
                    'osobowy' => 'Osobowy',
                    'osobowo_terenowy' => 'Osobowo-terenowy',
                    'ciezarowy' => 'Ciężarowy',
                    'autobus' => 'Autobus / mikrobus',
                    'specjalny' => 'Specjalny',
                    'przyczepa' => 'Przyczepa',
                ],
            ],
            ['name' => 'marka', 'label' => 'Marka', 'type' => 'text'],
            ['name' => 'model', 'label' => 'Model', 'type' => 'text'],
            ['name' => 'nr_ewidencyjny', 'label' => 'Nr ewidencyjny / taktyczny', 'type' => 'text'],
            ['name' => 'vin', 'label' => 'VIN / nr nadwozia', 'type' => 'text'],
            ['name' => 'rok_produkcji', 'label' => 'Rok produkcji', 'type' => 'number'],
            ['name' => 'wymagana_kategoria', 'label' => 'Wymagana kategoria prawa jazdy', 'type' => 'text', 'help' => 'Np. B, C, C+E, D.'],
            ['name' => 'liczba_miejsc', 'label' => 'Liczba miejsc', 'type' => 'number'],
            ['name' => 'ladownosc_kg', 'label' => 'Ładowność [kg]', 'type' => 'number'],
            [
                'name' => 'status',
                'label' => 'Status',
                'type' => 'select',
                'options' => [
                    'sprawny' => 'Sprawny',
                    'w_obsludze' => 'W obsłudze / naprawie',
                    'wycofany' => 'Wycofany z eksploatacji',
                ],
            ],
            ['name' => 'kolor', 'label' => 'Kolor w planerze', 'type' => 'text', 'help' => 'Kolor HEX, np. #4c6b3c. Puste = domyślny.'],
            ['type' => 'tab', 'label' => 'Eksploatacja'],
            [
                'name' => 'rodzaj_paliwa',
                'label' => 'Rodzaj paliwa',
                'type' => 'select',
                'options' => [
                    'ON' => 'Olej napędowy (ON)',
                    'PB' => 'Benzyna (PB)',
                    'F-34' => 'F-34',
                    'LPG' => 'LPG',
                    'EV' => 'Elektryczny',
                ],
            ],
            ['name' => 'norma_zuzycia', 'label' => 'Norma zużycia [l/100 km]', 'type' => 'decimal'],
            ['name' => 'pojemnosc_zbiornika', 'label' => 'Pojemność zbiornika [l]', 'type' => 'decimal'],
            ['name' => 'przebieg', 'label' => 'Aktualny przebieg [km]', 'type' => 'number'],
            ['name' => 'stan_paliwa', 'label' => 'Aktualny stan paliwa [l]', 'type' => 'decimal'],
            ['name' => 'limit_km_miesiac', 'label' => 'Miesięczny limit km', 'type' => 'number'],
            ['type' => 'tab', 'label' => 'Terminy'],
            ['name' => 'przeglad_do', 'label' => 'Badanie techniczne / przegląd ważny do', 'type' => 'date'],
            ['name' => 'obsluga_do', 'label' => 'Najbliższa obsługa techniczna', 'type' => 'date'],
            ['name' => 'ubezpieczenie_do', 'label' => 'Ubezpieczenie OC ważne do', 'type' => 'date', 'help' => 'Dotyczy pojazdów cywilnych.'],
            ['name' => 'uwagi', 'label' => 'Uwagi', 'type' => 'textarea'],
        ],
        'list' => [
            'columns' => ['nr_rejestracyjny', 'rodzaj', 'marka', 'model', 'status', 'przeglad_do'],
            'actions' => ['view', 'edit', 'delete'],
            'permissions' => ['view', 'create', 'edit', 'delete'],
            'default_sort' => 'nr_rejestracyjny',
            'default_dir' => 'asc',
        ],
    ],
    'database' => [
        'table' => 'pojazdy_entries',
        'indexes' => [
            'uniq_pojazdy_nr_rej' => ['columns' => ['nr_rejestracyjny'], 'unique' => true],
        ],
    ],
    'permission' => [
        'code' => 'pojazdy',
        'label' => 'Pojazdy',
    ],
];
