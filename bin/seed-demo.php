#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Dane demonstracyjne LAW Transport: pojazdy, kierowcy, wyjazdy na bieżące dwa tygodnie, tankowania.
 * Z opcją --z-kontami tworzy też konta demo (dyspozytor i kierowca) z hasłem podanym w --haslo.
 * Nie uruchamiać na produkcji z prawdziwymi danymi.
 */

$options = getopt('', ['z-kontami', 'haslo::', 'help', 'wyczysc']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Użycie: php bin/seed-demo.php [--wyczysc] [--z-kontami --haslo=TAJNE]\n");
    fwrite(STDOUT, "  --wyczysc    usuwa istniejące pojazdy, kierowców, wyjazdy i tankowania przed wstawieniem danych\n");
    fwrite(STDOUT, "  --z-kontami  tworzy konta dyspozytor@demo.local i kierowca@demo.local (wymaga --haslo)\n");
    exit(0);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Entity\Group;
use App\Entity\User;
use App\Service\Fleet\FleetRepository;
use App\Service\Fleet\TripRules;
use App\Service\InstallationSeeder;
use App\Service\ModuleDefinitionLoader;
use App\Service\ModuleInstaller;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;

$config = require dirname(__DIR__) . '/config/doctrine.php';
$em = EntityManager::create($config['db'], ORMSetup::createAttributeMetadataConfiguration($config['paths'], $config['dev_mode'], $config['proxy_dir']));
$connection = $em->getConnection();

(new ModuleInstaller($em, new ModuleDefinitionLoader()))->install();
(new InstallationSeeder($em, new ModuleDefinitionLoader()))->seed();
$repo = new FleetRepository($connection);

if (isset($options['wyczysc'])) {
    foreach ([FleetRepository::FUEL, FleetRepository::TRIPS, FleetRepository::DRIVERS, FleetRepository::VEHICLES] as $table) {
        $connection->executeStatement('DELETE FROM ' . $table);
    }
}

if ((int) $connection->fetchOne('SELECT COUNT(*) FROM ' . FleetRepository::VEHICLES) > 0) {
    fwrite(STDERR, "Tabela pojazdów nie jest pusta. Użyj --wyczysc, żeby wstawić dane demo od nowa.\n");
    exit(1);
}

if (!$repo->unit()) {
    $repo->insert(FleetRepository::UNIT, [
        'nazwa' => 'Grupa Transportowa (DEMO)',
        'nr_jednostki' => '0000',
        'miejscowosc' => 'Warszawa',
        'adres' => 'ul. Przykładowa 1',
        'dowodca' => 'płk Jan Przykładowy',
        'szef_sluzby' => 'mjr Anna Testowa',
        'prog_alertu_dni' => 30,
    ]);
}

$today = new DateTimeImmutable('today');
$d = static fn(string $modify): string => $today->modify($modify)->format('Y-m-d');

$vehicles = [
    ['UA 12345', 'wojskowy', 'ciezarowy', 'Jelcz', '442.32', 'C', 'ON', 32.0, 28450, '#4f6b3a', $d('+140 days'), $d('+20 days'), null, 'sprawny', 3, 6000],
    ['UA 23456', 'wojskowy', 'ciezarowy', 'Jelcz', '662D.43', 'C+E', 'ON', 38.5, 61210, '#6b5b2e', $d('+12 days'), $d('+45 days'), null, 'sprawny', 3, 10000],
    ['UB 34567', 'wojskowy', 'osobowo_terenowy', 'Toyota', 'Land Cruiser', 'B', 'ON', 12.5, 98340, '#3e6b8a', $d('+200 days'), $d('+70 days'), null, 'sprawny', 5, null],
    ['UB 45678', 'wojskowy', 'osobowo_terenowy', 'Honker', '2324', 'B', 'ON', 14.0, 154020, '#8a5a3e', $d('-3 days'), $d('+10 days'), null, 'w_obsludze', 6, null],
    ['UC 56789', 'wojskowy', 'autobus', 'Autosan', 'A0909L', 'D', 'ON', 24.0, 210300, '#5d4a7a', $d('+90 days'), $d('+30 days'), null, 'sprawny', 33, null],
    ['WX 1234A', 'cywilny', 'osobowy', 'Skoda', 'Octavia', 'B', 'PB', 7.2, 45210, '#2f7d6d', $d('+260 days'), null, $d('+25 days'), 'sprawny', 5, null],
    ['WX 5678B', 'cywilny', 'autobus', 'Mercedes-Benz', 'Sprinter', 'B', 'ON', 11.5, 120400, '#7a4e5d', $d('+60 days'), null, $d('+180 days'), 'sprawny', 9, null],
    ['WX 9012C', 'cywilny', 'ciezarowy', 'Iveco', 'Daily', 'C', 'ON', 13.8, 88750, '#a06a1a', $d('+300 days'), null, $d('+5 days'), 'sprawny', 3, 3500],
];
$vehicleIds = [];
foreach ($vehicles as [$nr, $rodzaj, $typ, $marka, $model, $kat, $paliwo, $norma, $przebieg, $kolor, $przeglad, $obsluga, $oc, $status, $miejsca, $ladownosc]) {
    $vehicleIds[$nr] = $repo->insert(FleetRepository::VEHICLES, [
        'nr_rejestracyjny' => $nr, 'rodzaj' => $rodzaj, 'typ' => $typ, 'marka' => $marka, 'model' => $model,
        'nr_ewidencyjny' => $rodzaj === 'wojskowy' ? 'E-' . substr(md5($nr), 0, 6) : null,
        'wymagana_kategoria' => $kat, 'rodzaj_paliwa' => $paliwo, 'norma_zuzycia' => $norma, 'przebieg' => $przebieg,
        'stan_paliwa' => 40.0, 'pojemnosc_zbiornika' => 120.0, 'kolor' => $kolor, 'przeglad_do' => $przeglad, 'obsluga_do' => $obsluga,
        'ubezpieczenie_do' => $oc, 'status' => $status, 'liczba_miejsc' => $miejsca, 'ladownosc_kg' => $ladownosc,
        'limit_km_miesiac' => $rodzaj === 'wojskowy' ? 2500 : 3000, 'rok_produkcji' => 2012 + crc32($nr) % 12,
    ]);
}

$drivers = [
    ['st. szer.', 'Piotr', 'Nowak', 'B, C, C+E', 'dostepny', $d('+900 days'), $d('+400 days'), $d('+200 days'), $d('+600 days'), 'kierowca@demo.local'],
    ['kpr.', 'Marek', 'Wiśniewski', 'B, C', 'dostepny', $d('+1500 days'), $d('+18 days'), $d('+300 days'), $d('+700 days'), null],
    ['szer.', 'Tomasz', 'Kowalczyk', 'B, D', 'dostepny', $d('+700 days'), $d('+500 days'), $d('-5 days'), $d('+120 days'), null],
    ['plut.', 'Kamil', 'Zieliński', 'B, C, C+E, D', 'dostepny', $d('+1200 days'), $d('+800 days'), $d('+90 days'), $d('+900 days'), null],
    ['st. szer.', 'Adam', 'Lewandowski', 'B', 'urlop', $d('+2000 days'), $d('+300 days'), $d('+300 days'), $d('+400 days'), null],
    ['', 'Krzysztof', 'Mazur', 'B, C', 'dostepny', $d('+600 days'), null, $d('+250 days'), $d('+250 days'), null],
];
$driverIds = [];
foreach ($drivers as $i => [$stopien, $imie, $nazwisko, $kat, $status, $pj, $pw, $lek, $psych, $email]) {
    $driverIds[$nazwisko] = $repo->insert(FleetRepository::DRIVERS, [
        'stopien' => $stopien ?: null, 'imie' => $imie, 'nazwisko' => $nazwisko, 'kategorie' => $kat, 'status' => $status,
        'pododdzial' => $stopien ? 'Pluton transportowy' : 'Pracownik cywilny', 'telefon' => sprintf('600 100 %03d', 101 + $i),
        'nr_prawa_jazdy' => sprintf('%05d/%02d/1465', 10000 + $i * 731, 10 + $i), 'prawo_jazdy_do' => $pj,
        'nr_pozwolenia_wojskowego' => $pw ? sprintf('PW-%04d', 2100 + $i) : null, 'pozwolenie_wojskowe_do' => $pw,
        'badania_lekarskie_do' => $lek, 'badania_psychologiczne_do' => $psych, 'email' => $email,
    ]);
}

$monday = $today->modify('monday this week');
$at = static fn(int $day, string $time): string => $monday->modify(sprintf('%+d days', $day))->format('Y-m-d') . ' ' . $time . ':00';
$trips = [
    // [dzień tygodnia od poniedziałku, od, dzień, do, cel, skąd, dokąd, pojazd, kierowca, status, osoby, ładunek]
    [-3, '07:00', -3, '15:00', 'Przewóz amunicji ćwiczebnej', 'Warszawa', 'Nowa Dęba', 'UA 12345', 'Nowak', 'zakonczony', 2, 'amunicja ćwiczebna'],
    [-2, '06:30', -2, '18:00', 'Dowóz żołnierzy na strzelnicę', 'Warszawa', 'Orzysz', 'UC 56789', 'Kowalczyk', 'zakonczony', 28, null],
    [0, '07:00', 0, '16:00', 'Transport sprzętu łączności', 'Warszawa', 'Zegrze', 'UA 12345', 'Nowak', 'zakonczony', 2, 'sprzęt łączności'],
    [0, '08:00', 0, '12:30', 'Wyjazd służbowy do RCWT', 'Warszawa', 'Radom', 'WX 1234A', 'Mazur', 'zakonczony', 3, null],
    [1, '06:00', 2, '18:00', 'Przemieszczenie na poligon', 'Warszawa', 'Drawsko Pomorskie', 'UA 23456', 'Zieliński', 'zatwierdzony', 2, 'kontener z wyposażeniem'],
    [1, '09:00', 1, '14:00', 'Odbiór części zamiennych', 'Warszawa', 'Grudziądz', 'WX 9012C', 'Wiśniewski', 'zatwierdzony', 1, 'części zamienne'],
    [2, '07:30', 2, '15:30', 'Dowóz kadry na odprawę', 'Warszawa', 'Bydgoszcz', 'WX 5678B', 'Mazur', 'zatwierdzony', 8, null],
    [3, '06:00', 3, '19:00', 'Przewóz żołnierzy – szkolenie', 'Warszawa', 'Biedrusko', 'UC 56789', 'Kowalczyk', 'planowany', 30, null],
    [3, '08:00', 3, '13:00', 'Rozpoznanie trasy przemarszu', 'Warszawa', 'Mińsk Mazowiecki', 'UB 34567', null, 'planowany', 3, null],
    [4, '07:00', 4, '17:00', 'Dostawa paliwa do pododdziału', 'Warszawa', 'Dęblin', null, 'Nowak', 'planowany', 2, 'paliwo w kanistrach'],
    [7, '06:00', 9, '20:00', 'Ćwiczenie taktyczne – kolumna', 'Warszawa', 'Żagań', 'UA 23456', 'Zieliński', 'planowany', 2, 'sprzęt saperski'],
    [8, '08:00', 8, '15:00', 'Odbiór pojazdu z naprawy', 'Warszawa', 'Siedlce', 'UB 34567', 'Nowak', 'planowany', 2, null],
    [9, '07:00', 9, '11:00', 'Przewóz dokumentacji', 'Warszawa', 'Warszawa – Sztab', 'WX 1234A', null, 'planowany', 1, 'dokumentacja niejawna'],
];
$year = (int) $today->format('Y');
$refuel = 0.0;
foreach ($trips as $i => [$dayFrom, $timeFrom, $dayTo, $timeTo, $cel, $skad, $dokad, $nr, $driver, $status, $osoby, $ladunek]) {
    $values = [
        'numer' => $repo->nextTripNumber($year),
        'cel' => $cel, 'miejsce_wyjazdu' => $skad, 'miejsce_docelowe' => $dokad,
        'data_od' => $at($dayFrom, $timeFrom), 'data_do' => $at($dayTo, $timeTo),
        'pojazd' => $nr ? $vehicleIds[$nr] : null, 'kierowca' => $driver ? $driverIds[$driver] : null,
        'status' => $status, 'liczba_osob' => $osoby, 'ladunek' => $ladunek,
        'dowodca' => $osoby > 2 ? 'ppor. Michał Dowódca' : null, 'zlecajacy' => 'Szef sekcji logistyki',
        'zatwierdzil' => in_array($status, ['zatwierdzony', 'zakonczony'], true) ? 'mjr Anna Testowa' : null,
    ];
    $refuel = 0.0;
    if ($status === 'zakonczony' && $nr) {
        $vehicle = $repo->vehicle($vehicleIds[$nr]);
        $km = 120 + ($i * 97) % 380;
        // Spójne stany paliwa: zużycie ok. 3% poniżej normy, tankowanie w trasie, gdy zbiornik by nie wystarczył.
        $used = round($km * (float) $vehicle['norma_zuzycia'] / 100 * 0.97, 1);
        $startLevel = (float) $vehicle['stan_paliwa'];
        $refuel = $startLevel - $used < 20 ? (float) ceil($used - $startLevel + 50) : 0.0;
        $values += [
            'licznik_start' => (int) $vehicle['przebieg'],
            'licznik_stop' => (int) $vehicle['przebieg'] + $km,
            'paliwo_start' => $startLevel,
            'paliwo_stop' => round($startLevel + $refuel - $used, 1),
            'faktyczny_wyjazd' => $values['data_od'],
            'faktyczny_powrot' => $values['data_do'],
        ];
        $repo->update(FleetRepository::VEHICLES, (int) $vehicle['id'], ['przebieg' => $values['licznik_stop'], 'stan_paliwa' => $values['paliwo_stop']]);
    }
    $tripId = $repo->insert(FleetRepository::TRIPS, $values);
    if ($status === 'zakonczony' && $nr && $refuel > 0) {
        $repo->insert(FleetRepository::FUEL, [
            'data' => $values['data_do'], 'pojazd' => $vehicleIds[$nr], 'kierowca' => $driverIds[$driver], 'wyjazd' => $tripId,
            'ilosc' => $refuel, 'rodzaj_paliwa' => 'ON', 'zrodlo' => 'stacja_wojskowa', 'licznik' => $values['licznik_stop'],
            'nr_dokumentu' => sprintf('MPS/%d/%03d', $year, 10 + $i),
        ]);
    }
}

// Najbliższy wolny termin dla kierowcy demo, żeby widok „Moje wyjazdy” pokazywał przycisk rozpoczęcia jazdy.
$rules = new TripRules($repo);
for ($offset = 1; $offset <= 7; $offset++) {
    $candidate = [
        'cel' => 'Transport sprzętu kwatermistrzowskiego', 'miejsce_wyjazdu' => 'Warszawa', 'miejsce_docelowe' => 'Modlin',
        'data_od' => $today->modify("+$offset days 06:00")->format('Y-m-d H:i:s'),
        'data_do' => $today->modify("+$offset days 14:00")->format('Y-m-d H:i:s'),
        'pojazd' => $vehicleIds['UA 12345'], 'kierowca' => $driverIds['Nowak'], 'status' => 'zatwierdzony',
        'liczba_osob' => 2, 'ladunek' => 'namioty i wyposażenie', 'zlecajacy' => 'Szef sekcji logistyki', 'zatwierdzil' => 'mjr Anna Testowa',
    ];
    if ($rules->check($candidate)['errors'] === []) {
        $repo->insert(FleetRepository::TRIPS, $candidate + ['numer' => $repo->nextTripNumber((int) substr($candidate['data_od'], 0, 4))]);
        break;
    }
}

if (isset($options['z-kontami'])) {
    $password = (string) ($options['haslo'] ?? '');
    if (strlen($password) < 10) {
        fwrite(STDERR, "Podaj hasło kont demo: --haslo=... (min. 10 znaków).\n");
        exit(1);
    }
    foreach ([['dyspozytor@demo.local', 'Ewa', 'Dyspozytor', 'Dyspozytorzy'], ['kierowca@demo.local', 'Piotr', 'Nowak', 'Kierowcy']] as [$email, $first, $last, $groupName]) {
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]) ?? new User();
        $user->setEmail($email);
        $user->setFirstName($first);
        $user->setLastName($last);
        $user->setActive(true);
        $user->setPasswordHash(password_hash($password, PASSWORD_DEFAULT));
        $group = $em->getRepository(Group::class)->findOneBy(['name' => $groupName]);
        if ($group && !$user->getGroups()->contains($group)) {
            $user->addGroup($group);
        }
        $em->persist($user);
    }
    $em->flush();
    fwrite(STDOUT, "Konta demo: dyspozytor@demo.local, kierowca@demo.local (hasło z --haslo).\n");
}

fwrite(STDOUT, sprintf("Wstawiono: %d pojazdów, %d kierowców, %d wyjazdów.\n", count($vehicles), count($drivers), count($trips) + 1));
