-- Speeds up lottery winning-code lookup and prevents duplicate non-empty pickup codes.
-- Run this once on the production database before testing the lottery form again.

SET @schema_name := DATABASE();

SET @sql := IF(
    (
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'lista_wygranych_kodow_entries'
          AND COLUMN_NAME = 'pickup_code_unique_key'
    ) = 0,
    'ALTER TABLE lista_wygranych_kodow_entries ADD COLUMN pickup_code_unique_key VARCHAR(255) GENERATED ALWAYS AS (NULLIF(TRIM(pickup_code), '''')) STORED',
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
          AND TABLE_NAME = 'lista_wygranych_kodow_entries'
          AND INDEX_NAME = 'uq_winning_codes_pickup_code'
    ) = 0,
    'ALTER TABLE lista_wygranych_kodow_entries ADD UNIQUE INDEX uq_winning_codes_pickup_code (pickup_code_unique_key)',
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
          AND TABLE_NAME = 'lista_wygranych_kodow_entries'
          AND INDEX_NAME = 'idx_winning_codes_winner'
    ) = 0,
    'ALTER TABLE lista_wygranych_kodow_entries ADD INDEX idx_winning_codes_winner (kod_przyznany_do_zwyciezcy, id)',
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
          AND TABLE_NAME = 'lista_wygranych_kodow_entries'
          AND INDEX_NAME = 'idx_winning_codes_any_lookup'
    ) = 0,
    'ALTER TABLE lista_wygranych_kodow_entries ADD INDEX idx_winning_codes_any_lookup (kod_wykorzystany, kod_przyznany_do_zwyciezcy, id)',
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
          AND TABLE_NAME = 'lista_wygranych_kodow_entries'
          AND INDEX_NAME = 'idx_winning_codes_lookup'
    ) = 0,
    'ALTER TABLE lista_wygranych_kodow_entries ADD INDEX idx_winning_codes_lookup (wartosc_kodu, kod_wykorzystany, kod_przyznany_do_zwyciezcy, id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
