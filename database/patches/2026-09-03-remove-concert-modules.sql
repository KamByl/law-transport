-- Permanently removes the obsolete concert modules and their stored relations.
-- Back up the production database before running this patch.

SET @schema_name := DATABASE();

SET @sql := IF(
    (
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'loteria_zgloszenia_entries'
          AND COLUMN_NAME = 'koncert'
    ) > 0,
    'ALTER TABLE loteria_zgloszenia_entries DROP COLUMN koncert',
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
          AND COLUMN_NAME = 'koncert'
    ) > 0,
    'ALTER TABLE rodzaje_nagrod_entries DROP COLUMN koncert',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DELETE gp
FROM group_permissions gp
INNER JOIN permissions p ON p.id = gp.permission_id
WHERE p.code IN ('koncerty', 'grupy_koncertow');

DELETE up
FROM user_permissions up
INNER JOIN permissions p ON p.id = up.permission_id
WHERE p.code IN ('koncerty', 'grupy_koncertow');

DELETE FROM module_list_preferences
WHERE module_key IN ('koncerty', 'grupy_koncertow');

DELETE FROM permissions
WHERE code IN ('koncerty', 'grupy_koncertow');

DROP TABLE IF EXISTS koncerty_entries;
DROP TABLE IF EXISTS grupy_koncertow_entries;
