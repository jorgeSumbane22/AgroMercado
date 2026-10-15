<?php
require_once __DIR__.'/includes/functions.php';

$id = safe_int($_GET['id'] ?? 0);

/* ============================================================
   CARREGAR AGRICULTOR
   ============================================================ */
$s = db()->prepare('
    SELECT f.*, u.name, u.email, u.phone, u.created_at
    FROM farmers f
    JOIN users u ON u.id = f.user_id
    WHERE f.id = ?
');
$s->execute([$id]);
$farmer = $s->fetch();

if (!$farmer) {
    http_response_code(404);
    exit('Agricultor não encontrado');
}

/* ============================================================
   PRODUTOS ATIVOS
   ============================================================ */
$p = db()->prepare("
    SELECT p.*, c.name AS category_name, u.name AS farmer_name, f.verification_status,
           (SELECT COALESCE(AVG(r.rating), 0) FROM reviews r WHERE r.product_id = p.id AND r.status = 'visible') rating,
           (SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.id AND r.status = 'visible') review_count
    FROM products p
    JOIN categories c ON c.id = p.category_id
    JOIN farmers f ON f.id = p.farmer_id
    JOIN users u ON u.id = f.user_id
    WHERE p.farmer_id = ? AND p.status = 'active' AND c.is_active = 1
    ORDER BY p.created_at DESC
");
$p->execute([$id]);
$products = $p->fetchAll() ?: [];

/* ============================================================
   ESTATÍSTICAS
   ============================================================ */
$isVerified = ($farmer['verification_status'] ?? '') === 'verified';
$location   = $farmer['district'] ?: ($farmer['province'] ?: 'Moçambique');
$specialty  = $farmer['specialty'] ?: 'Produção agrícola local';
$bio        = $farmer['bio'] ?: 'Agricultor dedicado à produção de alimentos frescos para a comunidade.';
$memberSince = !empty($farmer['created_at']) ? date('M Y', strtotime($farmer['created_at'])) : '—';
$productsCount = count($products);

/* Contagem de categorias distintas */
$categories = [];
foreach ($products as $prod) {
    $c = $prod['category_name'] ?? '';
    if ($c) $categories[$c] = ($categories[$c] ?? 0) + 1;
}

$page_title = $farmer['name'];

/* ============================================================
   META TAGS (SEO)
   ============================================================ */
$meta = [
    'description' => 'Perfil de ' . $farmer['name'] . ' — ' . $specialty . ' em ' . $location . '. ' . $productsCount . ' produtos disponíveis no AgroMercado.',
    'og_title'    => $farmer['name'] . ' · AgroMercado',
    'og_desc'     => $specialty . ' em ' . $location,
    'og_image'    => function_exists('get_farmer_image') ? get_farmer_image($farmer['avatar'] ?? null, $id) : '',
];

include __DIR__.'/includes/header.php';
?>

<!-- ============================================================
     HERO — CAPA + AVATAR
     ============================================================ -->
<section class="farmer-page-hero">
    <div class="farmer-cover">
        <div class="farmer-cover-glow"></div>
    </div>

    <div class="container">
        <div class="farmer-head">

            <div class="farmer-avatar-wrap">
                <div class="farmer-avatar-xl">
                    <img src="<?= e(get_farmer_image($farmer['avatar'] ?? null, $id)) ?>"
                         loading="lazy"
                         alt="Fotografia de <?= e($farmer['name']) ?>">
                </div>
                <?php if ($isVerified): ?>
                    <span class="farmer-verified-badge" title="Verificado">
                        <i class="bi bi-patch-check-fill"></i>
                    </span>
                <?php endif; ?>
            </div>

            <div class="farmer-head-info">
                <span class="eyebrow text-light">Perfil do agricultor</span>
                <h1 class="farmer-head-name">
                    <?= e($farmer['name']) ?>
                </h1>

                <p class="farmer-head-meta">
                    <span><i class="bi bi-geo-alt"></i> <?= e($location) ?></span>
                    <span class="farmer-meta-sep">·</span>
                    <span><i class="bi bi-flower1"></i> <?= e($specialty) ?></span>
                    <span class="farmer-meta-sep">·</span>
                    <span>
                        <i class="bi bi-<?= $isVerified ? 'patch-check-fill' : 'hourglass-split' ?>"></i>
                        <?= $isVerified ? 'Verificado' : 'Em verificação' ?>
                    </span>
                </p>

                <div class="farmer-head-actions">
                    <a href="#produtos" class="btn btn-light">
                        <i class="bi bi-basket2"></i> Ver produtos
                    </a>
                    <?php if (!empty($farmer['phone'])): ?>
                        <a href="tel:<?= e($farmer['phone']) ?>" class="btn btn-outline-light">
                            <i class="bi bi-telephone"></i> Contactar
                        </a>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- ============================================================
     CONTEÚDO PRINCIPAL
     ============================================================ -->
<section class="container farmer-page-content">

    <!-- ===== MÉTRICAS ===== -->
    <div class="row g-3 mb-5">
        <div class="col-6 col-md-3">
            <div class="farmer-stat">
                <span class="farmer-stat-icon farmer-stat-<?= $isVerified ? 'success' : 'warning' ?>">
                    <i class="bi bi-<?= $isVerified ? 'shield-check' : 'hourglass-split' ?>"></i>
                </span>
                <div>
                    <small>Estado</small>
                    <strong><?= $isVerified ? 'Verificado' : 'Em verificação' ?></strong>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="farmer-stat">
                <span class="farmer-stat-icon farmer-stat-primary">
                    <i class="bi bi-basket2"></i>
                </span>
                <div>
                    <small>Produtos</small>
                    <strong><?= $productsCount ?> disponíve<?= $productsCount === 1 ? 'l' : 'is' ?></strong>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="farmer-stat">
                <span class="farmer-stat-icon farmer-stat-info">
                    <i class="bi bi-tags"></i>
                </span>
                <div>
                    <small>Categorias</small>
                    <strong><?= count($categories) ?></strong>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="farmer-stat">
                <span class="farmer-stat-icon farmer-stat-primary">
                    <i class="bi bi-calendar-check"></i>
                </span>
                <div>
                    <small>Membro desde</small>
                    <strong><?= e($memberSince) ?></strong>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== SOBRE ===== -->
    <div class="farmer-about mb-5">
        <div class="row g-4 align-items-start">

            <div class="col-lg-8">
                <span class="eyebrow">Sobre o produtor</span>
                <h2 class="farmer-about-title"><?= e($specialty) ?></h2>
                <p class="farmer-about-text"><?= nl2br(e($bio)) ?></p>
            </div>

            <div class="col-lg-4">
                <div class="farmer-about-card">
                    <h5>
                        <i class="bi bi-info-circle"></i> Detalhes
                    </h5>

                    <ul class="farmer-about-list">
                        <?php if (!empty($farmer['phone'])): ?>
                            <li>
                                <span><i class="bi bi-telephone"></i> Telefone</span>
                                <strong><?= e($farmer['phone']) ?></strong>
                            </li>
                        <?php endif; ?>

                        <?php if (!empty($farmer['email'])): ?>
                            <li>
                                <span><i class="bi bi-envelope"></i> Email</span>
                                <strong><?= e($farmer['email']) ?></strong>
                            </li>
                        <?php endif; ?>

                        <li>
                            <span><i class="bi bi-geo-alt"></i> Localização</span>
                            <strong>
                                <?= e($location) ?>
                                <?php if (!empty($farmer['locality'])): ?>
                                    · <?= e($farmer['locality']) ?>
                                <?php endif; ?>
                            </strong>
                        </li>

                        <li>
                            <span><i class="bi bi-calendar3"></i> Membro desde</span>
                            <strong><?= e($memberSince) ?></strong>
                        </li>
                    </ul>
                </div>
            </div>

        </div>
    </div>

    <!-- ===== PRODUTOS ===== -->
    <div id="produtos" class="farmer-products">
        <div class="farmer-products-head">
            <div>
                <span class="eyebrow">Produção disponível</span>
                <h2 class="farmer-products-title">
                    Produtos de <?= e(explode(' ', $farmer['name'])[0]) ?>
                </h2>
                <p class="farmer-products-sub">
                    <?= $productsCount ?> produto<?= $productsCount !== 1 ? 's' : '' ?> no catálogo
                </p>
            </div>

            <?php if (!empty($categories)): ?>
                <div class="farmer-products-chips">
                    <?php $i = 0; foreach ($categories as $cat => $n): if ($i++ >= 4) break; ?>
                        <span class="farmer-chip">
                            <?= e($cat) ?>
                            <strong><?= (int)$n ?></strong>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (empty($products)): ?>
            <div class="empty-state py-5">
                <i class="bi bi-basket2" style="font-size:2.5rem;"></i>
                <p class="mt-3 mb-0">Este agricultor ainda não tem produtos disponíveis.</p>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($products as $p): ?>
                    <div class="col-sm-6 col-lg-3">
                        <?php include __DIR__.'/includes/product-card.php'; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</section>

<?php include __DIR__.'/includes/footer.php'; ?>