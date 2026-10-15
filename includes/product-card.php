<?php
$p = $p ?? [];
$cardId    = safe_int($p['id'] ?? 0);
$cardUrl   = base_path('produto.php?id=' . $cardId);
$fav       = is_favorite($cardId);
$cardUser  = function_exists('user') ? user() : null;
// Em produto.php o app.js lê #product-quantity (quantidade do produto principal),
// por isso os cartões relacionados apontam para o produto em vez de adicionar.
$canAddCart = $cardUser && ($cardUser['role'] ?? '') === 'buyer'
    && basename($_SERVER['PHP_SELF'] ?? '') !== 'produto.php'
    && (float)($p['stock'] ?? 1) > 0
    && (float)($p['quantity_min'] ?? 1) <= 1;
?>

<div class="product-card h-100">
    <div class="product-img-wrap">
        <img src="<?= e(get_product_image($p['main_image'] ?? null)) ?>"
             onerror="this.src='<?= asset('img/product-placeholder.svg') ?>'"
             loading="lazy"
             alt="<?= e($p['name'] ?? 'Produto agrícola') ?>">
        <span class="product-category"><?= e($p['category_name'] ?? 'Agrícola') ?></span>
        <button type="button" class="favorite-btn<?= $fav ? ' saved' : '' ?>" data-favorite-product="<?= $cardId ?>" aria-label="Guardar <?= e($p['name'] ?? 'produto') ?> nos favoritos">
            <i class="bi bi-heart<?= $fav ? '-fill' : '' ?>"></i>
        </button>
    </div>

    <div class="product-body">
        <div class="product-head">
            <h3 class="product-title">
                <a class="product-link" href="<?= $cardUrl ?>"><?= e($p['name'] ?? 'Produto') ?></a>
            </h3>
            <span class="product-origin">
                <?php if (($p['verification_status'] ?? '') === 'verified'): ?>
                    <i class="bi bi-check-lg verified-badge" role="img" aria-label="Agricultor verificado"></i>
                <?php endif; ?>
                <?= e($p['farmer_name'] ?? 'Produtor') ?> · <?= e($p['district'] ?? $p['province'] ?? 'Moçambique') ?>
            </span>
        </div>

        <div class="rating">
            <i class="bi bi-star-fill" aria-hidden="true"></i>
            <?= number_format((float)($p['rating'] ?? 0), 1) ?>
            <span class="text-muted">(<?= safe_int($p['review_count'] ?? 0) ?>)</span>
        </div>

        <div class="product-foot">
            <div class="price">
                <?= number_format((float)($p['price'] ?? 0), 2, ',', '.') ?> <small>MT / <?= e($p['unit'] ?? 'un') ?></small>
            </div>
            <?php if ($canAddCart): ?>
                <button type="button" class="product-add" data-add-cart="<?= $cardId ?>" aria-label="Adicionar <?= e($p['name'] ?? 'produto') ?> ao carrinho">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                </button>
            <?php else: ?>
                <a class="product-add" href="<?= $cardUrl ?>" aria-label="Ver <?= e($p['name'] ?? 'produto') ?>" tabindex="-1">
                    <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>
