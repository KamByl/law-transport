<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

final class WinnerDrawingDateResolver
{
    /** @param array<string, mixed> $winner */
    public function resolve(Connection $connection, array $winner): string
    {
        $drawingId = trim((string) ($winner['losowanie'] ?? ''));
        if ($drawingId === '' || !$connection->createSchemaManager()->tablesExist(['losowania_entries'])) {
            return '';
        }

        $drawingDate = $connection->createQueryBuilder()
            ->select('drawing_date')
            ->from('losowania_entries')
            ->where('id = :id')
            ->setParameter('id', $drawingId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $this->format($drawingDate);
    }

    public function format(mixed $drawingDate): string
    {
        if ($drawingDate instanceof \DateTimeInterface) {
            return $drawingDate->format('d.m.Y');
        }

        $value = trim((string) $drawingDate);
        if ($value === '' || preg_match('/^0{4}-0{2}-0{2}/', $value) === 1) {
            return '';
        }

        try {
            return (new \DateTimeImmutable($value))->format('d.m.Y');
        } catch (\Throwable) {
            return '';
        }
    }
}
