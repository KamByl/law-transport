<?php

return [
    'key' => 'kody_zywiec',
    'module' => [
        'label' => 'Kody zywiec',
        'type' => 'list',
        'menu' => [
            'icon' => 'bi-upc-scan',
            'position' => 30,
        ],
        'fields' => [
            ['name' => 'kod', 'label' => 'Kod', 'type' => 'text'],
            ['name' => 'wykorzystany_do_loterii', 'label' => 'Wykorzystany do loterii', 'type' => 'checkbox'],
            ['name' => 'data_wykorzystania_do_loterii', 'label' => 'Data wykorzystania do loterii', 'type' => 'datetime'],
        ],
        'list' => [
            'columns' => [
                'kod',
                'wykorzystany_do_loterii',
                'data_wykorzystania_do_loterii',
            ],
            'actions' => ['view', 'edit', 'delete'],
            'permissions' => ['view', 'create', 'edit', 'delete'],
            'stats_enabled' => true,
        ],
    ],
    'database' => [
        'table' => 'kody_zywiec_entries',
    ],
    'permission' => [
        'code' => 'kody_zywiec',
        'label' => 'Kody zywiec',
    ],
];
