<?php

return [
    'key' => 'godziny_wygranych',
    'module' => [
        'label' => 'Godziny wygranych',
        'type' => 'list',
        'menu' => [
            'icon' => 'bi-clock-history',
            'position' => 28,
        ],
        'fields' => [
            ['name' => 'win_at', 'label' => 'Data i godzina', 'type' => 'datetime'],
            [
                'name' => 'rodzaj_nagrody',
                'label' => 'Rodzaj nagrody',
                'type' => 'relation',
                'module' => 'rodzaje_nagrod',
                'label_field' => 'name',
                'multiple' => false,
            ],
            ['name' => 'granted', 'label' => 'Przyznana', 'type' => 'boolean_select'],
            ['name' => 'priority_prize', 'label' => 'Nagroda priorytetowa', 'type' => 'boolean_select'],
            [
                'name' => 'granted_to_submission_id',
                'label' => 'Przyznana do zgłoszenia',
                'type' => 'relation',
                'module' => 'loteria_zgloszenia',
                'label_field' => 'id',
                'label_prefix' => 'Zgłoszenie ',
                'remote' => true,
                'multiple' => false,
            ],
            [
                'name' => 'granted_to_ticket_id',
                'label' => 'Przyznany do e-zdrapki',
                'type' => 'relation',
                'module' => 'loteria_zdrapki',
                'label_field' => 'ticket_id',
                'label_prefix' => 'E-zdrapka ',
                'remote' => true,
                'multiple' => false,
            ],
            ['name' => 'granted_at', 'label' => 'Data przyznania', 'type' => 'datetime'],
        ],
        'list' => [
            'columns' => [
                'win_at',
                'rodzaj_nagrody',
                'granted',
                'priority_prize',
                'granted_to_submission_id',
                'granted_to_ticket_id',
                'granted_at',
            ],
            'filters' => [],
            'actions' => ['view', 'edit', 'delete'],
            'permissions' => ['view', 'create', 'edit', 'delete'],
            'stats_enabled' => true,
        ],
    ],
    'database' => [
        'table' => 'godziny_wygranych_entries',
    ],
    'permission' => [
        'code' => 'godziny_wygranych',
        'label' => 'Godziny wygranych',
    ],
];
