<?php

return [
    'key' => 'cron',
    'module' => [
        'label' => 'CRON',
        'type' => 'list',
        'menu' => [
            'icon' => 'bi-clock-history',
            'position' => 92,
        ],
        'fields' => [
            ['name' => 'name', 'label' => 'Nazwa zadania', 'type' => 'text'],
            [
                'name' => 'command_key',
                'label' => 'Komenda systemowa',
                'type' => 'select',
                'options' => [
                    'email_queue' => 'Kolejka e-maili',
                    'error_summary' => 'Podsumowanie błędów',
                    'ftp_media_offload' => 'Przenoszenie mediów na FTP',
                ],
            ],
            ['name' => 'enabled', 'label' => 'Włączone', 'type' => 'checkbox'],
            ['name' => 'interval_minutes', 'label' => 'Uruchamiaj co (minuty)', 'type' => 'number'],
            [
                'name' => 'email_queue_limit',
                'label' => 'Limit wiadomości e-mail',
                'type' => 'number',
                'help' => 'Maksymalna liczba wiadomości wysyłanych podczas jednego uruchomienia kolejki e-mail.',
                'visible_if' => ['field' => 'command_key', 'value' => 'email_queue'],
            ],
            [
                'name' => 'email_queue_delay_seconds',
                'label' => 'Opóźnienie wysyłki (sekundy)',
                'type' => 'number',
                'min' => 0,
                'help' => 'Wiadomość zostanie wysłana dopiero po upływie podanej liczby sekund od dodania jej do kolejki.',
                'visible_if' => ['field' => 'command_key', 'value' => 'email_queue'],
            ],
            [
                'name' => 'parameters',
                'label' => 'Parametry zadania',
                'type' => 'textarea',
                'help' => 'Opcjonalne parametry zadania w formacie query string, np. limit=250&minAgeSeconds=60. Import kodów graficznych obsługuje też inputDirectory=/pełna/ścieżka/do/do-importu.',
            ],
            [
                'name' => 'system_cron_command',
                'label' => 'Wpis do systemowego crona',
                'type' => 'info',
                'text' => 'Dodaj poniższą linijkę do systemowego crontaba. Ten jeden wpis uruchamia harmonogram zadań skonfigurowany w CMS.',
                'display_text_provider' => 'system_cron_command',
                'display_text_label' => 'Dokładna linijka crontab',
                'display_text_help' => 'Pole jest tylko do odczytu. Skopiuj całą linijkę do crontaba użytkownika na serwerze.',
                'display_text_rows' => 2,
                'display_text_monospace' => true,
                'display_text_select_on_click' => true,
            ],
            ['name' => 'last_run_at', 'label' => 'Ostatnie uruchomienie', 'type' => 'datetime'],
            ['name' => 'next_run_at', 'label' => 'Następne uruchomienie', 'type' => 'datetime'],
            ['name' => 'last_status', 'label' => 'Ostatni status', 'type' => 'text'],
            ['name' => 'remaining_items', 'label' => 'Pozostało rekordów', 'type' => 'number', 'readonly' => true],
            ['name' => 'last_message', 'label' => 'Ostatni komunikat', 'type' => 'textarea'],
            ['name' => 'running', 'label' => 'W trakcie', 'type' => 'checkbox'],
            ['name' => 'locked_at', 'label' => 'Blokada od', 'type' => 'datetime'],
        ],
        'list' => [
            'columns' => ['name', 'command_key', 'enabled', 'interval_minutes', 'email_queue_limit', 'email_queue_delay_seconds', 'last_run_at', 'next_run_at', 'last_status', 'remaining_items'],
            'actions' => ['view', 'edit', 'delete', 'copy'],
            'permissions' => ['view', 'create', 'edit', 'delete'],
            'stats_enabled' => false,
        ],
    ],
    'database' => [
        'table' => 'cron_entries',
    ],
    'permission' => [
        'code' => 'cron',
        'label' => 'CRON',
    ],
];
