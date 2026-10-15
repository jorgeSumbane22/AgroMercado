<?php
require_once __DIR__.'/includes/functions.php';

$page_title = 'Do campo para o mercado';

// Produtos em destaque
$products = db()->query("
    SELECT p.*, c.name category_name, u.name farmer_name, f.verification_status,
           (SELECT COALESCE(AVG(rating), 0) FROM reviews r WHERE r.product_id = p.id AND r.status = 'visible') rating,
           (SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.id AND r.status = 'visible') review_count
    FROM products p
    JOIN categories c ON c.id = p.category_id
    JOIN farmers f ON f.id = p.farmer_id
    JOIN users u ON u.id = f.user_id
    WHERE p.status = 'active' AND c.is_active = 1
    ORDER BY p.featured DESC, p.sales_count DESC, p.created_at DESC
    LIMIT 8
")->fetchAll();

// Categorias
$categories = db()->query("
    SELECT c.*, COUNT(p.id) product_count
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id AND p.status = 'active'
    WHERE c.is_active = 1
    GROUP BY c.id
    ORDER BY c.id
    LIMIT 6
")->fetchAll();

// Agricultores
$farmers = db()->query("
    SELECT f.*, u.name,
           (SELECT COUNT(*) FROM products p WHERE p.farmer_id = f.id AND p.status = 'active') products_count
    FROM farmers f
    JOIN users u ON u.id = f.user_id
    WHERE f.verification_status = 'verified'
    ORDER BY products_count DESC
    LIMIT 4
")->fetchAll();

// Estatísticas
$stats = [
    'farmers'  => db()->query('SELECT COUNT(*) FROM farmers')->fetchColumn(),
    'products' => db()->query("SELECT COUNT(*) FROM products p JOIN categories c ON c.id = p.category_id WHERE p.status = 'active' AND c.is_active = 1")->fetchColumn(),
    'buyers'   => db()->query('SELECT COUNT(*) FROM buyers')->fetchColumn(),
    'orders'   => db()->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
];

include __DIR__.'/includes/header.php';
?>

<!-- ============ HERO ============ -->
<section class="hero-section">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="eyebrow">
                    <i class="bi bi-geo-alt-fill"></i> Marketplace agrícola de Moçambique
                </span>
                <h1>Do campo<br>para o <em>mercado.</em></h1>
                <p class="lead">
                    Produtos agrícolas frescos, diretamente dos produtores.
                    Uma cadeia alimentar mais justa, curta e transparente.
                </p>
                <div class="hero-actions">
                    <a class="btn btn-primary btn-lg" href="<?= base_path('marketplace.php') ?>">
                        <i class="bi bi-basket2-fill"></i> Comprar produtos
                    </a>
                    <a class="btn btn-ghost btn-lg" href="<?= base_path('cadastro.php?tipo=agricultor') ?>">
                        <i class="bi bi-shop"></i> Quero vender
                    </a>
                </div>
                <ul class="hero-trust">
                    <li><i class="bi bi-patch-check-fill"></i> Produtores verificados</li>
                    <li><i class="bi bi-bag-check-fill"></i> Compra simples</li>
                    <li><i class="bi bi-truck"></i> Entrega local</li>
                </ul>
            </div>
            <div class="col-lg-6">
                <div class="hero-visual">
                    <img src="<?= asset('img/hero-farm.jpg') ?>"
                         onerror="this.src='<?= asset('img/product-placeholder.svg') ?>'"
                         alt="Agricultora num campo verde">
                    <div class="hero-caption">
                        <span class="hero-caption-number"><?= number_format((int)$stats['farmers'], 0, ',', ' ') ?></span>
                        <span class="hero-caption-label">
                            <i class="bi bi-people-fill"></i> agricultores registados
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ SEARCH ============ -->
<section class="search-overlap">
    <div class="container">
        <form class="search-box" action="<?= base_path('marketplace.php') ?>">
            <i class="bi bi-search search-icon"></i>
            <input name="q"
                   placeholder="O que procura hoje? Tomate, milho, batata…"
                   aria-label="Pesquisar produtos">
            <button class="btn btn-primary">
                <i class="bi bi-arrow-right"></i> Pesquisar
            </button>
        </form>
    </div>
</section>

<!-- ============ CATEGORIAS ============ -->
<section id="categorias" class="section-pad">
    <div class="container">
        <div class="section-heading">
            <div>
                <span class="eyebrow"><i class="bi bi-grid-3x3-gap-fill"></i> Explore por categoria</span>
                <h2>Escolha o que vai levar para casa</h2>
            </div>
            <a href="<?= base_path('marketplace.php') ?>" class="link-arrow">
                Ver tudo <i class="bi bi-arrow-right"></i>
            </a>
        </div>
        <div class="row g-3">
            <?php foreach ($categories as $c): ?>
                <div class="col-6 col-md-4 col-lg-2">
                    <a class="category-tile" href="<?= base_path('marketplace.php?categoria=' . safe_int($c['id'])) ?>">
                        <span class="category-media">
                            <img src="<?= e(get_category_image(safe_int($c['id']))) ?>"
                                 loading="lazy"
                                 alt="<?= e($c['name']) ?>">
                        </span>
                        <strong><?= category_icon_html($c['icon'] ?? null) ?> <?= e($c['name']) ?></strong>
                        <small><i class="bi bi-box-seam"></i> <?= safe_int($c['product_count']) ?> produtos</small>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============ PRODUTOS ============ -->
<section class="section-pad bg-soft">
    <div class="container">
        <div class="section-heading">
            <div>
                <span class="eyebrow"><i class="bi bi-star-fill"></i> Seleção da semana</span>
                <h2>Produtos em destaque</h2>
                <p class="text-muted mb-0">O melhor da produção local, selecionado para si.</p>
            </div>
            <a href="<?= base_path('marketplace.php') ?>" class="link-arrow">
                Explorar marketplace <i class="bi bi-arrow-right"></i>
            </a>
        </div>
        <div class="row g-4">
            <?php foreach ($products as $p): ?>
                <div class="col-sm-6 col-lg-3">
                    <?php include __DIR__ . '/includes/product-card.php'; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============ COMO FUNCIONA ============ -->
<section id="como-funciona" class="section-pad">
    <div class="container">
        <div class="section-heading center">
            <div>
                <span class="eyebrow"><i class="bi bi-info-circle-fill"></i> Simples e transparente</span>
                <h2>Como funciona</h2>
                <p class="text-muted">Uma nova forma de aproximar quem produz de quem compra.</p>
            </div>
        </div>
        <div class="row g-4">
            <?php foreach ([
                ['01', 'bi-person-plus-fill',  'O agricultor entra',   'Cria o seu perfil e apresenta a sua produção.'],
                ['02', 'bi-camera-fill',       'Publica produtos',     'Adiciona produtos, preços, stock e localização.'],
                ['03', 'bi-search',            'O comprador encontra', 'Pesquisa, compara e compra com confiança.'],
                ['04', 'bi-truck',             'Compra entregue',      'O produtor prepara e acompanha cada pedido.'],
            ] as $step): ?>
                <div class="col-md-6 col-lg-3">
                    <div class="process-card">
                        <span class="process-number"><?= $step[0] ?></span>
                        <span class="process-icon"><i class="bi <?= $step[1] ?>"></i></span>
                        <h5><?= $step[2] ?></h5>
                        <p><?= $step[3] ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============ ESTATÍSTICAS ============ -->
<section class="stats-section">
    <div class="container">
        <div class="row text-center g-4">
            <?php foreach ([
                ['farmers',  'bi-people-fill',        'Agricultores'],
                ['products', 'bi-basket2-fill',       'Produtos publicados'],
                ['buyers',   'bi-person-check-fill',  'Compradores'],
                ['orders',   'bi-receipt',            'Pedidos realizados'],
            ] as $s): ?>
                <div class="col-6 col-lg-3">
                    <div class="stat-item">
                        <span class="stat-icon"><i class="bi <?= $s[1] ?>"></i></span>
                        <strong><?= number_format((int)$stats[$s[0]], 0, ',', ' ') ?></strong>
                        <span class="stat-label"><?= $s[2] ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============ AGRICULTORES ============ -->
<section class="section-pad bg-soft">
    <div class="container">
        <div class="section-heading">
            <div>
                <span class="eyebrow"><i class="bi bi-person-badge-fill"></i> Conheça quem produz</span>
                <h2>Agricultores em destaque</h2>
            </div>
            <a href="<?= base_path('marketplace.php') ?>" class="link-arrow">
                Descobrir produtores <i class="bi bi-arrow-right"></i>
            </a>
        </div>
        <div class="row g-4">
            <?php foreach ($farmers as $f): ?>
                <div class="col-sm-6 col-lg-3">
                    <a class="farmer-card" href="<?= base_path('agricultor.php?id=' . safe_int($f['id'])) ?>">
                        <div class="farmer-photo-wrap">
                            <img class="farmer-photo"
                                 src="<?= e(get_farmer_image(null, safe_int($f['id']))) ?>"
                                 loading="lazy"
                                 alt="Fotografia de <?= e($f['name']) ?>">
                            <span class="farmer-photo-badge">
                                <i class="bi bi-patch-check-fill"></i>
                            </span>
                        </div>
                        <div class="farmer-info">
                            <h5><?= e($f['name']) ?></h5>
                            <p class="farmer-location">
                                <i class="bi bi-geo-alt-fill"></i>
                                <?= e($f['district'] ?: $f['province'] ?: 'Moçambique') ?>
                            </p>
                            <small>
                                <i class="bi bi-box-seam"></i>
                                <?= safe_int($f['products_count']) ?> produtos disponíveis
                            </small>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============ CTA ============ -->
<section class="cta-section">
    <div class="container">
        <div class="cta-box">
            <div>
                <span class="eyebrow text-light">
                    <i class="bi bi-rocket-takeoff-fill"></i> Faça parte da mudança
                </span>
                <h2>Comece a comercializar<br>os seus produtos hoje.</h2>
                <p>Mais visibilidade, mais clientes e uma relação direta com o mercado.</p>
            </div>
            <a class="btn btn-light btn-lg" href="<?= base_path('cadastro.php?tipo=agricultor') ?>">
                <i class="bi bi-person-plus-fill"></i> Criar perfil de agricultor
            </a>
        </div>
    </div>
</section>

<?php include __DIR__.'/includes/footer.php'; ?>