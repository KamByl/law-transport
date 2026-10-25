<?php

return [
    'key' => 'email_templates',
    'module' => [
        'label' => 'Szablony E-mail',
        'type' => 'list',
        'menu' => [
            'icon' => 'bi-envelope',
            'position' => 45,
        ],
        'fields' => [
            ['name' => 'name', 'label' => 'Nazwa szablonu', 'type' => 'text'],
            ['name' => 'subject', 'label' => 'Temat maila', 'type' => 'text'],
            ['name' => 'body', 'label' => 'Treść maila', 'type' => 'wysiwyg'],
            ['name' => 'bcc', 'label' => 'UDW (Ukryte Do Wiadomości)', 'type' => 'text'],
            ['name' => 'priority_email', 'label' => 'E-mail priorytetowy', 'type' => 'checkbox'],
            [
                'type' => 'section',
                'label' => 'Dostępne elementy',
            ],
            [
                'name' => 'placeholders_info',
                'label' => 'Skróty w treści',
                'type' => 'info',
                'text' => "Ogólne\n"
                    . "{data} - data zgłoszenia (DD-MM-RRRR)\n"
                    . "{kod} - unikatowy kod zgłoszenia\n"
                    . "{kod_slownie} - kod zapisany słownie (np. Tango, Jeden, X-ray)\n"
                    . "{data_czas} - data i czas zgłoszenia (RRRR-MM-DD GG:MM:SS)\n"
                    . "{imie_nazwisko} - imię i nazwisko (nazwisko jako pierwsza litera z kropką)\n"
                    . "{pesel} - PESEL w formacie 06...60\n"
                    . "{telefon} - telefon w formacie 12...89\n"
                    . "{link} - link potwierdzający (np. newsletter)\n"
                    . "{email} - adres e-mail odbiorcy\n"
                    . "{email_zwyciezcy} - adres e-mail zwycięzcy (dla maila do administratora po zmianie statusu)\n"
                    . "{id_zwyciezcy} - ID zwycięzcy (dla maila do administratora po zmianie statusu)\n"
                    . "{status} - nazwa ustawionego statusu zwycięzcy (dla maila do administratora po zmianie statusu)\n"
                    . "{link_sprzedawcy} - link do strony sprzedawcy dla właściwego centrum handlowego\n\n"
                    . "{dowod_zakupu} - numer dowodu zakupu ze zgłoszenia (dla maila o negatywnej weryfikacji rachunku oraz maila o odkryciu wygranej zdrapki)\n"
                    . "{wartosc-nagrody} - wartość nagrody z pola Wartość nagrody w module Rodzaje nagród (dla maila z informacją o wygranej i formularzu)\n\n"
                    . "Wysłanie zgłoszenia\n"
                    . "{link_zdrapki} - link do zdrapek\n"
                    . "{liczba-losow-za-zgloszenie} - ilość losów przyznana ze zgłoszenia\n"
                    . "{liczba-zdrapek-za-zgloszenie} - liczba e-zdrapek faktycznie przyznana ze zgłoszenia\n"
                    . "{laczna-liczba-wszytskich-losow} - ilość losów zarejestrowana na dany adres email\n\n"
                    . "Zwycięzcy (kod odbioru nagrody)\n"
                    . "{data_losowania} - data losowania przypisanego zwycięzcy (DD.MM.RRRR; tylko wiadomości do zwycięzców)\n"
                    . "{link_zwyciezca} - indywidualny link do formularza zwycięzcy\n"
                    . "{termin_formularza} - data ważności formularza zwycięzcy (DD.MM.RRRR; formularz jest aktywny do końca tego dnia)\n"
                    . "{pickup_code} - kod odbioru nagrody przyznany zwycięzcy; w treści pętli {kody}...{/kody} dla kodów z zaznaczonym polem Kod ean zostanie wstawiony obrazek EAN z linkiem do /kod-ean/{kod}.jpg, a dla kodów z zaznaczonym polem Code-128 obrazek Code-128 z linkiem do /kod-code-128/{kod}.jpg\n"
                    . "{kod_graficzny} - obraz kodu graficznego osadzony w treści wiadomości; można go użyć pojedynczo albo w pętli {kody}...{/kody}; plik pozostaje również załącznikiem wiadomości\n"
                    . "{kod_graficzny_link} - bezpieczny, klikalny link do kodu graficznego na serwerze; można go użyć pojedynczo albo w pętli {kody}...{/kody}\n"
                    . "{kod_ean} - obrazek/link EAN dla kodu z zaznaczonym polem Kod ean (alias dostępny w pętli {kody}...{/kody})\n"
                    . "{code_128} - obrazek/link Code-128 dla kodu z zaznaczonym polem Code-128 (alias dostępny w pętli {kody}...{/kody})\n"
                    . "{kwota} - kwota przypisana do kodu (500 lub 1000), dostępna w pętli {kody}...{/kody}",
            ],
        ],
        'list' => [
            'columns' => ['name', 'subject', 'bcc', 'priority_email'],
            'actions' => ['view', 'edit', 'delete'],
            'permissions' => ['view', 'create', 'edit', 'delete'],
            'manual_sort' => false,
            'sort_column' => 'id',
            'stats_enabled' => false,
            'extra_row_actions' => [
                [
                    'key' => 'send_test_email',
                    'label' => 'Wyślij testowy',
                    'handler' => 'sendTestEmailTemplate',
                ],
            ],
        ],
    ],
    'database' => [
        'table' => 'email_templates',
    ],
    'permission' => [
        'code' => 'email_templates',
        'label' => 'Szablony E-mail',
    ],
];
