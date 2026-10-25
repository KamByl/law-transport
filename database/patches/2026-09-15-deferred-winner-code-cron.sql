-- Adds the status flag and winner index used by the deferred code assignment CRON.
-- Existing winner and status data is not changed.

SET @schema_name := DATABASE();

SET @sql := IF(
    (
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'ustawienia_statusow'
          AND COLUMN_NAME = 'do_wyslania_kodow'
    ) = 0,
    'ALTER TABLE ustawienia_statusow ADD COLUMN do_wyslania_kodow TINYINT(1) DEFAULT NULL AFTER do_wysylki',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'loteria_zwyciezcy_entries'
          AND INDEX_NAME = 'idx_winners_deferred_codes'
    ) = 0,
    'ALTER TABLE loteria_zwyciezcy_entries ADD INDEX idx_winners_deferred_codes (stan, rodzaj_wygranej, id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
