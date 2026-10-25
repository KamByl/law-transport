<?php

// php tests/lottery-daily-entry-limits.php [ścieżka do vendor/autoload.php]
$autoload = $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('Zainstaluj zależności projektu lub podaj ścieżkę do autoload.php.');
}
require $autoload;
set_error_handler(static function (int $severity, string $message, string $file, int $line): void {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
});
foreach (['LotteryRegistrationValidator', 'LotteryDailyLimitException', 'LotteryDuplicateReceiptException', 'LotteryReceiptValidator', 'LotteryProductAllocation', 'LotteryDailyEntryLimitService'] as $class) {
    require dirname(__DIR__) . '/src/Service/' . $class . '.php';
}

use App\Service\LotteryDailyEntryLimitService;
use App\Service\LotteryDailyLimitException;
use App\Service\LotteryProductAllocation;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySqlPlatform;

// Verify the MySQL advisory-lock lifecycle using a real transactional SQLite database.
class LockLifecycleConnection extends Connection
{
    public array $events = [];
    public bool $busy = false;

    public function getDatabasePlatform()
    {
        return new MySqlPlatform();
    }

    public function fetchOne(string $query, array $params = [], array $types = [])
    {
        if ($query === 'SELECT GET_LOCK(?, 5)') {
            $this->events[] = ['acquire', $this->isTransactionActive()];
            return $this->busy ? 0 : 1;
        }
        if ($query === 'SELECT RELEASE_LOCK(?)') {
            $this->events[] = ['release', $this->isTransactionActive()];
            return 1;
        }
        return parent::fetchOne($query, $params, $types);
    }
}

$checks = 0;
$check = static function (bool $condition, string $description) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($description);
    }
    $checks++;
};
$expectLimit = static function (callable $operation) use ($check): void {
    try {
        $operation();
    } catch (LotteryDailyLimitException $exception) {
        $check(true, 'Odrzucono przekroczenie limitu');
        return;
    }
    throw new RuntimeException('Brak oczekiwanego odrzucenia');
};
$newDatabase = static function (bool $simulateMysql = false): Connection {
    $params = ['driver' => 'pdo_sqlite', 'memory' => true];
    if ($simulateMysql) {
        $params['wrapperClass'] = LockLifecycleConnection::class;
    }
    $connection = DriverManager::getConnection($params);
    foreach (['tickets', 'scratchcards'] as $table) {
        $connection->executeStatement("CREATE TABLE {$table} (id INTEGER PRIMARY KEY AUTOINCREMENT, zgloszenie INTEGER NULL, email TEXT, phone TEXT, submitted_at TEXT, status TEXT)");
    }
    $connection->executeStatement('CREATE TABLE submissions (id INTEGER PRIMARY KEY, code_used INTEGER, email TEXT, phone TEXT, submitted_at TEXT, promoted_products_count INTEGER, other_products_count INTEGER, allocated_promoted_products_count INTEGER, allocated_other_products_count INTEGER)');
    $connection->insert('submissions', ['id' => 1, 'code_used' => 0]);
    return $connection;
};
$alice = ['email' => 'alice@example.com', 'phone' => '501234567'];
$bob = ['email' => 'bob@example.com', 'phone' => '601234567'];
$date = new DateTimeImmutable('2026-10-13 12:00:00', new DateTimeZone('Europe/Warsaw'));
$tables = ['tickets' => 'tickets', 'scratchcards' => 'scratchcards'];
$seed = static function (Connection $connection, string $type, int $count, array $participant, string $timestamp = '2026-10-13 12:00:00', ?int $submissionId = 1): void {
    for ($i = 0; $i < $count; $i++) {
        $connection->insert($type, [
            'zgloszenie' => $submissionId, 'email' => $participant['email'], 'phone' => $participant['phone'],
            'submitted_at' => $timestamp, 'status' => 'sprawdzony',
        ]);
    }
};
$grant = static function (Connection $connection, array $settings, array $requested, array $participant, ?int $exclude = null, ?DateTimeImmutable $at = null) use ($tables, $date, $seed): array {
    return (new LotteryDailyEntryLimitService($connection))->allocate(
        $settings, $tables, $requested, $at ?? $date, $participant,
        static function (array $counts) use ($connection, $participant, $seed, $at, $date, $exclude): void {
            foreach ($counts as $type => $count) {
                if ($exclude !== null) {
                    $connection->delete($type, ['zgloszenie' => $exclude]);
                }
                $localTime = ($at ?? $date)->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i:s');
                $seed($connection, $type, $count, $participant, $localTime, $exclude ?? 2);
            }
        }, $exclude
    );
};

foreach ([[], ['daily_ticket_limit' => 0, 'daily_scratchcard_limit' => '0']] as $settings) {
    $result = $grant($newDatabase(), $settings, ['tickets' => 8, 'scratchcards' => 5], $alice);
    $check($result === ['tickets' => 8, 'scratchcards' => 5, 'limited' => false], 'Brak limitów zachowuje naliczanie');
}
$connection = $newDatabase();
$settings = ['daily_ticket_limit' => 3, 'daily_scratchcard_limit' => 6];
$seed($connection, 'tickets', 2, $alice);
$seed($connection, 'scratchcards', 4, $alice);
$result = $grant($connection, $settings, ['tickets' => 4, 'scratchcards' => 4], $alice);
$check($result === ['tickets' => 1, 'scratchcards' => 2, 'limited' => true], 'Niezależny częściowy przydział losów i zdrapek');
$expectLimit(static function () use ($grant, $connection, $settings, $alice): void {
    $grant($connection, $settings, ['tickets' => 1, 'scratchcards' => 1], $alice);
});
$result = $grant($connection, $settings, ['tickets' => 4, 'scratchcards' => 4], $bob);
$check($result === ['tickets' => 3, 'scratchcards' => 4, 'limited' => true], 'Inny uczestnik otrzymuje własny limit');
foreach ([['email' => 'changed@example.com', 'phone' => $alice['phone']], ['email' => ' ALICE@EXAMPLE.COM ', 'phone' => '701234567']] as $identity) {
    $expectLimit(static function () use ($grant, $connection, $settings, $identity): void {
        $grant($connection, $settings, ['tickets' => 1, 'scratchcards' => 1], $identity);
    });
}
$expectLimit(static function () use ($grant, $connection, $settings): void {
    $grant($connection, $settings, ['tickets' => 1], ['email' => 'other@example.com', 'phone' => '+48 501 - 234 - 567']);
});

$connection = $newDatabase();
$seed($connection, 'tickets', 2, $alice);
$seed($connection, 'tickets', 1, ['email' => $alice['email'], 'phone' => '701234567']);
$seed($connection, 'tickets', 2, ['email' => $bob['email'], 'phone' => $alice['phone']]);
$result = $grant($connection, ['daily_ticket_limit' => 5], ['tickets' => 2], $alice);
$check($result['tickets'] === 1, 'Obowiązuje mniejszy z limitów pozostałych dla e-maila i telefonu');

$connection = $newDatabase();
$seed($connection, 'scratchcards', 2, $alice);
$result = $grant($connection, ['daily_ticket_limit' => 0, 'daily_scratchcard_limit' => 2], ['tickets' => 5, 'scratchcards' => 5], $alice);
$check($result['tickets'] === 5 && $result['scratchcards'] === 0, 'Wyczerpanie zdrapek nie blokuje losów bez limitu');
$connection = $newDatabase();
$seed($connection, 'tickets', 3, $alice);
$result = $grant($connection, $settings, ['tickets' => 0, 'scratchcards' => 2], $alice);
$check($result['tickets'] === 0 && $result['scratchcards'] === 2 && !$result['limited'], 'Wyłączony rodzaj wpisów nie blokuje aktywnego');

$connection = $newDatabase();
$seed($connection, 'tickets', 5, $alice, '2026-10-12 23:59:59');
$seed($connection, 'tickets', 1, $alice, '2026-10-13 00:00:00');
$seed($connection, 'tickets', 5, $alice, '2026-10-14 00:00:00');
$result = $grant($connection, ['daily_ticket_limit' => 3], ['tickets' => 5], $alice, null, new DateTimeImmutable('2026-10-12 22:00:00', new DateTimeZone('UTC')));
$check($result['tickets'] === 2, 'Granice doby w Polsce i konwersja z UTC');
$result = $grant($connection, ['daily_scratchcard_limit' => 1], ['scratchcards' => 1], $alice, null, new DateTimeImmutable('2026-10-14 12:00:00', new DateTimeZone('Europe/Warsaw')));
$check($result['scratchcards'] === 1, 'Nowy dzień udostępnia nowy limit');
$connection = $newDatabase();
$seed($connection, 'tickets', 2, $alice, '2026-10-25 23:59:59');
$seed($connection, 'tickets', 4, $alice, '2026-10-26 00:00:00');
$result = $grant($connection, ['daily_ticket_limit' => 3], ['tickets' => 3], $alice, null, new DateTimeImmutable('2026-10-25 12:00:00', new DateTimeZone('Europe/Warsaw')));
$check($result['tickets'] === 1, 'Doba ze zmianą czasu obejmuje cały dzień kalendarzowy');

$connection = $newDatabase();
$seed($connection, 'tickets', 3, $alice, '2026-10-13 10:00:00', 10);
$seed($connection, 'tickets', 1, $alice, '2026-10-13 11:00:00', 20);
$seed($connection, 'tickets', 1, $alice, '2026-10-13 11:00:00', null);
$result = $grant($connection, ['daily_ticket_limit' => 5], ['tickets' => 8], $alice, 10);
$check($result['tickets'] === 3, 'Przeliczenie wyklucza własny przydział i uwzględnia wpisy bez relacji');
$check((int) $connection->fetchOne('SELECT COUNT(*) FROM tickets') === 5, 'Przeliczenie zastępuje przydział zamiast dodawać go ponownie');
$result = $grant($connection, ['daily_ticket_limit' => 5], ['tickets' => 0], $alice, 10);
$check($result['tickets'] === 0 && (int) $connection->fetchOne('SELECT COUNT(*) FROM tickets') === 2, 'Zmniejszenie przydziału do zera');

$connection = $newDatabase();
try {
    (new LotteryDailyEntryLimitService($connection))->allocate($settings, $tables, ['tickets' => 1], $date, $alice,
        static function (array $_counts) use ($connection, $seed, $alice): void {
            $connection->update('submissions', ['code_used' => 1], ['id' => 1]);
            $seed($connection, 'tickets', 1, $alice);
            throw new RuntimeException('Symulacja błędu zapisu');
        }
    );
    throw new RuntimeException('Brak błędu zapisu');
} catch (RuntimeException $exception) {
    $check($exception->getMessage() === 'Symulacja błędu zapisu', 'Zachowano błąd zapisu');
}
$check((int) $connection->fetchOne('SELECT COUNT(*) FROM tickets') === 0, 'Rollback usuwa częściowo zapisany los');
$check((int) $connection->fetchOne('SELECT code_used FROM submissions WHERE id = 1') === 0, 'Rollback zachowuje niewykorzystany kod');
$check(!$connection->isTransactionActive(), 'Transakcja jest zamknięta po błędzie');
foreach ([-1, '1.5', '1e2', '2147483648', ['1']] as $invalidLimit) {
    $expectLimit(static function () use ($grant, $newDatabase, $invalidLimit, $alice): void {
        $grant($newDatabase(), ['daily_ticket_limit' => $invalidLimit], ['tickets' => 1], $alice);
    });
}
$expectLimit(static function () use ($grant, $newDatabase, $settings): void {
    $grant($newDatabase(), $settings, ['tickets' => 1], ['email' => 'alice@example.com', 'phone' => '']);
});
$connection = $newDatabase(true);
$result = $grant($connection, $settings, ['tickets' => 1], $alice);
$check($result['tickets'] === 1 && $connection->events === [['acquire', false], ['release', false]], 'Blokada zwalniana dopiero po COMMIT');
$connection->events = [];
$expectLimit(static function () use ($grant, $connection, $alice): void {
    $grant($connection, ['daily_ticket_limit' => 1], ['tickets' => 1], $alice);
});
$check($connection->events === [['acquire', false], ['release', false]], 'Blokada zwalniana po ROLLBACK odrzucenia');
$connection->events = [];
$connection->busy = true;
$expectLimit(static function () use ($grant, $connection, $settings, $alice): void {
    $grant($connection, $settings, ['tickets' => 1], $alice);
});
$check($connection->events === [['acquire', false]], 'Zajęta blokada nie pozwala na niechroniony zapis');
echo sprintf("OK: %d sprawdzeń dziennych limitów, tożsamości, transakcji i cyklu blokady.\n", $checks);

$purchaseGrant = static function (Connection $connection, int $promoted, int $other, array $participant, array $settings = [], ?DateTimeImmutable $at = null, ?int $exclude = null, bool $fail = false) use ($date, $tables, $seed): array {
    $quantities = ['promoted_products_count' => $promoted, 'other_products_count' => $other];
    $entryCount = LotteryProductAllocation::requestedEntryCount($quantities, $settings);
    $timestamp = ($at ?? $date)->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i:s');
    return (new LotteryDailyEntryLimitService($connection))->allocate(
        $settings, $tables, ['scratchcards' => $entryCount], $at ?? $date, $participant,
        static function (array $counts) use ($connection, $quantities, $participant, $timestamp, $exclude, $fail, $seed): void {
            $values = array_merge($quantities, $participant, [
                'submitted_at' => $timestamp, 'code_used' => 1,
                'allocated_promoted_products_count' => $counts['allocated_promoted_products_count'],
                'allocated_other_products_count' => $counts['allocated_other_products_count'],
            ]);
            if ($exclude === null) {
                $connection->insert('submissions', $values);
                $id = (int) $connection->lastInsertId();
            } else {
                $connection->update('submissions', $values, ['id' => $exclude]);
                $connection->delete('scratchcards', ['zgloszenie' => $exclude]);
                $id = $exclude;
            }
            $seed($connection, 'scratchcards', $counts['scratchcards'], $participant, $timestamp, $id);
            if ($fail) { throw new RuntimeException('Błąd zapisu produktów'); }
        }, $exclude, array_merge(['table' => 'submissions'], $quantities)
    );
};
$check(LotteryProductAllocation::entryCount(['promoted_products_count' => 2, 'other_products_count' => 2], []) === 12, '2 promowane + 2 pozostałe = 12 e-zdrapek');
$check(LotteryProductAllocation::entryCount(['promoted_products_count' => 0, 'other_products_count' => 2], []) === 2, 'Same produkty pozostałe są dozwolone');
$check(LotteryProductAllocation::entryCount(['promoted_products_count' => 2, 'other_products_count' => 2, 'purchase_amount' => 0], ['max_tickets_per_receipt' => 1]) === 12, 'Kwota i dawny limit paragonu nie wpływają na wzór produktów');
$check(LotteryProductAllocation::entryCount(['promoted_products_count' => 1, 'other_products_count' => 2], ['promoted_product_entry_count' => 7, 'other_product_entry_count' => 3]) === 13, 'Konfigurowalne przeliczniki');
$check(LotteryProductAllocation::isProductBased([]) && !LotteryProductAllocation::isProductBased(['entry_calculation_basis' => 'amount']), 'Domyślne produkty i jawny tryb kwotowy');
foreach ([['promoted_products_count' => 0, 'other_products_count' => 0], ['promoted_products_count' => -1], ['other_products_count' => '1.5'], ['promoted_products_count' => 2147483647]] as $data) {
    $expectLimit(static function () use ($data): void { LotteryProductAllocation::entryCount($data, []); });
}
$connection = $newDatabase();
$result = $purchaseGrant($connection, 1, 1, $alice);
$check($result['scratchcards'] === 6, 'Pierwszy dowód: 1 promowany + 1 pozostały = 6');
$result = $purchaseGrant($connection, 0, 2, $alice);
$check($result['scratchcards'] === 2, 'Drugi dowód jest naliczany samodzielnie');
$check((int) $connection->fetchOne('SELECT SUM(promoted_products_count + other_products_count) FROM submissions WHERE code_used = 1') === 4, 'Dzienne produkty są sumowane ze zgłoszeń');
$expectLimit(static function () use ($purchaseGrant, $connection, $alice): void { $purchaseGrant($connection, 0, 1, $alice); });
$check((int) $connection->fetchOne('SELECT COUNT(*) FROM scratchcards') === 8, 'Odrzucone zgłoszenie nie tworzy e-zdrapek');
foreach ([['email' => 'new@example.com', 'phone' => $alice['phone']], ['email' => $alice['email'], 'phone' => $bob['phone']]] as $identity) {
    $expectLimit(static function () use ($purchaseGrant, $connection, $identity): void { $purchaseGrant($connection, 0, 1, $identity); });
}
$result = $purchaseGrant($connection, 4, 0, $bob);
$check($result['scratchcards'] === 20, 'Inny uczestnik ma własne 4 produkty i otrzymuje 20 e-zdrapek');
$result = $purchaseGrant($connection, 0, 1, $alice, [], new DateTimeImmutable('2026-10-14 00:00:00', new DateTimeZone('Europe/Warsaw')));
$check($result['scratchcards'] === 1, 'Produktowy limit resetuje się o północy');
$connection = $newDatabase();
$result = $purchaseGrant($connection, 2, 3, $alice);
$check($result['scratchcards'] === 12 && $result['limited'], '5 zadeklarowanych produktów daje przydział z 2 promowanych i 2 pozostałych');
$check((int) $connection->fetchOne('SELECT other_products_count FROM submissions WHERE code_used = 1') === 3, 'Pełna deklaracja klienta jest zachowana');
$connection = $newDatabase();
$result = $purchaseGrant($connection, 2, 3, $alice, ['daily_product_limit' => 6]);
$check($result['scratchcards'] === 13, 'Dzienny limit produktów można zmienić');
$result = $purchaseGrant($connection, 0, 5, $alice, ['daily_product_limit' => 0]);
$check($result['scratchcards'] === 5, '0 wyłącza limit produktów');
$connection = $newDatabase();
$purchaseGrant($connection, 1, 1, $alice);
$id = (int) $connection->fetchOne('SELECT id FROM submissions WHERE code_used = 1');
$result = $purchaseGrant($connection, 2, 2, $alice, [], null, $id);
$check($result['scratchcards'] === 12 && (int) $connection->fetchOne('SELECT COUNT(*) FROM scratchcards') === 12, 'Przeliczenie zastępuje produkty i e-zdrapki bez podwójnego naliczania');
$result = $purchaseGrant($connection, 3, 2, $alice, [], null, $id);
$check($result['scratchcards'] === 16 && (int) $connection->fetchOne('SELECT COUNT(*) FROM scratchcards') === 16, 'Przeliczenie wykorzystuje 3 promowane i 1 pozostały bez podwójnego naliczania');
$connection = $newDatabase();
$result = $purchaseGrant($connection, 1, 1, $alice, ['daily_scratchcard_limit' => 8]);
$result = $purchaseGrant($connection, 1, 1, $alice, ['daily_scratchcard_limit' => 8]);
$check($result['scratchcards'] === 2 && $result['limited'], 'Limit e-zdrapek nadal częściowo ogranicza przydział');
$check((int) $connection->fetchOne('SELECT SUM(promoted_products_count + other_products_count) FROM submissions WHERE code_used = 1') === 4, 'Częściowy przydział e-zdrapek nie zmienia zadeklarowanej liczby produktów');
$connection->executeStatement('DELETE FROM scratchcards');
$expectLimit(static function () use ($purchaseGrant, $connection, $alice): void { $purchaseGrant($connection, 0, 1, $alice); });
$connection = $newDatabase();
try {
    $purchaseGrant($connection, 1, 1, $alice, [], null, null, true);
    throw new RuntimeException('Brak oczekiwanego błędu');
} catch (RuntimeException $exception) {
    $check($exception->getMessage() === 'Błąd zapisu produktów', 'Błąd zapisu produktów jest propagowany');
}
$check((int) $connection->fetchOne('SELECT COUNT(*) FROM submissions WHERE code_used = 1') === 0 && (int) $connection->fetchOne('SELECT COUNT(*) FROM scratchcards') === 0, 'Rollback nie zużywa produktów ani nie zostawia zdrapek');
echo sprintf("OK: łącznie %d sprawdzeń, w tym naliczanie produktów i dzienny limit produktów.\n", $checks);

foreach ([[3, 5, 16, 3, 1], [6, 10, 20, 4, 0], [0, 12, 4, 0, 4]] as [$promoted, $other, $expected, $usedPromoted, $usedOther]) {
    $connection = $newDatabase();
    $result = $purchaseGrant($connection, $promoted, $other, $alice);
    $check($result['scratchcards'] === $expected && $result['limited'], 'Priorytet promowanych dla większej deklaracji');
    $check($result['allocated_promoted_products_count'] === $usedPromoted && $result['allocated_other_products_count'] === $usedOther, 'Poprawne wykorzystanie obu rodzajów produktów');
    $row = $connection->fetchAssociative('SELECT * FROM submissions WHERE code_used = 1');
    $check((int) $row['promoted_products_count'] === $promoted && (int) $row['other_products_count'] === $other, 'Zachowanie nieprzyciętej deklaracji');
    $expectLimit(static function () use ($purchaseGrant, $connection, $alice): void { $purchaseGrant($connection, 1, 0, $alice); });
}
$connection = $newDatabase();
$purchaseGrant($connection, 0, 2, $alice);
$result = $purchaseGrant($connection, 3, 5, $alice);
$check($result['scratchcards'] === 10 && $result['allocated_promoted_products_count'] === 2 && $result['allocated_other_products_count'] === 0, 'Pozostały limit 2 jest wykorzystany na promowane');
$check((int) $connection->fetchOne('SELECT SUM(allocated_promoted_products_count + allocated_other_products_count) FROM submissions WHERE code_used = 1') === 4, 'Dzienny limit sumuje wykorzystanie, a nie deklarację');
$connection = $newDatabase();
$result = $purchaseGrant($connection, 3, 5, $alice, ['daily_product_limit' => 0]);
$check($result['scratchcards'] === 20 && !$result['limited'], 'Wyłączenie limitu nalicza wszystkie zadeklarowane produkty');
$connection = $newDatabase();
$result = $purchaseGrant($connection, 6, 10, $alice, ['daily_scratchcard_limit' => 7]);
$check($result['scratchcards'] === 7 && $result['allocated_promoted_products_count'] === 4, 'Niezależny limit e-zdrapek stosowany po wyborze produktów');
$connection = $newDatabase();
$result = $purchaseGrant($connection, 2147483647, 2147483647, $alice);
$check($result['scratchcards'] === 20, 'Duża deklaracja jest przycinana przed mnożeniem przeliczników');
$connection = $newDatabase();
$connection->insert('submissions', ['id' => 10, 'code_used' => 1, 'email' => $alice['email'], 'phone' => $alice['phone'],
    'submitted_at' => '2026-10-13 10:00:00', 'promoted_products_count' => 0, 'other_products_count' => 2]);
$result = $purchaseGrant($connection, 3, 5, $alice);
$check($result['scratchcards'] === 10 && $result['allocated_promoted_products_count'] === 2, 'Starsze zgłoszenia bez wykorzystanych ilości nadal zużywają limit');
echo sprintf("OK: %d sprawdzeń, w tym priorytet promowanych, zachowanie deklaracji i kolejne zgłoszenia.\n", $checks);
