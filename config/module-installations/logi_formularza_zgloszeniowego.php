<?php

return [
    'key' => 'logi_formularza_zgloszeniowego',
    'module' => [
        'label' => 'Logi formularza zgłoszeniowego',
        'type' => 'list',
        'menu' => [
            'icon' => 'bi-journal-text',
            'position' => 28,
        ],
        'fields' => [
            ['name' => 'created_at', 'label' => 'Data i godzina zdarzenia', 'type' => 'datetime'],
            ['name' => 'lottery_type', 'label' => 'Typ formularza/loterii', 'type' => 'text'],
            ['name' => 'request_method', 'label' => 'Metoda HTTP', 'type' => 'text'],
            ['name' => 'request_uri', 'label' => 'Adres formularza', 'type' => 'text'],
            ['name' => 'ip_address', 'label' => 'Adres IP', 'type' => 'text'],
            ['name' => 'forwarded_for', 'label' => 'X-Forwarded-For', 'type' => 'text'],
            ['name' => 'user_agent', 'label' => 'Przeglądarka / User-Agent', 'type' => 'textarea'],
            ['name' => 'referer', 'label' => 'Referer', 'type' => 'text'],
            ['name' => 'accept_language', 'label' => 'Język przeglądarki', 'type' => 'text'],
            ['name' => 'session_id', 'label' => 'ID sesji', 'type' => 'text'],
            ['name' => 'submitted_email', 'label' => 'Podany e-mail', 'type' => 'text'],
            ['name' => 'submitted_phone', 'label' => 'Podany telefon', 'type' => 'text'],
            ['name' => 'submitted_code', 'label' => 'Podany kod', 'type' => 'text'],
            ['name' => 'submitted_document_number', 'label' => 'Podany nr dowodu zakupu', 'type' => 'text'],
            ['name' => 'submitted_amount', 'label' => 'Podana kwota', 'type' => 'text'],
            ['name' => 'errors', 'label' => 'Błędy walidacji', 'type' => 'json_pretty', 'db' => ['type' => 'text']],
            ['name' => 'payload', 'label' => 'Dane formularza', 'type' => 'json_pretty', 'db' => ['type' => 'text']],
            ['name' => 'headers', 'label' => 'Nagłówki żądania', 'type' => 'json_pretty', 'db' => ['type' => 'text']],
        ],
        'list' => [
            'columns' => ['created_at', 'submitted_email', 'submitted_phone', 'submitted_code', 'ip_address', 'lottery_type'],
            'actions' => ['view', 'delete'],
            'permissions' => ['view', 'delete'],
            'sort_column' => 'created_at',
            'sort_direction' => 'DESC',
        ],
    ],
    'database' => [
        'table' => 'logi_formularza_zgloszeniowego_entries',
    ],
    'permission' => [
        'code' => 'logi_formularza_zgloszeniowego',
        'label' => 'Logi formularza zgłoszeniowego',
    ],
];
