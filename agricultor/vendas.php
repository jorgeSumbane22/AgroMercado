<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';

require_role('farmer');

$page_title = 'Vendas';
$fid = user()['farmer_id'];
$pdo = db();

/* ============================================================
   PERÍODO
   ============================================================ */
$period = (int)($_GET['period'] ?? 0); // 0 = tudo
$since  = $period > 0 ? date('Y-m-d', strtotime('-' . ($period - 1) . ' day')) : null;

/* ============================================================
   EXPORTAÇÃO CSV (linhas de venda do período, sem cancelados)
   ============================================================ */
if (($_GET['export'] ?? '') === 'csv') {
    $sql = '
        SELECT o.created_at, o.order_number, u.name AS buyer_name, oi.product_name,
               oi.quantity, oi.unit, oi.unit_price, oi.subtotal, oi.status
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        JOIN buyers b ON b.id = o.buyer_id
        JOIN users u ON u.id = b.user_id
        WHERE oi.farmer_id = ? AND oi.status <> "cancelled"' . ($since ? ' AND DATE(o.created_at) >= ?' : '') . '
        ORDER BY o.created_at DESC, oi.id';
    $s = $pdo->prepare($sql);
    $s->execute($since ? [$fid, $since] : [$fid]);
    $rows = array_map(fn($r) => [
        date('d/m/Y H:i', strtotime($r['created_at'])), $r['order_number'], $r['buyer_name'], $r['product_name'],
        number_format((float)$r['quantity'], 2, ',', ''), $r['unit'], number_format((float)$r['unit_price'], 2, ',', ''),
        number_format((float)$r['subtotal'], 2, ',', ''), order_status_label($r['status']),
    ], $s->fetchAll());
    audit(user()['id'], 'export', 'reports', 'Agricultor exportou vendas (CSV)');
    csv_download('vendas_' . ($period > 0 ? $period . 'dias' : 'total') . '_' . date('Ymd') . '.csv',
        ['Data', 'Pedido', 'Cliente', 'Produto', 'Quantidade', 'Unidade', 'Preço unitário (MT)', 'Subtotal (MT)', 'Estado'], $rows);
}

/* ============================================================
   ESTATÍSTICAS GERAIS
   ============================================================ */
$sql = '
    SELECT
        COALESCE(SUM(oi.subtotal), 0) AS revenue,
        COALESCE(SUM(oi.quantity), 0) AS quantity,
        COUNT(DISTINCT o.id) AS orders_count
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE oi.farmer_id = ?
      AND oi.status <> "cancelled"
';
$params = [$fid];

if ($since) {
    $sql .= ' AND DATE(o.created_at) >= ?';
    $params[] = $since;
}

$s = $pdo->prepare($sql);
$s->execute($params);
$stats = $s->fetch() ?: ['revenue' => 0, 'quantity' => 0, 'orders_count' => 0];

$avgTicket = $stats['orders_count'] > 0
    ? (float)$stats['revenue'] / (int)$stats['orders_count']
    : 0;

/* ============================================================
   PRODUTOS MAIS VENDIDOS
   ============================================================ */
$sql = '
    SELECT
        oi.product_id,
        oi.product_name,
        SUM(oi.quantity) AS quantity,
        SUM(oi.subtotal) AS revenue,
        COUNT(DISTINCT o.id) AS orders_count
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE oi.farmer_id = ?
      AND oi.status <> "cancelled"
';
$params = [$fid];

if ($since) {
    $sql .= ' AND DATE(o.created_at) >= ?';
    $params[] = $since;
}

$sql .= '
    GROUP BY oi.product_id, oi.product_name
    ORDER BY revenue DESC
    LIMIT 20
';

$s = $pdo->prepare($sql);
$s->execute($params);
$products = $s->fetchAll();

/* Enriquecer com unidade + imagem do produto atual */
if (!empty($products)) {
    $ids = array_column($products, 'product_id');
    $in  = implode(',', array_fill(0, count($ids), '?'));

    $s = $pdo->prepare("
        SELECT id, unit, main_image
        FROM products
        WHERE id IN ($in)
    ");
    $s->execute($ids);
    $meta = [];
    foreach ($s->fetchAll() as $m) {
        $meta[(int)$m['id']] = $m;
    }
    foreach ($products as &$p) {
        $p['unit']  = $meta[(int)$p['product_id']]['unit']       ?? '';
        $p['image'] = $meta[(int)$p['product_id']]['main_image'] ?? null;
    }
    unset($p);
}

/* ============================================================
   TOP PRODUTO E TOTAIS PARA BARRA DE PROPORÇÃO
   ============================================================ */
$maxRevenue = 0;
$totalRevenue = 0;
foreach ($products as $p) {
    $r = (float)$p['revenue'];
    if ($r > $maxRevenue) $maxRevenue = $r;
    $totalRevenue += $r;
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
                    <span class="sa-dot"></span> Desempenho · Vendas
                </span>
                <h1 class="sa-hero-title">Análise de <em>vendas</em></h1>
                <p class="sa-hero-sub">
                    <?php if ($period > 0): ?>
                        Últimos <strong><?= $period ?> dias</strong> ·
                    <?php else: ?>
                        Todo o histórico ·
                    <?php endif; ?>
                    <strong><?= (int)$stats['orders_count'] ?></strong> pedidos ·
                    <strong><?= money($stats['revenue']) ?></strong> receita.
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('agricultor/dashboard.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <a href="<?= e('?' . http_build_query(array_filter(['period' => $period ?: null, 'export' => 'csv']))) ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-filetype-csv"></i> Exportar CSV
                </a>
                <a href="<?= base_path('agricultor/relatorios.php') ?>" class="btn btn-primary">
                    <i class="bi bi-file-earmark-bar-graph"></i> Relatórios
                </a>
            </div>
        </header>

        <!-- ================= PERÍODO ================= -->
        <div class="sa-period">
            <span class="sa-period-label"><i class="bi bi-calendar-range"></i> Período:</span>
            <div class="sa-period-tabs" role="tablist">
                <a href="?period=30"  class="sa-period-tab <?= $period === 30  ? 'active' : '' ?>">30 dias</a>
                <a href="?period=90"  class="sa-period-tab <?= $period === 90  ? 'active' : '' ?>">90 dias</a>
                <a href="?period=365" class="sa-period-tab <?= $period === 365 ? 'active' : '' ?>">1 ano</a>
                <a href="?period=0"   class="sa-period-tab <?= $period === 0   ? 'active' : '' ?>">Tudo</a>
            </div>
        </div>

        <!-- ================= KPIs ================= -->
        <div class="sa-kpi-grid sa-kpi-grid-4">

            <article class="sa-kpi sa-kpi-hero">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-cash-stack"></i></span>
                    <span class="sa-kpi-badge sa-badge-up">
                        <i class="bi bi-calendar3"></i>
                        <?= $period > 0 ? $period . 'd' : 'total' ?>
                    </span>
                </header>
                <span class="sa-kpi-label">Receita</span>
                <strong class="sa-kpi-value"><?= money($stats['revenue']) ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub">
                        <i class="bi bi-wallet2"></i> Ticket <?= money($avgTicket) ?>
                    </span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-box-seam"></i></span>
                </header>
                <span class="sa-kpi-label">Quantidade vendida</span>
                <strong class="sa-kpi-value">
                    <?= number_format((float)$stats['quantity'], 0, ',', ' ') ?>
                </strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-basket2"></i> unidades no total</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-info"><i class="bi bi-receipt"></i></span>
                </header>
                <span class="sa-kpi-label">Pedidos</span>
                <strong class="sa-kpi-value"><?= (int)$stats['orders_count'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub">
                        <i class="bi bi-graph-up"></i>
                        <?= $stats['orders_count'] > 0 ? number_format((float)$stats['quantity'] / (int)$stats['orders_count'], 1, ',', '.') : 0 ?> unid./pedido
                    </span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-trophy"></i></span>
                </header>
                <span class="sa-kpi-label">Produtos vendidos</span>
                <strong class="sa-kpi-value"><?= count($products) ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-basket"></i> no período</span>
                </footer>
            </article>

        </div>

        <!-- ================= TABELA ================= -->
        <article class="content-card sa-card">

            <div class="card-heading sa-card-head">
                <div>
                    <h4>Produtos mais vendidos</h4>
                    <p class="sa-card-sub"><?= count($products) ?> produtos · ordenados por receita</p>
                </div>

                <?php if (!empty($products)): ?>
                <div class="sa-toolbar">
                    <div class="sa-search">
                        <i class="bi bi-search"></i>
                        <input type="text" id="salesSearch" placeholder="Procurar produto…">
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (empty($products)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-graph-up" style="font-size:2.5rem;"></i>
                    <p class="mt-3 mb-1">
                        <?php if ($period > 0): ?>
                            Sem vendas nos últimos <?= $period ?> dias.
                        <?php else: ?>
                            Ainda não registou vendas.
                        <?php endif; ?>
                    </p>
                    <?php if ($period > 0): ?>
                        <a href="?period=0" class="btn btn-primary mt-2">
                            <i class="bi bi-calendar-range"></i> Ver todo o histórico
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="sa-scroll-x">
                    <table class="table align-middle sa-table sa-sales-table" id="salesTable">
                        <thead>
                            <tr>
                                <th style="width:50px;">#</th>
                                <th>Produto</th>
                                <th class="text-center">Quantidade</th>
                                <th class="text-center">Pedidos</th>
                                <th>Proporção</th>
                                <th class="text-end">Receita</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($products as $i => $p):
                            $rev   = (float)$p['revenue'];
                            $pct   = $maxRevenue > 0 ? round(($rev / $maxRevenue) * 100) : 0;
                            $pctTotal = $totalRevenue > 0 ? round(($rev / $totalRevenue) * 100, 1) : 0;
                            $qty   = (float)$p['quantity'];
                            $unit  = $p['unit'] ? ' ' . $p['unit'] : '';
                            $ordersN = (int)($p['orders_count'] ?? 0);

                            /* Medalha para o top 3 */
                            $rankIcon = '';
                            $rankClass = '';
                            if ($i === 0) { $rankIcon = 'trophy-fill';    $rankClass = 'sa-rank-gold'; }
                            elseif ($i === 1) { $rankIcon = 'award-fill';  $rankClass = 'sa-rank-silver'; }
                            elseif ($i === 2) { $rankIcon = 'award-fill';  $rankClass = 'sa-rank-bronze'; }

                            $search = strtolower($p['product_name'] ?? '');
                        ?>
                            <tr data-search="<?= e($search) ?>">
                                <td>
                                    <?php if ($rankIcon): ?>
                                        <span class="sa-rank <?= $rankClass ?>">
                                            <i class="bi bi-<?= $rankIcon ?>"></i>
                                        </span>
                                    <?php else: ?>
                                        <span class="sa-rank"><?= $i + 1 ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="sa-user-cell">
                                        <span class="sa-avatar sa-avatar-product">
                                            <i class="bi bi-basket2"></i>
                                        </span>
                                        <div class="sa-user-info">
                                            <strong><?= e($p['product_name']) ?></strong>
                                            <?php if (!empty($p['product_id'])): ?>
                                                <small>#<?= (int)$p['product_id'] ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span class="sa-count">
                                        <?= number_format($qty, 0, ',', ' ') ?><?= e($unit) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="sa-count sa-count-muted">
                                        <?= $ordersN ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="sa-progress">
                                        <div class="sa-progress-bar">
                                            <span style="width:<?= $pct ?>%"></span>
                                        </div>
                                        <small class="sa-progress-label"><?= $pctTotal ?>% do total</small>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <strong class="sa-price"><?= money($rev) ?></strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="sa-tfoot">
                                <td colspan="5" class="text-end">
                                    <strong>Total</strong>
                                </td>
                                <td class="text-end">
                                    <strong class="sa-price-lg"><?= money($totalRevenue) ?></strong>
                                </td>
                            </tr>
                        </tfoot>
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
    var input = document.getElementById('salesSearch');
    var table = document.getElementById('salesTable');
    var noRes = document.getElementById('noResults');
    if (!table) return;

    var rows = Array.from(table.querySelectorAll('tbody tr'));

    function applySearch() {
        var term = (input ? input.value : '').toLowerCase().trim();
        var visible = 0;
        rows.forEach(function (r) {
            var show = !term || (r.dataset.search || '').includes(term);
            r.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        if (noRes) noRes.style.display = visible === 0 ? '' : 'none';
    }

    if (input) input.addEventListener('input', applySearch);
})();
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>