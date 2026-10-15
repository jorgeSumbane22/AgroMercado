-- AgroMercado — patch de correções (outubro 2026)
-- Aplicar sobre uma base criada com schema.sql. É idempotente.
USE agro_marketplace;
SET NAMES utf8mb4;

-- Estado por item: cada agricultor gere apenas os seus itens de um pedido com vários agricultores.
ALTER TABLE order_items
  ADD COLUMN IF NOT EXISTS status ENUM('pending','confirmed','preparing','ready','shipping','delivered','cancelled') NOT NULL DEFAULT 'pending' AFTER subtotal;
UPDATE order_items oi JOIN orders o ON o.id=oi.order_id SET oi.status=o.status WHERE oi.status='pending' AND o.status<>'pending';

-- Prazo estimado de entrega calculado pela zona no checkout.
ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS delivery_eta_days TINYINT UNSIGNED NULL AFTER delivery_fee,
  ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(255) NULL AFTER notes;

-- Moderação de avaliações.
ALTER TABLE reviews
  ADD COLUMN IF NOT EXISTS status ENUM('visible','hidden') NOT NULL DEFAULT 'visible' AFTER comment;

-- Sincroniza o estado de pagamento dos pedidos com a tabela payments.
UPDATE orders o JOIN payments p ON p.order_id=o.id SET o.payment_status=p.status WHERE o.payment_status<>p.status;

-- Pagamentos gravados com método vazio (sql_mode não estrito) passam a numerário.
UPDATE payments SET method='cash' WHERE method='';

-- Dados demo: pedidos entregues ficam com pagamento confirmado.
UPDATE payments p JOIN orders o ON o.id=p.order_id SET p.status='paid', p.paid_at=o.updated_at WHERE o.status='delivered' AND p.status='pending';
UPDATE orders o JOIN payments p ON p.order_id=o.id SET o.payment_status=p.status WHERE o.payment_status<>p.status;

-- Agricultores não verificados não podem ter produtos publicados.
UPDATE products p JOIN farmers f ON f.id=p.farmer_id SET p.status='inactive' WHERE f.verification_status<>'verified' AND p.status='active';

-- Ícones das categorias: Bootstrap Icons em vez de emojis.
UPDATE categories SET icon = CASE slug
  WHEN 'cereais' THEN 'bi-sun' WHEN 'frutas' THEN 'bi-basket2' WHEN 'hortalicas' THEN 'bi-tree'
  WHEN 'tubérculos' THEN 'bi-box-seam' WHEN 'leguminosas' THEN 'bi-flower2' WHEN 'oleaginosas' THEN 'bi-droplet'
  WHEN 'especiarias' THEN 'bi-fire' WHEN 'produtos-animais' THEN 'bi-egg' WHEN 'produtos-processados' THEN 'bi-cup-hot'
  WHEN 'outros' THEN 'bi-flower1' ELSE icon END
WHERE icon IS NULL OR icon NOT LIKE 'bi-%';
