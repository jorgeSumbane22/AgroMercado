<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/_helpers.php';

require_role('farmer');

$page_title = 'Gestão de stock';
$fid = user()['farmer_id'];
$pdo = db();

/* ============================================================
   AÇÃO: atualizar stock
   ============================================================ */
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');

    $id  = safe_int($_POST['product_id'] ?? 0);
    $raw = str_replace(',', '.', (string)($_POST['quantity'] ?? ''));
    if (!is_numeric($raw) || (float)$raw < 0) {
        flash('danger', 'Indique uma quantidade válida (zero ou mais).');
        redirect(base_path('agricultor/stock.php'));
    }
    $qty = round((float)$raw, 2);

    try {
        $pdo->beginTransaction();
        $s = $pdo->prepare('SELECT id, name, stock, status FROM products WHERE id = ? AND farmer_id = ? FOR UPDATE');
        $s->execute([$id, $fid]);
        $prod = $s->fetch();
        if (!$prod) {
            $pdo->rollBack();
            flash('danger', 'Produto não encontrado.');
            redirect(base_path('agricultor/stock.php'));
        }

        /* Estado: sem stock → out_of_stock; reposto → ativo só se estava esgotado e a conta está verificada */
        $status = $prod['status'];
        if ($qty <= 0 && $status === 'active') $status = 'out_of_stock';
        elseif ($qty > 0 && $status === 'out_of_stock') $status = farmer_verified() ? 'active' : 'inactive';

        $pdo->prepare('UPDATE products SET stock = ?, status = ? WHERE id = ? AND farmer_id = ?')->execute([$qty, $status, $id, $fid]);

        /* Inventário: regista a diferença (novo − anterior) */
        $delta = round($qty - (float)$prod['stock'], 2);
        if ($delta != 0) {
            $pdo->prepare('
                INSERT INTO inventory(product_id, quantity, movement_type, notes, created_by)
                VALUES (?, ?, "adjustment", ?, ?)
            ')->execute([$id, $delta, 'Ajuste manual (' . (float)$prod['stock'] . ' → ' . $qty . ')', user()['id']]);
        }

        audit(user()['id'], 'update', 'inventory', 'Agricultor ajustou o stock do produto #' . $id . ' (' . ($delta > 0 ? '+' : '') . $delta . ')');
        $pdo->commit();
        flash('success', $delta != 0 ? 'Stock atualizado.' : 'O stock não foi alterado.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($e->getMessage());
        flash('danger', 'Não foi possível atualizar o stock.');
    }
    redirect(base_path('agricultor/stock.php'));
}

/* ============================================================
   LISTA DE PRODUTOS
   ============================================================ */
$s = $pdo->prepare('
    SELECT p.*, c.name AS category_name
    FROM products p
    JOIN categories c ON c.id = p.category_id
    WHERE p.farmer_id = ?
    ORDER BY p.stock ASC
');
$s->execute([$fid]);
$products = $s->fetchAll();

/* ============================================================
   ESTATÍSTICAS
   ============================================================ */
$counts = ['total' => count($products), 'ok' => 0, 'low' => 0, 'out' => 0];
foreach ($products as $p) {
    $stock = (float)($p['stock'] ?? 0);
    $min   = (float)($p['quantity_min'] ?? 0);

    if ($stock <= 0) $counts['out']++;
    elseif ($stock <= $min) $counts['low']++;
    else $counts['ok']++;
}

include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <!-- ================= HERO ================= -->
        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Inventário · Stock
                </span>
                <h1 class="sa-hero-title">Gestão de <em>stock</em></h1>
                <p class="sa-hero-sub">
                    <?php if ($counts['out'] > 0 || $counts['low'] > 0): ?>
                        <strong><?= $counts['out'] ?></strong> sem stock e
                        <strong><?= $counts['low'] ?></strong> com stock baixo.
                    <?php else: ?>
                        Todos os produtos estão com stock saudável.
                    <?php endif; ?>
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('agricultor/produtos.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Ver produtos
                </a>
                <a href="<?= base_path('agricultor/inventario.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-clock-history"></i> Histórico de movimentos
                </a>
                <a href="<?= base_path('agricultor/adicionar-produto.php') ?>" class="btn btn-primary">
                    <i class="bi bi-plus-lg"></i> Novo produto
                </a>
            </div>
        </header>

        <!-- ================= KPIs ================= -->
        <div class="sa-kpi-grid sa-kpi-grid-4">

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-boxes"></i></span>
                </header>
                <span class="sa-kpi-label">Total</span>
                <strong class="sa-kpi-value"><?= $counts['total'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-basket2"></i> produtos no catálogo</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-check2-circle"></i></span>
                </header>
                <span class="sa-kpi-label">Disponíveis</span>
                <strong class="sa-kpi-value"><?= $counts['ok'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-shield-check"></i> stock saudável</span>
                </footer>
            </article>

            <article class="sa-kpi <?= $counts['low'] > 0 ? 'sa-kpi-warn' : '' ?>">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-warning"><i class="bi bi-exclamation-triangle"></i></span>
                    <?php if ($counts['low'] > 0): ?>
                        <span class="sa-kpi-badge sa-badge-warn">atenção</span>
                    <?php endif; ?>
                </header>
                <span class="sa-kpi-label">Stock baixo</span>
                <strong class="sa-kpi-value"><?= $counts['low'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-clock-history"></i> próximo do mínimo</span>
                </footer>
            </article>

            <article class="sa-kpi <?= $counts['out'] > 0 ? 'sa-kpi-warn' : '' ?>">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-danger"><i class="bi bi-x-octagon"></i></span>
                    <?php if ($counts['out'] > 0): ?>
                        <span class="sa-kpi-badge sa-badge-warn">urgente</span>
                    <?php endif; ?>
                </header>
                <span class="sa-kpi-label">Sem stock</span>
                <strong class="sa-kpi-value"><?= $counts['out'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-slash-circle"></i> a repor</span>
                </footer>
            </article>

        </div>

        <!-- ================= TABELA ================= -->
        <article class="content-card sa-card">

            <div class="card-heading sa-card-head">
                <div>
                    <h4>Estado do inventário</h4>
                    <p class="sa-card-sub"><?= $counts['total'] ?> produtos</p>
                </div>

                <div class="sa-toolbar">
                    <div class="sa-search">
                        <i class="bi bi-search"></i>
                        <input type="text" id="stockSearch" placeholder="Procurar por produto ou categoria…">
                    </div>

                    <div class="sa-filters" role="tablist">
                        <button class="sa-filter active" data-filter="all">Todos</button>
                        <button class="sa-filter" data-filter="ok">Disponíveis</button>
                        <button class="sa-filter" data-filter="low">Stock baixo</button>
                        <button class="sa-filter" data-filter="out">Sem stock</button>
                    </div>
                </div>
            </div>

            <?php if (empty($products)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-boxes" style="font-size:2.5rem;"></i>
                    <p class="mt-3 mb-1">Ainda não tem produtos publicados.</p>
                    <a href="<?= base_path('agricultor/adicionar-produto.php') ?>" class="btn btn-primary mt-2">
                        <i class="bi bi-plus-lg"></i> Publicar primeiro produto
                    </a>
                </div>
            <?php else: ?>
                <div class="sa-scroll-x">
                    <table class="table align-middle sa-table sa-stock-table" id="stockTable">
                        <thead>
                            <tr>
                                <th>Produto</th>
                                <th class="text-center">Stock atual</th>
                                <th class="text-center">Mínimo</th>
                                <th>Estado</th>
                                <th class="text-end sa-stock-col-action">Atualizar stock</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($products as $p):
                            $stock = (float)($p['stock'] ?? 0);
                            $min   = (float)($p['quantity_min'] ?? 0);
                            $isOut = $stock <= 0;
                            $isLow = $stock > 0 && $stock <= $min;

                            $stateKey = $isOut ? 'out' : ($isLow ? 'low' : 'ok');
                            $stateLabel = $isOut ? 'Sem stock' : ($isLow ? 'Stock baixo' : 'Disponível');
                            $stateIcon  = $isOut ? 'x-circle-fill' : ($isLow ? 'exclamation-triangle-fill' : 'check-circle-fill');

                            /* Percentagem para a barra (em relação ao mínimo × 3) */
                            $cap   = max(1, $min * 3);
                            $pct   = min(100, max(4, round(($stock / $cap) * 100)));

                            $search = strtolower(($p['name'] ?? '') . ' ' . ($p['category_name'] ?? ''));
                        ?>
                            <tr data-state="<?= $stateKey ?>" data-search="<?= e($search) ?>">
                                <td>
                                    <div class="sa-product-cell">
                                        <span class="sa-avatar sa-avatar-product">
                                            <i class="bi bi-basket2"></i>
                                        </span>
                                        <div class="sa-user-info">
                                            <strong><?= e($p['name']) ?></strong>
                                            <small><?= e($p['category_name']) ?> · <?= e($p['unit']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <strong class="sa-stock-value"><?= e($p['stock']) ?></strong>
                                    <span class="sa-stock-unit"><?= e($p['unit']) ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="sa-min-value"><?= e($p['quantity_min']) ?></span>
                                </td>
                                <td>
                                    <span class="sa-badge sa-badge-<?= $isOut ? 'danger' : ($isLow ? 'warning' : 'success') ?>">
                                        <i class="bi bi-<?= $stateIcon ?>"></i>
                                        <?= $stateLabel ?>
                                    </span>
                                    <div class="sa-stock-bar">
                                        <span class="sa-stock-bar-fill sa-bar-<?= $stateKey ?>" style="width:<?= $pct ?>%"></span>
                                    </div>
                                </td>
                                <td class="text-end sa-stock-col-action">
                                    <form method="post" class="sa-stock-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">

                                        <div class="sa-qty">
                                            <button type="button" class="sa-qty-btn" data-step="-1" title="Diminuir">−</button>
                                            <input type="number"
                                                   name="quantity"
                                                   class="sa-qty-input"
                                                   value="<?= e($p['stock']) ?>"
                                                   step="0.01"
                                                   min="0"
                                                   data-original="<?= e($p['stock']) ?>">
                                            <button type="button" class="sa-qty-btn" data-step="1" title="Aumentar">+</button>
                                        </div>

                                        <button type="submit" class="sa-stock-save">
                                            <i class="bi bi-check-lg"></i>
                                            <span>Guardar</span>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div id="noResults" class="empty-state py-5" style="display:none;">
                    <i class="bi bi-search" style="font-size:2rem;"></i>
                    <p class="mt-3 mb-0">Nenhum resultado encontrado.</p>
                </div>
            <?php endif; ?>

        </article>

    </section>
</div>

<script>
(function () {
    /* ============================================================
       PESQUISA + FILTROS
       ============================================================ */
    var input   = document.getElementById('stockSearch');
    var table   = document.getElementById('stockTable');
    var noRes   = document.getElementById('noResults');
    var filters = document.querySelectorAll('.sa-filter');
    if (!table) return;

    var rows = Array.from(table.querySelectorAll('tbody tr'));
    var currentFilter = 'all';

    function applyFilters() {
        var term = (input ? input.value : '').toLowerCase().trim();
        var visible = 0;

        rows.forEach(function (r) {
            var matchesSearch = !term || (r.dataset.search || '').includes(term);
            var matchesFilter = currentFilter === 'all' || r.dataset.state === currentFilter;
            var show = matchesSearch && matchesFilter;
            r.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        if (noRes) noRes.style.display = visible === 0 ? '' : 'none';
    }

    if (input) input.addEventListener('input', applyFilters);

    filters.forEach(function (btn) {
        btn.addEventListener('click', function () {
            filters.forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            currentFilter = btn.dataset.filter;
            applyFilters();
        });
    });

    /* ============================================================
       BOTÕES +/- E MARCAÇÃO "ALTERADO"
       ============================================================ */
    document.querySelectorAll('.sa-qty').forEach(function (wrap) {
        var input = wrap.querySelector('.sa-qty-input');
        var form  = wrap.closest('form');
        var save  = form ? form.querySelector('.sa-stock-save') : null;
        var original = parseFloat(input.dataset.original || input.value) || 0;

        /* Botões +/- */
        wrap.querySelectorAll('.sa-qty-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var step = parseFloat(btn.dataset.step) || 0;
                var v = parseFloat(input.value) || 0;
                v = Math.max(0, v + step);
                input.value = v.toFixed(2).replace(/\.00$/, '');
                input.dispatchEvent(new Event('input'));
            });
        });

        /* Marca o form como "alterado" quando o valor muda */
        input.addEventListener('input', function () {
            var v = parseFloat(input.value) || 0;
            var dirty = v !== original;
            if (form) form.classList.toggle('is-dirty', dirty);
            if (save) save.classList.toggle('pulse', dirty);
        });
    });

    /* Confirmação ao eliminar por engano não aplica aqui (só stock) */
})();
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>