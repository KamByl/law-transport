<?php

return [
    'key' => 'podejrzane_adresy_email',
    'module' => [
        'label' => 'Podejrzane adresy e-mail',
        'type' => 'list',
        'menu' => [
            'icon' => 'bi-envelope-exclamation',
            'position' => 96,
        ],
        'fields' => [
            ['name' => 'email', 'label' => 'E-mail', 'type' => 'text'],
            [
                'name' => 'created_at',
                'label' => 'Data dodania',
                'type' => 'datetime',
                'form' => false,
                'auto_current_datetime_on_create' => true,
            ],
        ],
        'list' => [
            'columns' => ['email', 'created_at'],
            'actions' => ['view', 'edit', 'delete'],
            'permissions' => ['view', 'create', 'edit', 'delete'],
            'default_sort' => 'created_at',
            'default_dir' => 'desc',
            'stats_enabled' => false,
        ],
    ],
    'database' => [
        'table' => 'podejrzane_adresy_email_entries',
    ],
    'permission' => [
        'code' => 'podejrzane_adresy_email',
        'label' => 'Podejrzane adresy e-mail',
    ],
];
