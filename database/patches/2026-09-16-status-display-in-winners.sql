-- Adds a per-status flag controlling visibility in the frontend winners section.
-- Existing status settings are not changed; select the statuses that should be public.

SET @schema_name := DATABASE();

SET @sql := IF(
    (
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'ustawienia_statusow'
          AND COLUMN_NAME = 'wyswietlaj_w_sekcji_laureatow'
    ) = 0,
    'ALTER TABLE ustawienia_statusow ADD COLUMN wyswietlaj_w_sekcji_laureatow TINYINT(1) DEFAULT NULL AFTER zrealizowane',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
