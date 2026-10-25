<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class LotteryReceiptValidator
{
    /** @return array{purchase_document_number: string, store_nip: string} */
    public static function normalize(array $receipt): array
    {
        return [
            'purchase_document_number' => trim((string) ($receipt['purchase_document_number'] ?? '')),
            'store_nip' => LotteryRegistrationValidator::normalizeNip((string) ($receipt['store_nip'] ?? '')),
        ];
    }

    /** Before writing, repeat this check under the allocation lock and hold it until commit. */
    public static function assertAvailable(Connection $connection, string $table, array $receipt, ?int $excludeSubmissionId = null): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table)) {
            throw new \InvalidArgumentException('Nieprawidłowa tabela zgłoszeń.');
        }
        $receipt = self::normalize($receipt);
        if ($receipt['purchase_document_number'] === '' || $receipt['store_nip'] === '') {
            throw new LotteryDailyLimitException('Numer dowodu zakupu i NIP sklepu są wymagane.');
        }

        // Also recognize older CMS/import values saved before NIP normalization was introduced.
        $nipExpression = 'TRIM(store_nip)';
        foreach (['-', ' ', "\t", "\r", "\n"] as $separator) {
            $nipExpression = 'REPLACE(' . $nipExpression . ', ' . $connection->quote($separator) . ", '')";
        }
        $query = $connection->createQueryBuilder()
            ->select('id')->from($table)
            ->where('LOWER(TRIM(purchase_document_number)) = :documentNumber')
            ->andWhere($nipExpression . ' = :storeNip')
            ->setParameter('documentNumber', mb_strtolower($receipt['purchase_document_number']))
            ->setParameter('storeNip', $receipt['store_nip'])
            ->setMaxResults(1);
        if ($excludeSubmissionId !== null) {
            $query->andWhere('id <> :submissionId')->setParameter('submissionId', $excludeSubmissionId);
        }
        if ($query->executeQuery()->fetchOne() !== false) {
            throw new LotteryDuplicateReceiptException();
        }
    }
}
