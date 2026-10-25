<?php

return [
    'key' => 'sieci_sklepow',
    'module' => [
        'label' => 'Sieci sklepów',
        'type' => 'list',
        'menu' => ['icon' => 'bi-shop', 'position' => 24],
        'fields' => [
            ['name' => 'name', 'label' => 'Nazwa sieci', 'type' => 'text', 'required' => true],
            ['name' => 'active', 'label' => 'Aktywna w formularzu', 'type' => 'boolean_select'],
        ],
        'list' => [
            'columns' => ['name', 'active'],
            'filters' => [['name' => 'active']],
            'actions' => ['view', 'edit', 'delete'],
            'permissions' => ['view', 'create', 'edit', 'delete'],
            'default_sort' => 'name',
            'default_dir' => 'asc',
        ],
    ],
    'database' => ['table' => 'sieci_sklepow_entries'],
    'permission' => ['code' => 'sieci_sklepow', 'label' => 'Sieci sklepów'],
];
