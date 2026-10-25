<?php

return [
    'key' => 'email_queue',
    'module' => [
        'label' => 'Kolejka e-maili',
        'type' => 'custom',
        'menu' => [
            'icon' => 'bi-envelope-paper',
            'position' => 97,
            'url' => '/cms/email-queue',
        ],
        'permissions' => ['view'],
        'dashboard' => [
            'enabled' => false,
        ],
    ],
    'permission' => [
        'code' => 'email_queue',
        'label' => 'Kolejka e-maili',
    ],
];
