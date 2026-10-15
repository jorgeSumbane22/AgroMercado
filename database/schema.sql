-- AgroMercado — Schema completo, dados fictícios e patch operacional
-- Importação recomendada: este único ficheiro no phpMyAdmin.
-- Credenciais: JorgeSumbane / jorge.sumbane@agromercado.local / Admin@123
--
CREATE DATABASE IF NOT EXISTS agro_marketplace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE agro_marketplace;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS backup_logs, payment_webhooks, legal_acceptances, notification_preferences, farmer_documents, complaints, delivery_zones, password_resets, login_attempts;
DROP TABLE IF EXISTS audit_logs, farmer_verifications, notifications, reviews, favorites, payments, order_items, orders, inventory, cart_items, carts, product_images, products, categories, buyers, farmers, users, roles;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE roles (
 id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(30) NOT NULL UNIQUE,
 label VARCHAR(60) NOT NULL
) ENGINE=InnoDB;
INSERT INTO roles (name,label) VALUES ('superadmin','SuperAdmin'),('farmer','Agricultor'),('buyer','Comprador');

CREATE TABLE users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 role_id TINYINT UNSIGNED NOT NULL,
 name VARCHAR(120) NOT NULL,
 email VARCHAR(160) NOT NULL UNIQUE,
 password VARCHAR(255) NOT NULL,
 phone VARCHAR(40) NULL,
 avatar VARCHAR(255) NULL,
 status ENUM('active','inactive','blocked') NOT NULL DEFAULT 'active',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_users_role FOREIGN KEY(role_id) REFERENCES roles(id),
 INDEX idx_users_role_status(role_id,status)
) ENGINE=InnoDB;

CREATE TABLE farmers (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL UNIQUE,
 province VARCHAR(80) NOT NULL DEFAULT 'Maputo',
 district VARCHAR(100) NULL,
 locality VARCHAR(100) NULL,
 specialty VARCHAR(140) NULL,
 bio TEXT NULL,
 verification_status ENUM('unverified','pending','verified','rejected') NOT NULL DEFAULT 'pending',
 verified_at DATETIME NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_farmers_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 INDEX idx_farmers_verification(verification_status)
) ENGINE=InnoDB;

CREATE TABLE buyers (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL UNIQUE,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_buyers_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE categories (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL UNIQUE,
 slug VARCHAR(120) NOT NULL UNIQUE,
 icon VARCHAR(20) DEFAULT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE products (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 farmer_id INT UNSIGNED NOT NULL,
 category_id INT UNSIGNED NOT NULL,
 name VARCHAR(160) NOT NULL,
 slug VARCHAR(190) NOT NULL UNIQUE,
 description TEXT NOT NULL,
 price DECIMAL(12,2) NOT NULL,
 unit ENUM('kg','tonelada','saco','caixa','litro','unidade','dúzia') NOT NULL DEFAULT 'kg',
 stock DECIMAL(12,2) NOT NULL DEFAULT 0,
 quantity_min DECIMAL(12,2) NOT NULL DEFAULT 1,
 main_image VARCHAR(255) NULL,
 status ENUM('active','inactive','out_of_stock') NOT NULL DEFAULT 'active',
 province VARCHAR(80) NOT NULL DEFAULT 'Maputo',
 district VARCHAR(100) NULL,
 locality VARCHAR(100) NULL,
 harvest_date DATE NULL,
 expiry_date DATE NULL,
 featured TINYINT(1) NOT NULL DEFAULT 0,
 sales_count INT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_products_farmer FOREIGN KEY(farmer_id) REFERENCES farmers(id) ON DELETE CASCADE,
 CONSTRAINT fk_products_category FOREIGN KEY(category_id) REFERENCES categories(id),
 CONSTRAINT chk_product_price CHECK(price >= 0),
 CONSTRAINT chk_product_stock CHECK(stock >= 0),
 INDEX idx_products_market(status,category_id,province,price),
 INDEX idx_products_farmer(farmer_id),
 FULLTEXT KEY ft_products(name,description)
) ENGINE=InnoDB;

CREATE TABLE product_images (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 product_id INT UNSIGNED NOT NULL,
 image_path VARCHAR(255) NOT NULL,
 is_primary TINYINT(1) DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_product_images_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE inventory (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 product_id INT UNSIGNED NOT NULL,
 quantity DECIMAL(12,2) NOT NULL,
 movement_type ENUM('initial','sale','adjustment','return') NOT NULL,
 notes VARCHAR(255) NULL,
 created_by INT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_inventory_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,
 CONSTRAINT fk_inventory_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
 INDEX idx_inventory_product(product_id,created_at)
) ENGINE=InnoDB;

CREATE TABLE carts (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 buyer_id INT UNSIGNED NOT NULL UNIQUE,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_carts_buyer FOREIGN KEY(buyer_id) REFERENCES buyers(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE cart_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 cart_id INT UNSIGNED NOT NULL,
 product_id INT UNSIGNED NOT NULL,
 quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_cart_product(cart_id,product_id),
 CONSTRAINT fk_cart_items_cart FOREIGN KEY(cart_id) REFERENCES carts(id) ON DELETE CASCADE,
 CONSTRAINT fk_cart_items_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE orders (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 buyer_id INT UNSIGNED NOT NULL,
 order_number VARCHAR(30) NOT NULL UNIQUE,
 status ENUM('pending','confirmed','preparing','ready','shipping','delivered','cancelled') NOT NULL DEFAULT 'pending',
 payment_status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
 subtotal DECIMAL(12,2) NOT NULL,
 delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
 total DECIMAL(12,2) NOT NULL,
 delivery_name VARCHAR(120) NOT NULL,
 delivery_phone VARCHAR(40) NOT NULL,
 delivery_email VARCHAR(160) NOT NULL,
 province VARCHAR(80) NOT NULL,
 city VARCHAR(80) NOT NULL,
 district VARCHAR(100) NOT NULL,
 address VARCHAR(255) NOT NULL,
 notes TEXT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_orders_buyer FOREIGN KEY(buyer_id) REFERENCES buyers(id),
 INDEX idx_orders_status(status,created_at),
 INDEX idx_orders_buyer(buyer_id,created_at)
) ENGINE=InnoDB;
CREATE TABLE order_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 product_id INT UNSIGNED NOT NULL,
 farmer_id INT UNSIGNED NOT NULL,
 product_name VARCHAR(160) NOT NULL,
 unit VARCHAR(30) NOT NULL,
 unit_price DECIMAL(12,2) NOT NULL,
 quantity DECIMAL(12,2) NOT NULL,
 subtotal DECIMAL(12,2) NOT NULL,
 CONSTRAINT fk_order_items_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 CONSTRAINT fk_order_items_product FOREIGN KEY(product_id) REFERENCES products(id),
 CONSTRAINT fk_order_items_farmer FOREIGN KEY(farmer_id) REFERENCES farmers(id),
 INDEX idx_order_items_farmer(farmer_id)
) ENGINE=InnoDB;

CREATE TABLE payments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(12,2) NOT NULL,
 method ENUM('cash','mpesa','emola','bank','card') NOT NULL,
 reference VARCHAR(100) NULL,
 status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
 paid_at DATETIME NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_payments_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 UNIQUE KEY uq_payment_order(order_id)
) ENGINE=InnoDB;

CREATE TABLE favorites (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 product_id INT UNSIGNED NULL,
 farmer_id INT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_favorites_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_favorites_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,
 CONSTRAINT fk_favorites_farmer FOREIGN KEY(farmer_id) REFERENCES farmers(id) ON DELETE CASCADE,
 UNIQUE KEY uq_favorite_product(user_id,product_id)
) ENGINE=InnoDB;

CREATE TABLE reviews (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 product_id INT UNSIGNED NOT NULL,
 order_id BIGINT UNSIGNED NOT NULL,
 rating TINYINT UNSIGNED NOT NULL,
 comment TEXT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_reviews_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_reviews_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,
 CONSTRAINT fk_reviews_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 CONSTRAINT chk_review_rating CHECK(rating BETWEEN 1 AND 5),
 UNIQUE KEY uq_review_order_product(user_id,product_id,order_id)
) ENGINE=InnoDB;

CREATE TABLE notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 title VARCHAR(160) NOT NULL,
 body TEXT NOT NULL,
 is_read TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_notifications_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 INDEX idx_notifications_user(user_id,is_read,created_at)
) ENGINE=InnoDB;
CREATE TABLE farmer_verifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 farmer_id INT UNSIGNED NOT NULL,
 status ENUM('pending','verified','rejected') NOT NULL,
 notes TEXT NULL,
 reviewed_by INT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_verifications_farmer FOREIGN KEY(farmer_id) REFERENCES farmers(id) ON DELETE CASCADE,
 CONSTRAINT fk_verifications_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NULL,
 action VARCHAR(80) NOT NULL,
 module VARCHAR(80) NOT NULL,
 description TEXT NOT NULL,
 ip VARCHAR(45) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_audit_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
 INDEX idx_audit_created(created_at), INDEX idx_audit_module(module)
) ENGINE=InnoDB;

-- DADOS FICTÍCIOS
USE agro_marketplace;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;
TRUNCATE TABLE audit_logs;
TRUNCATE TABLE farmer_verifications;
TRUNCATE TABLE notifications;
TRUNCATE TABLE reviews;
TRUNCATE TABLE favorites;
TRUNCATE TABLE payments;
TRUNCATE TABLE order_items;
TRUNCATE TABLE orders;
TRUNCATE TABLE inventory;
TRUNCATE TABLE cart_items;
TRUNCATE TABLE carts;
TRUNCATE TABLE product_images;
TRUNCATE TABLE products;
TRUNCATE TABLE categories;
TRUNCATE TABLE buyers;
TRUNCATE TABLE farmers;
TRUNCATE TABLE users;
SET FOREIGN_KEY_CHECKS=1;
INSERT INTO categories(name,slug,icon) VALUES ('Cereais','cereais','bi-sun'),('Frutas','frutas','bi-basket2'),('Hortaliças','hortalicas','bi-tree'),('Tubérculos','tubérculos','bi-box-seam'),('Leguminosas','leguminosas','bi-flower2'),('Oleaginosas','oleaginosas','bi-droplet'),('Especiarias','especiarias','bi-fire'),('Produtos animais','produtos-animais','bi-egg'),('Produtos processados','produtos-processados','bi-cup-hot'),('Outros','outros','bi-flower1');
INSERT INTO users(role_id,name,email,password,phone,status) VALUES (1,'JorgeSumbane','jorge.sumbane@agromercado.local','$2y$12$BnPsNsXDTr9DIcpJtM.gK.tcD0YQn2NBhsLjqJUEmi1yNuaDQFCEa','+258 84 000 0000','active'),(2,'João Manuel','joao@agromercado.local','$2y$12$MtqavFxKnUJ0GrLHuUtlJelCjNtfYxkSVPSbt2vSE.Sev8TIgZXsK','+258 84 111 1111','active'),(2,'Maria Alice','maria@agromercado.local','$2y$12$MtqavFxKnUJ0GrLHuUtlJelCjNtfYxkSVPSbt2vSE.Sev8TIgZXsK','+258 84 222 2222','active'),(2,'António Sitoe','antonio@agromercado.local','$2y$12$MtqavFxKnUJ0GrLHuUtlJelCjNtfYxkSVPSbt2vSE.Sev8TIgZXsK','+258 84 333 3333','active'),(2,'Celina Mavume','celina@agromercado.local','$2y$12$MtqavFxKnUJ0GrLHuUtlJelCjNtfYxkSVPSbt2vSE.Sev8TIgZXsK','+258 84 444 4444','active'),(2,'Ernesto Nhamatanda','ernesto@agromercado.local','$2y$12$MtqavFxKnUJ0GrLHuUtlJelCjNtfYxkSVPSbt2vSE.Sev8TIgZXsK','+258 84 555 5555','active'),(2,'Lúcia Macuácua','lucia@agromercado.local','$2y$12$MtqavFxKnUJ0GrLHuUtlJelCjNtfYxkSVPSbt2vSE.Sev8TIgZXsK','+258 84 666 6666','active'),(3,'Carlos Matola','carlos_matola@agromercado.local','$2y$12$fNiNqjuLl14YqBx70J1daes5oxO22P2C6b/BK8dzwfQOA4srIaDwK','+258 82 200 1000','active'),(3,'Beatriz Nunes','beatriz_nunes@agromercado.local','$2y$12$fNiNqjuLl14YqBx70J1daes5oxO22P2C6b/BK8dzwfQOA4srIaDwK','+258 82 201 1001','active'),(3,'Nelson Tembe','nelson_tembe@agromercado.local','$2y$12$fNiNqjuLl14YqBx70J1daes5oxO22P2C6b/BK8dzwfQOA4srIaDwK','+258 82 202 1002','active'),(3,'Sónia Mucavele','sonia_mucavele@agromercado.local','$2y$12$fNiNqjuLl14YqBx70J1daes5oxO22P2C6b/BK8dzwfQOA4srIaDwK','+258 82 203 1003','active'),(3,'Paulo Chongo','paulo_chongo@agromercado.local','$2y$12$fNiNqjuLl14YqBx70J1daes5oxO22P2C6b/BK8dzwfQOA4srIaDwK','+258 82 204 1004','active'),(3,'Rita Nhantumbo','rita_nhantumbo@agromercado.local','$2y$12$fNiNqjuLl14YqBx70J1daes5oxO22P2C6b/BK8dzwfQOA4srIaDwK','+258 82 205 1005','active'),(3,'David Mussa','david_mussa@agromercado.local','$2y$12$fNiNqjuLl14YqBx70J1daes5oxO22P2C6b/BK8dzwfQOA4srIaDwK','+258 82 206 1006','active'),(3,'Amélia Cossa','amélia_cossa@agromercado.local','$2y$12$fNiNqjuLl14YqBx70J1daes5oxO22P2C6b/BK8dzwfQOA4srIaDwK','+258 82 207 1007','active');
INSERT INTO farmers(user_id,province,district,locality,specialty,bio,verification_status) VALUES (2,'Maputo','Boane','Matola-Rio','Hortaliças','Produção local com práticas responsáveis e colheita de qualidade.','verified'),(3,'Maputo','Marracuene','Michafutene','Frutas','Produção local com práticas responsáveis e colheita de qualidade.','verified'),(4,'Gaza','Chókwè','Lionde','Cereais','Produção local com práticas responsáveis e colheita de qualidade.','verified'),(5,'Manica','Chimoio','Vanduzi','Tubérculos','Produção local com práticas responsáveis e colheita de qualidade.','verified'),(6,'Sofala','Beira','Nhangau','Leguminosas','Produção local com práticas responsáveis e colheita de qualidade.','pending'),(7,'Nampula','Nampula','Rapale','Oleaginosas','Produção local com práticas responsáveis e colheita de qualidade.','pending');
INSERT INTO buyers(user_id) VALUES (8),(9),(10),(11),(12),(13),(14),(15);
INSERT INTO products(farmer_id,category_id,name,slug,description,price,unit,stock,quantity_min,main_image,status,province,district,locality,harvest_date,expiry_date,featured,sales_count) VALUES (1,3,'Tomate Fresco','tomate-fresco-1','Tomate Fresco selecionado, produzido localmente em Boane, com qualidade e cuidado do campo até ao mercado.',80.00,'kg',500.00,1,'produtos/tomate.jpg',"active",'Maputo','Boane','Matola-Rio',NULL,NULL,1,7),(2,1,'Milho Amarelo','milho-amarelo-2','Milho Amarelo selecionado, produzido localmente em Chókwè, com qualidade e cuidado do campo até ao mercado.',50.00,'kg',1200.00,1,'produtos/milho.jpg',"active",'Gaza','Chókwè','Lionde',NULL,NULL,1,14),(3,5,'Feijão Manteiga','feijao-manteiga-3','Feijão Manteiga selecionado, produzido localmente em Chimoio, com qualidade e cuidado do campo até ao mercado.',140.00,'kg',320.00,1,'produtos/feijao.jpg',"active",'Manica','Chimoio','Vanduzi',NULL,NULL,1,21),(4,4,'Mandioca Fresca','mandioca-fresca-4','Mandioca Fresca selecionado, produzido localmente em Chimoio, com qualidade e cuidado do campo até ao mercado.',65.00,'kg',700.00,1,'produtos/mandioca.jpg',"active",'Manica','Chimoio','Vanduzi',NULL,NULL,1,28),(5,4,'Batata Branca','batata-branca-5','Batata Branca selecionado, produzido localmente em Chimoio, com qualidade e cuidado do campo até ao mercado.',90.00,'kg',450.00,1,'produtos/batata.jpg',"active",'Manica','Chimoio','Vanduzi',NULL,NULL,1,35),(6,3,'Cebola Roxa','cebola-roxa-6','Cebola Roxa selecionado, produzido localmente em Boane, com qualidade e cuidado do campo até ao mercado.',110.00,'kg',240.00,1,'produtos/cebola.jpg',"active",'Maputo','Boane','Matola-Rio',NULL,NULL,1,42),(1,6,'Amendoim Natural','amendoim-natural-7','Amendoim Natural selecionado, produzido localmente em Nampula, com qualidade e cuidado do campo até ao mercado.',160.00,'kg',180.00,1,'produtos/amendoim.jpg',"active",'Nampula','Nampula','Rapale',NULL,NULL,1,49),(2,1,'Arroz Local','arroz-local-8','Arroz Local selecionado, produzido localmente em Beira, com qualidade e cuidado do campo até ao mercado.',95.00,'kg',650.00,1,'produtos/arroz.jpg',"active",'Sofala','Beira','Nhangau',NULL,NULL,1,56),(3,1,'Couve Manteiga','couve-manteiga-9','Couve Manteiga selecionado, produzido localmente em Marracuene, com qualidade e cuidado do campo até ao mercado.',45.00,'unidade',150.00,1,'produtos/couve-verde.jpg',"active",'Maputo','Marracuene','Michafutene',NULL,NULL,0,63),(4,1,'Alface Crocante','alface-crocante-10','Alface Crocante selecionado, produzido localmente em Boane, com qualidade e cuidado do campo até ao mercado.',35.00,'unidade',200.00,1,'produtos/alface.jpg',"active",'Maputo','Boane','Matola-Rio',NULL,NULL,0,70),(5,2,'Banana Madura','banana-madura-11','Banana Madura selecionado, produzido localmente em Marracuene, com qualidade e cuidado do campo até ao mercado.',75.00,'kg',350.00,1,'produtos/banana.jpg',"active",'Maputo','Marracuene','Michafutene',NULL,NULL,0,77),(6,2,'Abacate Hass','abacate-hass-12','Abacate Hass selecionado, produzido localmente em Marracuene, com qualidade e cuidado do campo até ao mercado.',130.00,'kg',220.00,1,'produtos/abacate.jpg',"active",'Maputo','Marracuene','Michafutene',NULL,NULL,0,84),(1,2,'Manga Rosa','manga-rosa-13','Manga Rosa selecionado, produzido localmente em Marracuene, com qualidade e cuidado do campo até ao mercado.',85.00,'kg',300.00,1,'produtos/manga.jpg',"active",'Maputo','Marracuene','Michafutene',NULL,NULL,0,91),(2,2,'Laranja Fresca','laranja-fresca-14','Laranja Fresca selecionado, produzido localmente em Marracuene, com qualidade e cuidado do campo até ao mercado.',70.00,'kg',400.00,1,'produtos/laranja.jpg',"active",'Maputo','Marracuene','Michafutene',NULL,NULL,0,98),(3,1,'Pimento Verde','pimento-verde-15','Pimento Verde selecionado, produzido localmente em Boane, com qualidade e cuidado do campo até ao mercado.',120.00,'kg',180.00,1,'produtos/pimento.jpg',"active",'Maputo','Boane','Matola-Rio',NULL,NULL,0,105),(4,1,'Cenoura','cenoura-16','Cenoura selecionado, produzido localmente em Boane, com qualidade e cuidado do campo até ao mercado.',60.00,'kg',280.00,1,'produtos/cenoura.jpg',"active",'Maputo','Boane','Matola-Rio',NULL,NULL,0,112),(5,1,'Repolho Verde','repolho-verde-17','Repolho Verde selecionado, produzido localmente em Boane, com qualidade e cuidado do campo até ao mercado.',55.00,'unidade',180.00,1,'produtos/couve.jpg',"active",'Maputo','Boane','Matola-Rio',NULL,NULL,0,119),(6,6,'Alho Nacional','alho-nacional-18','Alho Nacional selecionado, produzido localmente em Nampula, com qualidade e cuidado do campo até ao mercado.',240.00,'kg',90.00,1,'produtos/alho.jpg',"active",'Nampula','Nampula','Rapale',NULL,NULL,0,126),(1,6,'Gergelim','gergelim-19','Gergelim selecionado, produzido localmente em Nampula, com qualidade e cuidado do campo até ao mercado.',190.00,'kg',160.00,1,'produtos/gergelim.jpg',"active",'Nampula','Nampula','Rapale',NULL,NULL,0,133),(2,4,'Batata-doce','batata-doce-20','Batata-doce selecionado, produzido localmente em Chimoio, com qualidade e cuidado do campo até ao mercado.',70.00,'kg',500.00,1,'produtos/batata-doce.jpg',"active",'Manica','Chimoio','Vanduzi',NULL,NULL,0,140),(3,2,'Pepino','pepino-21','Pepino selecionado, produzido localmente em Marracuene, com qualidade e cuidado do campo até ao mercado.',58.00,'kg',210.00,1,'produtos/pepino.jpg',"active",'Maputo','Marracuene','Michafutene',NULL,NULL,0,147),(4,3,'Abóbora','abóbora-22','Abóbora selecionado, produzido localmente em Boane, com qualidade e cuidado do campo até ao mercado.',45.00,'unidade',140.00,1,'produtos/abobora.jpg',"active",'Maputo','Boane','Matola-Rio',NULL,NULL,0,154),(5,5,'Ervilha','ervilha-23','Ervilha selecionado, produzido localmente em Chimoio, com qualidade e cuidado do campo até ao mercado.',155.00,'kg',110.00,1,'produtos/ervilha.jpg',"active",'Manica','Chimoio','Vanduzi',NULL,NULL,0,161),(6,6,'Chá de Rooibos','chá-de-rooibos-24','Chá de Rooibos selecionado, produzido localmente em Nampula, com qualidade e cuidado do campo até ao mercado.',260.00,'caixa',80.00,1,'produtos/cha.jpg',"active",'Nampula','Nampula','Rapale',NULL,NULL,0,168),(1,5,'Mel de Colmeia','mel-de-colmeia-25','Mel de Colmeia selecionado, produzido localmente em Beira, com qualidade e cuidado do campo até ao mercado.',300.00,'litro',75.00,1,'produtos/mel.jpg',"active",'Sofala','Beira','Nhangau',NULL,NULL,0,175),(2,5,'Ovos Caipira','ovos-caipira-26','Ovos Caipira selecionado, produzido localmente em Beira, com qualidade e cuidado do campo até ao mercado.',180.00,'dúzia',120.00,1,'produtos/ovos.jpg',"active",'Sofala','Beira','Nhangau',NULL,NULL,0,182),(3,4,'Farinha de Mandioca','farinha-de-mandioca-27','Farinha de Mandioca selecionado, produzido localmente em Chimoio, com qualidade e cuidado do campo até ao mercado.',100.00,'saco',200.00,1,'produtos/farinha.jpg',"active",'Manica','Chimoio','Vanduzi',NULL,NULL,0,189),(4,6,'Caju Torrado','caju-torrado-28','Caju Torrado selecionado, produzido localmente em Nampula, com qualidade e cuidado do campo até ao mercado.',280.00,'kg',100.00,1,'produtos/caju.jpg',"active",'Nampula','Nampula','Rapale',NULL,NULL,0,196),(5,1,'Salsa Fresca','salsa-fresca-29','Salsa Fresca selecionado, produzido localmente em Boane, com qualidade e cuidado do campo até ao mercado.',50.00,'unidade',90.00,1,'produtos/ervas.jpg',"active",'Maputo','Boane','Matola-Rio',NULL,NULL,0,203),(6,3,'Quiabo','quiabo-30','Quiabo selecionado, produzido localmente em Boane, com qualidade e cuidado do campo até ao mercado.',95.00,'kg',130.00,1,'produtos/quiabo.jpg',"active",'Maputo','Boane','Matola-Rio',NULL,NULL,0,210);
INSERT INTO product_images(product_id,image_path,is_primary) VALUES (1,'produtos/tomate.jpg',1),(1,'produtos/tomate.jpg',0),(1,'produtos/tomate.jpg',0),(1,'produtos/tomate.jpg',0),(2,'produtos/milho.jpg',1),(2,'produtos/milho.jpg',0),(2,'produtos/milho.jpg',0),(2,'produtos/milho.jpg',0),(3,'produtos/feijao.jpg',1),(3,'produtos/feijao.jpg',0),(3,'produtos/feijao.jpg',0),(3,'produtos/feijao.jpg',0),(4,'produtos/mandioca.jpg',1),(4,'produtos/mandioca.jpg',0),(4,'produtos/mandioca.jpg',0),(4,'produtos/mandioca.jpg',0),(5,'produtos/batata.jpg',1),(5,'produtos/batata.jpg',0),(5,'produtos/batata.jpg',0),(5,'produtos/batata.jpg',0),(6,'produtos/cebola.jpg',1),(6,'produtos/cebola.jpg',0),(6,'produtos/cebola.jpg',0),(6,'produtos/cebola.jpg',0),(7,'produtos/amendoim.jpg',1),(7,'produtos/amendoim.jpg',0),(7,'produtos/amendoim.jpg',0),(7,'produtos/amendoim.jpg',0),(8,'produtos/arroz.jpg',1),(8,'produtos/arroz.jpg',0),(8,'produtos/arroz.jpg',0),(8,'produtos/arroz.jpg',0),(9,'produtos/couve-verde.jpg',1),(9,'produtos/couve-verde.jpg',0),(9,'produtos/couve-verde.jpg',0),(9,'produtos/couve-verde.jpg',0),(10,'produtos/alface.jpg',1),(10,'produtos/alface.jpg',0),(10,'produtos/alface.jpg',0),(10,'produtos/alface.jpg',0),(11,'produtos/banana.jpg',1),(11,'produtos/banana.jpg',0),(11,'produtos/banana.jpg',0),(12,'produtos/abacate.jpg',1),(12,'produtos/abacate.jpg',0),(12,'produtos/abacate.jpg',0),(13,'produtos/manga.jpg',1),(13,'produtos/manga.jpg',0),(13,'produtos/manga.jpg',0),(14,'produtos/laranja.jpg',1),(14,'produtos/laranja.jpg',0),(14,'produtos/laranja.jpg',0),(15,'produtos/pimento.jpg',1),(15,'produtos/pimento.jpg',0),(15,'produtos/pimento.jpg',0),(16,'produtos/cenoura.jpg',1),(16,'produtos/cenoura.jpg',0),(16,'produtos/cenoura.jpg',0),(17,'produtos/couve.jpg',1),(17,'produtos/couve.jpg',0),(17,'produtos/couve.jpg',0),(18,'produtos/alho.jpg',1),(18,'produtos/alho.jpg',0),(18,'produtos/alho.jpg',0),(19,'produtos/gergelim.jpg',1),(19,'produtos/gergelim.jpg',0),(19,'produtos/gergelim.jpg',0),(20,'produtos/batata-doce.jpg',1),(20,'produtos/batata-doce.jpg',0),(20,'produtos/batata-doce.jpg',0),(21,'produtos/pepino.jpg',1),(21,'produtos/pepino.jpg',0),(21,'produtos/pepino.jpg',0),(22,'produtos/abobora.jpg',1),(22,'produtos/abobora.jpg',0),(22,'produtos/abobora.jpg',0),(23,'produtos/ervilha.jpg',1),(23,'produtos/ervilha.jpg',0),(23,'produtos/ervilha.jpg',0),(24,'produtos/cha.jpg',1),(24,'produtos/cha.jpg',0),(24,'produtos/cha.jpg',0),(25,'produtos/mel.jpg',1),(25,'produtos/mel.jpg',0),(25,'produtos/mel.jpg',0),(26,'produtos/ovos.jpg',1),(26,'produtos/ovos.jpg',0),(26,'produtos/ovos.jpg',0),(27,'produtos/farinha.jpg',1),(27,'produtos/farinha.jpg',0),(27,'produtos/farinha.jpg',0),(28,'produtos/caju.jpg',1),(28,'produtos/caju.jpg',0),(28,'produtos/caju.jpg',0),(29,'produtos/ervas.jpg',1),(29,'produtos/ervas.jpg',0),(29,'produtos/ervas.jpg',0),(30,'produtos/quiabo.jpg',1),(30,'produtos/quiabo.jpg',0),(30,'produtos/quiabo.jpg',0);
INSERT INTO inventory(product_id,quantity,movement_type,notes,created_by) SELECT id,stock,"initial","Stock inicial",1 FROM products;
INSERT INTO orders(buyer_id,order_number,status,payment_status,subtotal,delivery_fee,total,delivery_name,delivery_phone,delivery_email,province,city,district,address,notes) VALUES (1,'AG-202608-001','delivered',"pending",310.00,0,310.00,'Cliente Demo 1','+258 82 700 0001','cliente1@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 1','Entregar durante o dia'),(2,'AG-202608-002','shipping',"pending",290.00,0,290.00,'Cliente Demo 2','+258 82 700 0002','cliente2@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 2','Entregar durante o dia'),(3,'AG-202608-003','preparing',"pending",270.00,0,270.00,'Cliente Demo 3','+258 82 700 0003','cliente3@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 3','Entregar durante o dia'),(4,'AG-202608-004','confirmed',"pending",400.00,0,400.00,'Cliente Demo 4','+258 82 700 0004','cliente4@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 4','Entregar durante o dia'),(5,'AG-202608-005','pending',"pending",380.00,0,380.00,'Cliente Demo 5','+258 82 700 0005','cliente5@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 5','Entregar durante o dia'),(6,'AG-202608-006','delivered',"pending",430.00,0,430.00,'Cliente Demo 6','+258 82 700 0006','cliente6@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 6','Entregar durante o dia'),(7,'AG-202608-007','confirmed',"pending",605.00,0,605.00,'Cliente Demo 7','+258 82 700 0007','cliente7@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 7','Entregar durante o dia'),(8,'AG-202608-008','ready',"pending",330.00,0,330.00,'Cliente Demo 8','+258 82 700 0008','cliente8@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 8','Entregar durante o dia'),(1,'AG-202608-009','delivered',"pending",115.00,0,115.00,'Cliente Demo 1','+258 82 700 0001','cliente1@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 1','Entregar durante o dia'),(2,'AG-202608-010','shipping',"pending",295.00,0,295.00,'Cliente Demo 2','+258 82 700 0002','cliente2@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 2','Entregar durante o dia'),(3,'AG-202608-011','preparing',"pending",355.00,0,355.00,'Cliente Demo 3','+258 82 700 0003','cliente3@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 3','Entregar durante o dia'),(4,'AG-202608-012','confirmed',"pending",300.00,0,300.00,'Cliente Demo 4','+258 82 700 0004','cliente4@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 4','Entregar durante o dia'),(5,'AG-202608-013','pending',"pending",380.00,0,380.00,'Cliente Demo 5','+258 82 700 0005','cliente5@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 5','Entregar durante o dia'),(6,'AG-202608-014','delivered',"pending",330.00,0,330.00,'Cliente Demo 6','+258 82 700 0006','cliente6@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 6','Entregar durante o dia'),(7,'AG-202608-015','confirmed',"pending",240.00,0,240.00,'Cliente Demo 7','+258 82 700 0007','cliente7@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 7','Entregar durante o dia'),(8,'AG-202608-016','ready',"pending",285.00,0,285.00,'Cliente Demo 8','+258 82 700 0008','cliente8@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 8','Entregar durante o dia'),(1,'AG-202608-017','delivered',"pending",405.00,0,405.00,'Cliente Demo 1','+258 82 700 0001','cliente1@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 1','Entregar durante o dia'),(2,'AG-202608-018','shipping',"pending",620.00,0,620.00,'Cliente Demo 2','+258 82 700 0002','cliente2@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 2','Entregar durante o dia'),(3,'AG-202608-019','preparing',"pending",590.00,0,590.00,'Cliente Demo 3','+258 82 700 0003','cliente3@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 3','Entregar durante o dia'),(4,'AG-202608-020','confirmed',"pending",268.00,0,268.00,'Cliente Demo 4','+258 82 700 0004','cliente4@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 4','Entregar durante o dia'),(5,'AG-202608-021','pending',"pending",148.00,0,148.00,'Cliente Demo 5','+258 82 700 0005','cliente5@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 5','Entregar durante o dia'),(6,'AG-202608-022','delivered',"pending",555.00,0,555.00,'Cliente Demo 6','+258 82 700 0006','cliente6@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 6','Entregar durante o dia'),(7,'AG-202608-023','confirmed',"pending",725.00,0,725.00,'Cliente Demo 7','+258 82 700 0007','cliente7@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 7','Entregar durante o dia'),(8,'AG-202608-024','ready',"pending",860.00,0,860.00,'Cliente Demo 8','+258 82 700 0008','cliente8@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 8','Entregar durante o dia'),(1,'AG-202608-025','delivered',"pending",1140.00,0,1140.00,'Cliente Demo 1','+258 82 700 0001','cliente1@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 1','Entregar durante o dia'),(2,'AG-202608-026','shipping',"pending",640.00,0,640.00,'Cliente Demo 2','+258 82 700 0002','cliente2@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 2','Entregar durante o dia'),(3,'AG-202608-027','preparing',"pending",660.00,0,660.00,'Cliente Demo 3','+258 82 700 0003','cliente3@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 3','Entregar durante o dia'),(4,'AG-202608-028','confirmed',"pending",710.00,0,710.00,'Cliente Demo 4','+258 82 700 0004','cliente4@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 4','Entregar durante o dia'),(5,'AG-202608-029','pending',"pending",245.00,0,245.00,'Cliente Demo 5','+258 82 700 0005','cliente5@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 5','Entregar durante o dia'),(6,'AG-202608-030','delivered',"pending",255.00,0,255.00,'Cliente Demo 6','+258 82 700 0006','cliente6@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 6','Entregar durante o dia'),(7,'AG-202608-031','confirmed',"pending",310.00,0,310.00,'Cliente Demo 7','+258 82 700 0007','cliente7@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 7','Entregar durante o dia'),(8,'AG-202608-032','ready',"pending",290.00,0,290.00,'Cliente Demo 8','+258 82 700 0008','cliente8@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 8','Entregar durante o dia'),(1,'AG-202608-033','delivered',"pending",270.00,0,270.00,'Cliente Demo 1','+258 82 700 0001','cliente1@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 1','Entregar durante o dia'),(2,'AG-202608-034','shipping',"pending",400.00,0,400.00,'Cliente Demo 2','+258 82 700 0002','cliente2@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 2','Entregar durante o dia'),(3,'AG-202608-035','preparing',"pending",380.00,0,380.00,'Cliente Demo 3','+258 82 700 0003','cliente3@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 3','Entregar durante o dia'),(4,'AG-202608-036','confirmed',"pending",430.00,0,430.00,'Cliente Demo 4','+258 82 700 0004','cliente4@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 4','Entregar durante o dia'),(5,'AG-202608-037','pending',"pending",605.00,0,605.00,'Cliente Demo 5','+258 82 700 0005','cliente5@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 5','Entregar durante o dia'),(6,'AG-202608-038','delivered',"pending",330.00,0,330.00,'Cliente Demo 6','+258 82 700 0006','cliente6@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 6','Entregar durante o dia'),(7,'AG-202608-039','confirmed',"pending",115.00,0,115.00,'Cliente Demo 7','+258 82 700 0007','cliente7@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 7','Entregar durante o dia'),(8,'AG-202608-040','ready',"pending",295.00,0,295.00,'Cliente Demo 8','+258 82 700 0008','cliente8@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 8','Entregar durante o dia'),(1,'AG-202608-041','delivered',"pending",355.00,0,355.00,'Cliente Demo 1','+258 82 700 0001','cliente1@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 1','Entregar durante o dia'),(2,'AG-202608-042','shipping',"pending",300.00,0,300.00,'Cliente Demo 2','+258 82 700 0002','cliente2@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 2','Entregar durante o dia'),(3,'AG-202608-043','preparing',"pending",380.00,0,380.00,'Cliente Demo 3','+258 82 700 0003','cliente3@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 3','Entregar durante o dia'),(4,'AG-202608-044','confirmed',"pending",330.00,0,330.00,'Cliente Demo 4','+258 82 700 0004','cliente4@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 4','Entregar durante o dia'),(5,'AG-202608-045','pending',"pending",240.00,0,240.00,'Cliente Demo 5','+258 82 700 0005','cliente5@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 5','Entregar durante o dia'),(6,'AG-202608-046','delivered',"pending",285.00,0,285.00,'Cliente Demo 6','+258 82 700 0006','cliente6@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 6','Entregar durante o dia'),(7,'AG-202608-047','confirmed',"pending",405.00,0,405.00,'Cliente Demo 7','+258 82 700 0007','cliente7@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 7','Entregar durante o dia'),(8,'AG-202608-048','ready',"pending",620.00,0,620.00,'Cliente Demo 8','+258 82 700 0008','cliente8@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 8','Entregar durante o dia'),(1,'AG-202608-049','delivered',"pending",590.00,0,590.00,'Cliente Demo 1','+258 82 700 0001','cliente1@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 1','Entregar durante o dia'),(2,'AG-202608-050','shipping',"pending",268.00,0,268.00,'Cliente Demo 2','+258 82 700 0002','cliente2@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 2','Entregar durante o dia'),(3,'AG-202608-051','preparing',"pending",148.00,0,148.00,'Cliente Demo 3','+258 82 700 0003','cliente3@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 3','Entregar durante o dia'),(4,'AG-202608-052','confirmed',"pending",555.00,0,555.00,'Cliente Demo 4','+258 82 700 0004','cliente4@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 4','Entregar durante o dia'),(5,'AG-202608-053','pending',"pending",725.00,0,725.00,'Cliente Demo 5','+258 82 700 0005','cliente5@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 5','Entregar durante o dia'),(6,'AG-202608-054','delivered',"pending",860.00,0,860.00,'Cliente Demo 6','+258 82 700 0006','cliente6@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 6','Entregar durante o dia'),(7,'AG-202608-055','confirmed',"pending",1140.00,0,1140.00,'Cliente Demo 7','+258 82 700 0007','cliente7@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 7','Entregar durante o dia'),(8,'AG-202608-056','ready',"pending",640.00,0,640.00,'Cliente Demo 8','+258 82 700 0008','cliente8@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 8','Entregar durante o dia'),(1,'AG-202608-057','delivered',"pending",660.00,0,660.00,'Cliente Demo 1','+258 82 700 0001','cliente1@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 1','Entregar durante o dia'),(2,'AG-202608-058','shipping',"pending",710.00,0,710.00,'Cliente Demo 2','+258 82 700 0002','cliente2@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 2','Entregar durante o dia'),(3,'AG-202608-059','preparing',"pending",245.00,0,245.00,'Cliente Demo 3','+258 82 700 0003','cliente3@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 3','Entregar durante o dia'),(4,'AG-202608-060','confirmed',"pending",255.00,0,255.00,'Cliente Demo 4','+258 82 700 0004','cliente4@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 4','Entregar durante o dia'),(5,'AG-202608-061','pending',"pending",310.00,0,310.00,'Cliente Demo 5','+258 82 700 0005','cliente5@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 5','Entregar durante o dia'),(6,'AG-202608-062','delivered',"pending",290.00,0,290.00,'Cliente Demo 6','+258 82 700 0006','cliente6@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 6','Entregar durante o dia'),(7,'AG-202608-063','confirmed',"pending",270.00,0,270.00,'Cliente Demo 7','+258 82 700 0007','cliente7@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 7','Entregar durante o dia'),(8,'AG-202608-064','ready',"pending",400.00,0,400.00,'Cliente Demo 8','+258 82 700 0008','cliente8@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 8','Entregar durante o dia'),(1,'AG-202608-065','delivered',"pending",380.00,0,380.00,'Cliente Demo 1','+258 82 700 0001','cliente1@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 1','Entregar durante o dia'),(2,'AG-202608-066','shipping',"pending",430.00,0,430.00,'Cliente Demo 2','+258 82 700 0002','cliente2@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 2','Entregar durante o dia'),(3,'AG-202608-067','preparing',"pending",605.00,0,605.00,'Cliente Demo 3','+258 82 700 0003','cliente3@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 3','Entregar durante o dia'),(4,'AG-202608-068','confirmed',"pending",330.00,0,330.00,'Cliente Demo 4','+258 82 700 0004','cliente4@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 4','Entregar durante o dia'),(5,'AG-202608-069','pending',"pending",115.00,0,115.00,'Cliente Demo 5','+258 82 700 0005','cliente5@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 5','Entregar durante o dia'),(6,'AG-202608-070','delivered',"pending",295.00,0,295.00,'Cliente Demo 6','+258 82 700 0006','cliente6@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 6','Entregar durante o dia'),(7,'AG-202608-071','confirmed',"pending",355.00,0,355.00,'Cliente Demo 7','+258 82 700 0007','cliente7@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 7','Entregar durante o dia'),(8,'AG-202608-072','ready',"pending",300.00,0,300.00,'Cliente Demo 8','+258 82 700 0008','cliente8@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 8','Entregar durante o dia'),(1,'AG-202608-073','delivered',"pending",380.00,0,380.00,'Cliente Demo 1','+258 82 700 0001','cliente1@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 1','Entregar durante o dia'),(2,'AG-202608-074','shipping',"pending",330.00,0,330.00,'Cliente Demo 2','+258 82 700 0002','cliente2@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 2','Entregar durante o dia'),(3,'AG-202608-075','preparing',"pending",240.00,0,240.00,'Cliente Demo 3','+258 82 700 0003','cliente3@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 3','Entregar durante o dia'),(4,'AG-202608-076','confirmed',"pending",285.00,0,285.00,'Cliente Demo 4','+258 82 700 0004','cliente4@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 4','Entregar durante o dia'),(5,'AG-202608-077','pending',"pending",405.00,0,405.00,'Cliente Demo 5','+258 82 700 0005','cliente5@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 5','Entregar durante o dia'),(6,'AG-202608-078','delivered',"pending",620.00,0,620.00,'Cliente Demo 6','+258 82 700 0006','cliente6@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 6','Entregar durante o dia'),(7,'AG-202608-079','confirmed',"pending",590.00,0,590.00,'Cliente Demo 7','+258 82 700 0007','cliente7@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 7','Entregar durante o dia'),(8,'AG-202608-080','ready',"pending",268.00,0,268.00,'Cliente Demo 8','+258 82 700 0008','cliente8@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 8','Entregar durante o dia'),(1,'AG-202608-081','delivered',"pending",148.00,0,148.00,'Cliente Demo 1','+258 82 700 0001','cliente1@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 1','Entregar durante o dia'),(2,'AG-202608-082','shipping',"pending",555.00,0,555.00,'Cliente Demo 2','+258 82 700 0002','cliente2@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 2','Entregar durante o dia'),(3,'AG-202608-083','preparing',"pending",725.00,0,725.00,'Cliente Demo 3','+258 82 700 0003','cliente3@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 3','Entregar durante o dia'),(4,'AG-202608-084','confirmed',"pending",860.00,0,860.00,'Cliente Demo 4','+258 82 700 0004','cliente4@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 4','Entregar durante o dia'),(5,'AG-202608-085','pending',"pending",1140.00,0,1140.00,'Cliente Demo 5','+258 82 700 0005','cliente5@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 5','Entregar durante o dia'),(6,'AG-202608-086','delivered',"pending",640.00,0,640.00,'Cliente Demo 6','+258 82 700 0006','cliente6@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 6','Entregar durante o dia'),(7,'AG-202608-087','confirmed',"pending",660.00,0,660.00,'Cliente Demo 7','+258 82 700 0007','cliente7@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 7','Entregar durante o dia'),(8,'AG-202608-088','ready',"pending",710.00,0,710.00,'Cliente Demo 8','+258 82 700 0008','cliente8@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 8','Entregar durante o dia'),(1,'AG-202608-089','delivered',"pending",245.00,0,245.00,'Cliente Demo 1','+258 82 700 0001','cliente1@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 1','Entregar durante o dia'),(2,'AG-202608-090','shipping',"pending",255.00,0,255.00,'Cliente Demo 2','+258 82 700 0002','cliente2@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 2','Entregar durante o dia'),(3,'AG-202608-091','preparing',"pending",310.00,0,310.00,'Cliente Demo 3','+258 82 700 0003','cliente3@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 3','Entregar durante o dia'),(4,'AG-202608-092','confirmed',"pending",290.00,0,290.00,'Cliente Demo 4','+258 82 700 0004','cliente4@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 4','Entregar durante o dia'),(5,'AG-202608-093','pending',"pending",270.00,0,270.00,'Cliente Demo 5','+258 82 700 0005','cliente5@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 5','Entregar durante o dia'),(6,'AG-202608-094','delivered',"pending",400.00,0,400.00,'Cliente Demo 6','+258 82 700 0006','cliente6@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 6','Entregar durante o dia'),(7,'AG-202608-095','confirmed',"pending",380.00,0,380.00,'Cliente Demo 7','+258 82 700 0007','cliente7@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 7','Entregar durante o dia'),(8,'AG-202608-096','ready',"pending",430.00,0,430.00,'Cliente Demo 8','+258 82 700 0008','cliente8@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 8','Entregar durante o dia'),(1,'AG-202608-097','delivered',"pending",605.00,0,605.00,'Cliente Demo 1','+258 82 700 0001','cliente1@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 1','Entregar durante o dia'),(2,'AG-202608-098','shipping',"pending",330.00,0,330.00,'Cliente Demo 2','+258 82 700 0002','cliente2@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 2','Entregar durante o dia'),(3,'AG-202608-099','preparing',"pending",115.00,0,115.00,'Cliente Demo 3','+258 82 700 0003','cliente3@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 3','Entregar durante o dia'),(4,'AG-202608-100','confirmed',"pending",295.00,0,295.00,'Cliente Demo 4','+258 82 700 0004','cliente4@agromercado.local','Maputo','Maputo','Boane','Avenida da Independência, 4','Entregar durante o dia');
INSERT INTO order_items(order_id,product_id,farmer_id,product_name,unit,unit_price,quantity,subtotal) VALUES (1,1,1,'Tomate Fresco','kg',80.00,2.00,160.00),(1,2,2,'Milho Amarelo','kg',50.00,3.00,150.00),(2,2,2,'Milho Amarelo','kg',50.00,3.00,150.00),(2,3,3,'Feijão Manteiga','kg',140.00,1.00,140.00),(3,3,3,'Feijão Manteiga','kg',140.00,1.00,140.00),(3,4,4,'Mandioca Fresca','kg',65.00,2.00,130.00),(4,4,4,'Mandioca Fresca','kg',65.00,2.00,130.00),(4,5,5,'Batata Branca','kg',90.00,3.00,270.00),(5,5,5,'Batata Branca','kg',90.00,3.00,270.00),(5,6,6,'Cebola Roxa','kg',110.00,1.00,110.00),(6,6,6,'Cebola Roxa','kg',110.00,1.00,110.00),(6,7,1,'Amendoim Natural','kg',160.00,2.00,320.00),(7,7,1,'Amendoim Natural','kg',160.00,2.00,320.00),(7,8,2,'Arroz Local','kg',95.00,3.00,285.00),(8,8,2,'Arroz Local','kg',95.00,3.00,285.00),(8,9,3,'Couve Manteiga','unidade',45.00,1.00,45.00),(9,9,3,'Couve Manteiga','unidade',45.00,1.00,45.00),(9,10,4,'Alface Crocante','unidade',35.00,2.00,70.00),(10,10,4,'Alface Crocante','unidade',35.00,2.00,70.00),(10,11,5,'Banana Madura','kg',75.00,3.00,225.00),(11,11,5,'Banana Madura','kg',75.00,3.00,225.00),(11,12,6,'Abacate Hass','kg',130.00,1.00,130.00),(12,12,6,'Abacate Hass','kg',130.00,1.00,130.00),(12,13,1,'Manga Rosa','kg',85.00,2.00,170.00),(13,13,1,'Manga Rosa','kg',85.00,2.00,170.00),(13,14,2,'Laranja Fresca','kg',70.00,3.00,210.00),(14,14,2,'Laranja Fresca','kg',70.00,3.00,210.00),(14,15,3,'Pimento Verde','kg',120.00,1.00,120.00),(15,15,3,'Pimento Verde','kg',120.00,1.00,120.00),(15,16,4,'Cenoura','kg',60.00,2.00,120.00),(16,16,4,'Cenoura','kg',60.00,2.00,120.00),(16,17,5,'Repolho Verde','unidade',55.00,3.00,165.00),(17,17,5,'Repolho Verde','unidade',55.00,3.00,165.00),(17,18,6,'Alho Nacional','kg',240.00,1.00,240.00),(18,18,6,'Alho Nacional','kg',240.00,1.00,240.00),(18,19,1,'Gergelim','kg',190.00,2.00,380.00),(19,19,1,'Gergelim','kg',190.00,2.00,380.00),(19,20,2,'Batata-doce','kg',70.00,3.00,210.00),(20,20,2,'Batata-doce','kg',70.00,3.00,210.00),(20,21,3,'Pepino','kg',58.00,1.00,58.00),(21,21,3,'Pepino','kg',58.00,1.00,58.00),(21,22,4,'Abóbora','unidade',45.00,2.00,90.00),(22,22,4,'Abóbora','unidade',45.00,2.00,90.00),(22,23,5,'Ervilha','kg',155.00,3.00,465.00),(23,23,5,'Ervilha','kg',155.00,3.00,465.00),(23,24,6,'Chá de Rooibos','caixa',260.00,1.00,260.00),(24,24,6,'Chá de Rooibos','caixa',260.00,1.00,260.00),(24,25,1,'Mel de Colmeia','litro',300.00,2.00,600.00),(25,25,1,'Mel de Colmeia','litro',300.00,2.00,600.00),(25,26,2,'Ovos Caipira','dúzia',180.00,3.00,540.00),(26,26,2,'Ovos Caipira','dúzia',180.00,3.00,540.00),(26,27,3,'Farinha de Mandioca','saco',100.00,1.00,100.00),(27,27,3,'Farinha de Mandioca','saco',100.00,1.00,100.00),(27,28,4,'Caju Torrado','kg',280.00,2.00,560.00),(28,28,4,'Caju Torrado','kg',280.00,2.00,560.00),(28,29,5,'Salsa Fresca','unidade',50.00,3.00,150.00),(29,29,5,'Salsa Fresca','unidade',50.00,3.00,150.00),(29,30,6,'Quiabo','kg',95.00,1.00,95.00),(30,30,6,'Quiabo','kg',95.00,1.00,95.00),(30,1,1,'Tomate Fresco','kg',80.00,2.00,160.00),(31,1,1,'Tomate Fresco','kg',80.00,2.00,160.00),(31,2,2,'Milho Amarelo','kg',50.00,3.00,150.00),(32,2,2,'Milho Amarelo','kg',50.00,3.00,150.00),(32,3,3,'Feijão Manteiga','kg',140.00,1.00,140.00),(33,3,3,'Feijão Manteiga','kg',140.00,1.00,140.00),(33,4,4,'Mandioca Fresca','kg',65.00,2.00,130.00),(34,4,4,'Mandioca Fresca','kg',65.00,2.00,130.00),(34,5,5,'Batata Branca','kg',90.00,3.00,270.00),(35,5,5,'Batata Branca','kg',90.00,3.00,270.00),(35,6,6,'Cebola Roxa','kg',110.00,1.00,110.00),(36,6,6,'Cebola Roxa','kg',110.00,1.00,110.00),(36,7,1,'Amendoim Natural','kg',160.00,2.00,320.00),(37,7,1,'Amendoim Natural','kg',160.00,2.00,320.00),(37,8,2,'Arroz Local','kg',95.00,3.00,285.00),(38,8,2,'Arroz Local','kg',95.00,3.00,285.00),(38,9,3,'Couve Manteiga','unidade',45.00,1.00,45.00),(39,9,3,'Couve Manteiga','unidade',45.00,1.00,45.00),(39,10,4,'Alface Crocante','unidade',35.00,2.00,70.00),(40,10,4,'Alface Crocante','unidade',35.00,2.00,70.00),(40,11,5,'Banana Madura','kg',75.00,3.00,225.00),(41,11,5,'Banana Madura','kg',75.00,3.00,225.00),(41,12,6,'Abacate Hass','kg',130.00,1.00,130.00),(42,12,6,'Abacate Hass','kg',130.00,1.00,130.00),(42,13,1,'Manga Rosa','kg',85.00,2.00,170.00),(43,13,1,'Manga Rosa','kg',85.00,2.00,170.00),(43,14,2,'Laranja Fresca','kg',70.00,3.00,210.00),(44,14,2,'Laranja Fresca','kg',70.00,3.00,210.00),(44,15,3,'Pimento Verde','kg',120.00,1.00,120.00),(45,15,3,'Pimento Verde','kg',120.00,1.00,120.00),(45,16,4,'Cenoura','kg',60.00,2.00,120.00),(46,16,4,'Cenoura','kg',60.00,2.00,120.00),(46,17,5,'Repolho Verde','unidade',55.00,3.00,165.00),(47,17,5,'Repolho Verde','unidade',55.00,3.00,165.00),(47,18,6,'Alho Nacional','kg',240.00,1.00,240.00),(48,18,6,'Alho Nacional','kg',240.00,1.00,240.00),(48,19,1,'Gergelim','kg',190.00,2.00,380.00),(49,19,1,'Gergelim','kg',190.00,2.00,380.00),(49,20,2,'Batata-doce','kg',70.00,3.00,210.00),(50,20,2,'Batata-doce','kg',70.00,3.00,210.00),(50,21,3,'Pepino','kg',58.00,1.00,58.00),(51,21,3,'Pepino','kg',58.00,1.00,58.00),(51,22,4,'Abóbora','unidade',45.00,2.00,90.00),(52,22,4,'Abóbora','unidade',45.00,2.00,90.00),(52,23,5,'Ervilha','kg',155.00,3.00,465.00),(53,23,5,'Ervilha','kg',155.00,3.00,465.00),(53,24,6,'Chá de Rooibos','caixa',260.00,1.00,260.00),(54,24,6,'Chá de Rooibos','caixa',260.00,1.00,260.00),(54,25,1,'Mel de Colmeia','litro',300.00,2.00,600.00),(55,25,1,'Mel de Colmeia','litro',300.00,2.00,600.00),(55,26,2,'Ovos Caipira','dúzia',180.00,3.00,540.00),(56,26,2,'Ovos Caipira','dúzia',180.00,3.00,540.00),(56,27,3,'Farinha de Mandioca','saco',100.00,1.00,100.00),(57,27,3,'Farinha de Mandioca','saco',100.00,1.00,100.00),(57,28,4,'Caju Torrado','kg',280.00,2.00,560.00),(58,28,4,'Caju Torrado','kg',280.00,2.00,560.00),(58,29,5,'Salsa Fresca','unidade',50.00,3.00,150.00),(59,29,5,'Salsa Fresca','unidade',50.00,3.00,150.00),(59,30,6,'Quiabo','kg',95.00,1.00,95.00),(60,30,6,'Quiabo','kg',95.00,1.00,95.00),(60,1,1,'Tomate Fresco','kg',80.00,2.00,160.00),(61,1,1,'Tomate Fresco','kg',80.00,2.00,160.00),(61,2,2,'Milho Amarelo','kg',50.00,3.00,150.00),(62,2,2,'Milho Amarelo','kg',50.00,3.00,150.00),(62,3,3,'Feijão Manteiga','kg',140.00,1.00,140.00),(63,3,3,'Feijão Manteiga','kg',140.00,1.00,140.00),(63,4,4,'Mandioca Fresca','kg',65.00,2.00,130.00),(64,4,4,'Mandioca Fresca','kg',65.00,2.00,130.00),(64,5,5,'Batata Branca','kg',90.00,3.00,270.00),(65,5,5,'Batata Branca','kg',90.00,3.00,270.00),(65,6,6,'Cebola Roxa','kg',110.00,1.00,110.00),(66,6,6,'Cebola Roxa','kg',110.00,1.00,110.00),(66,7,1,'Amendoim Natural','kg',160.00,2.00,320.00),(67,7,1,'Amendoim Natural','kg',160.00,2.00,320.00),(67,8,2,'Arroz Local','kg',95.00,3.00,285.00),(68,8,2,'Arroz Local','kg',95.00,3.00,285.00),(68,9,3,'Couve Manteiga','unidade',45.00,1.00,45.00),(69,9,3,'Couve Manteiga','unidade',45.00,1.00,45.00),(69,10,4,'Alface Crocante','unidade',35.00,2.00,70.00),(70,10,4,'Alface Crocante','unidade',35.00,2.00,70.00),(70,11,5,'Banana Madura','kg',75.00,3.00,225.00),(71,11,5,'Banana Madura','kg',75.00,3.00,225.00),(71,12,6,'Abacate Hass','kg',130.00,1.00,130.00),(72,12,6,'Abacate Hass','kg',130.00,1.00,130.00),(72,13,1,'Manga Rosa','kg',85.00,2.00,170.00),(73,13,1,'Manga Rosa','kg',85.00,2.00,170.00),(73,14,2,'Laranja Fresca','kg',70.00,3.00,210.00),(74,14,2,'Laranja Fresca','kg',70.00,3.00,210.00),(74,15,3,'Pimento Verde','kg',120.00,1.00,120.00),(75,15,3,'Pimento Verde','kg',120.00,1.00,120.00),(75,16,4,'Cenoura','kg',60.00,2.00,120.00),(76,16,4,'Cenoura','kg',60.00,2.00,120.00),(76,17,5,'Repolho Verde','unidade',55.00,3.00,165.00),(77,17,5,'Repolho Verde','unidade',55.00,3.00,165.00),(77,18,6,'Alho Nacional','kg',240.00,1.00,240.00),(78,18,6,'Alho Nacional','kg',240.00,1.00,240.00),(78,19,1,'Gergelim','kg',190.00,2.00,380.00),(79,19,1,'Gergelim','kg',190.00,2.00,380.00),(79,20,2,'Batata-doce','kg',70.00,3.00,210.00),(80,20,2,'Batata-doce','kg',70.00,3.00,210.00),(80,21,3,'Pepino','kg',58.00,1.00,58.00),(81,21,3,'Pepino','kg',58.00,1.00,58.00),(81,22,4,'Abóbora','unidade',45.00,2.00,90.00),(82,22,4,'Abóbora','unidade',45.00,2.00,90.00),(82,23,5,'Ervilha','kg',155.00,3.00,465.00),(83,23,5,'Ervilha','kg',155.00,3.00,465.00),(83,24,6,'Chá de Rooibos','caixa',260.00,1.00,260.00),(84,24,6,'Chá de Rooibos','caixa',260.00,1.00,260.00),(84,25,1,'Mel de Colmeia','litro',300.00,2.00,600.00),(85,25,1,'Mel de Colmeia','litro',300.00,2.00,600.00),(85,26,2,'Ovos Caipira','dúzia',180.00,3.00,540.00),(86,26,2,'Ovos Caipira','dúzia',180.00,3.00,540.00),(86,27,3,'Farinha de Mandioca','saco',100.00,1.00,100.00),(87,27,3,'Farinha de Mandioca','saco',100.00,1.00,100.00),(87,28,4,'Caju Torrado','kg',280.00,2.00,560.00),(88,28,4,'Caju Torrado','kg',280.00,2.00,560.00),(88,29,5,'Salsa Fresca','unidade',50.00,3.00,150.00),(89,29,5,'Salsa Fresca','unidade',50.00,3.00,150.00),(89,30,6,'Quiabo','kg',95.00,1.00,95.00),(90,30,6,'Quiabo','kg',95.00,1.00,95.00),(90,1,1,'Tomate Fresco','kg',80.00,2.00,160.00),(91,1,1,'Tomate Fresco','kg',80.00,2.00,160.00),(91,2,2,'Milho Amarelo','kg',50.00,3.00,150.00),(92,2,2,'Milho Amarelo','kg',50.00,3.00,150.00),(92,3,3,'Feijão Manteiga','kg',140.00,1.00,140.00),(93,3,3,'Feijão Manteiga','kg',140.00,1.00,140.00),(93,4,4,'Mandioca Fresca','kg',65.00,2.00,130.00),(94,4,4,'Mandioca Fresca','kg',65.00,2.00,130.00),(94,5,5,'Batata Branca','kg',90.00,3.00,270.00),(95,5,5,'Batata Branca','kg',90.00,3.00,270.00),(95,6,6,'Cebola Roxa','kg',110.00,1.00,110.00),(96,6,6,'Cebola Roxa','kg',110.00,1.00,110.00),(96,7,1,'Amendoim Natural','kg',160.00,2.00,320.00),(97,7,1,'Amendoim Natural','kg',160.00,2.00,320.00),(97,8,2,'Arroz Local','kg',95.00,3.00,285.00),(98,8,2,'Arroz Local','kg',95.00,3.00,285.00),(98,9,3,'Couve Manteiga','unidade',45.00,1.00,45.00),(99,9,3,'Couve Manteiga','unidade',45.00,1.00,45.00),(99,10,4,'Alface Crocante','unidade',35.00,2.00,70.00),(100,10,4,'Alface Crocante','unidade',35.00,2.00,70.00),(100,11,5,'Banana Madura','kg',75.00,3.00,225.00);
INSERT INTO payments(order_id,amount,method,reference,status) SELECT id,total,"cash",CONCAT("REF-",order_number),"pending" FROM orders;
INSERT INTO reviews(user_id,product_id,order_id,rating,comment) VALUES (1,1,1,5,"Produto muito fresco e entrega combinada com facilidade."),(2,2,2,4,"Boa qualidade e embalagem cuidada."),(3,3,3,5,"Feijão excelente, voltarei a comprar."),(4,4,4,4,"Produto conforme a descrição."),(5,5,5,5,"Muito satisfeito com a compra."),(6,6,6,4,"Boa experiência com o produtor.");
INSERT INTO notifications(user_id,title,body,is_read) VALUES (2,"Novo pedido recebido","O pedido AG-202608-001 inclui Tomate Fresco.",0),(3,"Novo pedido recebido","O pedido AG-202608-002 inclui Milho Amarelo.",0),(8,"Pedido entregue","O pedido AG-202608-001 foi entregue.",1),(9,"Pedido atualizado","O pedido AG-202608-002 está em transporte.",0);
INSERT INTO farmer_verifications(farmer_id,status,notes,reviewed_by) VALUES (1,"verified","Documentação validada no seed",1),(2,"verified","Documentação validada no seed",1),(3,"verified","Documentação validada no seed",1),(4,"verified","Documentação validada no seed",1),(5,"pending","Aguardando análise",NULL),(6,"pending","Aguardando análise",NULL);
INSERT INTO audit_logs(user_id,action,module,description,ip) VALUES (1,"seed","system","Dados demonstrativos importados","127.0.0.1");

-- FUNCIONALIDADES OPERACIONAIS
-- AgroMercado: patch de funcionalidades operacionais
USE agro_marketplace;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(160) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_attempts_lookup(email,ip,attempted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_resets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_password_resets_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_password_resets_active(user_id,expires_at,used_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS delivery_zones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  province VARCHAR(80) NOT NULL,
  district VARCHAR(100) NOT NULL,
  fee DECIMAL(12,2) NOT NULL DEFAULT 0,
  eta_days TINYINT UNSIGNED NOT NULL DEFAULT 2,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_delivery_zone(province,district)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS complaints (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reporter_id INT UNSIGNED NOT NULL,
  reported_user_id INT UNSIGNED NULL,
  product_id INT UNSIGNED NULL,
  order_id BIGINT UNSIGNED NULL,
  subject VARCHAR(160) NOT NULL,
  description TEXT NOT NULL,
  status ENUM('open','in_review','resolved','dismissed') NOT NULL DEFAULT 'open',
  resolution TEXT NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_complaints_reporter FOREIGN KEY(reporter_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_complaints_reported_user FOREIGN KEY(reported_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_complaints_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE SET NULL,
  CONSTRAINT fk_complaints_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_complaints_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_complaints_status(status,created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS farmer_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  farmer_id INT UNSIGNED NOT NULL,
  document_type ENUM('identity','license','certificate','other') NOT NULL DEFAULT 'other',
  file_path VARCHAR(255) NOT NULL,
  status ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
  expires_at DATE NULL,
  notes TEXT NULL,
  reviewed_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_farmer_documents_farmer FOREIGN KEY(farmer_id) REFERENCES farmers(id) ON DELETE CASCADE,
  CONSTRAINT fk_farmer_documents_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_farmer_documents_status(farmer_id,status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notification_preferences (
  user_id INT UNSIGNED PRIMARY KEY,
  email_enabled TINYINT(1) NOT NULL DEFAULT 1,
  sms_enabled TINYINT(1) NOT NULL DEFAULT 0,
  whatsapp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  order_updates TINYINT(1) NOT NULL DEFAULT 1,
  marketing TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_notification_preferences_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS legal_acceptances (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  document_type ENUM('terms','privacy','cookies') NOT NULL,
  version VARCHAR(30) NOT NULL,
  ip VARCHAR(45) NULL,
  accepted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_legal_acceptances_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_legal_acceptance(user_id,document_type,version)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payment_webhooks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(40) NOT NULL,
  external_id VARCHAR(160) NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  signature_valid TINYINT(1) NOT NULL DEFAULT 0,
  payload_json LONGTEXT NOT NULL,
  processed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payment_webhook(provider,external_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NULL,
  is_secret TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS backup_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  backup_type ENUM('database','uploads','full') NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  status ENUM('started','completed','failed') NOT NULL,
  size_bytes BIGINT UNSIGNED NULL,
  checksum VARCHAR(128) NULL,
  error_message TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO delivery_zones(province,district,fee,eta_days) VALUES
('Maputo','Boane',80,1),('Maputo','Marracuene',100,1),('Maputo','Maputo',120,1),
('Gaza','Chókwè',150,2),('Manica','Chimoio',180,2),('Sofala','Beira',200,2),('Nampula','Nampula',220,3)
ON DUPLICATE KEY UPDATE fee=VALUES(fee),eta_days=VALUES(eta_days);
INSERT INTO notification_preferences(user_id) SELECT id FROM users ON DUPLICATE KEY UPDATE user_id=user_id;
INSERT INTO app_settings(setting_key,setting_value,is_secret) VALUES
('app_name','AgroMercado',0),('app_env','local',0),('mail_enabled','0',0),('payment_mpesa_enabled','0',0),('payment_emola_enabled','0',0),('payment_webhook_secret','',1)
ON DUPLICATE KEY UPDATE setting_key=setting_key;

INSERT INTO complaints(reporter_id,reported_user_id,product_id,subject,description,status)
SELECT 8,2,1,'Exemplo de revisão de entrega','O comprador solicita uma verificação demonstrativa do prazo e da condição de entrega do produto.', 'open'
WHERE NOT EXISTS (SELECT 1 FROM complaints WHERE subject='Exemplo de revisão de entrega');

-- ===== Patch de correções (outubro 2026) — também disponível em patch_2026_10.sql =====

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
