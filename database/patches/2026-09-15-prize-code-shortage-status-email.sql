-- Adds prize-type settings used when the pool of required winning codes is empty.
-- The /cms/install schema updater adds the same columns automatically.

SET @schema_name := DATABASE();

SET @sql := IF(
    (
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'rodzaje_nagrod_entries'
          AND COLUMN_NAME = 'status_po_wygraniu_nagrody_gdy_brak_kodow'
    ) = 0,
    'ALTER TABLE rodzaje_nagrod_entries ADD COLUMN status_po_wygraniu_nagrody_gdy_brak_kodow INT DEFAULT NULL AFTER status_po_wygraniu_nagrody',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'rodzaje_nagrod_entries'
          AND COLUMN_NAME = 'prize_granted_no_codes_email_template'
    ) = 0,
    'ALTER TABLE rodzaje_nagrod_entries ADD COLUMN prize_granted_no_codes_email_template INT DEFAULT NULL AFTER prize_granted_email_template',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
