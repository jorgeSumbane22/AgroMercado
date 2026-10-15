<?php $p = $p ?? []; ?>

<div class="product-card h-100">
    <div class="product-img-wrap">
        <img src="<?= e(get_product_image($p['main_image'] ?? null)) ?>"
             onerror="this.src='<?= asset('img/product-placeholder.svg') ?>'"
             loading="lazy"
             alt="<?= e($p['name'] ?? 'Produto agrícola') ?>">
        <span class="product-category"><?= e($p['category_name'] ?? 'Agrícola') ?></span>
        <?php if (($p['verification_status'] ?? '') === 'verified'): ?>
            <span class="verified-badge"><i class="bi bi-patch-check-fill"></i></span>
        <?php endif; ?>
    </div>

    <div class="p-3 d-flex flex-column h-100">
        <div class="d-flex justify-content-between gap-2">
            <h5 class="product-title mb-1"><?= e($p['name']) ?></h5>
            <?php $fav = is_favorite(safe_int($p['id'])); ?>
            <button class="favorite-btn<?= $fav ? ' saved' : '' ?>" data-favorite-product="<?= safe_int($p['id']) ?>" aria-label="Favorito">
                <i class="bi bi-heart<?= $fav ? '-fill' : '' ?>"></i>
            </button>
        </div>

        <div class="rating mb-2">
            <i class="bi bi-star-fill"></i>
            <?= number_format((float)($p['rating'] ?? 0), 1) ?>
            <span class="text-muted">(<?= safe_int($p['review_count'] ?? 0) ?>)</span>
        </div>

        <div class="mt-auto">
            <div class="price">
                <?= money($p['price']) ?> <small>/ <?= e($p['unit']) ?></small>
            </div>
            <div class="small text-muted mb-3">
                <i class="bi bi-geo-alt me-1"></i><?= e($p['district'] ?? $p['province'] ?? 'Moçambique') ?>
                · <?= e($p['farmer_name'] ?? 'Produtor') ?>
            </div>
            <a class="btn btn-outline-primary w-100" href="<?= base_path('produto.php?id=' . safe_int($p['id'])) ?>">
                Ver produto <i class="bi bi-arrow-right ms-1"></i>
            </a>
        </div>
    </div>
</div>