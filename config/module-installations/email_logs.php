<?php

return [
    'key' => 'email_logs',
    'module' => [
        'label' => 'Logi e-mail',
        'type' => 'custom',
        'menu' => [
            'icon' => 'bi-envelope',
            'position' => 96,
            'url' => '/cms/email-logs',
        ],
        'permissions' => ['view'],
        'dashboard' => [
            'enabled' => false,
        ],
    ],
    'permission' => [
        'code' => 'email_logs',
        'label' => 'Logi e-mail',
    ],
];
