<?php

return [
    'app' => [
        'name' => 'LAW Transport',
        'base_path' => '/cms',
        'env' => 'dev',
        'timezone' => 'Europe/Warsaw',
    ],
    'security' => [
        'session_name' => 'cms_session',
        'headers' => [
            'x_frame_options' => 'DENY',
            'x_content_type_options' => 'nosniff',
            'referrer_policy' => 'strict-origin-when-cross-origin',
            'permissions_policy' => 'camera=(), geolocation=(), microphone=(), payment=(), usb=()',
            'cross_origin_opener_policy' => 'same-origin',
            'cross_origin_resource_policy' => 'same-origin',
            'strict_transport_security' => 'max-age=31536000; includeSubDomains',
            'content_security_policy' => implode('; ', [
                "default-src 'self'",
                "base-uri 'self'",
                "frame-ancestors 'self'",
                "form-action 'self'",
                "object-src 'none'",
                "img-src 'self' data: blob: https:",
                "font-src 'self' data:",
                "style-src 'self' 'unsafe-inline' https://cdn.ckeditor.com",
                "script-src 'self' 'unsafe-inline' https://cdn.ckeditor.com",
                "connect-src 'self'",
                "frame-src 'self'",
                "manifest-src 'self'",
            ]),
        ],
    ],
    'schema' => [
        'allow_auto_update' => true,
    ],
    'mail' => [
        'from' => 'no-reply@example.com',
        'admin' => 'goffyy@gmail.com',
    ],
    'twig' => [
        'cache_enabled' => true,
        'auto_reload' => true,
        'cache_dir' => __DIR__ . '/../var/cache/twig',
    ],
    'menu' => [
        'module_groups' => [
            'fleet_group' => [
                'label' => 'Flota',
                'modules' => [
                    'pojazdy',
                    'kierowcy',
                    'wyjazdy',
                    'tankowania',
                    'jednostka',
                ],
            ],
            'content' => [
                'label' => 'Pliki',
                'modules' => [
                    'pliki',
                ],
            ],
            'templates_group' => [
                'label' => 'Powiadomienia',
                'modules' => [
                    'sms_templates',
                    'email_templates',
                    'email_logs',
                    'email_queue',
                ],
            ],
            'settings_group' => [
                'label' => 'Ustawienia',
                'modules' => [
                    'settings',
                    'system_settings',
                    'cron',
                ],
            ],
        ],
        'module_group_default_label' => 'Pozostałe moduły',
        'hidden_modules' => [],
    ],
];
