-- Adds per-status administrator e-mail notification settings.
-- The /cms/install schema updater adds the same columns automatically.

SET @schema_name := DATABASE();

SET @sql := IF(
    (
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'ustawienia_statusow'
          AND COLUMN_NAME = 'administrator_email_template'
    ) = 0,
    'ALTER TABLE ustawienia_statusow ADD COLUMN administrator_email_template INT DEFAULT NULL AFTER email_template',
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
          AND TABLE_NAME = 'ustawienia_statusow'
          AND COLUMN_NAME = 'administrator_email'
    ) = 0,
    'ALTER TABLE ustawienia_statusow ADD COLUMN administrator_email VARCHAR(255) DEFAULT NULL AFTER administrator_email_template',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
