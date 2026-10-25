<?php

// php tests/lottery-scratchcards.php [ścieżka do vendor/autoload.php]
require $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/Service/LotteryScratchcardAccess.php';

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySqlPlatform;

function scratchMethod(string $name): string
{
    $source = file_get_contents(dirname(__DIR__) . '/src/Controller/FrontendController.php');
    preg_match('/(?:private|public) function ' . preg_quote($name, '/') . '\b/', $source, $match, PREG_OFFSET_CAPTURE);
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

// The local CLI is PHP 7.4. Adapt only syntax of PHP 8's mixed, noncapturing catch and match.
foreach (['NearestPastHourScratchcardWinnerSelector', 'EveryScratchcardWinsSelector', 'EveryScratchcardWinsWithPrioritySelector'] as $class) {
    $source = file_get_contents(dirname(__DIR__) . '/src/Service/ScratchcardWinnerSelection/' . $class . '.php');
    $source = str_replace(['<?php', 'mixed $', 'catch (\Throwable)'], ['', '$', 'catch (\Throwable $ignored)'], $source);
    eval($source);
}
$methods = '';
foreach (['checkLosowaneZdrapkiTicket', 'resolveLosowaneZdrapkiTicket', 'revealRemainingScratchcards', 'buildScratchcardsLink', 'buildWinnerLink', 'generateScratchcardsToken', 'resolveScratchcardsTokenForEmail', 'sendLotterySubmissionEmail'] as $name) {
    $methods .= scratchMethod($name);
}
$methods = preg_replace('/\$winnerSelector = match \(\$scratchcardPrizeDrawType\) \{.*?\};/s', '\$winnerSelector = (function ($type) {
    switch ($type) {
        case "every_scratchcard_wins": return new EveryScratchcardWinsSelector();
        case "every_scratchcard_wins_with_priority": return new EveryScratchcardWinsWithPrioritySelector();
        default: return new NearestPastHourScratchcardWinnerSelector();
    }
})($scratchcardPrizeDrawType);', $methods);
$methods = str_replace("        } catch (\\Throwable \$exception) {\n            if (\$connection->isTransactionActive())", "        } catch (\\Throwable \$exception) {\n            fwrite(STDERR, \$exception->getMessage() . PHP_EOL);\n            if (\$connection->isTransactionActive())", $methods);
eval('use Doctrine\DBAL\Connection; use App\Service\LotteryScratchcardAccess; use App\Service\ScratchcardWinnerSelection\NearestPastHourScratchcardWinnerSelector;
use App\Service\ScratchcardWinnerSelection\EveryScratchcardWinsSelector; use App\Service\ScratchcardWinnerSelection\EveryScratchcardWinsWithPrioritySelector;
use Symfony\Component\HttpFoundation\Request; use Symfony\Component\HttpFoundation\Response; use Symfony\Component\HttpFoundation\JsonResponse; use Symfony\Component\HttpFoundation\RedirectResponse;
class ScratchHarness {
    public $entityManager;
    public $mailer;
    public array $settings = [];
    private function requireAgeVerification($request) { return null; }
    private function resolveModuleDefinition($key) { return ["database" => ["table" => ["loteria_zgloszenia" => "submissions", "loteria_zdrapki" => "cards", "godziny_wygranych" => "schedule", "zwyciezcy" => "winners", "rodzaje_nagrod" => "prizes", "email_templates" => "mail_templates"][$key]]]; }
    private function fetchModuleEntry($key) { return $key === "loteria_ustawienia" ? $this->settings : []; }
    private function resolveDefaultScratchLottery() { return []; }
    private function resolveUpcomingLottery($settings) { return []; }
    private function resolveCurrentLottery($settings) { return []; }
    private function resolveConsumerFaqEntries($settings) { return []; }
    private function fetchModuleEntries($key) { return []; }
    private function resolveRulesContent($entries) { return []; }
    private function resolveFrontendWinnersPreview($limit) { return ["winners" => [], "hasMore" => false]; }
    private function resolveFrontendScheduleDraws() { return []; }
    private function buildSellerRegistrationUrl($id, $centers) { return ""; }
    private function resolveRegistrationPageTemplate($settings) { return "frontend/zywiec.html.twig"; }
    private function issueLotteryFormToken() { return "form-token"; }
    private function render($template, $context) { return new JsonResponse($context); }
    private function resolveScratchcardPrizeTypeIdForWinnerTicket() { return 0; }
    private function resolveScratchcardPrizeTypeIdForTicket() { return 0; }
    private function fetchScratchcardPrizeTypeByName() { return []; }
    private function sendLotteryWinnerEmail() { throw new RuntimeException("QA: no outbound email"); }
    private function resolveTemplateBcc($template) { return []; }
    private function isPriorityEmailTemplate($template) { return false; }
    public function sendSubmission() { $this->sendLotterySubmissionEmail("alice@example.com", ["phone" => "501234567"], new DateTimeImmutable(), "https://lottery.example/loteria/zdrapki/alice-token", 3, 3, 3); }
    public function token($connection, $email) { return $this->resolveScratchcardsTokenForEmail($connection, "submissions", $email); }
' . $methods . '}');

$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$db->executeStatement('CREATE TABLE submissions (id INTEGER PRIMARY KEY, email TEXT, phone TEXT, scratch_link_token TEXT)');
$db->executeStatement('CREATE TABLE cards (id INTEGER PRIMARY KEY, ticket_id TEXT, zgloszenie INTEGER, wygrany INTEGER, status TEXT, clicked_at TEXT, clicked_at_microseconds TEXT, win_checked_at TEXT, win_diff_microseconds INTEGER, win_schedule_id INTEGER, prize_name TEXT, voucher_code TEXT)');
$db->executeStatement('CREATE TABLE schedule (id INTEGER PRIMARY KEY, win_at TEXT, granted INTEGER, granted_to_ticket_id INTEGER, granted_to_submission_id INTEGER, granted_at TEXT, prize_name TEXT)');
$db->executeStatement('CREATE TABLE winners (id INTEGER PRIMARY KEY, email TEXT, telefon TEXT, token_formularza TEXT, zdrapka_w_loterii INTEGER, zgloszenie INTEGER, stan TEXT, rodzaj TEXT)');
$db->executeStatement('CREATE TABLE prizes (id INTEGER PRIMARY KEY)');
$db->executeStatement('CREATE TABLE mail_templates (id INTEGER PRIMARY KEY, subject TEXT, body TEXT)');
foreach ([[1, 'alice@example.com', 'alice-token'], [2, 'alice@example.com', 'alice-token'], [3, 'bob@example.com', 'bob-token']] as [$id, $email, $token]) {
    $db->insert('submissions', ['id' => $id, 'email' => $email, 'scratch_link_token' => $token]);
}
foreach ([[1, '1-01', 1], [2, '1-02', 1], [3, '2-01', 2], [4, '3-01', 3]] as [$id, $ticket, $submission]) {
    $db->insert('cards', ['id' => $id, 'ticket_id' => $ticket, 'zgloszenie' => $submission, 'status' => 'Nie zdrapana', 'wygrany' => 0]);
}
$db->insert('schedule', ['id' => 1, 'win_at' => (new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), 'granted' => 0, 'prize_name' => 'Nagroda testowa']);
$harness = new ScratchHarness();
$harness->entityManager = new class($db) {
    private Connection $connection;
    public function __construct(Connection $connection) { $this->connection = $connection; }
    public function getConnection(): Connection { return $this->connection; }
};
$checks = 0;
$check = static function (bool $ok, string $description) use (&$checks): void { if (!$ok) { throw new RuntimeException($description); } $checks++; };
$request = Symfony\Component\HttpFoundation\Request::create('https://lottery.example/loteria/zdrapki/alice-token');
$linkResponse = $harness->revealRemainingScratchcards($request, ['token' => 'alice-token']);
$context = json_decode($linkResponse->getContent(), true);
$check(count($context['lotterySubmission']['ticket_entries']) === 3, 'Link łączy nieaktywowane zdrapki z wielu zgłoszeń');
$check((int) $db->fetchOne('SELECT COUNT(*) FROM cards WHERE win_checked_at IS NOT NULL') === 0 && (int) $db->fetchOne('SELECT COUNT(*) FROM winners') === 0, 'Wejście w link nie ustala wyników i nie przyznaje nagród');
$check($linkResponse->headers->get('Referrer-Policy') === 'no-referrer', 'Prywatny link nie jest przekazywany jako referrer');
$check($harness->token($db, ' ALICE@EXAMPLE.COM ') === 'alice-token', 'Kolejne zgłoszenie używa linku uczestnika po normalizacji e-maila');
$activate = static function (string $token, string $ticket = '1-01', int $submission = 1) use ($harness) {
    $request = Symfony\Component\HttpFoundation\Request::create('https://lottery.example/loteria/losowane-zdrapki/sprawdz-wynik', 'POST', [], [], [], [], json_encode(['scratch_token' => $token, 'ticket_id' => $ticket, 'submission_id' => $submission]));
    return $harness->checkLosowaneZdrapkiTicket($request);
};
$check($activate('bob-token')->getStatusCode() === 403 && $activate('')->getStatusCode() === 403, 'Cudzy lub pusty token nie aktywuje zdrapki');
$response = $activate('alice-token');
$result = json_decode($response->getContent(), true);
$check($response->getStatusCode() === 200 && $result['is_winner'] === true && $result['already_checked'] === false, 'Kliknięcie ustala wygraną według istniejącego mechanizmu');
$firstCard = $db->fetchAssociative('SELECT * FROM cards WHERE id = 1');
$check($firstCard['clicked_at'] !== null && $firstCard['win_checked_at'] !== null && $firstCard['status'] === 'Zdrapana', 'Kliknięcie zapisuje czas i wynik aktywacji');
$check(count($result['ticket_entries']) === 2 && (int) $db->fetchOne('SELECT COUNT(*) FROM cards WHERE win_checked_at IS NOT NULL') === 1, 'Aktywowana jest dokładnie jedna zdrapka');
$check((int) $db->fetchOne('SELECT COUNT(*) FROM winners') === 1, 'Nagroda pojawia się dopiero po kliknięciu');
$retry = json_decode($activate('alice-token')->getContent(), true);
$check($retry['already_checked'] === true && $retry['is_winner'] === true && $db->fetchAssociative('SELECT * FROM cards WHERE id = 1') === $firstCard, 'Ponowienie zwraca ten sam wynik i nie zmienia czasu');
$check((int) $db->fetchOne('SELECT COUNT(*) FROM winners') === 1, 'Ponowienie nie przyznaje drugiej nagrody');
$second = json_decode($activate('alice-token', '1-02')->getContent(), true);
$check($second['is_winner'] === false && count($second['ticket_entries']) === 1, 'Kolejna zdrapka jest sprawdzana osobno');
$remaining = json_decode($harness->revealRemainingScratchcards($request, ['token' => 'alice-token'])->getContent(), true);
$check(count($remaining['lotterySubmission']['ticket_entries']) === 1, 'Link pokazuje wyłącznie nieaktywowane zdrapki');
$check($activate('alice-token', '3-01', 3)->getStatusCode() === 403, 'Token nie daje dostępu do innego uczestnika');
$check($activate('alice-token', '3-01', 1)->getStatusCode() === 404, 'ID zdrapki musi należeć do wskazanego zgłoszenia');
$source = scratchMethod('submitLotteryRegistration');
$check(strpos($source, 'checkLosowaneZdrapkiTicket(') === false && strpos($source, 'resolveLosowaneZdrapkiTicket(') === false && strpos($source, 'ticket_results') === false, 'Przyjęcie zgłoszenia nie uruchamia sprawdzania ani nie wysyła wyników');

class ScratchLockConnection extends Connection
{
    public array $events = [];
    public bool $busy = false;
    public function getDatabasePlatform() { return new MySqlPlatform(); }
    public function fetchOne(string $query, array $params = [], array $types = [])
    {
        if ($query === 'SELECT GET_LOCK(?, 5)' || $query === 'SELECT RELEASE_LOCK(?)') {
            $this->events[] = [$query, $this->isTransactionActive(), $params[0]];
            return $this->busy && $query === 'SELECT GET_LOCK(?, 5)' ? 0 : 1;
        }
        return parent::fetchOne($query, $params, $types);
    }
}
$lockingDb = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => ScratchLockConnection::class]);
$access = new App\Service\LotteryScratchcardAccess($lockingDb, 'submissions', 'cards');
$called = 0;
$access->withActivationLock(1, '1-01', static function () use ($lockingDb, &$called): void {
    $lockingDb->beginTransaction(); $called++; $lockingDb->commit();
});
$check($called === 1 && $lockingDb->events[0][0] === 'SELECT GET_LOCK(?, 5)' && $lockingDb->events[1][0] === 'SELECT RELEASE_LOCK(?)'
    && $lockingDb->events[1][1] === false && $lockingDb->events[0][2] === $lockingDb->events[1][2], 'Blokada zdrapki obejmuje sprawdzenie i COMMIT');
$lockingDb->busy = true;
try { $access->withActivationLock(1, '1-01', static function () use (&$called): void { $called++; }); }
catch (RuntimeException $exception) { $check($called === 1, 'Zajęta blokada nie uruchamia równoległego sprawdzenia'); }
$lockingDb->busy = false;
try { $access->withActivationLock(1, '1-01', static function (): void { throw new RuntimeException('Błąd'); }); }
catch (RuntimeException $exception) { $check(end($lockingDb->events)[0] === 'SELECT RELEASE_LOCK(?)', 'Błąd również zwalnia blokadę'); }

$twig = new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/templates'));
$twig->addFunction(new Twig\TwigFunction('absolute_url', static function ($path) { return $path; }));
$html = $twig->render('frontend/zywiec.html.twig', $context);
$dom = new DOMDocument(); libxml_use_internal_errors(true); $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
$xpath = new DOMXPath($dom);
$check($xpath->query('//*[@data-scratch-modal and @aria-hidden="true"]')->length === 1
    && $xpath->query('//*[@data-scratch-outcome and @hidden]')->length === 1, 'Po wejściu w link Twig ukrywa okno i wynik');
$check($xpath->query('//*[@data-open-scratch and not(@hidden)]')->length === 1, 'Dostępne zdrapki mają przycisk odkrycia');
$context['lotterySubmission']['ticket_entries'] = [];
$html = $twig->render('frontend/zywiec.html.twig', $context);
$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html); $xpath = new DOMXPath($dom);
$check($xpath->query('//*[@data-open-scratch and @hidden]')->length === 1, 'Pusta kolejka ukrywa przycisk odkrycia');
$db->insert('mail_templates', ['id' => 1, 'subject' => 'Twoje zdrapki', 'body' => '<a href="{link_zdrapki}">Odkryj</a> Liczba: {liczba-zdrapek-za-zgloszenie}']);
$harness->settings['submission_email_template'] = 1;
$harness->mailer = new class {
    public array $messages = [];
    public function send(...$args): void { $this->messages[] = $args; }
};
$harness->sendSubmission();
$check(count($harness->mailer->messages) === 1 && strpos($harness->mailer->messages[0][2], 'https://lottery.example/loteria/zdrapki/alice-token') !== false
    && strpos($harness->mailer->messages[0][2], 'Liczba: 3') !== false, 'Mail po zgłoszeniu zawiera indywidualny link i liczbę e-zdrapek');
echo sprintf("OK: %d sprawdzeń rzeczywistych metod frontendowych: link, aktywacja, wygrana, kolejka, token i idempotentne ponowienie.\n", $checks);
