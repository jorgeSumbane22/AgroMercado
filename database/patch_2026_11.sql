-- AgroMercado — patch de funcionalidades (novembro 2026)
-- Aplicar depois de schema.sql/patch_2026_10.sql. É idempotente.
USE agro_marketplace;
SET NAMES utf8mb4;

/* ---------- Segurança e contas ---------- */
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL AFTER phone,
  ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(64) NULL AFTER avatar,
  ADD COLUMN IF NOT EXISTS totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret;
-- Contas existentes (demo) contam como verificadas.
UPDATE users SET email_verified_at = created_at WHERE email_verified_at IS NULL;

-- Tokens de "manter sessão" e de verificação de email (selector + hash do validador).
CREATE TABLE IF NOT EXISTS user_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type ENUM('remember','verify_email') NOT NULL,
  selector CHAR(24) NOT NULL UNIQUE,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_tokens_user (user_id, type),
  CONSTRAINT fk_user_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Limite de pedidos por IP/endpoint (janela fixa).
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket VARCHAR(160) NOT NULL PRIMARY KEY,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL
) ENGINE=InnoDB;

-- Fila de emails (enviados por SMTP quando configurado; em local ficam registados).
CREATE TABLE IF NOT EXISTS email_outbox (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  to_email VARCHAR(160) NOT NULL,
  subject VARCHAR(200) NOT NULL,
  body TEXT NOT NULL,
  status ENUM('queued','sent','failed','logged') NOT NULL DEFAULT 'queued',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  INDEX idx_outbox_status (status, created_at),
  CONSTRAINT fk_outbox_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

/* ---------- Comissão, repasses e reembolsos ---------- */
INSERT INTO app_settings(setting_key,setting_value,is_secret) VALUES
  ('commission_percent','10',0),
  ('smtp_host','',0),('smtp_port','587',0),('smtp_user','',0),('smtp_pass','',1),('smtp_secure','tls',0)
ON DUPLICATE KEY UPDATE setting_key=setting_key;

ALTER TABLE order_items
  ADD COLUMN IF NOT EXISTS commission_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER subtotal,
  ADD COLUMN IF NOT EXISTS commission_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER commission_rate,
  ADD COLUMN IF NOT EXISTS farmer_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER commission_amount,
  ADD COLUMN IF NOT EXISTS payout_id BIGINT UNSIGNED NULL AFTER farmer_amount;
UPDATE order_items SET commission_rate=10, commission_amount=ROUND(subtotal*0.10,2), farmer_amount=subtotal-ROUND(subtotal*0.10,2)
WHERE commission_amount=0 AND farmer_amount=0;

CREATE TABLE IF NOT EXISTS farmer_payouts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  farmer_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  items_count INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
  method VARCHAR(30) NULL,
  reference VARCHAR(100) NULL,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  INDEX idx_payouts_farmer (farmer_id, status),
  CONSTRAINT fk_payouts_farmer FOREIGN KEY (farmer_id) REFERENCES farmers(id),
  CONSTRAINT fk_payouts_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS refunds (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL,
  reason VARCHAR(255) NULL,
  status ENUM('requested','processed','rejected') NOT NULL DEFAULT 'requested',
  reference VARCHAR(100) NULL,
  processed_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  INDEX idx_refunds_status (status),
  CONSTRAINT fk_refunds_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_refunds_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
  CONSTRAINT fk_refunds_user FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

/* ---------- Cupões e faturas ---------- */
CREATE TABLE IF NOT EXISTS coupons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  description VARCHAR(160) NULL,
  discount_type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  value DECIMAL(12,2) NOT NULL,
  min_subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  max_uses INT UNSIGNED NULL,
  used_count INT UNSIGNED NOT NULL DEFAULT 0,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS discount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER delivery_fee,
  ADD COLUMN IF NOT EXISTS coupon_id INT UNSIGNED NULL AFTER discount,
  ADD COLUMN IF NOT EXISTS invoice_number VARCHAR(30) NULL AFTER order_number;
UPDATE orders SET invoice_number = CONCAT('FT ', YEAR(created_at), '/', LPAD(id, 6, '0')) WHERE invoice_number IS NULL;
-- Índice único só depois de preencher os números.
SET @idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='orders' AND index_name='uq_orders_invoice');
SET @sql := IF(@idx=0, 'ALTER TABLE orders ADD UNIQUE KEY uq_orders_invoice (invoice_number)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

INSERT INTO coupons(code,description,discount_type,value,min_subtotal,max_uses) VALUES
  ('BEMVINDO10','10% na primeira compra','percent',10,200,NULL)
ON DUPLICATE KEY UPDATE code=code;

/* ---------- Mensagens comprador ↔ agricultor (por pedido) ---------- */
CREATE TABLE IF NOT EXISTS order_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  farmer_id INT UNSIGNED NOT NULL,
  sender_id INT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_messages_thread (order_id, farmer_id, created_at),
  CONSTRAINT fk_messages_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_messages_farmer FOREIGN KEY (farmer_id) REFERENCES farmers(id),
  CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id)
) ENGINE=InnoDB;

/* ---------- Catálogo e documentos ---------- */
ALTER TABLE product_images ADD COLUMN IF NOT EXISTS sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER is_primary;
ALTER TABLE farmer_documents ADD COLUMN IF NOT EXISTS expiry_notified_at DATETIME NULL AFTER expires_at;
