<?php

return [
    'key' => 'loteria_losy',
    'module' => [
        'label' => 'Loteria losy',
        'type' => 'list',
        'menu' => [
            'icon' => 'bi-ticket',
            'position' => 26,
        ],
        'fields' => [
            ['name' => 'ticket_id', 'label' => 'ID losu', 'type' => 'text'],
            [
                'name' => 'zgloszenie',
                'label' => 'Zgłoszenie',
                'type' => 'relation',
                'module' => 'loteria_zgloszenia',
                'label_field' => 'id',
                'label_prefix' => 'Zgłoszenie ',
                'remote' => true,
                'multiple' => false,
            ],
            ['name' => 'email', 'label' => 'Adres e-mail', 'type' => 'text'],
            ['name' => 'phone', 'label' => 'Numer telefonu', 'type' => 'text'],
            ['name' => 'rachunek', 'label' => 'Rachunek', 'type' => 'related_field', 'relation' => 'zgloszenie', 'field' => 'rachunek', 'display_type' => 'image', 'readonly' => true],
            [
                'name' => 'zgloszenie_sprawdzone',
                'label' => 'Zgłoszenie sprawdzone',
                'type' => 'related_field',
                'relation' => 'zgloszenie',
                'field' => 'zgloszenie_sprawdzone',
                'display_type' => 'select',
                'options' => [
                    'nie_sprawdzone' => 'Nie sprawdzone',
                    'poprawne' => 'Poprawne',
                    'niepoprawne' => 'Niepoprawne',
                ],
                'editable' => true,
            ],
            ['name' => 'submitted_at', 'label' => 'Data i godzina zgłoszenia', 'type' => 'datetime'],
            ['name' => 'wygrany', 'label' => 'Wygrany', 'type' => 'boolean_select'],
            [
                'name' => 'status',
                'label' => 'Status',
                'type' => 'select',
                'options' => [
                    'nie_sprawdzany' => 'Nie sprawdzany',
                    'sprawdzony_ai' => 'Sprawdzony AI',
                    'sprawdzony_recznie' => 'Sprawdzony ręcznie',
                    'do_sprawdzenia' => 'Do sprawdzenia',
                    'odrzucony' => 'Odrzucony',
                ],
                'placeholder' => 'Wybierz status',
            ],
        ],
        'list' => [
            'columns' => ['ticket_id', 'zgloszenie', 'zgloszenie_sprawdzone', 'email', 'phone', 'submitted_at', 'wygrany', 'status'],
            'extra_actions' => [
                [
                    'key' => 'reject_tickets_for_rejected_scratchcards',
                    'label' => 'Sprawdź losy ze zdrapkami',
                    'handler' => 'rejectTicketsForRejectedScratchcards',
                    'confirm' => 'Czy na pewno chcesz ustawić status Odrzucony dla losów powiązanych z odrzuconymi zdrapkami?',
                ],
            ],
            'filters' => [],
'actions' => ['view', 'edit', 'delete'],
            'permissions' => ['view', 'create', 'edit', 'delete'],
            'stats_enabled' => true,
        ],
    ],
    'database' => [
        'table' => 'loteria_losy_entries',
    ],
    'permission' => [
        'code' => 'loteria_losy',
        'label' => 'Loteria losy',
    ],
];
