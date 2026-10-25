<?php

return [
    'key' => 'jednostka',
    'module' => [
        'label' => 'Dane jednostki',
        'type' => 'single',
        'menu' => [
            'icon' => 'bi-building',
            'position' => 14,
        ],
        'fields' => [
            ['name' => 'nazwa', 'label' => 'Nazwa jednostki / grupy', 'type' => 'text', 'help' => 'Pojawia się w nagłówku dokumentów.'],
            ['name' => 'nr_jednostki', 'label' => 'Nr jednostki wojskowej', 'type' => 'text'],
            ['name' => 'miejscowosc', 'label' => 'Miejscowość', 'type' => 'text'],
            ['name' => 'adres', 'label' => 'Adres', 'type' => 'text'],
            ['name' => 'dowodca', 'label' => 'Dowódca / kierownik (stopień, imię, nazwisko)', 'type' => 'text'],
            ['name' => 'szef_sluzby', 'label' => 'Szef służby transportowej', 'type' => 'text'],
            ['name' => 'prog_alertu_dni', 'label' => 'Ostrzegaj o terminach na ile dni wcześniej', 'type' => 'number'],
        ],
    ],
    'database' => [
        'table' => 'jednostka_entries',
    ],
    'permission' => [
        'code' => 'jednostka',
        'label' => 'Dane jednostki',
    ],
];
