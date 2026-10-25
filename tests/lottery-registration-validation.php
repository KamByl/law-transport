<?php

require dirname(__DIR__) . '/src/Service/LotteryRegistrationValidator.php';

use App\Service\LotteryRegistrationValidator as Validator;

$checks = 0;
$check = static function (bool $condition, string $description) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($description);
    }
    $checks++;
};

foreach (['45', '50', '51', '53', '57', '60', '66', '69', '72', '73', '78', '79', '88'] as $prefix) {
    $check(preg_match(Validator::MOBILE_PHONE_PATTERN, $prefix . '1234567') === 1, 'Poprawny prefiks komórkowy: ' . $prefix);
}
foreach (['221234567', '481234567', '391234567', '50123456', '5012345678', '48501123456', '501234567' . "\n"] as $phone) {
    $check(preg_match(Validator::MOBILE_PHONE_PATTERN, $phone) === 0, 'Odrzucony nieprawidłowy numer: ' . $phone);
}
$check(Validator::normalizePhone('+48 501 - 234 - 567') === '501234567', 'Usunięcie prefiksu kraju i formatowania telefonu');
$check(Validator::normalizePhone('0048 501 234 567') === '501234567', 'Prefiks 0048');
$check(Validator::normalizePhone('481234567') === '481234567', 'Numer krajowy rozpoczynający się od 48 nie może zostać skrócony');
$check(Validator::normalizePhone('+49 501234567') === '+49501234567', 'Zagraniczny prefiks nie może zostać usunięty');
$check(Validator::isValidNip('526-025-09-95'), 'Poprawny NIP z separatorami');
foreach (['5260250994', '0000000000', '12345', 'PL5260250995', '526025099x'] as $nip) {
    $check(!Validator::isValidNip($nip), 'Odrzucony NIP: ' . $nip);
}

$validData = [
    'purchase_document_number' => '123/2026',
    'promoted_products_count' => '1',
    'other_products_count' => '0',
    'store_chain' => '7',
    'store_nip' => '526-025-09-95',
];
$check(Validator::validatePurchaseData($validData, [7]) === [], 'Poprawne zgłoszenie, w tym zero pozostałych produktów');
$check(Validator::validatePurchaseData(array_replace($validData, ['promoted_products_count' => '0', 'other_products_count' => '2']), [7]) === [], 'Zgłoszenie samych pozostałych produktów');
$check(count(Validator::validatePurchaseData([], [7])) === 5, 'Wszystkie dane zakupu są wymagane');
$check(isset(Validator::validatePurchaseData($validData, [])['store_chain']), 'Brak aktywnych sieci blokuje zgłoszenie');
foreach (['0', '-1', '1.5', '1e2', '2147483648', ''] as $count) {
    $data = array_replace($validData, ['promoted_products_count' => $count]);
    $check(isset(Validator::validatePurchaseData($data, [7])['promoted_products_count']), 'Nieprawidłowa liczba produktów promowanych: ' . $count);
}
foreach (['-1', '0.5', '1e2', '2147483648', ''] as $count) {
    $data = array_replace($validData, ['other_products_count' => $count]);
    $check(isset(Validator::validatePurchaseData($data, [7])['other_products_count']), 'Nieprawidłowa liczba pozostałych produktów: ' . $count);
}
foreach (['999', '7 OR 1=1', ['7']] as $chain) {
    $check(isset(Validator::validatePurchaseData(array_replace($validData, ['store_chain' => $chain]), [7])['store_chain']), 'Sieć musi pochodzić z aktywnej listy');
}
$check(isset(Validator::validatePurchaseData(array_replace($validData, ['purchase_document_number' => str_repeat('a', 256)]), [7])['purchase_document_number']), 'Limit długości numeru dowodu zakupu');
$check(Validator::validatePurchaseData(array_replace($validData, ['promoted_products_count' => '2147483647', 'other_products_count' => '2147483647']), [7]) === [], 'Granica pojemności kolumn liczbowych');

echo sprintf("OK: %d sprawdzeń walidacji zgłoszenia.\n", $checks);
