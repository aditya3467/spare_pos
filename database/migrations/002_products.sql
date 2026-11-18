CREATE TABLE IF NOT EXISTS vehicle_manufacturers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, shop_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(150) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY manufacturer_name(shop_id,name), UNIQUE KEY manufacturer_scope(shop_id,id),
 FOREIGN KEY(shop_id) REFERENCES shops(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS vehicle_models (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, shop_id BIGINT UNSIGNED NOT NULL,
 manufacturer_id BIGINT UNSIGNED NOT NULL, name VARCHAR(150) NOT NULL,
 UNIQUE KEY model_name(shop_id,manufacturer_id,name), UNIQUE KEY model_scope(shop_id,id),
 FOREIGN KEY(shop_id,manufacturer_id) REFERENCES vehicle_manufacturers(shop_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS vehicle_variants (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, shop_id BIGINT UNSIGNED NOT NULL,
 model_id BIGINT UNSIGNED NOT NULL, name VARCHAR(150) NOT NULL,
 UNIQUE KEY variant_name(shop_id,model_id,name), UNIQUE KEY variant_scope(shop_id,model_id,id),
 FOREIGN KEY(shop_id,model_id) REFERENCES vehicle_models(shop_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS vehicle_fitments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, shop_id BIGINT UNSIGNED NOT NULL,
 model_id BIGINT UNSIGNED NOT NULL, variant_id BIGINT UNSIGNED NULL,
 year_from SMALLINT UNSIGNED NULL, year_to SMALLINT UNSIGNED NULL, notes VARCHAR(500) NOT NULL DEFAULT '',
 identity_hash CHAR(64) NOT NULL,
 UNIQUE KEY fitment_identity(shop_id,identity_hash), UNIQUE KEY fitment_scope(shop_id,id),
 FOREIGN KEY(shop_id,model_id) REFERENCES vehicle_models(shop_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(shop_id,model_id,variant_id) REFERENCES vehicle_variants(shop_id,model_id,id) ON DELETE RESTRICT,
 CHECK(year_from IS NULL OR year_to IS NULL OR year_from<=year_to)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS product_fitments (
 shop_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL, fitment_id BIGINT UNSIGNED NOT NULL,
 verified_by BIGINT UNSIGNED NULL, verified_at DATETIME NULL,
 PRIMARY KEY(shop_id,product_id,fitment_id),
 FOREIGN KEY(shop_id,product_id) REFERENCES products(shop_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(shop_id,fitment_id) REFERENCES vehicle_fitments(shop_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(verified_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS product_specifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, shop_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL,
 label VARCHAR(80) NOT NULL, value VARCHAR(250) NOT NULL,
 UNIQUE KEY specification_name(shop_id,product_id,label),
 FOREIGN KEY(shop_id,product_id) REFERENCES products(shop_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB;
