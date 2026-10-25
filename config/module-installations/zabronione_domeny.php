<?php

return [
    'key' => 'zabronione_domeny',
    'module' => [
        'label' => 'Zabronione domeny',
        'type' => 'list',
        'menu' => [
            'icon' => 'bi-shield-x',
            'position' => 95,
        ],
        'fields' => [
            ['name' => 'domena', 'label' => 'Nazwa domeny', 'type' => 'text'],
        ],
        'list' => [
            'columns' => ['domena'],
            'actions' => ['view', 'edit', 'delete'],
            'permissions' => ['view', 'create', 'edit', 'delete'],
            'stats_enabled' => false,
        ],
    ],
    'database' => [
        'table' => 'zabronione_domeny_entries',
    ],
    'permission' => [
        'code' => 'zabronione_domeny',
        'label' => 'Zabronione domeny',
    ],
];
