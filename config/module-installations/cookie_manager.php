<?php

return [
    'key' => 'cookie_manager',
    'module' => [
        'label' => 'Cookies i tagi',
        'type' => 'single',
        'menu' => [
            'icon' => 'bi-shield-check',
            'position' => 91,
        ],
        'fields' => [
            ['type' => 'tab', 'key' => 'baner-cookies', 'label' => 'Baner cookies'],
            ['type' => 'section', 'label' => 'Treści cookies', 'text' => 'Skonfiguruj treści banera i link do polityki cookies.'],
            ['name' => 'banner_enabled', 'label' => 'Włącz baner cookies', 'type' => 'checkbox'],
            [
                'name' => 'simple_banner',
                'label' => 'Prosty baner cookie',
                'type' => 'checkbox',
                'help' => 'Wyświetla tylko tytuł, opis i przycisk „OK”. Potwierdzenie nie uruchamia cookies biznesowych.',
            ],
            [
                'name' => 'test_mode',
                'label' => 'Tryb testowy (tylko zalogowany administrator, przy każdym odświeżeniu)',
                'type' => 'checkbox',
                'help' => 'Po zaznaczeniu baner nie jest widoczny dla zwykłych odwiedzających i nie zapisuje decyzji administratora.',
            ],
            ['name' => 'banner_title', 'label' => 'Tytuł banera', 'type' => 'text'],
            ['name' => 'banner_description', 'label' => 'Opis banera', 'type' => 'textarea'],
            ['name' => 'privacy_policy_url', 'label' => 'Link do polityki prywatności / cookies', 'type' => 'relation', 'module' => 'pliki', 'label_field' => 'nazwa', 'multiple' => false],

            ['type' => 'tab', 'key' => 'kategorie-cookies', 'label' => 'Kategorie cookies'],
            ['type' => 'section', 'label' => 'Kategorie cookies', 'text' => 'Skonfiguruj nazwy i opisy kategorii widocznych w pełnym wariancie banera.'],
            ['name' => 'necessary_label', 'label' => 'Etykieta kategorii niezbędnych', 'type' => 'text'],
            ['name' => 'necessary_description', 'label' => 'Opis kategorii niezbędnych', 'type' => 'textarea'],
            ['name' => 'business_label', 'label' => 'Etykieta kategorii biznesowych', 'type' => 'text'],
            ['name' => 'business_description', 'label' => 'Opis kategorii biznesowych', 'type' => 'textarea'],

            ['type' => 'tab', 'key' => 'tagi', 'label' => 'Tagi'],
            ['type' => 'section', 'label' => 'TikTok Pixel', 'text' => 'Wpisz same identyfikatory Pixeli. Kod TikToka zostanie zainicjalizowany jeden raz, a każdy Pixel zostanie uruchomiony dopiero po wyrażeniu zgody na cookies biznesowe.'],
            [
                'name' => 'tiktok_pixel_id',
                'label' => 'TikTok Pixel ID 1',
                'type' => 'text',
                'help' => 'Wklej wyłącznie ID pierwszego Pixela, np. D9CBHJ3C77U5KEVKOEP0 — bez tagów <script> i pozostałego kodu.',
            ],
            [
                'name' => 'tiktok_pixel_id_secondary',
                'label' => 'TikTok Pixel ID 2',
                'type' => 'text',
                'help' => 'Wklej wyłącznie ID drugiego Pixela. Pozostaw puste, jeśli drugi Pixel nie jest używany.',
            ],
            [
                'name' => 'tiktok_pixel_head',
                'label' => 'Dotychczasowy pełny kod TikTok Pixel (zgodność wsteczna)',
                'type' => 'textarea',
                'rows' => 10,
                'help' => 'To pole zachowuje istniejącą konfigurację. Jest używane tylko wtedy, gdy oba pola ID powyżej są puste. Przy nowej konfiguracji nie wklejaj tutaj drugiego kodu.',
            ],
        ],
        'permissions' => ['view', 'create', 'edit'],
    ],
    'database' => [
        'table' => 'cookie_manager',
    ],
    'permission' => [
        'code' => 'cookie_manager',
        'label' => 'Cookies i tagi',
    ],
];
