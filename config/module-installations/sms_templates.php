<?php

return [
    'key' => 'sms_templates',
    'module' => [
        'label' => 'Szablony SMS',
        'type' => 'list',
        'menu' => [
            'icon' => 'bi-chat-dots',
            'position' => 46,
        ],
        'fields' => [
            ['name' => 'name', 'label' => 'Nazwa szablonu', 'type' => 'text'],
            ['name' => 'body', 'label' => 'Treść SMS', 'type' => 'textarea'],
            [
                'name' => 'placeholders_info',
                'label' => 'Skróty w treści',
                'type' => 'info',
                'text' => "{data} - data zgłoszenia (dd-mm-rrrr)\n{kod} - kod zgłoszenia\n{kod_slownie} - kod zgłoszenia zapisany słownie\n{data_czas} - data i czas zgłoszenia (YYYY-MM-DD HH:MM:SS)\n{imie_nazwisko} - imię i nazwisko (np. Jan K.)\n{pesel} - zamaskowany PESEL (pierwsze i ostatnie 2 cyfry)\n{telefon} - zamaskowany numer telefonu (pierwsze i ostatnie 2 cyfry)",
            ],
        ],
        'list' => [
            'columns' => ['name'],
            'actions' => ['view', 'edit', 'delete'],
            'permissions' => ['view', 'create', 'edit', 'delete'],
            'manual_sort' => false,
            'sort_column' => 'id',
            'stats_enabled' => false,
            'extra_action' => [
                'label' => 'Wyślij test',
                'handler' => 'sendTestSmsTemplate',
            ],
        ],
    ],
    'database' => [
        'table' => 'sms_templates',
    ],
    'permission' => [
        'code' => 'sms_templates',
        'label' => 'Szablony SMS',
    ],
];
