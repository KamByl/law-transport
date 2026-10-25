<?php

return [
    'key' => 'email_templates',
    'module' => [
        'label' => 'Szablony E-mail',
        'type' => 'list',
        'menu' => [
            'icon' => 'bi-envelope',
            'position' => 45,
        ],
        'fields' => [
            ['name' => 'name', 'label' => 'Nazwa szablonu', 'type' => 'text'],
            ['name' => 'subject', 'label' => 'Temat maila', 'type' => 'text'],
            ['name' => 'body', 'label' => 'Treść maila', 'type' => 'wysiwyg'],
            ['name' => 'bcc', 'label' => 'UDW (Ukryte Do Wiadomości)', 'type' => 'text'],
            ['name' => 'priority_email', 'label' => 'E-mail priorytetowy', 'type' => 'checkbox'],
            [
                'type' => 'section',
                'label' => 'Dostępne elementy',
            ],
            [
                'name' => 'placeholders_info',
                'label' => 'Skróty w treści',
                'type' => 'info',
                'text' => "Znaczniki w treści są uzupełniane przez moduł, który wysyła wiadomość.\n"
                    . "Lista znaczników dla każdego modułu jest opisana w jego dokumentacji.",
            ],
        ],
        'list' => [
            'columns' => ['name', 'subject', 'bcc', 'priority_email'],
            'actions' => ['view', 'edit', 'delete'],
            'permissions' => ['view', 'create', 'edit', 'delete'],
            'manual_sort' => false,
            'sort_column' => 'id',
            'stats_enabled' => false,
            'extra_row_actions' => [
                [
                    'key' => 'send_test_email',
                    'label' => 'Wyślij testowy',
                    'handler' => 'sendTestEmailTemplate',
                ],
            ],
        ],
    ],
    'database' => [
        'table' => 'email_templates',
    ],
    'permission' => [
        'code' => 'email_templates',
        'label' => 'Szablony E-mail',
    ],
];
