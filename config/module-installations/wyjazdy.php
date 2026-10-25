<?php

return [
    'key' => 'wyjazdy',
    'module' => [
        'label' => 'Wyjazdy',
        'type' => 'list',
        'menu' => [
            'icon' => 'bi-signpost-split',
            'position' => 12,
        ],
        'fields' => [
            ['type' => 'tab', 'label' => 'Wyjazd'],
            ['name' => 'numer', 'label' => 'Numer', 'type' => 'text', 'help' => 'Nadawany automatycznie we frontendzie.'],
            ['name' => 'cel', 'label' => 'Cel wyjazdu', 'type' => 'text', 'required' => true],
            ['name' => 'miejsce_wyjazdu', 'label' => 'Skąd', 'type' => 'text'],
            ['name' => 'miejsce_docelowe', 'label' => 'Dokąd', 'type' => 'text'],
            ['name' => 'trasa', 'label' => 'Trasa (przez)', 'type' => 'text'],
            ['name' => 'data_od', 'label' => 'Wyjazd', 'type' => 'datetime', 'required' => true],
            ['name' => 'data_do', 'label' => 'Powrót', 'type' => 'datetime', 'required' => true],
            ['name' => 'pojazd', 'label' => 'Pojazd', 'type' => 'relation', 'module' => 'pojazdy', 'label_field' => 'nr_rejestracyjny', 'label_template' => '{nr_rejestracyjny} {marka} {model}'],
            ['name' => 'kierowca', 'label' => 'Kierowca', 'type' => 'relation', 'module' => 'kierowcy', 'label_field' => 'nazwisko', 'label_template' => '{stopien} {imie} {nazwisko}'],
            ['name' => 'dowodca', 'label' => 'Dowódca pojazdu / starszy', 'type' => 'text'],
            ['name' => 'zlecajacy', 'label' => 'Zlecający', 'type' => 'text'],
            ['name' => 'liczba_osob', 'label' => 'Liczba osób', 'type' => 'number'],
            ['name' => 'ladunek', 'label' => 'Ładunek', 'type' => 'text'],
            ['name' => 'uwagi', 'label' => 'Uwagi', 'type' => 'textarea'],
            [
                'name' => 'status',
                'label' => 'Status',
                'type' => 'select',
                'options' => [
                    'planowany' => 'Planowany',
                    'zatwierdzony' => 'Zatwierdzony',
                    'w_realizacji' => 'W realizacji',
                    'zakonczony' => 'Zakończony',
                    'anulowany' => 'Anulowany',
                ],
            ],
            ['type' => 'tab', 'label' => 'Rozliczenie'],
            ['name' => 'licznik_start', 'label' => 'Licznik przy wyjeździe [km]', 'type' => 'number'],
            ['name' => 'licznik_stop', 'label' => 'Licznik przy powrocie [km]', 'type' => 'number'],
            ['name' => 'paliwo_start', 'label' => 'Paliwo przy wyjeździe [l]', 'type' => 'decimal'],
            ['name' => 'paliwo_stop', 'label' => 'Paliwo przy powrocie [l]', 'type' => 'decimal'],
            ['name' => 'faktyczny_wyjazd', 'label' => 'Faktyczny wyjazd', 'type' => 'datetime'],
            ['name' => 'faktyczny_powrot', 'label' => 'Faktyczny powrót', 'type' => 'datetime'],
            ['name' => 'zatwierdzil', 'label' => 'Zatwierdził', 'type' => 'text'],
        ],
        'list' => [
            'columns' => ['numer', 'cel', 'data_od', 'data_do', 'pojazd', 'kierowca', 'status'],
            'actions' => ['view', 'edit', 'delete'],
            'permissions' => ['view', 'create', 'edit', 'delete'],
            'default_sort' => 'data_od',
            'default_dir' => 'desc',
        ],
    ],
    'database' => [
        'table' => 'wyjazdy_entries',
        'indexes' => [
            'idx_wyjazdy_okres' => ['data_od', 'data_do'],
            'idx_wyjazdy_pojazd' => ['pojazd', 'data_od'],
            'idx_wyjazdy_kierowca' => ['kierowca', 'data_od'],
        ],
    ],
    'permission' => [
        'code' => 'wyjazdy',
        'label' => 'Wyjazdy',
    ],
];
