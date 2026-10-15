<?php
require_once __DIR__ . '/includes/functions.php';

$page_title = 'Marketplace';

// =========================================================================
// FILTROS
// =========================================================================
$where  = ["p.status='active'", 'c.is_active=1'];
$params = [];
$q      = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);

if ($q) {
    $where[] = '(p.name LIKE ? OR p.description LIKE ? OR c.name LIKE ? OR u.name LIKE ? OR p.district LIKE ?)';
    for ($i = 0; $i < 5; $i++) $params[] = "%$q%";
}

if (!empty($_GET['categoria'])) {
    $where[]  = 'p.category_id=?';
    $params[] = safe_int($_GET['categoria']);
}

if (($_GET['min'] ?? '') !== '') {
    $where[]  = 'p.price>=?';
    $params[] = (float)$_GET['min'];
}

if (($_GET['max'] ?? '') !== '') {
    $where[]  = 'p.price<=?';
    $params[] = (float)$_GET['max'];
}

if (!empty($_GET['localizacao'])) {
    $where[] = '(p.province LIKE ? OR p.district LIKE ? OR p.locality LIKE ?)';
    for ($i = 0; $i < 3; $i++) {
        $params[] = '%' . trim($_GET['localizacao']) . '%';
    }
}

if (isset($_GET['stock']) && $_GET['stock'] === '1') {
    $where[] = 'p.stock>0';
}

if (isset($_GET['verified']) && $_GET['verified'] === '1') {
    $where[] = "f.verification_status='verified'";
}

// =========================================================================
// ORDENAÇÃO
// =========================================================================
$sort = [
    'recent' => 'p.created_at DESC',
    'low'    => 'p.price ASC',
    'high'   => 'p.price DESC',
    'sales'  => 'p.sales_count DESC',
    'rating' => '(SELECT COALESCE(AVG(r.rating),0) FROM reviews r WHERE r.product_id=p.id AND r.status="visible") DESC',
][$_GET['sort'] ?? 'recent'] ?? 'p.created_at DESC';

// =========================================================================
// PAGINAÇÃO + QUERY
// =========================================================================
$per  = 12;
$page = max(1, safe_int($_GET['page'] ?? 1, 1));

$join = ' FROM products p
          JOIN categories c ON c.id=p.category_id
          JOIN farmers f    ON f.id=p.farmer_id
          JOIN users u      ON u.id=f.user_id';

$condition = ' WHERE ' . implode(' AND ', $where);

// Total
$count = db()->prepare('SELECT COUNT(*)' . $join . $condition);
$count->execute($params);
$total = (int)$count->fetchColumn();

// Lista paginada
$sql = "SELECT p.*, c.name category_name, u.name farmer_name, f.verification_status,
               (SELECT COALESCE(AVG(r.rating),0) FROM reviews r WHERE r.product_id=p.id AND r.status='visible') rating,
               (SELECT COUNT(*) FROM reviews r WHERE r.product_id=p.id AND r.status='visible') review_count"
       . $join . $condition
       . ' ORDER BY ' . $sort
       . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per);

$s = db()->prepare($sql);
$s->execute($params);
$products = $s->fetchAll();

$cats = db()->query('SELECT * FROM categories WHERE is_active=1 ORDER BY name')->fetchAll();

// Nome da categoria selecionada (para chips)
$categoriaAtual = null;
if (!empty($_GET['categoria'])) {
    foreach ($cats as $c) {
        if ($c['id'] == safe_int($_GET['categoria'])) {
            $categoriaAtual = $c;
            break;
        }
    }
}

// Conta filtros ativos
$filtrosAtivos = 0;
if ($q) $filtrosAtivos++;
if (!empty($_GET['categoria'])) $filtrosAtivos++;
if (($_GET['min'] ?? '') !== '') $filtrosAtivos++;
if (($_GET['max'] ?? '') !== '') $filtrosAtivos++;
if (!empty($_GET['localizacao'])) $filtrosAtivos++;
if (isset($_GET['stock'])) $filtrosAtivos++;
if (isset($_GET['verified'])) $filtrosAtivos++;

include __DIR__ . '/includes/header.php';
?>

<!-- ============ HERO ============ -->
<section class="marketplace-hero">
    <div class="container">
        <div class="marketplace-hero-inner">
            <div>
                <span class="eyebrow text-light">
                    <i class="bi bi-geo-alt-fill"></i> Mercado local
                </span>
                <h1>Produtos agrícolas</h1>
                <p>Produção fresca, diretamente de quem cultiva.</p>
            </div>
            <div class="marketplace-hero-stats">
                <div class="hero-stat">
                    <strong><?= number_format($total) ?></strong>
                    <span>produtos</span>
                </div>
                <div class="hero-stat">
                    <strong><?= count($cats) ?></strong>
                    <span>categorias</span>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="container marketplace-wrap">
    <div class="row g-4">

        <!-- ============ FILTROS ============ -->
        <aside class="col-lg-3">
            <form class="filter-panel" method="get">

                <!-- Mobile: botão colapsar -->
                <button class="filter-toggle d-lg-none" type="button"
                        data-bs-toggle="collapse" data-bs-target="#filterBody">
                    <i class="bi bi-sliders"></i> Filtros
                    <?php if ($filtrosAtivos): ?>
                        <span class="filter-count"><?= $filtrosAtivos ?></span>
                    <?php endif; ?>
                    <i class="bi bi-chevron-down ms-auto"></i>
                </button>

                <div class="collapse d-lg-block" id="filterBody">

                    <div class="filter-head">
                        <h5>
                            <i class="bi bi-sliders"></i> Filtrar
                        </h5>
                        <?php if ($filtrosAtivos): ?>
                            <a href="<?= base_path('marketplace.php') ?>" class="filter-clear">
                                Limpar
                            </a>
                        <?php endif; ?>
                    </div>

                    <!-- Pesquisa -->
                    <div class="filter-group">
                        <label class="filter-label">
                            <i class="bi bi-search"></i> Pesquisar
                        </label>
                        <input name="q" value="<?= e($q) ?>"
                               class="form-control" placeholder="Tomate, milho...">
                    </div>

                    <!-- Categoria -->
                    <div class="filter-group">
                        <label class="filter-label">
                            <i class="bi bi-grid"></i> Categoria
                        </label>
                        <select name="categoria" class="form-select">
                            <option value="">Todas as categorias</option>
                            <?php foreach ($cats as $c): ?>
                                <option value="<?= $c['id'] ?>"
                                        <?= $c['id'] == ($_GET['categoria'] ?? '') ? 'selected' : '' ?>>
                                    <?= e($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Preço -->
                    <div class="filter-group">
                        <label class="filter-label">
                            <i class="bi bi-cash-coin"></i> Preço (MT)
                        </label>
                        <div class="filter-range">
                            <input type="number" name="min" value="<?= e($_GET['min'] ?? '') ?>"
                                   class="form-control" placeholder="Mín.">
                            <span>—</span>
                            <input type="number" name="max" value="<?= e($_GET['max'] ?? '') ?>"
                                   class="form-control" placeholder="Máx.">
                        </div>
                    </div>

                    <!-- Localização -->
                    <div class="filter-group">
                        <label class="filter-label">
                            <i class="bi bi-geo-alt"></i> Localização
                        </label>
                        <input name="localizacao" value="<?= e($_GET['localizacao'] ?? '') ?>"
                               class="form-control" placeholder="Boane, Maputo...">
                    </div>

                    <!-- Checkboxes -->
                    <div class="filter-group">
                        <label class="filter-check">
                            <input type="checkbox" name="stock" value="1"
                                   <?= isset($_GET['stock']) ? 'checked' : '' ?>>
                            <span class="filter-check-box"></span>
                            <span>
                                <strong>Disponível em stock</strong>
                                <small>Só produtos prontos a enviar</small>
                            </span>
                        </label>

                        <label class="filter-check">
                            <input type="checkbox" name="verified" value="1"
                                   <?= isset($_GET['verified']) ? 'checked' : '' ?>>
                            <span class="filter-check-box"></span>
                            <span>
                                <strong>Agricultor verificado</strong>
                                <small>Produtores confirmados</small>
                            </span>
                        </label>
                    </div>

                    <button class="btn btn-primary w-100 filter-submit">
                        <i class="bi bi-check2-circle"></i> Aplicar filtros
                    </button>

                </div>
            </form>
        </aside>

        <!-- ============ RESULTADOS ============ -->
        <div class="col-lg-9">

            <!-- Cabeçalho de resultados -->
            <div class="results-head">
                <div>
                    <h2>
                        <?= number_format($total) ?>
                        <span><?= $total === 1 ? 'produto' : 'produtos' ?></span>
                    </h2>
                    <?php if ($q): ?>
                        <p class="results-sub">Resultados para "<?= e($q) ?>"</p>
                    <?php endif; ?>
                </div>

                <div class="results-sort">
                    <label for="sort">
                        <i class="bi bi-sort-down"></i> Ordenar
                    </label>
                    <select id="sort" class="form-select" onchange="location.href=this.value">
                        <option value="<?= e('?' . http_build_query(array_merge($_GET, ['sort' => 'recent', 'page' => 1]))) ?>" <?= ($_GET['sort'] ?? 'recent') === 'recent' ? 'selected' : '' ?>>Mais recentes</option>
                        <option value="<?= e('?' . http_build_query(array_merge($_GET, ['sort' => 'low',    'page' => 1]))) ?>" <?= ($_GET['sort'] ?? '') === 'low'    ? 'selected' : '' ?>>Menor preço</option>
                        <option value="<?= e('?' . http_build_query(array_merge($_GET, ['sort' => 'high',   'page' => 1]))) ?>" <?= ($_GET['sort'] ?? '') === 'high'   ? 'selected' : '' ?>>Maior preço</option>
                        <option value="<?= e('?' . http_build_query(array_merge($_GET, ['sort' => 'sales',  'page' => 1]))) ?>" <?= ($_GET['sort'] ?? '') === 'sales'  ? 'selected' : '' ?>>Mais vendidos</option>
                    </select>
                </div>
            </div>

            <!-- Chips de filtros ativos -->
            <?php if ($filtrosAtivos): ?>
                <div class="filter-chips">
                    <?php if ($q): ?>
                        <span class="filter-chip">
                            <i class="bi bi-search"></i> <?= e($q) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($categoriaAtual): ?>
                        <span class="filter-chip">
                            <?= category_icon_html($categoriaAtual['icon'] ?? null) ?> <?= e($categoriaAtual['name']) ?>
                        </span>
                    <?php endif; ?>
                    <?php if (($_GET['min'] ?? '') !== ''): ?>
                        <span class="filter-chip">
                            <i class="bi bi-cash-coin"></i> min <?= e($_GET['min']) ?> MT
                        </span>
                    <?php endif; ?>
                    <?php if (($_GET['max'] ?? '') !== ''): ?>
                        <span class="filter-chip">
                            <i class="bi bi-cash-coin"></i> max <?= e($_GET['max']) ?> MT
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($_GET['localizacao'])): ?>
                        <span class="filter-chip">
                            <i class="bi bi-geo-alt"></i> <?= e($_GET['localizacao']) ?>
                        </span>
                    <?php endif; ?>
                    <?php if (isset($_GET['stock'])): ?>
                        <span class="filter-chip">
                            <i class="bi bi-box-seam"></i> Em stock
                        </span>
                    <?php endif; ?>
                    <?php if (isset($_GET['verified'])): ?>
                        <span class="filter-chip">
                            <i class="bi bi-patch-check-fill"></i> Verificado
                        </span>
                    <?php endif; ?>
                    <a href="<?= base_path('marketplace.php') ?>" class="filter-chip-clear">
                        Limpar tudo
                    </a>
                </div>
            <?php endif; ?>

            <!-- Grelha -->
            <?php if (!$products): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-search"></i>
                    </div>
                    <h4>Nenhum produto encontrado</h4>
                    <p>Tente ajustar os filtros ou pesquisar por outro termo.</p>
                    <a href="<?= base_path('marketplace.php') ?>" class="btn btn-primary">
                        <i class="bi bi-arrow-counterclockwise"></i> Ver todos os produtos
                    </a>
                </div>
            <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($products as $p): ?>
                        <div class="col-sm-6 col-xl-4">
                            <?php include __DIR__ . '/includes/product-card.php'; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-5">
                    <?= pagination($page, $per, $total) ?>
                </div>
            <?php endif; ?>

        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>