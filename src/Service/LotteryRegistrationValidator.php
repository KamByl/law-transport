<?php

namespace App\Service;

class LotteryRegistrationValidator
{
    // Wyróżniki sieci ruchomych z planu numeracji krajowej (Dz.U. 2024 poz. 1862).
    public const MOBILE_PHONE_PATTERN = '/^(?:45|50|51|53|57|60|66|69|72|73|78|79|88)[0-9]{7}$/D';
    public const MAX_RECEIPT_SIZE = 15 * 1024 * 1024;

    public static function normalizePhone(string $value): string
    {
        $value = preg_replace('/[\s()-]+/', '', trim($value)) ?? '';
        return preg_replace('/^(?:\+48|0048)/', '', $value) ?? '';
    }

    public static function normalizeNip(string $value): string
    {
        return preg_replace('/[\s-]+/', '', trim($value)) ?? '';
    }

    public static function isValidNip(string $value): bool
    {
        $nip = self::normalizeNip($value);
        if (!preg_match('/^[0-9]{10}$/D', $nip) || $nip === '0000000000') {
            return false;
        }

        $sum = 0;
        foreach ([6, 5, 7, 2, 3, 4, 5, 6, 7] as $index => $weight) {
            $sum += (int) $nip[$index] * $weight;
        }

        return $sum % 11 === (int) $nip[9];
    }

    /** @return array<string, string> */
    public static function validatePurchaseData(array $data, array $activeChainIds): array
    {
        $errors = [];
        $documentNumber = self::scalarValue($data['purchase_document_number'] ?? '');
        if ($documentNumber === '') {
            $errors['purchase_document_number'] = 'Numer dowodu zakupu jest wymagany.';
        } elseif (mb_strlen($documentNumber) > 255) {
            $errors['purchase_document_number'] = 'Numer dowodu zakupu może mieć maksymalnie 255 znaków.';
        }

        foreach (['promoted_products_count', 'other_products_count'] as $field) {
            $value = self::scalarValue($data[$field] ?? '');
            if ($value === '') {
                $errors[$field] = 'Podanie liczby produktów jest wymagane.';
            } elseif (!preg_match('/^[0-9]+$/D', $value) || (float) $value > 2147483647) {
                $errors[$field] = 'Liczba produktów musi być liczbą całkowitą co najmniej 0.';
            }
        }
        if (!isset($errors['promoted_products_count']) && !isset($errors['other_products_count'])
            && (int) $data['promoted_products_count'] + (int) $data['other_products_count'] === 0
        ) {
            $errors['promoted_products_count'] = 'Zgłoś co najmniej jeden produkt promowany lub pozostały.';
            $errors['other_products_count'] = 'Zgłoś co najmniej jeden produkt promowany lub pozostały.';
        }

        $chainId = self::scalarValue($data['store_chain'] ?? '');
        if ($chainId === '' || !in_array($chainId, array_map('strval', $activeChainIds), true)) {
            $errors['store_chain'] = 'Wybierz sieć sklepu z dostępnej listy.';
        }

        $nip = self::scalarValue($data['store_nip'] ?? '');
        if ($nip === '') {
            $errors['store_nip'] = 'NIP sklepu jest wymagany.';
        } elseif (!self::isValidNip($nip)) {
            $errors['store_nip'] = 'Wpisz poprawny, dziesięciocyfrowy NIP sklepu z dowodu zakupu.';
        }

        return $errors;
    }

    private static function scalarValue($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
