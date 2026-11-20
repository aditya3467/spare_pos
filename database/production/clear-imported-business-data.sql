-- ONE-TIME FRESH DEPLOYMENT RESET. Select your production database first.
-- Run only after exporting a full backup and stopping all application activity.
-- Confirmed scope: no genuine production records have been entered.
-- Applies to ALL shops in the selected database. Not a selective import undo.
-- Removes products, brands, customers, sales, payments, services, stock and
-- invoice counters. Invoice numbering starts afresh with the retained prefix.
-- Keeps accounts/passwords/permissions, login security records, shops, tax and
-- invoice settings, tax rates, units, categories/subcategories, and vehicle
-- manufacturers/models/variants. Keeps table definitions and existing triggers.
-- TRUNCATE requires DROP privilege, bypasses DELETE triggers, resets IDs and
-- commits immediately. This script CANNOT be rolled back. Do not wrap in BEGIN.

SET SESSION FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE `sale_payments`;
TRUNCATE TABLE `sale_services`;
TRUNCATE TABLE `service_expenses`;
TRUNCATE TABLE `sale_items`;
TRUNCATE TABLE `sale_cancellations`;
TRUNCATE TABLE `sales`;
TRUNCATE TABLE `invoice_sequences`;
TRUNCATE TABLE `customers`;
TRUNCATE TABLE `inventory_requests`;
TRUNCATE TABLE `stock_balances`;
TRUNCATE TABLE `stock_movements`;
TRUNCATE TABLE `product_fitments`;
TRUNCATE TABLE `product_specifications`;
TRUNCATE TABLE `product_aliases`;
TRUNCATE TABLE `products`;
TRUNCATE TABLE `vehicle_fitments`;
TRUNCATE TABLE `brands`;

SET SESSION FOREIGN_KEY_CHECKS = 1;

-- All removed-data counts must be zero.
SELECT 'sales' AS table_name, COUNT(*) AS remaining_rows FROM sales
UNION ALL SELECT 'sale_items', COUNT(*) FROM sale_items
UNION ALL SELECT 'sale_payments', COUNT(*) FROM sale_payments
UNION ALL SELECT 'sale_cancellations', COUNT(*) FROM sale_cancellations
UNION ALL SELECT 'sale_services', COUNT(*) FROM sale_services
UNION ALL SELECT 'service_expenses', COUNT(*) FROM service_expenses
UNION ALL SELECT 'invoice_sequences', COUNT(*) FROM invoice_sequences
UNION ALL SELECT 'customers', COUNT(*) FROM customers
UNION ALL SELECT 'inventory_requests', COUNT(*) FROM inventory_requests
UNION ALL SELECT 'stock_balances', COUNT(*) FROM stock_balances
UNION ALL SELECT 'stock_movements', COUNT(*) FROM stock_movements
UNION ALL SELECT 'product_fitments', COUNT(*) FROM product_fitments
UNION ALL SELECT 'product_specifications', COUNT(*) FROM product_specifications
UNION ALL SELECT 'product_aliases', COUNT(*) FROM product_aliases
UNION ALL SELECT 'products', COUNT(*) FROM products
UNION ALL SELECT 'vehicle_fitments', COUNT(*) FROM vehicle_fitments
UNION ALL SELECT 'brands', COUNT(*) FROM brands;

SELECT @@SESSION.FOREIGN_KEY_CHECKS AS foreign_key_checks_enabled;
