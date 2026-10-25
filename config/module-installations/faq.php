<?php

return [
    'key' => 'faq',
    'module' => [
        'label' => 'Q&A',
        'type' => 'list',
        'menu' => [
            'icon' => 'bi-question-circle',
            'position' => 95,
        ],
        'fields' => [
            ['name' => 'question', 'label' => 'Pytanie', 'type' => 'text'],
            ['name' => 'answer', 'label' => 'Odpowiedź', 'type' => 'wysiwyg'],
            ['name' => 'active', 'label' => 'Aktywne', 'type' => 'boolean_select'],
            ['name' => 'position', 'label' => 'Kolejność', 'type' => 'number'],
        ],
        'list' => [
            'columns' => ['question', 'active', 'position'],
            'filters' => [
                ['name' => 'active'],
            ],
'actions' => ['view', 'edit', 'delete'],
            'permissions' => ['view', 'create', 'edit', 'delete'],
            'manual_sort' => true,
            'sort_column' => 'position',
        ],
    ],
    'database' => [
        'table' => 'faq_entries',
    ],
    'permission' => [
        'code' => 'faq',
        'label' => 'Q&A',
    ],
];
