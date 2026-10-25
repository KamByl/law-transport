<?php

// php tests/lottery-receipt-uniqueness.php [ścieżka do vendor/autoload.php]
require $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';
foreach (['LotteryRegistrationValidator', 'LotteryDailyLimitException', 'LotteryDuplicateReceiptException', 'LotteryReceiptValidator', 'LotteryProductAllocation', 'LotteryDailyEntryLimitService'] as $class) {
    require dirname(__DIR__) . '/src/Service/' . $class . '.php';
}

use App\Service\LotteryDailyEntryLimitService;
use App\Service\LotteryDailyLimitException;
use App\Service\LotteryDuplicateReceiptException;
use App\Service\LotteryReceiptValidator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySqlPlatform;

class ReceiptLockConnection extends Connection
{
    public array $events = [];

    public function getDatabasePlatform() { return new MySqlPlatform(); }
    public function fetchOne(string $query, array $params = [], array $types = [])
    {
        if (in_array($query, ['SELECT GET_LOCK(?, 5)', 'SELECT RELEASE_LOCK(?)'], true)) {
            $this->events[] = [$query, $this->isTransactionActive(), $params[0]];
            return 1;
        }
        return parent::fetchOne($query, $params, $types);
    }
}

$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => ReceiptLockConnection::class]);
$connection->executeStatement('CREATE TABLE submissions (id INTEGER PRIMARY KEY, purchase_document_number TEXT, store_nip TEXT, email TEXT, phone TEXT, submitted_at TEXT, code_used INTEGER, promoted_products_count INTEGER, other_products_count INTEGER, allocated_promoted_products_count INTEGER, allocated_other_products_count INTEGER)');
$connection->executeStatement('CREATE TABLE scratchcards (id INTEGER PRIMARY KEY, zgloszenie INTEGER)');
$checks = 0;
$check = static function (bool $condition, string $description) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($description); }
    $checks++;
};
$expectDuplicate = static function (callable $operation) use ($check): void {
    try { $operation(); }
    catch (LotteryDuplicateReceiptException $exception) {
        $check($exception->getMessage() === 'Ten dowód zakupu został już zgłoszony.', 'Komunikat o wykorzystanym dowodzie');
        return;
    }
    throw new RuntimeException('Brak odrzucenia duplikatu');
};
$alice = ['email' => 'alice@example.com', 'phone' => '501234567'];
$bob = ['email' => 'bob@example.com', 'phone' => '601234567'];
$date = new DateTimeImmutable('2026-10-13 12:00:00', new DateTimeZone('Europe/Warsaw'));
$grant = static function (string $number, string $nip, array $participant, ?DateTimeImmutable $at = null, bool $fail = false) use ($connection, $date): void {
    $receipt = LotteryReceiptValidator::normalize(['purchase_document_number' => $number, 'store_nip' => $nip]);
    $at = $at ?? $date;
    (new LotteryDailyEntryLimitService($connection))->allocate(
        [], ['scratchcards' => 'scratchcards'], ['scratchcards' => 5], $at, $participant,
        static function (array $counts) use ($connection, $receipt, $participant, $at, $fail): void {
            $connection->insert('submissions', array_merge($receipt, $participant, ['submitted_at' => $at->format('Y-m-d H:i:s'), 'code_used' => 1]));
            $id = (int) $connection->lastInsertId();
            for ($i = 0; $i < $counts['scratchcards']; $i++) { $connection->insert('scratchcards', ['zgloszenie' => $id]); }
            if ($fail) { throw new RuntimeException('Błąd zapisu'); }
        }, null, null, array_merge(['table' => 'submissions'], $receipt)
    );
};

$grant('00123/ABC', '5260250274', $alice);
$check((int) $connection->fetchOne('SELECT COUNT(*) FROM scratchcards') === 5, 'Pierwszy dowód przyznaje zdrapki');
$expectDuplicate(static function () use ($grant, $alice): void { $grant('00123/ABC', '5260250274', $alice); });
$expectDuplicate(static function () use ($grant, $bob): void { $grant('00123/ABC', '5260250274', $bob); });
$expectDuplicate(static function () use ($grant, $bob, $date): void { $grant('00123/ABC', '5260250274', $bob, $date->modify('+1 day')); });
$expectDuplicate(static function () use ($grant, $bob): void { $grant(' 00123/abc ', '526-025-02-74', $bob); });
$check((int) $connection->fetchOne('SELECT COUNT(*) FROM submissions') === 1 && (int) $connection->fetchOne('SELECT COUNT(*) FROM scratchcards') === 5, 'Duplikaty nie tworzą zgłoszeń, zdrapek ani zużycia kodu');

$grant('00123/ABC', '5252248481', $bob);
$grant('00124/ABC', '5260250274', $bob);
$grant('123/ABC', '5260250274', $bob);
$check((int) $connection->fetchOne('SELECT COUNT(*) FROM submissions') === 4, 'Inny NIP, inny numer i różne zera wiodące są odrębnymi dowodami');

$connection->insert('submissions', ['purchase_document_number' => ' OLD/1 ', 'store_nip' => " 526-025 02\t74\r\n"]);
$expectDuplicate(static function () use ($grant, $alice): void { $grant('old/1', '5260250274', $alice); });

LotteryReceiptValidator::assertAvailable($connection, 'submissions', ['purchase_document_number' => '00123/ABC', 'store_nip' => '5260250274'], 1);
$check(true, 'Edycja nie jest duplikatem własnego zgłoszenia');
$expectDuplicate(static function () use ($connection): void {
    LotteryReceiptValidator::assertAvailable($connection, 'submissions', ['purchase_document_number' => '00124/ABC', 'store_nip' => '5260250274'], 1);
});

// A successful preliminary check cannot bypass the check performed under the write lock.
$racingReceipt = ['purchase_document_number' => 'RACE/1', 'store_nip' => '5260250274'];
LotteryReceiptValidator::assertAvailable($connection, 'submissions', $racingReceipt);
$grant('RACE/1', '5260250274', $alice);
$expectDuplicate(static function () use ($grant, $bob): void { $grant('RACE/1', '5260250274', $bob); });
$check((int) $connection->fetchOne("SELECT COUNT(*) FROM submissions WHERE purchase_document_number = 'RACE/1'") === 1, 'Powtórne sprawdzenie wykrywa zapis wykonany po sprawdzeniu wstępnym');

try { $grant('RETRY/1', '5260250274', $alice, null, true); }
catch (RuntimeException $exception) { $check($exception->getMessage() === 'Błąd zapisu', 'Błąd zapisu jest propagowany'); }
$check((int) $connection->fetchOne("SELECT COUNT(*) FROM submissions WHERE purchase_document_number = 'RETRY/1'") === 0, 'Rollback nie rezerwuje numeru paragonu');
$grant('RETRY/1', '5260250274', $alice);
$check((int) $connection->fetchOne("SELECT COUNT(*) FROM submissions WHERE purchase_document_number = 'RETRY/1'") === 1, 'Po nieudanym zapisie dowód można zgłosić ponownie');

foreach ([['purchase_document_number' => '', 'store_nip' => '5260250274'], ['purchase_document_number' => 'MISSING/1', 'store_nip' => '']] as $receipt) {
    try { LotteryReceiptValidator::assertAvailable($connection, 'submissions', $receipt, 1); }
    catch (LotteryDailyLimitException $exception) { $check(true, 'Brak części klucza nie pozwala ominąć kontroli w panelu'); continue; }
    throw new RuntimeException('Dopuszczono niepełne dane dowodu');
}
$check(!$connection->isTransactionActive(), 'Sukces i odrzucenie zamykają transakcję');
foreach ($connection->events as $index => $event) {
    $check($event[0] === ($index % 2 === 0 ? 'SELECT GET_LOCK(?, 5)' : 'SELECT RELEASE_LOCK(?)')
        && $event[1] === false && $event[2] === 'cms_lottery_entry_allocation', 'Sprawdzenie i zapis korzystają ze wspólnej blokady zwalnianej dopiero po transakcji');
}

// Exercise the actual controller methods used by manual creation, CSV import and receipt-only edits.
function receiptControllerMethod(string $name, string $controller = 'ModuleController'): string
{
    $source = file_get_contents(dirname(__DIR__) . '/src/Controller/' . $controller . '.php');
    if (!preg_match('/(?:private|protected|public) function ' . preg_quote($name, '/') . '\b/', $source, $match, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException('Brak metody ' . $name);
    }
    $result = '';
    $depth = 0;
    $started = false;
    foreach (token_get_all('<?php ' . substr($source, $match[0][1])) as $token) {
        if (is_array($token)) {
            if ($token[0] !== T_OPEN_TAG) { $result .= $token[1]; }
        } else {
            $result .= $token;
            if ($token === '{') { $started = true; $depth++; }
            if ($token === '}') { $depth--; }
            if ($started && $depth === 0) { return $result; }
        }
    }
    throw new RuntimeException('Niepełna metoda ' . $name);
}
eval('use Doctrine\DBAL\Connection; use App\Service\LotteryDailyEntryLimitService; use App\Service\LotteryProductAllocation; use App\Service\LotteryRegistrationValidator; use App\Service\LotteryReceiptValidator;
class ReceiptCmsHarness {
    protected array $config = ["app" => ["timezone" => "Europe/Warsaw"]];
    private function fetchModuleEntry($key) { return ["daily_product_limit" => 0]; }
    private function resolveModuleDefinition($key) { return ["database" => ["table" => $key === "loteria_zgloszenia" ? "submissions" : ($key === "loteria_zdrapki" ? "scratchcards" : null)]]; }
    public function insert(Connection $connection, array &$values): string { return $this->insertModuleEntry($connection, "loteria_zgloszenia", "submissions", $values); }
    public function edit(Connection $connection, int $id, array $values): void { $this->updateLotterySubmission($connection, "submissions", $values, $id, static function () use ($connection, $id, $values): void { $connection->update("submissions", $values, ["id" => $id]); }); }
    public function rebuild(Connection $connection, int $id, array $values): void { $this->rebuildLotteryTickets($connection, $values, $id, static function () use ($connection, $id, $values): void { $connection->update("submissions", $values, ["id" => $id]); }); }
' . receiptControllerMethod('insertModuleEntry') . receiptControllerMethod('updateLotterySubmission') . receiptControllerMethod('rebuildLotteryTickets')
    . str_replace('mixed $', '$', receiptControllerMethod('normalizeLotteryAmount'))
    . receiptControllerMethod('resolveLotteryEntryGenerationMode', 'BaseController')
    . receiptControllerMethod('shouldGenerateLotteryTickets', 'BaseController')
    . receiptControllerMethod('shouldGenerateLotteryScratchcards', 'BaseController') . '}');
$cms = new ReceiptCmsHarness();
$cmsValues = array_merge($bob, ['purchase_document_number' => ' CMS/1 ', 'store_nip' => '526-025-02-74', 'promoted_products_count' => 1, 'other_products_count' => 0]);
$cmsId = (int) $cms->insert($connection, $cmsValues);
$check($cmsValues['purchase_document_number'] === 'CMS/1' && $cmsValues['store_nip'] === '5260250274', 'CMS/CSV zapisuje znormalizowany klucz dowodu');
$expectDuplicate(static function () use ($cms, $connection, $cmsValues): void { $values = $cmsValues; $cms->insert($connection, $values); });
$check((int) $connection->fetchOne("SELECT COUNT(*) FROM submissions WHERE purchase_document_number = 'CMS/1'") === 1, 'Ponowny import CMS/CSV nie tworzy duplikatu');
$scratchcardsBefore = $connection->fetchAllAssociative('SELECT * FROM scratchcards ORDER BY id');
$cms->edit($connection, $cmsId, ['purchase_document_number' => 'CMS/1', 'store_nip' => '5260250274', 'phone' => '701234567']);
$check($connection->fetchOne('SELECT phone FROM submissions WHERE id = ?', [$cmsId]) === '701234567', 'Panel pozwala edytować własny dowód');
$expectDuplicate(static function () use ($cms, $connection, $cmsId): void {
    $cms->edit($connection, $cmsId, ['purchase_document_number' => '00123/ABC', 'store_nip' => '5260250274']);
});
$check($connection->fetchOne('SELECT purchase_document_number FROM submissions WHERE id = ?', [$cmsId]) === 'CMS/1', 'Odrzucona edycja zachowuje wcześniejszy numer');
$expectDuplicate(static function () use ($cms, $connection, $cmsId, $cmsValues): void {
    $cms->rebuild($connection, $cmsId, array_replace($cmsValues, ['purchase_document_number' => '00123/ABC', 'store_nip' => '5260250274', 'promoted_products_count' => 2]));
});
$check($connection->fetchOne('SELECT purchase_document_number FROM submissions WHERE id = ?', [$cmsId]) === 'CMS/1', 'Przeliczenie produktów z duplikatem również zachowuje wcześniejsze zgłoszenie');
$check($connection->fetchAllAssociative('SELECT * FROM scratchcards ORDER BY id') === $scratchcardsBefore, 'Edycja danych paragonu nie regeneruje ani nie usuwa zdrapek');
echo sprintf("OK: %d sprawdzeń unikalności dowodów, normalizacji, rollback, blokady zapisu i rzeczywistych metod CMS/CSV.\n", $checks);
