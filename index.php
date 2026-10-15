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
$heroFarmer = $farmers[0] ?? null;
?>

<!-- ============ HERO ============ -->
<section class="home-hero">
    <div class="container home-hero-grid">
        <div class="home-hero-copy">
            <span class="home-kicker">Direto do campo moçambicano</span>
            <h1>Compre direto de quem planta.</h1>
            <p class="home-lead">
                Produtos frescos de agricultores de todo o país. Preço justo para quem produz,
                entrega acompanhada para quem compra.
            </p>
            <div class="home-actions">
                <a class="btn btn-primary btn-lg" href="<?= base_path('marketplace.php') ?>">
                    Explorar produtos <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
                <a class="btn btn-ink-outline btn-lg" href="<?= base_path('cadastro.php?tipo=agricultor') ?>">
                    Quero vender
                </a>
            </div>
            <dl class="home-stats">
                <div>
                    <dt><?= number_format((int)$stats['farmers'], 0, ',', ' ') ?></dt>
                    <dd>agricultores registados</dd>
                </div>
                <div>
                    <dt><?= number_format((int)$stats['products'], 0, ',', ' ') ?></dt>
                    <dd>produtos à venda</dd>
                </div>
                <div>
                    <dt><?= number_format((int)$stats['orders'], 0, ',', ' ') ?></dt>
                    <dd>pedidos realizados</dd>
                </div>
            </dl>
        </div>

        <div class="home-hero-visual">
            <img src="<?= asset('img/hero-farm.jpg') ?>"
                 onerror="this.src='<?= asset('img/product-placeholder.svg') ?>'"
                 alt="Campo agrícola em Moçambique">
            <span class="home-hero-tag">Do campo para o mercado</span>
            <?php if ($heroFarmer): ?>
                <a class="home-hero-farmer" href="<?= base_path('agricultor.php?id=' . safe_int($heroFarmer['id'])) ?>">
                    <img src="<?= e(get_farmer_image(null, safe_int($heroFarmer['id']))) ?>"
                         alt="" loading="lazy">
                    <span>
                        <strong>
                            <?= e($heroFarmer['name']) ?>
                            <i class="bi bi-check-lg" role="img" aria-label="Verificado"></i>
                        </strong>
                        <small>
                            <?= e($heroFarmer['specialty'] ?: 'Agricultor verificado') ?>
                            · <?= e($heroFarmer['district'] ?: $heroFarmer['province'] ?: 'Moçambique') ?>
                        </small>
                    </span>
                </a>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ============ PRODUTOS + CATEGORIAS ============ -->
<section id="categorias" class="home-section">
    <div class="container">
        <div class="home-head">
            <div>
                <h2 id="produtos">Produtos em destaque</h2>
                <p>Escolha a categoria ou pesquise. A entrega é calculada pela sua zona.</p>
            </div>
            <a href="<?= base_path('marketplace.php') ?>" class="link-arrow">
                Ver os <?= number_format((int)$stats['products'], 0, ',', ' ') ?> produtos <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </div>

        <form class="home-search" action="<?= base_path('marketplace.php') ?>" role="search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <label class="visually-hidden" for="homeSearch">Pesquisar produtos</label>
            <input id="homeSearch" name="q" type="search" placeholder="Tomate, milho, mel…">
            <button class="btn btn-primary" type="submit">Pesquisar</button>
        </form>

        <nav class="category-pills" aria-label="Categorias">
            <a class="category-pill active" href="<?= base_path('marketplace.php') ?>">Todos</a>
            <?php foreach ($categories as $c): ?>
                <a class="category-pill" href="<?= base_path('marketplace.php?categoria=' . safe_int($c['id'])) ?>">
                    <?= category_icon_html($c['icon'] ?? null) ?>
                    <?= e($c['name']) ?>
                    <span class="category-pill-count"><?= safe_int($c['product_count']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($products): ?>
            <div class="product-grid">
                <?php foreach ($products as $p): ?>
                    <?php include __DIR__ . '/includes/product-card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="empty-state">Ainda não há produtos publicados.</p>
        <?php endif; ?>
    </div>
</section>

<!-- ============ COMO FUNCIONA ============ -->
<section id="como-funciona" class="home-band">
    <div class="container">
        <h2>Como funciona</h2>
        <ol class="home-steps">
            <li>
                <span class="home-step-num" aria-hidden="true">01</span>
                <strong>Escolha</strong>
                <p>Compare preços, origem e stock disponível de cada agricultor.</p>
            </li>
            <li>
                <span class="home-step-num" aria-hidden="true">02</span>
                <strong>Confirme e pague</strong>
                <p>Finalize o pedido no checkout e escolha o método de pagamento disponível.</p>
            </li>
            <li>
                <span class="home-step-num" aria-hidden="true">03</span>
                <strong>Acompanhe a entrega</strong>
                <p>A taxa e o prazo dependem da sua zona. Cada passo chega como notificação.</p>
            </li>
        </ol>
    </div>
</section>

<!-- ============ AGRICULTORES ============ -->
<?php if ($farmers): ?>
<section class="home-section">
    <div class="container">
        <div class="home-head">
            <div>
                <h2>Agricultores verificados</h2>
                <p>Conheça quem produz o que chega à sua mesa.</p>
            </div>
            <a href="<?= base_path('marketplace.php') ?>" class="link-arrow">
                Descobrir produtores <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
        <div class="farmer-grid">
            <?php foreach ($farmers as $f): ?>
                <a class="home-farmer" href="<?= base_path('agricultor.php?id=' . safe_int($f['id'])) ?>">
                    <img src="<?= e(get_farmer_image(null, safe_int($f['id']))) ?>"
                         loading="lazy"
                         alt="Fotografia de <?= e($f['name']) ?>">
                    <span>
                        <strong>
                            <?= e($f['name']) ?>
                            <i class="bi bi-check-lg" role="img" aria-label="Verificado"></i>
                        </strong>
                        <small>
                            <?= e($f['specialty'] ?: 'Agricultor') ?>
                            · <?= e($f['district'] ?: $f['province'] ?: 'Moçambique') ?>
                        </small>
                        <small class="home-farmer-count"><?= safe_int($f['products_count']) ?> produtos disponíveis</small>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ============ CTA ============ -->
<section class="home-cta-wrap">
    <div class="container">
        <div class="home-cta">
            <div>
                <h2>Produz? Venda a sua colheita aqui.</h2>
                <p>Crie a conta, envie o documento de identificação e publique os produtos assim que a verificação for aprovada.</p>
            </div>
            <a class="btn btn-ink btn-lg" href="<?= base_path('cadastro.php?tipo=agricultor') ?>">
                Criar conta de agricultor
            </a>
        </div>
    </div>
</section>

<?php include __DIR__.'/includes/footer.php'; ?>
