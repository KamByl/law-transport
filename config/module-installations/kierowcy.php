<?php

return [
    'key' => 'kierowcy',
    'module' => [
        'label' => 'Kierowcy',
        'type' => 'list',
        'menu' => [
            'icon' => 'bi-person-badge',
            'position' => 11,
        ],
        'fields' => [
            ['type' => 'tab', 'label' => 'Dane kierowcy'],
            ['name' => 'stopien', 'label' => 'Stopień', 'type' => 'text', 'help' => 'Puste dla pracowników cywilnych.'],
            ['name' => 'imie', 'label' => 'Imię', 'type' => 'text', 'required' => true],
            ['name' => 'nazwisko', 'label' => 'Nazwisko', 'type' => 'text', 'required' => true],
            ['name' => 'pododdzial', 'label' => 'Pododdział / komórka', 'type' => 'text'],
            ['name' => 'telefon', 'label' => 'Telefon', 'type' => 'text'],
            ['name' => 'email', 'label' => 'E-mail konta użytkownika', 'type' => 'text', 'help' => 'Ten sam adres co konto użytkownika. Dzięki temu kierowca widzi swoje wyjazdy po zalogowaniu.'],
            [
                'name' => 'status',
                'label' => 'Dostępność',
                'type' => 'select',
                'options' => [
                    'dostepny' => 'Dostępny',
                    'urlop' => 'Urlop',
                    'l4' => 'Zwolnienie lekarskie',
                    'delegacja' => 'Delegacja / kurs',
                    'nieaktywny' => 'Nieaktywny',
                ],
            ],
            ['type' => 'tab', 'label' => 'Uprawnienia'],
            ['name' => 'kategorie', 'label' => 'Kategorie prawa jazdy', 'type' => 'text', 'help' => 'Oddzielone przecinkami, np. B, C, C+E.'],
            ['name' => 'nr_prawa_jazdy', 'label' => 'Nr prawa jazdy', 'type' => 'text'],
            ['name' => 'prawo_jazdy_do', 'label' => 'Prawo jazdy ważne do', 'type' => 'date'],
            ['name' => 'nr_pozwolenia_wojskowego', 'label' => 'Nr pozwolenia do kierowania pojazdami wojskowymi', 'type' => 'text'],
            ['name' => 'pozwolenie_wojskowe_do', 'label' => 'Pozwolenie wojskowe ważne do', 'type' => 'date'],
            ['name' => 'badania_lekarskie_do', 'label' => 'Badania lekarskie ważne do', 'type' => 'date'],
            ['name' => 'badania_psychologiczne_do', 'label' => 'Badania psychologiczne ważne do', 'type' => 'date'],
            ['name' => 'uwagi', 'label' => 'Uwagi', 'type' => 'textarea'],
        ],
        'list' => [
            'columns' => ['stopien', 'imie', 'nazwisko', 'pododdzial', 'kategorie', 'status'],
            'actions' => ['view', 'edit', 'delete'],
            'permissions' => ['view', 'create', 'edit', 'delete'],
            'default_sort' => 'nazwisko',
            'default_dir' => 'asc',
        ],
    ],
    'database' => [
        'table' => 'kierowcy_entries',
        'indexes' => [
            'idx_kierowcy_email' => ['email'],
        ],
    ],
    'permission' => [
        'code' => 'kierowcy',
        'label' => 'Kierowcy',
    ],
];
