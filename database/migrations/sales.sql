CREATE TABLE IF NOT EXISTS shop_tax_settings (
 shop_id BIGINT UNSIGNED PRIMARY KEY, legal_name VARCHAR(150) NOT NULL, address VARCHAR(500) NOT NULL,
 state_code CHAR(2) NOT NULL, registration_type ENUM('regular','composition','unregistered') NOT NULL,
 gstin VARCHAR(15) NULL, price_mode ENUM('inclusive','exclusive') NOT NULL DEFAULT 'inclusive',
 default_tax_rate_id BIGINT UNSIGNED NULL, require_hsn BOOLEAN NOT NULL DEFAULT 0,
 invoice_prefix VARCHAR(4) NOT NULL DEFAULT 'INV', footer VARCHAR(300) NOT NULL DEFAULT '',
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(shop_id) REFERENCES shops(id) ON DELETE RESTRICT,
 FOREIGN KEY(shop_id,default_tax_rate_id) REFERENCES tax_rates(shop_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS customers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, shop_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(150) NOT NULL, phone VARCHAR(30) NULL, address VARCHAR(500) NULL,
 state_code CHAR(2) NULL, gstin VARCHAR(15) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY customer_scope(shop_id,id), INDEX customer_name(shop_id,name), INDEX customer_phone(shop_id,phone),
 FOREIGN KEY(shop_id) REFERENCES shops(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS invoice_sequences (
 shop_id BIGINT UNSIGNED NOT NULL, financial_year CHAR(4) NOT NULL, prefix VARCHAR(4) NOT NULL, last_number INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(shop_id,financial_year,prefix), FOREIGN KEY(shop_id) REFERENCES shops(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS sales (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, shop_id BIGINT UNSIGNED NOT NULL,
 invoice_number VARCHAR(16) NOT NULL, request_key CHAR(40) NOT NULL, payload_hash CHAR(64) NOT NULL,
 customer_id BIGINT UNSIGNED NULL, customer_snapshot TEXT NOT NULL, shop_snapshot TEXT NOT NULL,
 vehicle_model_snapshot VARCHAR(400) NULL,
 place_of_supply CHAR(2) NOT NULL, document_type VARCHAR(40) NOT NULL,
 subtotal DECIMAL(15,2) NOT NULL, discount DECIMAL(15,2) NOT NULL, taxable DECIMAL(15,2) NOT NULL,
 cgst DECIMAL(15,2) NOT NULL, sgst DECIMAL(15,2) NOT NULL, igst DECIMAL(15,2) NOT NULL, total DECIMAL(15,2) NOT NULL,
 amount_received DECIMAL(15,2) NOT NULL, amount_paid DECIMAL(15,2) NOT NULL, change_due DECIMAL(15,2) NOT NULL, balance_due DECIMAL(15,2) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY sale_scope(shop_id,id), UNIQUE KEY invoice_number(shop_id,invoice_number), UNIQUE KEY sale_request(shop_id,request_key),
 INDEX sales_date(shop_id,created_at,id), FOREIGN KEY(shop_id) REFERENCES shops(id) ON DELETE RESTRICT,
 FOREIGN KEY(shop_id,customer_id) REFERENCES customers(shop_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS sale_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, shop_id BIGINT UNSIGNED NOT NULL, sale_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL, quantity DECIMAL(18,6) NOT NULL, unit_cost DECIMAL(18,6) NOT NULL,
 movement_id BIGINT UNSIGNED NOT NULL, snapshot TEXT NOT NULL,
 UNIQUE KEY sale_product(shop_id,sale_id,product_id),
 FOREIGN KEY(shop_id,sale_id) REFERENCES sales(shop_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(shop_id,product_id) REFERENCES products(shop_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(shop_id,movement_id) REFERENCES stock_movements(shop_id,id) ON DELETE RESTRICT,
 CHECK(quantity>0 AND unit_cost>=0)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS sale_cancellations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, shop_id BIGINT UNSIGNED NOT NULL, sale_id BIGINT UNSIGNED NOT NULL,
 reason VARCHAR(500) NOT NULL, created_by BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY cancellation_scope(shop_id,id), UNIQUE KEY one_cancellation(shop_id,sale_id),
 FOREIGN KEY(shop_id,sale_id) REFERENCES sales(shop_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS sale_payments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, shop_id BIGINT UNSIGNED NOT NULL, sale_id BIGINT UNSIGNED NOT NULL,
 method ENUM('cash','upi','card','bank') NOT NULL, amount DECIMAL(15,2) NOT NULL,
 received DECIMAL(15,2) NOT NULL, change_returned DECIMAL(15,2) NOT NULL DEFAULT 0,
 reference VARCHAR(100) NULL, payment_date DATETIME NOT NULL, recorded_by BIGINT UNSIGNED NOT NULL,
 reverses_payment_id BIGINT UNSIGNED NULL, cancellation_id BIGINT UNSIGNED NULL,
 UNIQUE KEY payment_scope(shop_id,id), UNIQUE KEY single_payment_reversal(shop_id,reverses_payment_id),
 FOREIGN KEY(shop_id,sale_id) REFERENCES sales(shop_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(shop_id,reverses_payment_id) REFERENCES sale_payments(shop_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(shop_id,cancellation_id) REFERENCES sale_cancellations(shop_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(recorded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
