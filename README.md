# CMS2 - Szkielet CMS (PHP + Doctrine + Twig + Symfony Routing)

## Wymagania
- PHP 8.1+
- Composer
- MySQL/MariaDB

## Instalacja
1. Zainstaluj zależności:
   ```bash
   composer install
   ```
2. Skonfiguruj połączenie do bazy danych w pliku `config/doctrine.php`.
3. Skonfiguruj ustawienia aplikacji i maili w `config/app.php`.
4. Skonfiguruj serwer WWW, aby katalog `public_html/` był katalogiem głównym (DocumentRoot).
5. Wejdź na `https://twojadomena.pl/cms` aby przejść do panelu.

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

## Formularz front-end
Formularz główny znajduje się w `templates/frontend/home.html.twig`.

Formularz loterii Żywiec znajduje się w `templates/frontend/zywiec.html.twig`. Po aktualizacji projektu uruchom **Instalację / aktualizację DB** pod `/cms/install`, aby utworzyć tabelę sieci sklepów i nowe kolumny zgłoszeń. W panelu **Zgłoszenia → Sieci sklepów** uzupełnij nazwy sieci i oznacz pozycje dostępne w formularzu jako aktywne. Sieci w panelu i formularzu są domyślnie sortowane alfabetycznie po nazwie. Pusta lista nie pozwala wysłać zgłoszenia.

Formularz wymaga nieujemnych liczb produktów promowanych i pozostałych (łącznie co najmniej 1 produkt), numeru dowodu zakupu, sieci i poprawnego NIP-u sklepu oraz pełnego zdjęcia/skanu dowodu w formacie JPG, PNG lub PDF do 15 MB. Można zgłosić wyłącznie produkty pozostałe, wpisując 0 produktów promowanych. Dane zakupu i oba potwierdzenia są zapisywane w zgłoszeniu. Numer telefonu jest sprawdzany według wyróżników polskich sieci ruchomych z [planu numeracji krajowej](https://eli.gov.pl/api/acts/DU/2024/1862/text.html). Dotychczasowe pole kodu z Żappki pozostaje w formularzu.

Na serwerze ustaw `upload_max_filesize` na co najmniej `15M` oraz `post_max_size` powyżej `15M` (np. `20M`), aby oprócz pliku zmieściły się pozostałe dane formularza. Czytelność, kompletność i zgodność numeru na dowodzie z numerem zgłoszenia wymagają weryfikacji dokumentu; walidacja uploadu sprawdza format, typ MIME i rozmiar pliku.

### Jednorazowe zgłoszenie dowodu zakupu

Dowód zakupu jest rozpoznawany po parze **numer dowodu + NIP sklepu** w historii zgłoszeń tej loterii. Ponowne użycie tej pary jest odrzucane komunikatem „Ten dowód zakupu został już zgłoszony”, niezależnie od uczestnika i dnia zgłoszenia. Ten sam numer z innym NIP-em oraz inny numer z tym samym NIP-em są dozwolone. Zewnętrzne spacje i wielkość liter numeru oraz spacje i myślniki NIP-u nie zmieniają wyniku sprawdzenia; zera wiodące numeru są zachowane.

Kontrola obowiązuje w formularzu, przy dodawaniu i edycji w CMS oraz w imporcie CSV. Duplikat nie zużywa kodu, produktów ani puli losów/e-zdrapek. Import pomija taki wpis i pokazuje powód w podsumowaniu. Edycja wyklucza własne zgłoszenie, ale nie może zmienić danych na dowód wykorzystany w innym zgłoszeniu. Numer dowodu i NIP są wymagane również przy zapisie w panelu. Ostateczne sprawdzenie i zapis odbywają się pod wspólną blokadą MySQL/MariaDB i w jednej transakcji, więc równoczesne wysłanie tego samego dowodu nie tworzy dwóch zgłoszeń.

### Odkrywanie e-zdrapek i indywidualny link

Przyjęcie zgłoszenia tworzy e-zdrapki bez ustalania ich wyników. Uczestnik widzi potwierdzenie, liczbę dostępnych zdrapek i przycisk **ODKRYJ E-ZDRAPKĘ**. Kliknięcie wysyła żądanie aktywacji jednej zdrapki; wtedy istniejący mechanizm wyboru nagród zapisuje wynik i czas aktywacji. Odsłonięcie pola pokazuje wygraną lub brak wygranej, a przy pozostałych zdrapkach udostępnia przycisk **ODKRYJ KOLEJNĄ E-ZDRAPKĘ**. Przydział i sprawdzanie wyników to oddzielne czynności.

Indywidualny link `/loteria/zdrapki/{token}` jest wspólny dla zgłoszeń tego samego adresu e-mail. Wyświetla wyłącznie zdrapki bez ustalonego wyniku ze wszystkich zgłoszeń uczestnika; samo wejście w link niczego nie aktywuje. W **Loteria ustawienia → Wiadomości e-mail** wybierz szablon e-maila po zgłoszeniu i umieść w nim `{link_zdrapki}` (np. w adresie przycisku). Dostępne są również przypomnienia oraz ponowne wysłanie linku z panelu zgłoszeń.

Aktywacja wymaga indywidualnego tokenu należącego do uczestnika danego zgłoszenia. Blokada konkretnej zdrapki chroni przed równoczesnymi kliknięciami; powtórzenie żądania zwraca zapisany wynik bez ponownego przyznawania nagrody. Przeglądarka zapamiętuje oczekującą aktywację w pamięci sesji, aby po utracie odpowiedzi lub odświeżeniu można było wrócić do tej samej zdrapki. Wcześniej ustalone wyniki pozostają zapisane. Zmiana korzysta z istniejących pól i nie wymaga aktualizacji bazy danych.

### Standardowe statusy losów i e-zdrapek

Losy korzystają ze standardowych statusów weryfikacji, a e-zdrapki ze statusów **Nie zdrapana / Zdrapana**. Obsługa wygranych, formularzy i wysyłek odbywa się jak wcześniej w **Zwycięzcach**, przy użyciu wspólnej listy **Ustawienia statusów**. Osobne listy statusów losów i e-zdrapek oraz pole „Status obsługi nagrody” zostały wycofane.

Po wdrożeniu uruchom `/cms/install`. Jeżeli wcześniejsza aktualizacja rozdzieliła statusy, migracja powrotna scala niezmienione kopie z oryginałami i przenosi przypisania zwycięzców, ustawienia nagród oraz historię wysyłek. Statusy utworzone lub zmienione ręcznie zostają zachowane we wspólnej liście. Migracja nie wysyła maili, nie zmienia wyników losowania ani przyznanych nagród; można ją uruchomić ponownie.

### Naliczanie według produktów i dzienny limit produktów

Po aktualizacji uruchom `/cms/install`. W **Loteria ustawienia → Podstawowe** można wybrać podstawę naliczania: liczbę produktów (domyślnie) albo kwotę zakupu. Przeliczniki produktów są konfigurowalne; wartości początkowe to 5 za produkt promowany i 1 za pozostały produkt. Każde zgłoszenie jest naliczane osobno według wzoru `promowane × przelicznik + pozostałe × przelicznik`. Przykładowo 2 produkty promowane i 2 pozostałe dają 12 e-zdrapek. W tym trybie kwota, bonus sprzedawcy i limit losów z jednego paragonu nie zmieniają przydziału. Wybór **Zdrapki i nagrody → Co system ma naliczać za zgłoszenie → Tylko zdrapki** powoduje generowanie wyłącznie e-zdrapek.

W zakładce **Limity zgłoszeń** pole **Maksymalna liczba produktów uczestnika uwzględnianych dziennie** ma wartość początkową 4. Klient może podać większe ilości, ale do naliczenia są wybierane najpierw produkty promowane, następnie pozostałe, aż do wykorzystania pozostałego limitu dnia. Przy przelicznikach 5/1 i wolnym limicie 4 deklaracja 3 promowanych + 5 pozostałych daje 16 e-zdrapek (3 × 5 + 1 × 1), a 6 promowanych + 10 pozostałych daje 20 (4 × 5). Pełna deklaracja jest zachowana, a liczby wykorzystane do naliczenia zapisują się osobno w polach `allocated_promoted_products_count` i `allocated_other_products_count`. Limit jest liczony z tych wykorzystanych liczb po e-mailu i telefonie; dla starszych zgłoszeń bez tych danych używa deklaracji. Po całkowitym wyczerpaniu limitu nowe zgłoszenie jest odrzucane bez zużycia kodu.

Pusta wartość stosuje limit 4; 0 wyłącza tę kontrolę. Usunięcie samych e-zdrapek lub losów nie zwalnia limitu produktów. Przeliczenie ilości w CMS wyklucza wcześniejsze wykorzystanie edytowanego zgłoszenia, ponownie wybiera produkty według priorytetu i zapisuje przydział w tej samej transakcji. Zasada obowiązuje również przy ręcznym dodawaniu i imporcie zgłoszeń.

### Dzienne limity losów i e-zdrapek

Po aktualizacji uruchom `/cms/install`. W **Loteria ustawienia → Limity zgłoszeń** są dwa niezależne pola: maksymalna liczba losów uczestnika dziennie i maksymalna liczba e-zdrapek uczestnika dziennie. Puste pole lub `0` wyłącza dany limit. Limity są sprawdzane równolegle dla adresu e-mail oraz numeru telefonu. Przydział musi mieścić się w pozostałym limicie obu danych — zmiana jednego z nich nie pozwala ominąć ograniczenia. Inni uczestnicy mają własne limity. Doba trwa od północy do północy w strefie `Europe/Warsaw`. Liczą się przyznane losy i e-zdrapki według daty zgłoszenia, niezależnie od wyniku lub zdrapania; usunięte wpisy zwalniają miejsce w limicie.

Jeżeli zostało mniej miejsca niż wynika ze zgłoszenia, system przyznaje pozostałą liczbę osobno dla losów i e-zdrapek oraz informuje uczestnika o ograniczeniu. Gdy żadna z naliczanych pul uczestnika nie ma wolnego miejsca, odrzuca zgłoszenie bez zużycia kodu. Przykładowo przy pozostałych 2 losach i 5 e-zdrapkach zgłoszenie naliczające po 4 otrzyma 2 losy i 4 e-zdrapki. Przeliczenie po edycji kwoty lub liczby produktów w CMS sprawdza limit dnia zgłoszenia, zastępuje dotychczasowy przydział i nie nalicza go ponownie do zużytej puli. Limity są sprawdzane również przy ręcznym dodawaniu i imporcie CSV losów oraz e-zdrapek w CMS; wymagane są wtedy dane uczestnika. Import pomija wpisy przekraczające limit i podaje ich liczbę w podsumowaniu.

Sprawdzenie puli i zapis odbywają się w jednej transakcji, pod wspólną blokadą bazy MySQL/MariaDB. Blokada jest zwalniana po zatwierdzeniu lub wycofaniu transakcji. Aktualizacja DB dodaje również indeksy daty zgłoszenia, aby sprawdzanie limitów nie wymagało przeglądania całej historii.

## Logowanie
System jest przygotowany pod logowanie użytkowników i profil. Podczas pierwszego wejścia na `/cms/install` instalator poprosi o podanie adresu e-mail i hasła dla głównego użytkownika administratora, dzięki czemu aplikacja nie seeduje już przewidywalnego konta z twardym hasłem.

## SMSAPI.pl
Konfiguracja SMS znajduje się w module **Ustawienia systemu** w panelu administracyjnym.
Uzupełnij `SMSAPI token`, `SMSAPI token raportów` oraz opcjonalnie nadawcę i pozostałe parametry endpointu zgodnie z ustawieniami w panelu SMSAPI.
Endpoint raportów doręczeń `/smsapi/delivery-report` akceptuje zgłoszenia wyłącznie po podaniu poprawnego tokenu raportów w nagłówku `X-SMSAPI-Token` albo parametrze `token`.
Wysyłkę można wykonać w kontrolerach przez metodę `sendSms($to, $message)` w klasie bazowej.

## CRON - harmonogram zadań w CMS
W panelu CMS dostępna jest zakładka **CRON**. Po uruchomieniu instalacji/aktualizacji DB tworzy tabelę `cron_entries`, a pierwsze uruchomienie harmonogramu dodaje domyślne zadania: kolejkę e-maili, przetwarzanie zwycięzców po imporcie CSV, przypomnienia o zdrapkach, AI-weryfikację paragonów zwycięzców oraz podsumowanie błędów.

Na serwerze wystarczy dodać jedną ogólną komendę, najlepiej co minutę:

```cron
* * * * /usr/bin/php /sciezka/do/projektu/bin/cron-run.php --base-url="https://twojadomena.pl" >> /var/log/cms2-cron.log 2>&1
```

W CMS można dla każdego zadania ustawić:

- włączone/wyłączone,
- interwał w minutach,
- dla zadania kolejki e-mail osobny limit wiadomości wysyłanych w jednym uruchomieniu,
- parametry zadania, np. `maxRuns=10` albo `windowMinutes=15`,
- liczbę rekordów pozostałych do przetworzenia dla kolejki zwycięzców i kolejki e-mail,
- podgląd ostatniego i następnego uruchomienia oraz ostatniego statusu.

Import CSV zapisuje zwycięzców atomowo, a przydzielanie kodów i przygotowanie wiadomości wykonuje zadanie CRON partiami (domyślnie 25 rekordów na minutę). Nieudana pozycja jest ponawiana maksymalnie 5 razy, a jej status i ostatni błąd są widoczne przy zwycięzcy. Po usunięciu przyczyny błędu można użyć akcji „Ponów przetwarzanie”.

Endpoint `GET /cron/run` jest jedynym publicznym endpointem harmonogramu CRON. Jest zabezpieczony: działa z localhosta albo po podaniu nagłówka `X-Automation-Secret` lub parametru `?secret=` zgodnego z ustawieniem `automation_secret` w module **Ustawienia systemu**.

Starsze endpointy pojedynczych zadań CRON zostały usunięte. Do crontaba dodawaj wyłącznie jeden wpis z `bin/cron-run.php`.

### Kody graficzne

Obrazy kodów należy wgrywać poza katalog publiczny, do poniższej struktury (katalogi są tworzone automatycznie przez pierwsze uruchomienie zadania):

```text
var/kody-graficzne/do-importu/<wartosc_kodu>/
```

Przykład dla kodów o wartości 10:

```text
var/kody-graficzne/do-importu/10/kod-00001.jpg
var/kody-graficzne/do-importu/10/kod-00002.png
var/kody-graficzne/do-importu/20/kod-00003.pdf
```

Zadanie CRON **Import kodów graficznych z katalogu** pobiera domyślnie do 250 plików na uruchomienie. Akceptuje pliki JPG, PNG, GIF i PDF starsze niż 60 sekund (ochrona przed odczytem pliku w trakcie wysyłania), zapisuje ich metadane w module **Kody graficzne** i przenosi je do `var/kody-graficzne/przechowywane/`. Pliki nie są zapisywane binarnie w bazie ani udostępniane publicznie. Nieprawidłowe pliki trafiają do `var/kody-graficzne/odrzucone/`, a duplikaty nazw do `var/kody-graficzne/duplikaty/`.

Po każdym uruchomieniu komunikat zadania pokazuje pełną ścieżkę skanowanego katalogu, liczbę katalogów wartości i liczbę widocznych plików. Jeżeli wdrożenie korzysta z niestandardowego układu katalogów, w polu **Parametry zadania** można jawnie ustawić katalog wejściowy, np. `limit=250&minAgeSeconds=60&inputDirectory=/home/uzytkownik/domena/public_html/var/kody-graficzne/do-importu`.

W module **Rodzaje nagród** ustaw pole **Format kodów do nagrody** na „Kody graficzne (załączniki e-mail)”. Konfiguracja ilości i wartości pozostaje w polu **Przyznane kody**, tak samo jak dla kodów tekstowych. Użycie w szablonie `{kod_graficzny}`, `{kod_graficzny_link}` albo pętli `{kody}...{/kody}` powoduje dołączenie przypisanych plików jako załączników. Znacznik `{kod_graficzny}` dodatkowo umieszcza w treści maila pliki JPG, PNG i GIF; plik PDF pozostaje wyłącznie załącznikiem. Znacznik `{kod_graficzny_link}` dodaje bezpieczny link do pliku na serwerze.
