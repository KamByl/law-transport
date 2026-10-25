<?php

return [
    'key' => 'losowania',
    'module' => [
        'label' => 'Losowania',
        'type' => 'list',
        'menu' => [
            'icon' => 'bi-shuffle',
            'position' => 28,
        ],
        'fields' => [
            ['name' => 'name', 'label' => 'Nazwa', 'type' => 'text'],
            ['name' => 'description', 'label' => 'Opis', 'type' => 'textarea'],
            ['name' => 'start_date', 'label' => 'Data startu', 'type' => 'date'],
            ['name' => 'end_date', 'label' => 'Data zakończenia', 'type' => 'date'],
            ['name' => 'drawing_date', 'label' => 'Data losowania', 'type' => 'date'],
            [
                'name' => 'eligible_submissions_count',
                'label' => 'Ilość zgłoszeń uprawniających do losowania',
                'type' => 'text',
                'numeric_only' => true,
            ],
            ['name' => 'visible_on_page', 'label' => 'Widoczne na stronie', 'type' => 'boolean_select'],
            [
                'type' => 'tab',
                'key' => 'protokol',
                'label' => 'Protokół',
            ],
            [
                'name' => 'protocol_director_decision',
                'label' => 'Decyzji Dyrektora Izby Administracji Skarbowej',
                'type' => 'text',
                'placeholder' => 'Decyzji Dyrektora Izby Administracji Skarbowej w Gdańsku nr ...',
                'help' => 'Wpisz pełną treść decyzji, która ma zostać umieszczona w tytule protokołu.',
            ],
            [
                'name' => 'protocol_daily_drawings',
                'label' => 'Losowania dotyczyły dziennych',
                'type' => 'structured_list',
                'db' => ['type' => 'text'],
                'fields' => [
                    [
                        'name' => 'text',
                        'label' => 'Treść',
                        'input_type' => 'text',
                    ],
                ],
            ],
        ],
        'list' => [
            'columns' => ['id', 'name', 'start_date', 'end_date', 'drawing_date', 'eligible_submissions_count', 'visible_on_page'],
            'extra_row_actions' => [
                [
                    'key' => 'open_lottery_submissions',
                    'label' => 'Zgłoszenia',
                    'handler' => 'openLotterySubmissions',
                ],
                [
                    'key' => 'open_lottery_tickets',
                    'label' => 'Losy',
                    'handler' => 'openLotteryTickets',
                ],
                [
                    'key' => 'open_lottery_scratchcards',
                    'label' => 'Zdrapki',
                    'handler' => 'openLotteryScratchcards',
                ],
                [
                    'key' => 'download_lottery_tickets',
                    'label' => 'Pobierz losy',
                    'handler' => 'downloadLotteryTicketsCsv',
                ],
                [
                    'key' => 'generate_lottery_protocol',
                    'label' => 'Generuj protokół',
                    'handler' => 'generateLotteryProtocolDocx',
                ],
            ],
            'actions' => ['view', 'edit', 'delete'],
            'permissions' => ['view', 'create', 'edit', 'delete'],
        ],
    ],
    'database' => [
        'table' => 'losowania_entries',
    ],
    'permission' => [
        'code' => 'losowania',
        'label' => 'Losowania',
    ],
];
