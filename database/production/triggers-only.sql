-- Production repair: TRIGGERS ONLY. Select the production database before import.
-- For MySQL 8.0.29+ (or MariaDB with CREATE TRIGGER IF NOT EXISTS).
-- No tables, data, DEFINER clauses or DROP statements are included.
-- Prerequisite: a database administrator must resolve error 1419, for example:
--   SET GLOBAL log_bin_trust_function_creators = 1;
-- Your import account also needs TRIGGER privilege on this database.
-- Do not re-import the original full dump over existing records.

CREATE TRIGGER IF NOT EXISTS `stock_movements_no_update`
BEFORE UPDATE ON `stock_movements` FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Stock movements are append-only; insert a reversal instead.';

CREATE TRIGGER IF NOT EXISTS `stock_movements_no_delete`
BEFORE DELETE ON `stock_movements` FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Stock movements are append-only; insert a reversal instead.';

CREATE TRIGGER IF NOT EXISTS `sales_immutable_update`
BEFORE UPDATE ON `sales` FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted sales records are immutable';

CREATE TRIGGER IF NOT EXISTS `sales_immutable_delete`
BEFORE DELETE ON `sales` FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted sales records are immutable';

CREATE TRIGGER IF NOT EXISTS `sale_items_immutable_update`
BEFORE UPDATE ON `sale_items` FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted sales records are immutable';

CREATE TRIGGER IF NOT EXISTS `sale_items_immutable_delete`
BEFORE DELETE ON `sale_items` FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted sales records are immutable';

CREATE TRIGGER IF NOT EXISTS `sale_payments_immutable_update`
BEFORE UPDATE ON `sale_payments` FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted sales records are immutable';

CREATE TRIGGER IF NOT EXISTS `sale_payments_immutable_delete`
BEFORE DELETE ON `sale_payments` FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted sales records are immutable';

CREATE TRIGGER IF NOT EXISTS `sale_cancellations_immutable_update`
BEFORE UPDATE ON `sale_cancellations` FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted sales records are immutable';

CREATE TRIGGER IF NOT EXISTS `sale_cancellations_immutable_delete`
BEFORE DELETE ON `sale_cancellations` FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted sales records are immutable';

CREATE TRIGGER IF NOT EXISTS `sale_services_immutable_update`
BEFORE UPDATE ON `sale_services` FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted service records are immutable';

CREATE TRIGGER IF NOT EXISTS `sale_services_immutable_delete`
BEFORE DELETE ON `sale_services` FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted service records are immutable';

CREATE TRIGGER IF NOT EXISTS `service_expenses_immutable_update`
BEFORE UPDATE ON `service_expenses` FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted service records are immutable';

CREATE TRIGGER IF NOT EXISTS `service_expenses_immutable_delete`
BEFORE DELETE ON `service_expenses` FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted service records are immutable';

-- Expected: all 14 rows listed below. Existing guards are preserved.
SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA=DATABASE()
  AND TRIGGER_NAME IN (
    'stock_movements_no_update',
    'stock_movements_no_delete',
    'sales_immutable_update',
    'sales_immutable_delete',
    'sale_items_immutable_update',
    'sale_items_immutable_delete',
    'sale_payments_immutable_update',
    'sale_payments_immutable_delete',
    'sale_cancellations_immutable_update',
    'sale_cancellations_immutable_delete',
    'sale_services_immutable_update',
    'sale_services_immutable_delete',
    'service_expenses_immutable_update',
    'service_expenses_immutable_delete')
ORDER BY EVENT_OBJECT_TABLE, EVENT_MANIPULATION;
