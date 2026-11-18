CREATE TABLE IF NOT EXISTS sale_services (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, shop_id BIGINT UNSIGNED NOT NULL, sale_id BIGINT UNSIGNED NOT NULL,
 line_number SMALLINT UNSIGNED NOT NULL, description VARCHAR(190) NOT NULL, revenue DECIMAL(15,2) NOT NULL,
 snapshot TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY service_scope(shop_id,id), UNIQUE KEY service_line(shop_id,sale_id,line_number),
 FOREIGN KEY(shop_id,sale_id) REFERENCES sales(shop_id,id) ON DELETE RESTRICT,
 CHECK(revenue>=0)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS service_expenses (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, shop_id BIGINT UNSIGNED NOT NULL,
 sale_id BIGINT UNSIGNED NULL, description VARCHAR(190) NOT NULL, payee VARCHAR(150) NULL,
 amount DECIMAL(15,2) NOT NULL, incurred_on DATE NOT NULL, created_by BIGINT UNSIGNED NOT NULL,
 request_key CHAR(40) NOT NULL, payload_hash CHAR(64) NOT NULL, reverses_expense_id BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY expense_scope(shop_id,id), UNIQUE KEY expense_request(shop_id,request_key),
 UNIQUE KEY expense_reversal(shop_id,reverses_expense_id), INDEX expense_date(shop_id,incurred_on,id),
 FOREIGN KEY(shop_id) REFERENCES shops(id) ON DELETE RESTRICT,
 FOREIGN KEY(shop_id,sale_id) REFERENCES sales(shop_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(shop_id,reverses_expense_id) REFERENCES service_expenses(shop_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK((reverses_expense_id IS NULL AND amount>0) OR (reverses_expense_id IS NOT NULL AND amount<0))
) ENGINE=InnoDB;
