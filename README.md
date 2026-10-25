# LAW Transport

System zarządzania grupą transportową z flotą wojskową i cywilną, zbudowany na CMS2 (PHP + Doctrine + Twig + Symfony Routing).

- **Frontend** (`/`) – codzienna praca dyspozytorów, kierowców i osób z podglądem.
- **Panel administracyjny** (`/cms`) – tylko dla grupy *Administratorzy*: użytkownicy, grupy, uprawnienia, moduły, ustawienia. Pozostali użytkownicy są z `/cms` przekierowywani na `/`.

## Wymagania
- PHP 8.1+
- Composer
- MySQL/MariaDB

## Instalacja
1. Zainstaluj zależności:
   ```bash
   composer install
   ```
2. Skonfiguruj połączenie do bazy danych przez zmienne środowiskowe `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` albo w pliku `config/doctrine.local.php` (wzór: `config/doctrine.local.php.dist`, plik nie trafia do repozytorium).
3. Skonfiguruj ustawienia aplikacji i maili w `config/app.php`.
4. Skonfiguruj serwer WWW, aby katalog `public_html/` był katalogiem głównym (DocumentRoot).
5. Wejdź na `https://twojadomena.pl/cms/install`, załóż konto administratora i zainstaluj moduły.
6. Opcjonalnie wczytaj dane demonstracyjne (nie na produkcji): `php bin/seed-demo.php --wyczysc --z-kontami --haslo=TwojeHasloDemo`.

## Frontend LAW Transport

| Adres | Kto | Co |
|---|---|---|
| `/` | dyspozytor, podgląd | pulpit: stan floty, wyjazdy dziś i jutro, zadania, terminy |
| `/planer` | dyspozytor (edycja), podgląd (odczyt) | graficzny planer: przeciąganie wyjazdów między pojazdami/kierowcami i w czasie, zmiana długości, przydział kierowcy lub pojazdu przez upuszczenie na wyjazd, wykrywanie konfliktów |
| `/wyjazdy` | dyspozytor, podgląd | lista z filtrami, karta wyjazdu, zatwierdzanie, anulowanie |
| `/moje-wyjazdy` | kierowca | grafik na 2 tygodnie, rozpoczęcie i zakończenie jazdy (licznik, paliwo, tankowanie w trasie) |
| `/pojazdy`, `/kierowcy` | wg uprawnień modułów | ewidencja z terminami ważności |
| `/paliwo` | wg uprawnień modułu Tankowania | tankowania, miesięczna karta pracy pojazdu i rozliczenie paliwa |
| `/terminy` | dyspozytor, podgląd | przeglądy, OC, prawa jazdy, pozwolenia wojskowe, badania |

Role wynikają z uprawnień modułów: *edycja wyjazdów* = dyspozytor, *podgląd wyjazdów* = podgląd. Kierowca nie potrzebuje uprawnień – wystarczy, że e-mail jego konta jest wpisany w karcie kierowcy. Przy pierwszym logowaniu tworzone są grupy *Dyspozytorzy*, *Podgląd* i *Kierowcy* (później zmieniasz je w `/cms/groups`).

Reguły planowania (`src/Service/Fleet/TripRules.php`): pojazd i kierowca nie mogą mieć dwóch wyjazdów w tym samym czasie (blokada zapisu). Ostrzeżenia: brak wymaganej kategorii prawa jazdy, brak lub nieważne pozwolenie wojskowe, nieważne badania, przegląd albo OC, pojazd w obsłudze, kierowca na urlopie. Zmiana terminu, pojazdu lub kierowcy zatwierdzonego wyjazdu cofa go do statusu *Planowany*.

Dokumenty do druku (rozkaz wyjazdu, karta drogowa, karta pracy pojazdu z rozliczeniem paliwa) to **wzory przykładowe** w `templates/app/print/`. Należy je dostosować do wzorów obowiązujących w jednostce. Nagłówki (nazwa, nr JW, dowódca, szef służby) uzupełnia się w module *Dane jednostki*.

Formularze frontendu są budowane z definicji modułów (`config/module-installations/*.php`), więc nowe pole dodane w definicji i zainstalowane przez `/cms/install` pojawia się też we frontendzie.

## Struktura
- `config/` - konfiguracja aplikacji, modułów i routingu
- `public_html/` - publiczny front controller i assets
- `src/` - logika aplikacji (MVC)
- `templates/` - szablony Twig

## Moduły konfigurowalne
Przykładowe moduły znajdują się w `config/modules.php`. To tam definiujesz:
- pozycję w menu
- pola (nazwa w bazie, etykieta, typ pola)
- akcje na listach i prawa dostępu

Definicje modułów w `config/module-installations/*.php` obsługują dodatkowo ustawienia dashboardu:
- `dashboard.enabled` – domyślna widoczność kafelka modułu,
- `dashboard.order` – domyślna kolejność kafelka modułu,
- `dashboard.stats.enabled` – domyślna widoczność sekcji statystyk modułu,
- `dashboard.stats.order` – domyślna kolejność sekcji statystyk modułu.

## Grupy i uprawnienia
Wprowadzono encje `Group`, `Permission`, `GroupPermission` i `UserPermission`, aby nadawać uprawnienia
grupom oraz dodatkowe wyjątki bezpośrednio użytkownikom.
Dostępne akcje uprawnień obejmują teraz także `stats`, które pozwala wyświetlać i konfigurować
statystyki modułu na dashboardzie nawet bez pełnego prawa `view` dla listy, o ile moduł obsługuje statystyki.

## Automatyczne aktualizacje schematu
System posiada opcję instalacji schematu w panelu pod adresem `/cms/install`.
Mechanizm tworzy brakujące tabele i kolumny, ale nie usuwa istniejących pól.
Opcję można wyłączyć w `config/app.php` (`schema.allow_auto_update`).

## Logowanie
System jest przygotowany pod logowanie użytkowników i profil. Podczas pierwszego wejścia na `/cms/install` instalator poprosi o podanie adresu e-mail i hasła dla głównego użytkownika administratora, dzięki czemu aplikacja nie seeduje już przewidywalnego konta z twardym hasłem.

## SMSAPI.pl
Konfiguracja SMS znajduje się w module **Ustawienia systemu** w panelu administracyjnym.
Uzupełnij `SMSAPI token`, `SMSAPI token raportów` oraz opcjonalnie nadawcę i pozostałe parametry endpointu zgodnie z ustawieniami w panelu SMSAPI.
Endpoint raportów doręczeń `/smsapi/delivery-report` akceptuje zgłoszenia wyłącznie po podaniu poprawnego tokenu raportów w nagłówku `X-SMSAPI-Token` albo parametrze `token`.
Wysyłkę można wykonać w kontrolerach przez metodę `sendSms($to, $message)` w klasie bazowej.

## CRON - harmonogram zadań w CMS
W panelu CMS dostępna jest zakładka **CRON**. Po uruchomieniu instalacji/aktualizacji DB tworzy tabelę `cron_entries`, a pierwsze uruchomienie harmonogramu dodaje domyślne zadania: kolejkę e-maili, podsumowanie błędów i przenoszenie mediów na FTP.

Na serwerze wystarczy dodać jedną ogólną komendę, najlepiej co minutę:

```cron
* * * * /usr/bin/php /sciezka/do/projektu/bin/cron-run.php --base-url="https://twojadomena.pl" >> /var/log/cms2-cron.log 2>&1
```

W CMS można dla każdego zadania ustawić:

- włączone/wyłączone,
- interwał w minutach,
- dla zadania kolejki e-mail osobny limit wiadomości wysyłanych w jednym uruchomieniu,
- parametry zadania, np. `maxRuns=10` albo `windowMinutes=15`,
- liczbę rekordów pozostałych do przetworzenia w kolejce e-mail,
- podgląd ostatniego i następnego uruchomienia oraz ostatniego statusu.

Endpoint `GET /cron/run` jest jedynym publicznym endpointem harmonogramu CRON. Jest zabezpieczony: działa z localhosta albo po podaniu nagłówka `X-Automation-Secret` lub parametru `?secret=` zgodnego z ustawieniem `automation_secret` w module **Ustawienia systemu**.

Starsze endpointy pojedynczych zadań CRON zostały usunięte. Do crontaba dodawaj wyłącznie jeden wpis z `bin/cron-run.php`.
