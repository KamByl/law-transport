<?php

$winnersDefinition = require __DIR__ . '/zwyciezcy.php';
$module = $winnersDefinition['module'];

$module['label'] = 'Wysyłka nagród';
$module['controller_module'] = 'zwyciezcy';
$module['menu'] = [
    'icon' => 'bi-truck',
    'position' => 25.7,
];
$module['list']['actions'] = ['view'];
$module['list']['permissions'] = ['view', 'edit'];
$module['list']['fixed_filters'] = [
    [
        'field' => 'stan',
        'operator' => 'in_relation_flag',
        'module' => 'ustawienia_statusow',
        'flag' => 'do_wysylki',
    ],
];
$prizeShippingListFields = [
    'rodzaj',
    'zgloszenie',
    'rodzaj_wygranej',
];
$prizeShippingViewFields = [
    ...$prizeShippingListFields,
    'imie',
    'nazwisko',
    'ulica',
    'numer_domu',
    'numer_mieszkania',
    'kod_pocztowy',
    'miejscowosc',
    'email',
    'telefon',
    'numer_rachunku_bankowego',
];
$module['list']['available_columns'] = $module['list']['columns'];
$module['list']['columns'] = $prizeShippingListFields;
// $module['list']['available_columns'] = $prizeShippingListFields;
unset($module['create_action'], $module['create_fields'], $module['list']['extra_actions'], $module['list']['extra_row_actions']);
$module['view'] = [
    'display_mode' => 'text',
    'fields' => $prizeShippingViewFields,
    'editable_fields' => [
        [
            'name' => 'przewoznik',
            'label' => 'Przewoźnik',
            'type' => 'select',
            'options' => [
                'Inpost' => 'Inpost',
                'DHL' => 'DHL',
                'DPD' => 'DPD',
                'UPS' => 'UPS',
            ],
            'required' => true,
            'placeholder' => 'Wybierz przewoźnika',
        ],
        [
            'name' => 'data_wysylki',
            'label' => 'Data wysyłki',
            'type' => 'date',
        ],
        [
            'name' => 'numer_listu_przewozowego',
            'label' => 'Numer listu przewozowego',
            'type' => 'text',
            'required' => true,
            'placeholder' => 'Wpisz numer listu przewozowego',
        ],
    ],
    'actions' => [
        [
            'key' => 'save_shipping_data',
            'label' => 'Zapisz dane wysyłki',
            'handler' => 'savePrizeShippingData',
            'class' => 'btn-success',
        ],
    ],
];

return [
    'key' => 'wysylka_nagrod',
    'module' => $module,
    'database' => $winnersDefinition['database'],
    'permission' => [
        'code' => 'wysylka_nagrod',
        'label' => 'Wysyłka nagród',
    ],
];
