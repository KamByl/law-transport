# Opis systemu CMS2 (wersja dla klienta)

## 1. Wymagania minimalne

### Środowisko serwerowe
- **PHP 8.1 lub nowszy**.
- **Composer** do instalacji i aktualizacji zależności.
- **MySQL lub MariaDB** jako baza danych.
- Serwer WWW (np. Apache/Nginx) skonfigurowany tak, aby katalog **`public_html/`** był katalogiem publicznym (DocumentRoot).

### Wymagania operacyjne
- Dostęp do harmonogramu zadań (**cron**) dla zadań cyklicznych (kolejka e-mail, podsumowania błędów, automatyzacje loterii).
- Dostęp do SMTP / dostawcy e-mail (przez Symfony Mailer).
- (Opcjonalnie) integracja z **SMSAPI** do wysyłki i raportów doręczeń SMS.

---

## 2. Z czego korzystamy (technologie i architektura)

### Główne komponenty
- **PHP + Composer** (zarządzanie projektem i zależnościami).
- **Doctrine ORM** (mapowanie encji i komunikacja z bazą danych).
- **Twig** (warstwa widoków / szablonów).
- **Symfony Routing + HttpFoundation + HttpKernel** (routing i obsługa HTTP).
- **Symfony Mailer/Mime** (wysyłka e-mail).
- **Symfony HttpClient** (komunikacja HTTP z usługami zewnętrznymi).
- **Symfony Cache** (mechanizmy cache).
- Dodatkowo: **dompdf** (PDF) oraz **PhpSpreadsheet** (arkusze/eksporty).

### Architektura aplikacji
- Aplikacja ma strukturę warstwową opartą o katalogi:
  - `src/` – logika backendowa (kontrolery, serwisy, encje),
  - `templates/` – widoki Twig,
  - `config/` – konfiguracja aplikacji i modułów,
  - `public_html/` – publiczny front controller i zasoby statyczne.
- System ma modułowy panel administracyjny z konfiguracją modułów i uprawnień.

---

## 3. Jakie zabezpieczenia są w systemie

### Bezpieczeństwo HTTP i przeglądarki
- Konfigurowane nagłówki bezpieczeństwa, m.in.:
  - `X-Frame-Options: DENY`,
  - `X-Content-Type-Options: nosniff`,
  - `Referrer-Policy: strict-origin-when-cross-origin`,
  - `Permissions-Policy` (blokada dostępu do wybranych API przeglądarki),
  - `Cross-Origin-Opener-Policy` i `Cross-Origin-Resource-Policy`,
  - `Strict-Transport-Security` (HSTS),
  - **Content-Security-Policy (CSP)** ograniczający źródła skryptów, stylów, ramek i połączeń.

### Sesje i logowanie
- Włączone bezpieczne ustawienia sesji:
  - `session.use_strict_mode`,
  - `session.use_only_cookies`,
  - `HttpOnly` dla cookie,
  - `Secure` cookie przy HTTPS,
  - `SameSite` dla cookie.
- Regeneracja ID sesji po logowaniu/wylogowaniu (ochrona przed session fixation).
- Weryfikacja haseł przez `password_verify`.
- Rejestrowanie prób logowania (udanych i nieudanych) z metadanymi.
- Ograniczanie prób logowania (rate limiting) na e-mail/IP w oknie czasowym.

### Ochrona przed CSRF
- Wewnętrzny serwis CSRF generujący tokeny (`random_bytes`) i walidujący je przez `hash_equals`.
- Obsługa tokenu zarówno w polu formularza, jak i nagłówku `X-CSRF-Token`.

### Kontrola dostępu i audyt
- Uprawnienia per grupa i per użytkownik (RBAC + wyjątki użytkownika).
- Filtracja widoczności modułów i akcji w panelu wg uprawnień.
- Logi operacyjne obejmujące m.in.: logowania, błędy, e-maile, SMS-y, zmiany modułów.

### Zabezpieczenia automatyzacji (cron/integracje)
- Endpoint raportów SMS akceptuje zgłoszenia tylko z poprawnym tokenem (`X-SMSAPI-Token` lub parametr `token`).
- Endpoint cron do AI-weryfikacji paragonów jest ograniczony do localhost lub wymaga sekretu automatyzacji (`X-Automation-Secret` / `?secret=`).
- Preferowany tryb uruchomień zadań: **bezpośrednio CLI**, bez ekspozycji endpointów HTTP.

---

## 4. Inne ważne informacje techniczne

- System ma mechanizm **auto-aktualizacji schematu** przez panel instalacyjny (`/cms/install`) – tworzy brakujące tabele i kolumny, ale nie usuwa istniejących pól.
- Pierwsza konfiguracja administratora odbywa się w instalatorze (brak twardo zaszytego domyślnego hasła).
- Wspierane są moduły konfigurowalne (układ pól, menu, akcje, dashboard/statystyki), co ułatwia rozwój bez głębokiej ingerencji w kod.
- W projekcie dostępne są narzędzia eksportu i generowania dokumentów (Excel/PDF).
- Wdrożeniowo warto dopilnować:
  - środowiska `prod`,
  - wymuszenia HTTPS,
  - kopii zapasowych bazy i plików,
  - regularnego uruchamiania cronów,
  - monitoringu logów błędów i kolejek wysyłki.
