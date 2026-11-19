-- Portable generator for older MySQL/MariaDB versions.
-- Run in the selected production database after the administrator resolves error 1419.
-- This query makes no changes. Copy the sql_to_run result rows into the SQL tab and run them.
-- No result rows means none of these 14 project trigger names are missing.
SELECT CONCAT('CREATE TRIGGER `', expected.trigger_name, '` BEFORE ', expected.operation,
              ' ON `', expected.table_name, '` FOR EACH ROW SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=',
              QUOTE(expected.message), ';') AS sql_to_run
FROM (
SELECT 'stock_movements_no_update' AS trigger_name, 'stock_movements' AS table_name, 'UPDATE' AS operation, 'Stock movements are append-only; insert a reversal instead.' AS message
UNION ALL
SELECT 'stock_movements_no_delete' AS trigger_name, 'stock_movements' AS table_name, 'DELETE' AS operation, 'Stock movements are append-only; insert a reversal instead.' AS message
UNION ALL
SELECT 'sales_immutable_update' AS trigger_name, 'sales' AS table_name, 'UPDATE' AS operation, 'Posted sales records are immutable' AS message
UNION ALL
SELECT 'sales_immutable_delete' AS trigger_name, 'sales' AS table_name, 'DELETE' AS operation, 'Posted sales records are immutable' AS message
UNION ALL
SELECT 'sale_items_immutable_update' AS trigger_name, 'sale_items' AS table_name, 'UPDATE' AS operation, 'Posted sales records are immutable' AS message
UNION ALL
SELECT 'sale_items_immutable_delete' AS trigger_name, 'sale_items' AS table_name, 'DELETE' AS operation, 'Posted sales records are immutable' AS message
UNION ALL
SELECT 'sale_payments_immutable_update' AS trigger_name, 'sale_payments' AS table_name, 'UPDATE' AS operation, 'Posted sales records are immutable' AS message
UNION ALL
SELECT 'sale_payments_immutable_delete' AS trigger_name, 'sale_payments' AS table_name, 'DELETE' AS operation, 'Posted sales records are immutable' AS message
UNION ALL
SELECT 'sale_cancellations_immutable_update' AS trigger_name, 'sale_cancellations' AS table_name, 'UPDATE' AS operation, 'Posted sales records are immutable' AS message
UNION ALL
SELECT 'sale_cancellations_immutable_delete' AS trigger_name, 'sale_cancellations' AS table_name, 'DELETE' AS operation, 'Posted sales records are immutable' AS message
UNION ALL
SELECT 'sale_services_immutable_update' AS trigger_name, 'sale_services' AS table_name, 'UPDATE' AS operation, 'Posted service records are immutable' AS message
UNION ALL
SELECT 'sale_services_immutable_delete' AS trigger_name, 'sale_services' AS table_name, 'DELETE' AS operation, 'Posted service records are immutable' AS message
UNION ALL
SELECT 'service_expenses_immutable_update' AS trigger_name, 'service_expenses' AS table_name, 'UPDATE' AS operation, 'Posted service records are immutable' AS message
UNION ALL
SELECT 'service_expenses_immutable_delete' AS trigger_name, 'service_expenses' AS table_name, 'DELETE' AS operation, 'Posted service records are immutable' AS message
) AS expected
LEFT JOIN information_schema.TRIGGERS actual
  ON actual.TRIGGER_SCHEMA=DATABASE() AND actual.TRIGGER_NAME=expected.trigger_name
WHERE actual.TRIGGER_NAME IS NULL
ORDER BY expected.table_name, expected.operation;
