<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';

require_role('farmer');

$page_title = 'Dashboard do agricultor';
$fid = user()['farmer_id'];
$pdo = db();

/* ============================================================
   ESTATÍSTICAS DE PRODUTOS
   ============================================================ */
$q = $pdo->prepare('
    SELECT
        COUNT(*) AS total,
        SUM(status = "active") AS active_count,
        SUM(stock <= quantity_min) AS low_stock,
        SUM(stock = 0) AS zero_stock
    FROM products
    WHERE farmer_id = ?
');
$q->execute([$fid]);
$ps = $q->fetch() ?: [
    'total' => 0, 'active_count' => 0, 'low_stock' => 0, 'zero_stock' => 0
];
$ps['active'] = $ps['active_count'];   /* alias para o JS abaixo */

/* ============================================================
   ESTATÍSTICAS DE VENDAS (só itens deste agricultor, sem cancelados)
   ============================================================ */
$q = $pdo->prepare('
    SELECT
        COUNT(DISTINCT CASE WHEN oi.status <> "cancelled" THEN o.id END) AS orders_count,
        COALESCE(SUM(CASE WHEN oi.status <> "cancelled" THEN oi.subtotal ELSE 0 END), 0) AS revenue,
        COUNT(DISTINCT CASE WHEN oi.status = "pending" THEN o.id END) AS pending_orders,
        COUNT(DISTINCT CASE WHEN oi.status = "delivered" THEN o.id END) AS delivered_orders
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    WHERE oi.farmer_id = ?
');
$q->execute([$fid]);
$sales = $q->fetch() ?: [
    'orders_count' => 0, 'revenue' => 0,
    'pending_orders' => 0, 'delivered_orders' => 0
];
$avgTicket = $sales['orders_count'] > 0
    ? (float)$sales['revenue'] / (int)$sales['orders_count']
    : 0;

/* ============================================================
   RECEITA — ÚLTIMOS 7 DIAS (sparkline)
   ============================================================ */
$daily = [];
$maxDaily = 0;
$q = $pdo->prepare('
    SELECT COALESCE(SUM(oi.subtotal), 0)
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    WHERE oi.farmer_id = ?
      AND oi.status <> "cancelled"
      AND DATE(o.created_at) = ?
');
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i day"));
    $q->execute([$fid, $day]);
    $val = (float)$q->fetchColumn();
    $daily[] = [
        'date'    => $day,
        'label'   => date('D', strtotime($day)),
        'revenue' => $val,
    ];
    if ($val > $maxDaily) $maxDaily = $val;
}

/* ============================================================
   PEDIDOS RECENTES (estado = estado dos itens deste agricultor)
   ============================================================ */
$q = $pdo->prepare("
    SELECT o.*, u.name AS buyer_name,
           COALESCE(ELT(MIN(CASE WHEN oi.status <> 'cancelled' THEN FIELD(oi.status,'pending','confirmed','preparing','ready','shipping','delivered') END),'pending','confirmed','preparing','ready','shipping','delivered'),'cancelled') AS my_status
    FROM orders o
    JOIN buyers b ON b.id = o.buyer_id
    JOIN users u ON u.id = b.user_id
    JOIN order_items oi ON oi.order_id = o.id
    WHERE oi.farmer_id = ?
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT 6
");
$q->execute([$fid]);
$latest = $q->fetchAll();

/* ============================================================
   TOP PRODUTOS (filtro de cancelados dentro do JOIN dos itens)
   ============================================================ */
$q = $pdo->prepare('
    SELECT p.id, p.name, p.price,
           COALESCE(SUM(oi.quantity), 0) AS sold,
           COALESCE(SUM(oi.subtotal), 0) AS revenue
    FROM products p
    LEFT JOIN order_items oi ON oi.product_id = p.id AND oi.farmer_id = p.farmer_id AND oi.status <> "cancelled"
    WHERE p.farmer_id = ?
    GROUP BY p.id, p.name, p.price
    ORDER BY revenue DESC
    LIMIT 4
');
$q->execute([$fid]);
$topProducts = $q->fetchAll();

/* ============================================================
   SAUDAÇÃO
   ============================================================ */
$h = (int)date('H');
$greeting = $h < 12 ? 'Bom dia' : ($h < 19 ? 'Boa tarde' : 'Boa noite');
$firstName = explode(' ', user()['name'])[0];
$months = [1=>'janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
$dias   = ['Sunday'=>'domingo','Monday'=>'segunda','Tuesday'=>'terça','Wednesday'=>'quarta','Thursday'=>'quinta','Friday'=>'sexta','Saturday'=>'sábado'];
$hoje = ($dias[date('l')] ?? '') . ', ' . (int)date('d') . ' de ' . ($months[(int)date('n')] ?? '');

include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <!-- ================= HERO ================= -->
        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Visão geral · <?= e($hoje) ?>
                </span>
                <h1 class="sa-hero-title">
                    <?= $greeting ?>, <em><?= e($firstName) ?>.</em>
                </h1>
                <p class="sa-hero-sub">
                    <?php if ((int)$sales['pending_orders'] > 0): ?>
                        Tem <strong><?= (int)$sales['pending_orders'] ?> pedidos</strong> pendentes de processamento.
                    <?php else: ?>
                        Sem pedidos pendentes. Bom trabalho.
                    <?php endif; ?>
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('agricultor/pedidos.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-receipt"></i> Ver pedidos
                </a>
                <a href="<?= base_path('agricultor/adicionar-produto.php') ?>" class="btn btn-primary">
                    <i class="bi bi-plus-lg"></i> Novo produto
                </a>
            </div>
        </header>

        <!-- ================= ALERTAS ================= -->
        <?php if ((int)$ps['zero_stock'] > 0 || (int)$ps['low_stock'] > 0): ?>
        <div class="sa-alerts">
            <?php if ((int)$ps['zero_stock'] > 0): ?>
                <a href="<?= base_path('agricultor/stock.php') ?>" class="sa-alert sa-alert-danger">
                    <span class="sa-alert-icon"><i class="bi bi-exclamation-octagon-fill"></i></span>
                    <span class="sa-alert-body">
                        <strong><?= (int)$ps['zero_stock'] ?> produtos</strong> sem stock disponível
                    </span>
                    <i class="bi bi-arrow-right sa-alert-arrow"></i>
                </a>
            <?php endif; ?>
            <?php if ((int)$ps['low_stock'] > 0): ?>
                <a href="<?= base_path('agricultor/stock.php') ?>" class="sa-alert sa-alert-warn">
                    <span class="sa-alert-icon"><i class="bi bi-exclamation-triangle-fill"></i></span>
                    <span class="sa-alert-body">
                        <strong><?= (int)$ps['low_stock'] ?> produtos</strong> com stock baixo
                    </span>
                    <i class="bi bi-arrow-right sa-alert-arrow"></i>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ================= KPIs ================= -->
        <div class="sa-kpi-grid sa-kpi-grid-4">

            <article class="sa-kpi sa-kpi-hero">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-cash-stack"></i></span>
                    <span class="sa-kpi-badge sa-badge-up">
                        <i class="bi bi-calendar3"></i> total
                    </span>
                </header>
                <span class="sa-kpi-label">Receita total</span>
                <strong class="sa-kpi-value"><?= money($sales['revenue']) ?></strong>

                <svg class="sa-spark" viewBox="0 0 200 50" preserveAspectRatio="none" aria-hidden="true">
                    <?php
                    $pts = [];
                    $n = count($daily);
                    foreach ($daily as $i => $d) {
                        $x = $n > 1 ? ($i / ($n - 1)) * 200 : 0;
                        $y = 50 - ($maxDaily > 0 ? ($d['revenue'] / $maxDaily) * 45 : 0) - 2;
                        $pts[] = round($x, 1) . ',' . round($y, 1);
                    }
                    $path = 'M' . implode(' L', $pts);
                    ?>
                    <defs>
                        <linearGradient id="farmSpark" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="var(--green)" stop-opacity=".35"/>
                            <stop offset="100%" stop-color="var(--green)" stop-opacity="0"/>
                        </linearGradient>
                    </defs>
                    <path d="<?= $path ?> L200,50 L0,50 Z" fill="url(#farmSpark)"/>
                    <path d="<?= $path ?>" fill="none" stroke="var(--green)" stroke-width="2"
                          stroke-linecap="round" stroke-linejoin="round"/>
                </svg>

                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub">
                        <i class="bi bi-wallet2"></i> Ticket <?= money($avgTicket) ?>
                    </span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-info"><i class="bi bi-receipt"></i></span>
                    <?php if ((int)$sales['pending_orders'] > 0): ?>
                        <span class="sa-kpi-badge sa-badge-warn"><?= (int)$sales['pending_orders'] ?> novos</span>
                    <?php endif; ?>
                </header>
                <span class="sa-kpi-label">Pedidos recebidos</span>
                <strong class="sa-kpi-value"><?= (int)$sales['orders_count'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub">
                        <i class="bi bi-bag-check"></i> <?= (int)$sales['delivered_orders'] ?> entregues
                    </span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-basket2"></i></span>
                </header>
                <span class="sa-kpi-label">Produtos publicados</span>
                <strong class="sa-kpi-value"><?= (int)$ps['total'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub">
                        <i class="bi bi-check2-circle"></i> <?= (int)$ps['active_count'] ?> ativos
                    </span>
                </footer>
            </article>

            <article class="sa-kpi <?= (int)$ps['low_stock'] > 0 ? 'sa-kpi-warn' : '' ?>">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon <?= (int)$ps['low_stock'] > 0 ? 'sa-icon-warning' : 'sa-icon-success' ?>">
                        <i class="bi bi-<?= (int)$ps['low_stock'] > 0 ? 'exclamation-triangle' : 'shield-check' ?>"></i>
                    </span>
                    <?php if ((int)$ps['low_stock'] > 0): ?>
                        <span class="sa-kpi-badge sa-badge-warn">atenção</span>
                    <?php endif; ?>
                </header>
                <span class="sa-kpi-label">Stock baixo</span>
                <strong class="sa-kpi-value"><?= (int)$ps['low_stock'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub">
                        <i class="bi bi-boxes"></i> produtos a repor
                    </span>
                </footer>
            </article>

        </div>

        <!-- ================= CONTEÚDO ================= -->
        <div class="sa-main-grid">

            <div class="sa-col-main">

                <article class="content-card sa-card">
                    <div class="card-heading">
                        <div>
                            <h4>Pedidos recentes</h4>
                            <p class="sa-card-sub">Últimas transações recebidas</p>
                        </div>
                        <a href="<?= base_path('agricultor/pedidos.php') ?>" class="sa-link-arrow">
                            Ver todos <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>

                    <?php if (empty($latest)): ?>
                        <div class="empty-state py-5">
                            <i class="bi bi-inbox" style="font-size:2.5rem;"></i>
                            <p class="mt-3 mb-0">Ainda sem pedidos.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle sa-table">
                                <thead>
                                    <tr>
                                        <th>Pedido</th>
                                        <th>Cliente</th>
                                        <th>Data</th>
                                        <th>Estado</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($latest as $o):
                                    $orderNum = $o['order_number'] ?? ('#' . $o['id']);
                                ?>
                                    <tr>
                                        <td>
                                            <span class="sa-order-id">
                                                <i class="bi bi-receipt"></i>
                                                <?= e($orderNum) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="sa-user-cell">
                                                <span class="sa-avatar">
                                                    <?= e(strtoupper(substr($o['buyer_name'] ?? '?', 0, 1))) ?>
                                                </span>
                                                <span><?= e($o['buyer_name'] ?? '—') ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="sa-date">
                                                <?= date('d M Y', strtotime($o['created_at'])) ?>
                                                <small><?= date('H:i', strtotime($o['created_at'])) ?></small>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (function_exists('order_status_badge')): ?>
                                                <?= order_status_badge($o['my_status']) ?>
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><?= e($o['my_status']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="<?= base_path('agricultor/pedido-detalhes.php?id='.(int)$o['id']) ?>"
                                               class="sa-row-action" title="Ver detalhes">
                                                <i class="bi bi-arrow-right"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>

                <?php if (!empty($topProducts)): ?>
                <article class="content-card sa-card">
                    <div class="card-heading">
                        <div>
                            <h4>Produtos mais vendidos</h4>
                            <p class="sa-card-sub">Por receita gerada</p>
                        </div>
                        <a href="<?= base_path('agricultor/produtos.php') ?>" class="sa-link-arrow">
                            Ver todos <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>

                    <div class="sa-top-list">
                        <?php foreach ($topProducts as $i => $p): ?>
                            <div class="sa-top-item">
                                <span class="sa-top-rank"><?= $i + 1 ?></span>
                                <div class="sa-top-info">
                                    <strong><?= e($p['name']) ?></strong>
                                    <small><?= (int)$p['sold'] ?> unid. · <?= money($p['price']) ?></small>
                                </div>
                                <span class="sa-top-value"><?= money($p['revenue']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
                <?php endif; ?>

            </div>

            <aside class="sa-col-side">

                <article class="content-card sa-card">
                    <div class="card-heading">
                        <div>
                            <h4>Estado dos produtos</h4>
                            <p class="sa-card-sub">Distribuição do catálogo</p>
                        </div>
                    </div>

                    <div class="sa-chart-wrap">
                        <div class="sa-chart-box sa-chart-box-doughnut">
                            <canvas id="farmerChart"></canvas>
                        </div>
                        <div class="sa-chart-center">
                            <strong><?= (int)$ps['total'] ?></strong>
                            <small>produtos</small>
                        </div>
                    </div>
                </article>

                <article class="content-card sa-card">
                    <div class="card-heading">
                        <div>
                            <h4>Ações rápidas</h4>
                            <p class="sa-card-sub">Atalhos frequentes</p>
                        </div>
                    </div>
                    <div class="sa-quick-actions">
                        <a href="<?= base_path('agricultor/adicionar-produto.php') ?>" class="sa-quick">
                            <span class="sa-quick-icon"><i class="bi bi-plus-circle"></i></span>
                            <span>
                                <strong>Novo produto</strong>
                                <small>Publicar no catálogo</small>
                            </span>
                            <i class="bi bi-chevron-right sa-quick-arrow"></i>
                        </a>
                        <a href="<?= base_path('agricultor/stock.php') ?>" class="sa-quick">
                            <span class="sa-quick-icon"><i class="bi bi-boxes"></i></span>
                            <span>
                                <strong>Stock</strong>
                                <small>Repor quantidades</small>
                            </span>
                            <i class="bi bi-chevron-right sa-quick-arrow"></i>
                        </a>
                        <a href="<?= base_path('agricultor/pedidos.php') ?>" class="sa-quick">
                            <span class="sa-quick-icon"><i class="bi bi-receipt"></i></span>
                            <span>
                                <strong>Pedidos</strong>
                                <small>Gerir encomendas</small>
                            </span>
                            <i class="bi bi-chevron-right sa-quick-arrow"></i>
                        </a>
                        <a href="<?= base_path('agricultor/relatorios.php') ?>" class="sa-quick">
                            <span class="sa-quick-icon"><i class="bi bi-file-earmark-bar-graph"></i></span>
                            <span>
                                <strong>Relatórios</strong>
                                <small>Análise de vendas</small>
                            </span>
                            <i class="bi bi-chevron-right sa-quick-arrow"></i>
                        </a>
                    </div>
                </article>

            </aside>
        </div>

    </section>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;

    function cssVar(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }

    Chart.defaults.font.family = "'DM Sans', sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.color = cssVar('--muted') || '#6d7f77';

    var el = document.getElementById('farmerChart');
    if (!el) return;

    var active   = <?= (int)$ps['active_count'] ?>;
    var total    = <?= (int)$ps['total'] ?>;
    var lowStock = <?= (int)$ps['low_stock'] ?>;
    var inactive = Math.max(0, total - active);

    new Chart(el, {
        type: 'doughnut',
        data: {
            labels: ['Ativos', 'Inativos', 'Stock baixo'],
            datasets: [{
                data: [active, inactive, lowStock],
                backgroundColor: ['#1c7c54', '#dce9e1', '#e3a93c'],
                borderColor: cssVar('--surface') || '#fff',
                borderWidth: 4,
                hoverOffset: 8,
                spacing: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '72%',
            animation: { animateRotate: true, duration: 900, easing: 'easeOutQuart' },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: cssVar('--ink') || '#17332a',
                        font: { weight: '600', size: 11 },
                        boxWidth: 10,
                        boxHeight: 10,
                        usePointStyle: true,
                        pointStyle: 'circle',
                        padding: 14
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(23,51,42,.96)',
                    titleColor: '#fff',
                    bodyColor: '#e8f0ea',
                    padding: 10,
                    cornerRadius: 10,
                    displayColors: false,
                    callbacks: {
                        label: function (c) {
                            var total = c.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                            var pct = total > 0 ? ((c.parsed / total) * 100).toFixed(1) : 0;
                            return '  ' + c.label + ': ' + c.parsed + ' (' + pct + '%)';
                        }
                    }
                }
            }
        }
    });

    new MutationObserver(function () {
        var chart = Chart.getChart(el);
        if (!chart) return;
        chart.options.plugins.legend.labels.color = cssVar('--ink') || '#17332a';
        chart.data.datasets[0].borderColor = cssVar('--surface') || '#fff';
        chart.update('none');
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
})();
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>