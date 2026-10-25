<?php

$winnersDefinition = require __DIR__ . '/zwyciezcy.php';
$module = $winnersDefinition['module'];

$module['label'] = 'Weryfikacja - administrator';
$module['controller_module'] = 'zwyciezcy';
$module['menu'] = [
    'icon' => 'bi-person-check',
    'position' => 25.5,
];
$module['list']['actions'] = ['view'];
$module['list']['permissions'] = ['view', 'edit'];
$module['list']['fixed_filters'] = [
    [
        'field' => 'stan',
        'operator' => 'in_relation_flag',
        'module' => 'ustawienia_statusow',
        'flag' => 'do_weryfikacji_przez_administratora',
    ],
];
unset($module['create_action'], $module['create_fields'], $module['list']['extra_actions'], $module['list']['extra_row_actions']);
$module['view'] = [
    'display_mode' => 'text',
    'fields' => [
        'rodzaj',
        'zgloszenie',
        'rodzaj_wygranej',
        'email',
        'telefon',
        ['name' => 'zgloszenie_wypelnione_at', 'label' => 'Data zgłoszenia'],
        'numer_dowodu_zakupu',
        'rachunek',
    ],
    'editable_fields' => [
        [
            'name' => 'data_zakupu',
            'label' => 'Data zakupu',
            'type' => 'date',
            'required' => true,
        ],
        [
            'name' => 'komentarz_administratora',
            'label' => 'Komentarz administratora',
            'type' => 'textarea',
            'required' => false,
            'placeholder' => 'Dodaj komentarz administratora',
        ],
    ],
    'actions' => [
        [
            'key' => 'save_verification_comment',
            'label' => 'Zapisz komentarz',
            'handler' => 'saveVerificationEditableFields',
            'class' => 'btn-primary',
        ],
        [
            'key' => 'admin_verification_valid',
            'label' => 'Zgłoszenie poprawne',
            'handler' => 'markAdminVerificationValid',
            'class' => 'btn-success',
        ],
        [
            'key' => 'admin_verification_invalid',
            'label' => 'Zgłoszenie niepoprawne',
            'handler' => 'markAdminVerificationInvalid',
            'class' => 'btn-danger',
        ],
    ],
];

return [
    'key' => 'weryfikacja_administrator',
    'module' => $module,
    'database' => $winnersDefinition['database'],
    'permission' => [
        'code' => 'weryfikacja_administrator',
        'label' => 'Weryfikacja - administrator',
    ],
];
