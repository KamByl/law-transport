<?php

// php tests/lottery-standard-statuses.php [ścieżka do vendor/autoload.php]
require $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/Service/LotteryStandardStatusMigration.php';

use App\Service\LotteryStandardStatusMigration;
use Doctrine\DBAL\DriverManager;

$checks = 0;
$check = static function (bool $ok, string $description) use (&$checks): void {
    if (!$ok) { throw new RuntimeException($description); }
    $checks++;
};
$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
LotteryStandardStatusMigration::migrate($db);
$db->executeStatement('CREATE TABLE ustawienia_statusow (id INTEGER PRIMARY KEY, name TEXT, position INTEGER, email_template INTEGER, zrealizowane INTEGER)');
$db->insert('ustawienia_statusow', ['id' => 1, 'name' => 'Formularz', 'position' => 1, 'email_template' => 101]);
$db->insert('ustawienia_statusow', ['id' => 2, 'name' => 'Wysłana', 'position' => 2, 'email_template' => 102, 'zrealizowane' => 1]);
LotteryStandardStatusMigration::migrate($db);
$check((int) $db->fetchOne('SELECT COUNT(*) FROM ustawienia_statusow') === 2, 'Baza sprzed rozdzielenia statusów nie wymaga zmian');
$db->executeStatement("ALTER TABLE ustawienia_statusow ADD status_scope TEXT NOT NULL DEFAULT 'zgloszenia'");
$db->executeStatement('ALTER TABLE ustawienia_statusow ADD legacy_status_id INTEGER');
$db->executeStatement('CREATE UNIQUE INDEX uniq_lottery_status_legacy ON ustawienia_statusow(status_scope, legacy_status_id)');
$db->executeStatement('CREATE TABLE loteria_zwyciezcy_entries (id INTEGER PRIMARY KEY, stan INTEGER, historia_wysylek_email_status TEXT, voucher_code TEXT)');
$db->executeStatement('CREATE TABLE rodzaje_nagrod_entries (id INTEGER PRIMARY KEY, status_po_wygraniu_nagrody INTEGER, status_po_wygraniu_nagrody_gdy_brak_kodow INTEGER)');
$originals = $db->fetchAllAssociative('SELECT * FROM ustawienia_statusow ORDER BY id');
foreach ([[11, 1, 'losy'], [12, 2, 'losy'], [21, 1, 'zdrapki'], [22, 2, 'zdrapki']] as [$id, $legacy, $scope]) {
    $copy = $originals[$legacy - 1];
    $copy['id'] = $id;
    $copy['legacy_status_id'] = $legacy;
    $copy['status_scope'] = $scope;
    $db->insert('ustawienia_statusow', $copy);
}
$db->update('ustawienia_statusow', ['name' => 'Własna wysyłka', 'email_template' => 999], ['id' => 22]);
$db->insert('ustawienia_statusow', ['id' => 30, 'name' => 'Dodatkowy etap', 'position' => 3, 'status_scope' => 'losy']);
$db->insert('ustawienia_statusow', ['id' => 31, 'name' => 'Usunięty oryginał', 'position' => 4, 'status_scope' => 'zdrapki', 'legacy_status_id' => 999]);
$history = json_encode([['status_id' => '21', 'template_id' => '101', 'sent_at' => '2026-10-20'], ['status_id' => '22', 'template_id' => '999'], ['message' => 'Bez statusu']]);
$db->insert('loteria_zwyciezcy_entries', ['id' => 1, 'stan' => 21, 'historia_wysylek_email_status' => $history, 'voucher_code' => 'KEEP-CODE']);
$db->insert('loteria_zwyciezcy_entries', ['id' => 2, 'stan' => 11]);
$db->insert('loteria_zwyciezcy_entries', ['id' => 3, 'stan' => 22]);
$db->insert('loteria_zwyciezcy_entries', ['id' => 4, 'stan' => 2, 'historia_wysylek_email_status' => '[{"status_id":"12","template_id":"102"}]']);
$db->insert('loteria_zwyciezcy_entries', ['id' => 5, 'stan' => 12, 'historia_wysylek_email_status' => 'legacy text']);
$db->insert('rodzaje_nagrod_entries', ['id' => 1, 'status_po_wygraniu_nagrody' => 21, 'status_po_wygraniu_nagrody_gdy_brak_kodow' => 22]);
$db->insert('rodzaje_nagrod_entries', ['id' => 2, 'status_po_wygraniu_nagrody' => 11, 'status_po_wygraniu_nagrody_gdy_brak_kodow' => 12]);

// A failed remap must leave the entire catalog and all assignments untouched.
$before = $db->fetchAllAssociative('SELECT * FROM ustawienia_statusow ORDER BY id');
$db->executeStatement("CREATE TRIGGER fail_remap BEFORE UPDATE ON rodzaje_nagrod_entries BEGIN SELECT RAISE(ABORT, 'test failure'); END");
try {
    LotteryStandardStatusMigration::migrate($db);
    throw new RuntimeException('Nie wykryto błędu zapisu');
} catch (Doctrine\DBAL\Exception $exception) {
    $check($db->fetchAllAssociative('SELECT * FROM ustawienia_statusow ORDER BY id') === $before
        && (string) $db->fetchOne('SELECT stan FROM loteria_zwyciezcy_entries WHERE id = 1') === '21', 'Błąd zapisu wycofuje całą migrację');
}
$db->executeStatement('DROP TRIGGER fail_remap');
LotteryStandardStatusMigration::migrate($db);
$check($db->fetchAllAssociative('SELECT * FROM ustawienia_statusow WHERE id IN (1, 2) ORDER BY id') === $originals, 'Oryginalne ustawienia pozostają bez zmian');
$check((int) $db->fetchOne('SELECT COUNT(*) FROM ustawienia_statusow WHERE id IN (11, 12, 21)') === 0, 'Niezmienione kopie nie dublują etapów wspólnej listy');
$check((string) $db->fetchOne('SELECT stan FROM loteria_zwyciezcy_entries WHERE id = 1') === '1'
    && (string) $db->fetchOne('SELECT stan FROM loteria_zwyciezcy_entries WHERE id = 2') === '1', 'Wygrane obu rodzajów wracają do wspólnych statusów');
$check((string) $db->fetchOne('SELECT stan FROM loteria_zwyciezcy_entries WHERE id = 3') === '22'
    && (int) $db->fetchOne('SELECT email_template FROM ustawienia_statusow WHERE id = 22') === 999, 'Ręcznie zmieniony status zachowuje ustawienia i przypisanie');
$check((int) $db->fetchOne("SELECT COUNT(*) FROM ustawienia_statusow WHERE id IN (22, 30, 31) AND status_scope = 'zgloszenia' AND legacy_status_id IS NULL") === 3, 'Własne i osierocone statusy zostają we wspólnym katalogu');
$afterHistory = json_decode($db->fetchOne('SELECT historia_wysylek_email_status FROM loteria_zwyciezcy_entries WHERE id = 1'), true);
$check($afterHistory[0] === ['status_id' => '1', 'template_id' => '101', 'sent_at' => '2026-10-20']
    && $afterHistory[1]['status_id'] === '22' && $afterHistory[2] === ['message' => 'Bez statusu'], 'Historia zachowuje szablony, daty i dodatkowe wpisy');
$check(json_decode($db->fetchOne('SELECT historia_wysylek_email_status FROM loteria_zwyciezcy_entries WHERE id = 4'), true)[0]['status_id'] === '2', 'Historia jest scalana także gdy aktualny status już jest wspólny');
$check($db->fetchOne('SELECT historia_wysylek_email_status FROM loteria_zwyciezcy_entries WHERE id = 5') === 'legacy text', 'Starsza historia w innym formacie nie jest nadpisywana');
$check($db->fetchOne('SELECT voucher_code FROM loteria_zwyciezcy_entries WHERE id = 1') === 'KEEP-CODE', 'Przyznany kod nagrody pozostaje bez zmian');
$prizes = $db->fetchAllAssociative('SELECT * FROM rodzaje_nagrod_entries ORDER BY id');
$check((string) $prizes[0]['status_po_wygraniu_nagrody'] === '1' && (string) $prizes[0]['status_po_wygraniu_nagrody_gdy_brak_kodow'] === '22'
    && (string) $prizes[1]['status_po_wygraniu_nagrody_gdy_brak_kodow'] === '2', 'Ustawienia nagród wskazują istniejące statusy po scaleniu');
$all = $db->fetchAllAssociative('SELECT * FROM ustawienia_statusow ORDER BY id');
LotteryStandardStatusMigration::migrate($db);
$check($db->fetchAllAssociative('SELECT * FROM ustawienia_statusow ORDER BY id') === $all && !$db->isTransactionActive(), 'Ponowne uruchomienie jest bezpieczne');

function standardStatusMethod(string $controller, string $name): string
{
    $source = file_get_contents(dirname(__DIR__) . '/src/Controller/' . $controller . '.php');
    preg_match('/(?:private|protected|public) function ' . preg_quote($name, '/') . '\b/', $source, $match, PREG_OFFSET_CAPTURE);
    $result = ''; $depth = 0; $started = false;
    foreach (token_get_all('<?php ' . substr($source, $match[0][1])) as $token) {
        if (is_array($token)) { if ($token[0] !== T_OPEN_TAG) { $result .= $token[1]; } }
        else {
            $result .= $token;
            if ($token === '{') { $started = true; $depth++; }
            if ($token === '}') { $depth--; }
            if ($started && $depth === 0) { return $result; }
        }
    }
    throw new RuntimeException('Niepełna metoda ' . $name);
}
eval('use Doctrine\DBAL\Connection; class StandardStatusHarness {
    public $entityManager;
    public function next($db, $status) { return $this->resolveNextWinnerStatusAfterFormSubmission($db, $status); }
    public function fulfilled() { return $this->fetchStatusIdByFlag("zrealizowane"); }
' . standardStatusMethod('FrontendController', 'resolveNextWinnerStatusAfterFormSubmission')
    . standardStatusMethod('WinnersModuleListActionController', 'fetchStatusIdByFlag') . '}');
$harness = new StandardStatusHarness();
$harness->entityManager = new class($db) {
    private $db;
    public function __construct($db) { $this->db = $db; }
    public function getConnection() { return $this->db; }
};
$check($harness->next($db, '1') === '2' && $harness->next($db, '') === '1', 'Formularz korzysta z dotychczasowej kolejności wspólnej listy');
$check($harness->fulfilled() === '2', 'Weryfikacja wybiera wspólny status realizacji');

echo sprintf("OK: %d sprawdzeń powrotu do standardowych statusów, przypisań, historii i transakcyjnej migracji.\n", $checks);
