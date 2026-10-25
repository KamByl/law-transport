<?php

return [
    'news' => [
        'label' => 'Aktualności',
        'type' => 'list',
        'menu' => [
            'icon' => 'bi-newspaper',
            'position' => 10,
        ],
        'fields' => [
            [
                'name' => 'title',
                'label' => 'Tytuł',
                'type' => 'text',
            ],
            [
                'name' => 'content',
                'label' => 'Treść',
                'type' => 'textarea',
            ],
            [
                'name' => 'published_at',
                'label' => 'Data publikacji',
                'type' => 'date',
            ],
            [
                'name' => 'category',
                'label' => 'Kategoria',
                'type' => 'relation',
                'module' => 'news_categories',
                'multiple' => false,
                'allow_create' => true,
            ],
        ],
        'list' => [
            'columns' => ['title', 'published_at', 'category'],
            'actions' => ['view', 'edit', 'delete', 'copy'],
            'permissions' => ['view', 'create', 'edit', 'delete'],
        ],
    ],
    'settings' => [
        'label' => 'Ustawienia strony',
        'type' => 'single',
        'menu' => [
            'icon' => 'bi-gear',
            'position' => 90,
        ],
        'fields' => [
            [
                'name' => 'site_name',
                'label' => 'Nazwa strony',
                'type' => 'text',
            ],
            [
                'name' => 'site_description',
                'label' => 'Opis strony',
                'type' => 'textarea',
            ],
            [
                'name' => 'favicon',
                'label' => 'Favicon',
                'type' => 'image',
            ],
            [
                'name' => 'regulamin',
                'label' => 'Regulamin',
                'type' => 'file',
                'file_types' => ['pdf'],
            ],
            [
                'name' => 'deklaracja_zgodnosci',
                'label' => 'Deklaracja zgodności',
                'type' => 'file',
                'file_types' => ['pdf'],
            ],
            [
                'name' => 'polityka_cookies',
                'label' => 'Polityka cookies',
                'type' => 'file',
                'file_types' => ['pdf'],
            ],
            [
                'name' => 'link_youtube',
                'label' => 'Link YouTube',
                'type' => 'link',
            ],
            [
                'name' => 'link_facebook',
                'label' => 'Link Facebook',
                'type' => 'link',
            ],
        ],
        'permissions' => ['view', 'edit'],
    ],
];
