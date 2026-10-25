<?php

return [
    'key' => 'tankowania',
    'module' => [
        'label' => 'Tankowania',
        'type' => 'list',
        'menu' => [
            'icon' => 'bi-fuel-pump',
            'position' => 13,
        ],
        'fields' => [
            ['name' => 'data', 'label' => 'Data', 'type' => 'datetime', 'required' => true],
            ['name' => 'pojazd', 'label' => 'Pojazd', 'type' => 'relation', 'module' => 'pojazdy', 'label_field' => 'nr_rejestracyjny', 'label_template' => '{nr_rejestracyjny} {marka} {model}', 'required' => true],
            ['name' => 'kierowca', 'label' => 'Kierowca', 'type' => 'relation', 'module' => 'kierowcy', 'label_field' => 'nazwisko', 'label_template' => '{stopien} {imie} {nazwisko}'],
            ['name' => 'wyjazd', 'label' => 'Wyjazd', 'type' => 'relation', 'module' => 'wyjazdy', 'label_field' => 'numer', 'label_template' => '{numer} {cel}'],
            ['name' => 'ilosc', 'label' => 'Ilość [l]', 'type' => 'decimal', 'required' => true],
            [
                'name' => 'rodzaj_paliwa',
                'label' => 'Rodzaj paliwa',
                'type' => 'select',
                'options' => [
                    'ON' => 'Olej napędowy (ON)',
                    'PB' => 'Benzyna (PB)',
                    'F-34' => 'F-34',
                    'LPG' => 'LPG',
                ],
            ],
            [
                'name' => 'zrodlo',
                'label' => 'Źródło',
                'type' => 'select',
                'options' => [
                    'stacja_wojskowa' => 'Stacja wojskowa (MPS)',
                    'karta_paliwowa' => 'Karta paliwowa',
                    'gotowka' => 'Zakup gotówkowy / faktura',
                ],
            ],
            ['name' => 'licznik', 'label' => 'Stan licznika [km]', 'type' => 'number'],
            ['name' => 'nr_dokumentu', 'label' => 'Nr dokumentu (asygnata, faktura)', 'type' => 'text'],
            ['name' => 'koszt', 'label' => 'Koszt [zł]', 'type' => 'decimal'],
            ['name' => 'uwagi', 'label' => 'Uwagi', 'type' => 'text'],
        ],
        'list' => [
            'columns' => ['data', 'pojazd', 'kierowca', 'ilosc', 'rodzaj_paliwa', 'zrodlo'],
            'actions' => ['view', 'edit', 'delete'],
            'permissions' => ['view', 'create', 'edit', 'delete'],
            'default_sort' => 'data',
            'default_dir' => 'desc',
        ],
    ],
    'database' => [
        'table' => 'tankowania_entries',
        'indexes' => [
            'idx_tankowania_pojazd' => ['pojazd', 'data'],
        ],
    ],
    'permission' => [
        'code' => 'tankowania',
        'label' => 'Tankowania',
    ],
];
