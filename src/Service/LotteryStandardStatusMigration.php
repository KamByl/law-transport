<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/** Undo the separate prize catalogs without discarding manually configured statuses. */
final class LotteryStandardStatusMigration
{
    public static function migrate(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        if (!$schema->tablesExist(['ustawienia_statusow'])) {
            return;
        }
        $columns = $schema->listTableColumns('ustawienia_statusow');
        if (!isset($columns['status_scope'], $columns['legacy_status_id'])) {
            return;
        }

        $hasWinners = $schema->tablesExist(['loteria_zwyciezcy_entries']);
        $hasPrizes = $schema->tablesExist(['rodzaje_nagrod_entries']);
        $connection->transactional(static function () use ($connection, $hasWinners, $hasPrizes): void {
            $statuses = [];
            foreach ($connection->fetchAllAssociative('SELECT * FROM ustawienia_statusow') as $row) {
                $statuses[(string) $row['id']] = $row;
            }
            $mapping = [];
            $retained = [];
            foreach ($statuses as $id => $row) {
                if (!in_array($row['status_scope'], ['losy', 'zdrapki'], true)) {
                    continue;
                }
                $originalId = (string) ($row['legacy_status_id'] ?? '');
                $original = $statuses[$originalId] ?? null;
                $copy = $row;
                unset($copy['id'], $copy['status_scope'], $copy['legacy_status_id']);
                if ($original !== null && $original['status_scope'] === 'zgloszenia') {
                    unset($original['id'], $original['status_scope'], $original['legacy_status_id']);
                    if ($copy === $original) {
                        $mapping[(string) $id] = $originalId;
                        continue;
                    }
                }
                $retained[] = $id;
            }

            // Update history as well as current statuses, preserving mail deduplication.
            if ($mapping !== [] && $hasWinners) {
                foreach ($connection->fetchAllAssociative('SELECT * FROM loteria_zwyciezcy_entries') as $winner) {
                    $update = [];
                    $status = (string) ($winner['stan'] ?? '');
                    if (isset($mapping[$status])) {
                        $update['stan'] = $mapping[$status];
                    }
                    $history = json_decode((string) ($winner['historia_wysylek_email_status'] ?? ''), true);
                    $historyChanged = false;
                    if (is_array($history)) {
                        foreach ($history as &$entry) {
                            if (is_array($entry) && isset($mapping[(string) ($entry['status_id'] ?? '')])) {
                                $entry['status_id'] = $mapping[(string) $entry['status_id']];
                                $historyChanged = true;
                            }
                        }
                        unset($entry);
                    }
                    if ($historyChanged) {
                        $update['historia_wysylek_email_status'] = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    }
                    if ($update !== []) {
                        $connection->update('loteria_zwyciezcy_entries', $update, ['id' => $winner['id']]);
                    }
                }
            }
            if ($mapping !== [] && $hasPrizes) {
                foreach ($connection->fetchAllAssociative('SELECT * FROM rodzaje_nagrod_entries') as $prize) {
                    $update = [];
                    foreach (['status_po_wygraniu_nagrody', 'status_po_wygraniu_nagrody_gdy_brak_kodow'] as $field) {
                        $status = (string) ($prize[$field] ?? '');
                        if (isset($mapping[$status])) {
                            $update[$field] = $mapping[$status];
                        }
                    }
                    if ($update !== []) {
                        $connection->update('rodzaje_nagrod_entries', $update, ['id' => $prize['id']]);
                    }
                }
            }
            foreach ($mapping as $id => $originalId) {
                $connection->delete('ustawienia_statusow', ['id' => $id]);
            }
            foreach ($retained as $id) {
                $connection->update('ustawienia_statusow', [
                    'status_scope' => 'zgloszenia',
                    'legacy_status_id' => null,
                ], ['id' => $id]);
            }
        });
    }
}
