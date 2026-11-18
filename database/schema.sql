CREATE TABLE IF NOT EXISTS shops (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(150) NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 shop_id BIGINT UNSIGNED NULL,
 name VARCHAR(120) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('superadmin','shop_owner','staff') NOT NULL,
 active BOOLEAN NOT NULL DEFAULT TRUE,
 last_login_at DATETIME NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (shop_id) REFERENCES shops(id),
 CHECK ((role = 'superadmin' AND shop_id IS NULL) OR (role <> 'superadmin' AND shop_id IS NOT NULL))
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS staff_permissions (
 user_id BIGINT UNSIGNED NOT NULL,
 permission VARCHAR(80) NOT NULL,
 PRIMARY KEY (user_id, permission),
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS login_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 identity_hash CHAR(64) NOT NULL,
 attempted_at DATETIME NOT NULL,
 INDEX attempt_lookup (identity_hash, attempted_at)
) ENGINE=InnoDB;

-- Phase 1: additive tables only. All business references restrict deletion.
CREATE TABLE IF NOT EXISTS categories (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 shop_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(150) NOT NULL,
 slug VARCHAR(190) NOT NULL,
 description TEXT NULL,
 status ENUM('active','inactive') NOT NULL DEFAULT 'active',
 sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 archived_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE RESTRICT,
 UNIQUE KEY scoped_id (shop_id,id),
 UNIQUE KEY category_name (shop_id,name),
 UNIQUE KEY category_slug (shop_id,slug)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS subcategories (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 shop_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(150) NOT NULL,
 slug VARCHAR(190) NOT NULL,
 description TEXT NULL,
 status ENUM('active','inactive') NOT NULL DEFAULT 'active',
 sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 archived_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE RESTRICT,
 UNIQUE KEY scoped_id (shop_id,id),
 category_id BIGINT UNSIGNED NOT NULL,
 UNIQUE KEY subcategory_parent (shop_id,category_id,id),
 UNIQUE KEY subcategory_name (shop_id,category_id,name),
 UNIQUE KEY subcategory_slug (shop_id,category_id,slug),
 FOREIGN KEY (shop_id,category_id) REFERENCES categories(shop_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS brands (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 shop_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(150) NOT NULL,
 status ENUM('active','inactive') NOT NULL DEFAULT 'active',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY brand_name (shop_id,name), UNIQUE KEY brand_scope (shop_id,id),
 FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS units (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 shop_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(80) NOT NULL, code VARCHAR(20) NOT NULL,
 decimal_places TINYINT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY unit_code (shop_id,code), UNIQUE KEY unit_scope (shop_id,id),
 FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE RESTRICT,
 CHECK (decimal_places <= 6)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS tax_rates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 shop_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(80) NOT NULL, rate DECIMAL(5,2) NOT NULL,
 status ENUM('active','inactive') NOT NULL DEFAULT 'active',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY tax_name (shop_id,name), UNIQUE KEY tax_scope (shop_id,id),
 FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE RESTRICT,
 CHECK (rate >= 0 AND rate <= 100)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS products (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 shop_id BIGINT UNSIGNED NOT NULL,
 category_id BIGINT UNSIGNED NOT NULL, subcategory_id BIGINT UNSIGNED NULL,
 brand_id BIGINT UNSIGNED NULL, base_unit_id BIGINT UNSIGNED NOT NULL,
 purchase_unit_id BIGINT UNSIGNED NULL, sale_unit_id BIGINT UNSIGNED NULL,
 purchase_conversion_factor DECIMAL(18,6) NOT NULL DEFAULT 1,
 sale_conversion_factor DECIMAL(18,6) NOT NULL DEFAULT 1,
 tax_rate_id BIGINT UNSIGNED NULL,
 name VARCHAR(190) NOT NULL, sku VARCHAR(100) NOT NULL, barcode VARCHAR(100) NULL,
 hsn_code VARCHAR(20) NULL, description TEXT NULL,
 mrp DECIMAL(15,2) NOT NULL DEFAULT 0, selling_price DECIMAL(15,2) NOT NULL DEFAULT 0,
 reorder_level DECIMAL(18,6) NOT NULL DEFAULT 0,
 status ENUM('active','inactive') NOT NULL DEFAULT 'active', archived_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY product_sku (shop_id,sku), UNIQUE KEY product_barcode (shop_id,barcode), UNIQUE KEY product_scope (shop_id,id),
 FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE RESTRICT,
 FOREIGN KEY (shop_id,category_id) REFERENCES categories(shop_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (shop_id,category_id,subcategory_id) REFERENCES subcategories(shop_id,category_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (shop_id,brand_id) REFERENCES brands(shop_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (shop_id,base_unit_id) REFERENCES units(shop_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (shop_id,purchase_unit_id) REFERENCES units(shop_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (shop_id,sale_unit_id) REFERENCES units(shop_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (shop_id,tax_rate_id) REFERENCES tax_rates(shop_id,id) ON DELETE RESTRICT,
 CHECK (purchase_conversion_factor > 0 AND sale_conversion_factor > 0),
 CHECK (mrp >= 0 AND selling_price >= 0 AND reorder_level >= 0)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS product_aliases (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 shop_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL, alias VARCHAR(190) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY product_alias (shop_id,product_id,alias), INDEX alias_search (shop_id,alias),
 FOREIGN KEY (shop_id,product_id) REFERENCES products(shop_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS stock_movements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 shop_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL,
 event_key VARCHAR(100) NOT NULL,
 movement_type ENUM('opening','purchase','sale','customer_return','supplier_return','adjustment','damage','reversal') NOT NULL,
 quantity_delta DECIMAL(18,6) NOT NULL COMMENT 'Signed quantity in product base units',
 unit_cost DECIMAL(18,6) NOT NULL DEFAULT 0,
 reference_type VARCHAR(60) NULL, reference_id VARCHAR(100) NULL,
 reverses_movement_id BIGINT UNSIGNED NULL,
 note VARCHAR(500) NULL, created_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY movement_event (shop_id,event_key), UNIQUE KEY movement_scope (shop_id,id),
 INDEX movement_product_date (shop_id,product_id,created_at,id),
 FOREIGN KEY (shop_id,product_id) REFERENCES products(shop_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY (shop_id,reverses_movement_id) REFERENCES stock_movements(shop_id,id) ON DELETE RESTRICT,
 CHECK (quantity_delta <> 0 AND unit_cost >= 0)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS stock_balances (
 shop_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL,
 quantity DECIMAL(18,6) NOT NULL DEFAULT 0,
 average_unit_cost DECIMAL(18,6) NOT NULL DEFAULT 0,
 last_movement_id BIGINT UNSIGNED NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (shop_id,product_id),
 FOREIGN KEY (shop_id,product_id) REFERENCES products(shop_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (shop_id,last_movement_id) REFERENCES stock_movements(shop_id,id) ON DELETE RESTRICT,
 CHECK (average_unit_cost >= 0)
) ENGINE=InnoDB;
