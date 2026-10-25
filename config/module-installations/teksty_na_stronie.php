<?php

return [
    'key' => 'teksty_na_stronie',
    'module' => [
        'label' => 'Strona konsumenta',
        'type' => 'single',
        'menu' => [
            'icon' => 'bi-card-text',
            'position' => 20,
        ],
        'fields' => [
            [
                'type' => 'tab',
                'key' => 'zasady_loterii',
                'label' => 'Zasady Loterii',
            ],
            [
                'type' => 'section',
                'label' => 'Zasady Loterii',
            ],
            ['name' => 'opis_loteria_1', 'label' => 'Opis loteria', 'type' => 'textarea'],
            ['name' => 'zdjecie_1', 'label' => 'Zdjęcie', 'type' => 'image'],
            ['name' => 'opis_loteria_2', 'label' => 'Opis loteria', 'type' => 'textarea'],
            ['name' => 'zdjecie_2', 'label' => 'Zdjęcie', 'type' => 'image'],
            ['name' => 'opis_loteria_3', 'label' => 'Opis loteria', 'type' => 'textarea'],
            ['name' => 'zdjecie_3', 'label' => 'Zdjęcie', 'type' => 'image'],
            ['name' => 'opis_loteria_4', 'label' => 'Opis loteria', 'type' => 'textarea'],
            ['name' => 'zdjecie_4', 'label' => 'Zdjęcie', 'type' => 'image'],
            ['name' => 'informacje', 'label' => 'Informacje', 'type' => 'textarea'],
            [
                'type' => 'tab',
                'key' => 'produkty',
                'label' => 'Produkty',
            ],
            [
                'type' => 'section',
                'label' => 'Produkty',
            ],
            [
                'name' => 'produkty',
                'label' => 'Produkty',
                'type' => 'structured_list',
                'db' => ['type' => 'text'],
                'fields' => [
                    ['name' => 'zdjecie', 'label' => 'Zdjęcie', 'type' => 'image'],
                ],
            ],
            [
                'type' => 'tab',
                'key' => 'nagrody',
                'label' => 'Nagrody',
            ],
            [
                'type' => 'section',
                'label' => 'Nagrody',
            ],
            [
                'name' => 'nagrody',
                'label' => 'Nagrody',
                'type' => 'structured_list',
                'db' => ['type' => 'text'],
                'fields' => [
                    ['name' => 'zdjecie', 'label' => 'Zdjęcie', 'type' => 'image'],
                ],
            ],
            [
                'type' => 'tab',
                'key' => 'formularz',
                'label' => 'Formularz loteria',
            ],
            [
                'type' => 'section',
                'label' => 'Formularz loteria',
            ],
            ['name' => 'formularz_tekst_dol', 'label' => 'Tekst dół', 'type' => 'wysiwyg'],
            ['name' => 'formularz_regulamin_plik', 'label' => 'Plik z regulaminem', 'type' => 'relation', 'module' => 'pliki', 'label_field' => 'nazwa', 'multiple' => false],
            ['name' => 'formularz_regulamin_zdjecie', 'label' => 'Wyciąg z regulaminu', 'type' => 'image'],
            [
                'type' => 'tab',
                'key' => 'stopka',
                'label' => 'Stopka loteria',
            ],
            [
                'type' => 'section',
                'label' => 'Stopka loteria',
            ],
            ['name' => 'stopka_naglowek_1', 'label' => 'Nagłówek 1', 'type' => 'text'],
            ['name' => 'stopka_tresc_1', 'label' => 'Treść 1', 'type' => 'wysiwyg'],
            ['name' => 'stopka_naglowek_2', 'label' => 'Nagłówek 2', 'type' => 'text'],
            ['name' => 'stopka_tresc_2', 'label' => 'Treść 2', 'type' => 'wysiwyg'],
            ['name' => 'stopka_linki', 'label' => 'Linki', 'type' => 'link_list', 'module' => 'pliki', 'label_field' => 'nazwa'],
        ],
        'permissions' => ['view', 'edit'],
    ],
    'database' => [
        'table' => 'teksty_na_stronie_entries',
    ],
    'permission' => [
        'code' => 'teksty_na_stronie',
        'label' => 'Strona konsumenta',
    ],
];
