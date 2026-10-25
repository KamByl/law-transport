<?php

return [
    'app' => [
        'name' => 'CMS2',
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
                "frame-ancestors 'self' https://www.google.com",
                "form-action 'self'",
                "object-src 'none'",
                "img-src 'self' data: blob: https:",
                "font-src 'self' data: https://cdn.jsdelivr.net https://use.typekit.net https://fonts.gstatic.com",
                "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://use.typekit.net https://p.typekit.net https://fonts.googleapis.com",
                "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdn.ckeditor.com https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/ https://cdn.cookielaw.org https://www.googletagmanager.com https://connect.facebook.net https://analytics.tiktok.com",
                "connect-src 'self' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/ https://www.recaptcha.net/recaptcha/ https://cdn.jsdelivr.net https://cdn.cookielaw.org https://*.onetrust.com https://*.google.com https://analytics.tiktok.com https://*.tiktok.com https://analytics-ipv6.tiktokw.us",
                "frame-src 'self' https://www.google.com/recaptcha/ https://recaptcha.google.com/recaptcha/ https://www.recaptcha.net/recaptcha/ https://www.googletagmanager.com/",
                "manifest-src 'self'",
                "upgrade-insecure-requests",
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
        'cache_dir' => __DIR__ . '/../var/cache/twig',
    ],
    'menu' => [
        'module_groups' => [
            'content' => [
                'label' => 'Treści',
                'modules' => [
                    'teksty_na_stronie',
                    'faq',
                    'pliki',
                ],
            ],
            'veryfication' => [
                'label' => 'Weryfikacja',
                'modules' => [
                    'weryfikacja_administrator',
                    'weryfikacja_klient',
                    'wysylka_nagrod',
                ],
            ],
            'submissions' => [
                'label' => 'Zgłoszenia',
                'modules' => [
                    'losowania',
                    'loteria_zgloszenia',
                    'sieci_sklepow',
                    'logi_formularza_zgloszeniowego',
                    'loteria_losy',
                    'loteria_zdrapki',
                ],
            ],
            'templates_group' => [
                'label' => 'Szablony',
                'modules' => [
                    'sms_templates',
                    'email_templates',
                    'email_logs',
                    'email_queue',
                ],
            ],
            'zwyciezcy_group' => [
                'label' => 'Zwycięzcy',
                'modules' => [
                    'zwyciezcy',
                ],
            ],
            'nagrody_group' => [
                'label' => 'Nagrody',
                'modules' => [
                    'rodzaje_nagrod',
                    'godziny_wygranych',
                    'lista_wygranych_kodow',
                    'kody_graficzne',
                ],
            ],
            'settings_group' => [
                'label' => 'Ustawienia',
                'modules' => [
                    'settings',
                    'system_settings',
                    'cron',
                    'loteria_ustawienia',
                    'ustawienia_statusow',
                    'zabronione_domeny',
                    'podejrzane_adresy_email',
                ],
            ],
        ],
        'module_group_default_label' => 'Pozostałe moduły',
        'hidden_modules' => [],
    ],
];
