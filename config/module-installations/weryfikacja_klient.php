<?php

$winnersDefinition = require __DIR__ . '/zwyciezcy.php';
$module = $winnersDefinition['module'];

$module['label'] = 'Weryfikacja - klient';
$module['controller_module'] = 'zwyciezcy';
$module['menu'] = [
    'icon' => 'bi-person-badge',
    'position' => 25.6,
];
$module['list']['actions'] = ['view'];
$module['list']['permissions'] = ['view', 'edit'];
$module['list']['fixed_filters'] = [
    [
        'field' => 'stan',
        'operator' => 'in_relation_flag',
        'module' => 'ustawienia_statusow',
        'flag' => 'do_weryfikacji_przez_klienta',
    ],
    [
        'field' => 'data_zakupu',
        'operator' => 'at_least_days_ago',
        'days' => 14,
    ],
];
$clientVerificationFields = [
    'rodzaj',
    'zgloszenie',
    'rodzaj_wygranej',
    'rachunek',
];
$module['list']['columns'] = $clientVerificationFields;
$module['list']['available_columns'] = $clientVerificationFields;
unset($module['create_action'], $module['create_fields'], $module['list']['extra_actions'], $module['list']['extra_row_actions']);
$module['view'] = [
    'display_mode' => 'text',
    'fields' => $clientVerificationFields,
    'editable_fields' => [
        [
            'name' => 'komentarz_uzytkownika',
            'label' => 'Komentarz klienta',
            'type' => 'textarea',
            'required' => false,
            'placeholder' => 'Dodaj komentarz klienta',
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
            'key' => 'client_verification_valid',
            'label' => 'Zgłoszenie poprawne',
            'handler' => 'markClientVerificationValid',
            'class' => 'btn-success',
        ],
        [
            'key' => 'client_verification_invalid',
            'label' => 'Zgłoszenie niepoprawne',
            'handler' => 'markClientVerificationInvalid',
            'class' => 'btn-danger',
        ],
    ],
];

return [
    'key' => 'weryfikacja_klient',
    'module' => $module,
    'database' => $winnersDefinition['database'],
    'permission' => [
        'code' => 'weryfikacja_klient',
        'label' => 'Weryfikacja - klient',
    ],
];
