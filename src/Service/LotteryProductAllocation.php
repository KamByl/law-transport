<?php

namespace App\Service;

class LotteryProductAllocation
{
    public static function isProductBased(array $settings): bool
    {
        return ($settings['entry_calculation_basis'] ?? 'products') !== 'amount';
    }

    public static function setting(array $settings, string $field, int $default): int
    {
        $value = $settings[$field] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_scalar($value) || !preg_match('/^[0-9]+$/D', (string) $value) || (float) $value > 2147483647) {
            throw new LotteryDailyLimitException('Ustawienia naliczania produktów muszą zawierać nieujemne liczby całkowite.');
        }
        return (int) $value;
    }

    /** @return array{promoted_products_count: int, other_products_count: int} */
    public static function quantities(array $data): array
    {
        $quantities = [];
        foreach (['promoted_products_count', 'other_products_count'] as $field) {
            $value = $data[$field] ?? 0;
            if (!is_scalar($value) || !preg_match('/^[0-9]+$/D', (string) $value) || (float) $value > 2147483647) {
                throw new LotteryDailyLimitException('Liczby zakupionych produktów muszą być nieujemnymi liczbami całkowitymi.');
            }
            $quantities[$field] = (int) $value;
        }
        return $quantities;
    }

    public static function entryCount(array $data, array $settings): int
    {
        $quantities = self::quantities($data);
        if (array_sum($quantities) === 0) {
            throw new LotteryDailyLimitException('Zgłoszenie musi zawierać co najmniej jeden zakupiony produkt.');
        }
        $promotedWeight = self::setting($settings, 'promoted_product_entry_count', 5);
        $otherWeight = self::setting($settings, 'other_product_entry_count', 1);
        $count = $quantities['promoted_products_count'] * $promotedWeight
            + $quantities['other_products_count'] * $otherWeight;
        if ($count > 2147483647) {
            throw new LotteryDailyLimitException('Liczba losów lub e-zdrapek wynikająca ze zgłoszenia jest zbyt duża.');
        }
        if ($count === 0) {
            throw new LotteryDailyLimitException('Podane produkty nie uprawniają do przyznania losów ani e-zdrapek przy obecnych ustawieniach loterii.');
        }
        return (int) $count;
    }

    /** Uses promoted products first, then ordinary products within the remaining allowance. */
    public static function prioritize(array $data, ?int $availableProducts): array
    {
        $quantities = self::quantities($data);
        if ($availableProducts === null) {
            return $quantities;
        }
        $promoted = min($quantities['promoted_products_count'], max(0, $availableProducts));
        $other = min($quantities['other_products_count'], max(0, $availableProducts - $promoted));
        return ['promoted_products_count' => $promoted, 'other_products_count' => $other];
    }

    /** Preliminary request; the actual remaining daily allowance is checked under the DB lock. */
    public static function requestedEntryCount(array $data, array $settings): int
    {
        $limit = self::setting($settings, 'daily_product_limit', 4);
        return self::entryCount(self::prioritize($data, $limit > 0 ? $limit : null), $settings);
    }
}
