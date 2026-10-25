<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

final class WinnerDrawingAvailabilityService
{
    /**
     * @return array<int, array{id: string, name: string, drawing_date: string, label: string, selectable: bool}>
     */
    public function fetchDrawingOptions(Connection $connection, ?\DateTimeImmutable $today = null): array
    {
        $rows = $connection->createQueryBuilder()
            ->select('id', 'name', 'drawing_date')
            ->from('losowania_entries')
            ->orderBy('drawing_date', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $selectableOptions = [];
        $disabledOptions = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $drawingDate = trim((string) ($row['drawing_date'] ?? ''));
            $label = $name !== '' ? $name : 'Losowanie ' . $id;
            if ($drawingDate !== '') {
                $label .= ' — ' . $drawingDate;
            }

            $option = [
                'id' => $id,
                'name' => $name,
                'drawing_date' => $drawingDate,
                'label' => $label,
                'selectable' => $this->isDrawingDateSelectable($drawingDate, $today),
            ];

            if ($option['selectable']) {
                $selectableOptions[] = $option;
            } else {
                $disabledOptions[] = $option;
            }
        }

        return array_merge($selectableOptions, $disabledOptions);
    }

    public function isDrawingDateSelectable(mixed $value, ?\DateTimeImmutable $today = null): bool
    {
        $drawingDate = $this->parseDate($value);
        if (!$drawingDate instanceof \DateTimeImmutable) {
            return false;
        }

        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        return $drawingDate <= $today;
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        foreach (['!Y-m-d', '!d.m.Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, substr($value, 0, 10));
            $errors = \DateTimeImmutable::getLastErrors();
            if (
                $date instanceof \DateTimeImmutable
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            ) {
                return $date;
            }
        }

        return null;
    }
}
