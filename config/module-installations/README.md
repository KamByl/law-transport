# Pliki instalacyjne modułów

Umieść w tym katalogu pliki `*.php`, które zwracają tablicę opisującą moduł. Przykład:

```php
<?php

return [
    'key' => 'news',
    'module' => [
        'label' => 'Aktualności',
        'type' => 'list',
        'menu' => [
            'icon' => 'bi-envelope',
            'position' => 30,
        ],
        'fields' => [
            ['name' => 'email', 'label' => 'Email', 'type' => 'text'],
            ['name' => 'created_at', 'label' => 'Data zapisu', 'type' => 'date'],
        ],
        'list' => [
            'columns' => ['email', 'created_at'],
            'actions' => ['view', 'delete'],
            'permissions' => ['view', 'delete'],
            'manual_sort' => false,
            'sort_column' => 'position',
        ],
    ],
    'database' => [
        'table' => 'news_entries',
    ],
    'permission' => [
        'code' => 'news',
        'label' => 'Aktualności',
    ],
];

Możesz też pogrupować moduły w lewym menu panelu. W `config/app.php` ustawiasz
sekcję `menu.module_groups`, podając kolejność grup oraz listę kluczy modułów
wyświetlanych w danej grupie. Moduły niewymienione trafią do grupy domyślnej
`menu.module_group_default_label`.

Przykład:

```php
'menu' => [
    'module_groups' => [
        'content' => [
            'label' => 'Treści',
            'modules' => ['news'],
        ],
        'marketing' => [
            'label' => 'Marketing',
            'modules' => ['sms_templates'],
        ],
    ],
    'module_group_default_label' => 'Pozostałe moduły',
],
```
```
