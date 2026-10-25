<?php

return [
    'key' => 'settings',
    'module' => [
        'label' => 'Ustawienia strony',
        'type' => 'single',
        'menu' => [
            'icon' => 'bi-gear',
            'position' => 90,
        ],
        'fields' => [
            ['type' => 'section', 'label' => 'Meta dane'],
            ['name' => 'site_name', 'label' => 'Nazwa strony', 'type' => 'text'],
            ['name' => 'site_description', 'label' => 'Opis strony', 'type' => 'textarea'],
            ['name' => 'favicon', 'label' => 'Favicon', 'type' => 'image'],
            ['type' => 'section', 'label' => 'Udostępnianie (Open Graph)'],
            ['name' => 'share_title', 'label' => 'Tytuł przy udostępnianiu', 'type' => 'text'],
            ['name' => 'share_description', 'label' => 'Opis przy udostępnianiu', 'type' => 'textarea'],
            ['name' => 'share_image', 'label' => 'Obrazek przy udostępnianiu', 'type' => 'image'],
            ['type' => 'section', 'label' => 'E-mail'],
            ['name' => 'email_header_image', 'label' => 'Grafika nagłówka maila', 'type' => 'image'],
            ['name' => 'email_footer_image', 'label' => 'Grafika stopki maila', 'type' => 'image'],
        ],
        'permissions' => ['view', 'create', 'edit'],
    ],
    'database' => [
        'table' => 'site_settings',
    ],
    'permission' => [
        'code' => 'settings',
        'label' => 'Ustawienia strony',
    ],
];
