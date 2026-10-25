<?php

return [
    'key' => 'ustawienia_statusow',
    'module' => [
        'label' => 'Ustawienia statusów',
        'type' => 'list',
        'menu' => [
            'icon' => 'bi-diagram-3',
            'position' => 92,
        ],
        'fields' => [
            ['name' => 'name', 'label' => 'Nazwa', 'type' => 'text'],
            ['name' => 'position', 'label' => 'Kolejność', 'type' => 'number'],
            [
                'name' => 'email_template',
                'label' => 'Szablon maila wysyłany przy ustawieniu statusu',
                'type' => 'relation',
                'module' => 'email_templates',
                'label_field' => 'name',
                'options_group_label' => 'Szablony e-mail',
                'extra_options_group_label' => 'Szablony z rodzaju nagrody',
                'extra_options' => [
                    'predefined:prize_info_email_template' => 'Nagrody - Mail z informacją o wygranej i formularzu',
                    'predefined:prize_granted_email_template' => 'Nagrody - Mail z informacją o przyznaniu nagrody (wysyłka kodów)',
                    'predefined:prize_granted_no_codes_email_template' => 'Nagrody - Mail z informacją o przyznaniu nagrody (gdy brak kodów w bazie)',
                ],
                'multiple' => false,
            ],
            [
                'name' => 'administrator_email_template',
                'label' => 'Szablon maila wysyłany przy ustawieniu statusu do administratora',
                'type' => 'relation',
                'module' => 'email_templates',
                'label_field' => 'name',
                'multiple' => false,
            ],
            [
                'name' => 'administrator_email',
                'label' => 'Mail administratora',
                'type' => 'text',
            ],
            [
                'name' => 'do_weryfikacji_przez_administratora',
                'label' => 'Do weryfikacji przez administratora',
                'type' => 'checkbox',
            ],
            [
                'name' => 'do_weryfikacji_przez_klienta',
                'label' => 'Do weryfikacji przez klienta',
                'type' => 'checkbox',
            ],
            [
                'name' => 'do_wysylki',
                'label' => 'Do wysyłki',
                'type' => 'checkbox',
            ],
            [
                'name' => 'do_wyslania_kodow',
                'label' => 'Do wysłania kodów jak będą dostępne',
                'type' => 'checkbox',
                'help' => 'CRON będzie sprawdzał zwycięzców z tym statusem i po przydzieleniu dostępnych kodów ustawi status skonfigurowany w rodzaju nagrody.',
            ],
            [
                'name' => 'zrealizowane',
                'label' => 'Zrealizowane',
                'type' => 'checkbox',
            ],
            [
                'name' => 'wyswietlaj_w_sekcji_laureatow',
                'label' => 'Wyświetlaj w sekcji laureatów',
                'type' => 'checkbox',
            ],
            [
                'name' => 'odrzucone',
                'label' => 'Odrzucone',
                'type' => 'checkbox',
            ],
        ],
        'list' => [
            'columns' => [
                'name',
                'email_template',
                'administrator_email_template',
                'administrator_email',
                'do_weryfikacji_przez_administratora',
                'do_weryfikacji_przez_klienta',
                'do_wysylki',
                'do_wyslania_kodow',
                'zrealizowane',
                'wyswietlaj_w_sekcji_laureatow',
                'odrzucone',
                'position',
            ],
            'actions' => ['view', 'edit', 'delete'],
            'permissions' => ['view', 'create', 'edit', 'delete'],
            'manual_sort' => true,
            'sort_column' => 'position',
            'stats_enabled' => false,
        ],
    ],
    'database' => [
        'table' => 'ustawienia_statusow',
    ],
    'permission' => [
        'code' => 'ustawienia_statusow',
        'label' => 'Ustawienia statusów',
    ],
];
