<?php

return [
    'key' => 'pliki',
    'module' => [
        'label' => 'Pliki',
        'type' => 'list',
        'menu' => [
            'icon' => 'bi-folder2-open',
            'position' => 96,
        ],
        'fields' => [
            ['name' => 'nazwa', 'label' => 'Nazwa', 'type' => 'text'],
            [
                'name' => 'plik',
                'label' => 'Plik',
                'type' => 'file',
                'original_name_field' => 'oryginalna_nazwa_pliku',
            ],
            ['name' => 'oryginalna_nazwa_pliku', 'label' => 'Oryginalna nazwa pliku', 'type' => 'text', 'form' => false],
        ],
        'list' => [
            'columns' => ['nazwa'],
            'actions' => ['view', 'edit', 'delete'],
            'permissions' => ['view', 'create', 'edit', 'delete'],
            'default_sort' => 'nazwa',
            'default_dir' => 'asc',
        ],
    ],
    'database' => [
        'table' => 'pliki_entries',
    ],
    'permission' => [
        'code' => 'pliki',
        'label' => 'Pliki',
    ],
];
