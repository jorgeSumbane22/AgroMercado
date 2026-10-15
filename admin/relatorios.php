<?php require_once __DIR__.'/../includes/permissions.php'; ?>
<?php
require_role('superadmin');
$page_title = 'Relatórios';
$pdo = db();

/* ============================================================
   PERÍODO (7 / 30 / 90 / 365 dias)
   Vendas = subtotal dos itens não cancelados (sem taxa de entrega).
   ============================================================ */
$period = (int)($_GET['period'] ?? 30);
if (!in_array($period, [7, 30, 90, 365], true)) $period = 30;
$since = date('Y-m-d', strtotime('-'.($period - 1).' day'));
$validItem = "oi.status <> 'cancelled' AND o.status <> 'cancelled'";

/** Vendas e nº de pedidos entre duas datas (inclusive). */
function report_totals(PDO $pdo, string $from, string $to, string $validItem): array {
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(oi.subtotal),0) AS revenue, COUNT(DISTINCT o.id) AS orders
        FROM order_items oi JOIN orders o ON o.id = oi.order_id
        WHERE $validItem AND o.created_at >= ? AND o.created_at < DATE_ADD(?, INTERVAL 1 DAY)");
    $st->execute([$from, $to]);
    return $st->fetch() ?: ['revenue' => 0, 'orders' => 0];
}

/* ============================================================
   KPIs GERAIS
   ============================================================ */
$stats = [
    'revenue' => (float)$pdo->query("SELECT COALESCE(SUM(oi.subtotal),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE $validItem")->fetchColumn(),
    'orders'  => (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    'farmers' => (int)$pdo->query('SELECT COUNT(*) FROM farmers')->fetchColumn(),
    'buyers'  => (int)$pdo->query('SELECT COUNT(*) FROM buyers')->fetchColumn(),
];

/* KPIs do período */
$row = report_totals($pdo, $since, date('Y-m-d'), $validItem);
$periodRevenue = (float)$row['revenue'];
$periodOrders  = (int)$row['orders'];
$avgTicket     = $periodOrders > 0 ? $periodRevenue / $periodOrders : 0;

/* Período anterior (para comparação) */
$prevSince = date('Y-m-d', strtotime('-'.($period * 2 - 1).' day'));
$prevUntil = date('Y-m-d', strtotime('-'.$period.' day'));
$prev = report_totals($pdo, $prevSince, $prevUntil, $validItem);
$prevRevenue = (float)$prev['revenue'];
$prevOrders  = (int)$prev['orders'];

$deltaRevenue = $prevRevenue > 0 ? (($periodRevenue - $prevRevenue) / $prevRevenue) * 100 : 0;
$deltaOrders  = $prevOrders  > 0 ? (($periodOrders  - $prevOrders)  / $prevOrders)  * 100 : 0;

/* ============================================================
   CATEGORIAS MAIS MOVIMENTADAS (no período, sem cancelados)
   ============================================================ */
$st = $pdo->prepare("
    SELECT c.name, COALESCE(x.items,0) AS items, COALESCE(x.revenue,0) AS revenue
    FROM categories c
    LEFT JOIN (
        SELECT p.category_id, COUNT(oi.id) AS items, SUM(oi.subtotal) AS revenue
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        JOIN products p ON p.id = oi.product_id
        WHERE $validItem AND o.created_at >= ?
        GROUP BY p.category_id
    ) x ON x.category_id = c.id
    ORDER BY items DESC, c.name
    LIMIT 8");
$st->execute([$since]);
$cats = $st->fetchAll();

/* ============================================================
   VENDAS + PEDIDOS POR DIA (uma só query agrupada)
   ============================================================ */
$st = $pdo->prepare("
    SELECT DATE(o.created_at) AS d, COALESCE(SUM(oi.subtotal),0) AS rev, COUNT(DISTINCT o.id) AS cnt
    FROM order_items oi JOIN orders o ON o.id = oi.order_id
    WHERE $validItem AND o.created_at >= ?
    GROUP BY DATE(o.created_at)");
$st->execute([$since]);
$byDay = [];
foreach ($st as $r) $byDay[$r['d']] = $r;
$daily = [];
$maxDaily = 0;
for ($i = $period - 1; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime('-'.$i.' day'));
    $rev = (float)($byDay[$day]['rev'] ?? 0);
    $daily[] = [
        'date'    => $day,
        'label'   => date('d/m', strtotime($day)),
        'revenue' => $rev,
        'orders'  => (int)($byDay[$day]['cnt'] ?? 0),
    ];
    if ($rev > $maxDaily) $maxDaily = $rev;
}

/* ============================================================
   TOP PRODUTOS (no período)
   ============================================================ */
$topProductsSql = "
    SELECT p.id, p.name, p.price, p.unit,
           COALESCE(SUM(oi.quantity),0) AS sold,
           COALESCE(SUM(oi.subtotal),0) AS revenue
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN products p ON p.id = oi.product_id
    WHERE $validItem AND o.created_at >= ?
    GROUP BY p.id, p.name, p.price, p.unit
    ORDER BY revenue DESC";
$st = $pdo->prepare($topProductsSql . ' LIMIT 6');
$st->execute([$since]);
$topProducts = $st->fetchAll();

/* ============================================================
   TOP AGRICULTORES (no período)
   ============================================================ */
$st = $pdo->prepare("
    SELECT f.id, u.name,
           COALESCE(SUM(oi.subtotal),0) AS revenue,
           COUNT(DISTINCT o.id) AS orders_count
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN farmers f ON f.id = oi.farmer_id
    JOIN users u ON u.id = f.user_id
    WHERE $validItem AND o.created_at >= ?
    GROUP BY f.id, u.name
    ORDER BY revenue DESC
    LIMIT 6");
$st->execute([$since]);
$topFarmers = $st->fetchAll();

/* ============================================================
   MÉTODOS DE PAGAMENTO (todos os não falhados, no período)
   ============================================================ */
$payMethods = [];
try {
    $st = $pdo->prepare("
        SELECT method, COUNT(*) AS n, COALESCE(SUM(amount),0) AS total
        FROM payments
        WHERE status <> 'failed' AND created_at >= ?
        GROUP BY method
        ORDER BY total DESC");
    $st->execute([$since]);
    foreach ($st as $m) { $m['method'] = payment_method_label((string)$m['method']); $payMethods[] = $m; }
} catch (Throwable $e) { $payMethods = []; }

/* ============================================================
   EXPORTAR CSV (respeita o período selecionado)
   ============================================================ */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $fmt = fn($v) => number_format((float)$v, 2, ',', '');
    $rows = [];
    $rows[] = ['Relatório AgroMercado', date('d/m/Y H:i'), ''];
    $rows[] = ['Período', date('d/m/Y', strtotime($since)) . ' a ' . date('d/m/Y') . ' (' . $period . ' dias)', ''];
    $rows[] = ['', '', ''];
    $rows[] = ['Métrica', 'Valor', ''];
    $rows[] = ['Vendas no período (itens não cancelados)', $fmt($periodRevenue), ''];
    $rows[] = ['Pedidos no período (não cancelados)', $periodOrders, ''];
    $rows[] = ['Ticket médio', $fmt($avgTicket), ''];
    $rows[] = ['Vendas no período anterior', $fmt($prevRevenue), ''];
    $rows[] = ['Agricultores (total)', $stats['farmers'], ''];
    $rows[] = ['Compradores (total)', $stats['buyers'], ''];
    $rows[] = ['', '', ''];
    $rows[] = ['Data', 'Vendas (MT)', 'Pedidos'];
    foreach ($daily as $d) $rows[] = [date('d/m/Y', strtotime($d['date'])), $fmt($d['revenue']), $d['orders']];
    $rows[] = ['', '', ''];
    $rows[] = ['Produto', 'Quantidade vendida', 'Vendas (MT)'];
    $st = $pdo->prepare($topProductsSql . ' LIMIT 50');
    $st->execute([$since]);
    foreach ($st as $p) $rows[] = [$p['name'], rtrim(rtrim(number_format((float)$p['sold'], 2, ',', ''), '0'), ',') . ' ' . $p['unit'], $fmt($p['revenue'])];
    $rows[] = ['', '', ''];
    $rows[] = ['Categoria', 'Itens vendidos', 'Vendas (MT)'];
    foreach ($cats as $c) $rows[] = [$c['name'], (int)$c['items'], $fmt($c['revenue'])];
    $rows[] = ['', '', ''];
    $rows[] = ['Método de pagamento', 'Pagamentos (não falhados)', 'Valor (MT)'];
    foreach ($payMethods as $m) $rows[] = [$m['method'], (int)$m['n'], $fmt($m['total'])];
    audit(user()['id'], 'export', 'reports', 'Exportou relatório CSV (' . $period . ' dias)');
    csv_download('relatorio-' . $period . 'd-' . date('Y-m-d') . '.csv', ['AgroMercado', '', ''], $rows);
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
                    <span class="sa-dot"></span> Análise · Relatórios
                </span>
                <h1 class="sa-hero-title">Relatórios da <em>plataforma</em></h1>
                <p class="sa-hero-sub">
                    Últimos <strong><?= $period ?></strong> dias ·
                    <strong><?= $periodOrders ?></strong> pedidos ·
                    <strong><?= money($periodRevenue) ?></strong> em vendas (sem cancelados)
                    <span class="sa-hero-delta <?= $deltaRevenue >= 0 ? 'up' : 'down' ?>">
                        <i class="bi bi-arrow-<?= $deltaRevenue >= 0 ? 'up' : 'down' ?>-right"></i>
                        <?= number_format(abs($deltaRevenue), 1) ?>%
                    </span>
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('admin/dashboard.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                    <i class="bi bi-printer"></i> Imprimir / PDF
                </button>
                <a href="?period=<?= $period ?>&amp;export=csv" class="btn btn-primary">
                    <i class="bi bi-download"></i> Exportar CSV
                </a>
            </div>
        </header>
        <p class="print-only small mb-3"><strong>AgroMercado — Relatório da plataforma</strong> · <?= e(user()['name'] ?? '') ?> · gerado a <?= date('d/m/Y H:i') ?></p>

        <!-- ================= PERÍODO ================= -->
        <div class="sa-period">
            <span class="sa-period-label"><i class="bi bi-calendar-range"></i> Período:</span>
            <div class="sa-period-tabs" role="tablist">
                <?php foreach ([7 => '7 dias', 30 => '30 dias', 90 => '90 dias', 365 => '1 ano'] as $d => $label): ?>
                    <a href="?period=<?= $d ?>"
                       class="sa-period-tab <?= $period === $d ? 'active' : '' ?>">
                        <?= $label ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ================= KPIs ================= -->
        <div class="sa-kpi-grid sa-kpi-grid-4">

            <article class="sa-kpi sa-kpi-hero">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-cash-stack"></i></span>
                    <span class="sa-kpi-badge <?= $deltaRevenue >= 0 ? 'sa-badge-up' : 'sa-badge-down' ?>">
                        <i class="bi bi-arrow-<?= $deltaRevenue >= 0 ? 'up' : 'down' ?>-right"></i>
                        <?= number_format(abs($deltaRevenue), 1) ?>%
                    </span>
                </header>
                <span class="sa-kpi-label">Receita · <?= $period ?> dias</span>
                <strong class="sa-kpi-value"><?= money($periodRevenue) ?></strong>

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
                        <linearGradient id="sgRel" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="var(--green)" stop-opacity=".35"/>
                            <stop offset="100%" stop-color="var(--green)" stop-opacity="0"/>
                        </linearGradient>
                    </defs>
                    <path d="<?= $path ?> L200,50 L0,50 Z" fill="url(#sgRel)"/>
                    <path d="<?= $path ?>" fill="none" stroke="var(--green)" stroke-width="2"
                          stroke-linecap="round" stroke-linejoin="round"/>
                </svg>

                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-wallet2"></i> Ticket <?= money($avgTicket) ?></span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-receipt"></i></span>
                    <span class="sa-kpi-badge <?= $deltaOrders >= 0 ? 'sa-badge-up' : 'sa-badge-down' ?>">
                        <i class="bi bi-arrow-<?= $deltaOrders >= 0 ? 'up' : 'down' ?>-right"></i>
                        <?= number_format(abs($deltaOrders), 1) ?>%
                    </span>
                </header>
                <span class="sa-kpi-label">Pedidos · <?= $period ?> dias</span>
                <strong class="sa-kpi-value"><?= $periodOrders ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-list-check"></i> <?= $stats['orders'] ?> no total</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-info"><i class="bi bi-people"></i></span>
                </header>
                <span class="sa-kpi-label">Agricultores</span>
                <strong class="sa-kpi-value"><?= $stats['farmers'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-shop"></i> ativos</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-person-check"></i></span>
                </header>
                <span class="sa-kpi-label">Compradores</span>
                <strong class="sa-kpi-value"><?= $stats['buyers'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-bag-check"></i> registados</span>
                </footer>
            </article>

        </div>

        <!-- ================= GRÁFICO PRINCIPAL: RECEITA E PEDIDOS ================= -->
        <article class="content-card sa-card mb-4">
            <div class="card-heading">
                <div>
                    <h4>Receita e pedidos ao longo do tempo</h4>
                    <p class="sa-card-sub">Evolução diária no período selecionado</p>
                </div>
                <div class="sa-chart-legend">
                    <span><i class="bi bi-circle-fill" style="color:#1c7c54"></i> Receita</span>
                    <span><i class="bi bi-circle-fill" style="color:#de9a31"></i> Pedidos</span>
                </div>
            </div>
            <div class="sa-chart-box sa-chart-box-lg">
                <canvas id="mainChart"></canvas>
            </div>
        </article>

        <!-- ================= CATEGORIAS + MÉTODOS ================= -->
        <div class="row g-4 mb-4">

            <div class="col-lg-7">
                <article class="content-card sa-card">
                    <div class="card-heading">
                        <div>
                            <h4>Categorias mais movimentadas</h4>
                            <p class="sa-card-sub">Itens vendidos no período (sem cancelados)</p>
                        </div>
                    </div>
                    <div class="sa-chart-box">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </article>
            </div>

            <div class="col-lg-5">
                <article class="content-card sa-card">
                    <div class="card-heading">
                        <div>
                            <h4>Métodos de pagamento</h4>
                            <p class="sa-card-sub">Pagamentos não falhados no período</p>
                        </div>
                    </div>
                    <?php if (!empty($payMethods)): ?>
                        <div class="sa-chart-box sa-chart-box-doughnut">
                            <canvas id="payChart"></canvas>
                        </div>
                    <?php else: ?>
                        <div class="empty-state py-5">
                            <i class="bi bi-credit-card" style="font-size:2rem;"></i>
                            <p class="mt-3 mb-0">Sem dados de pagamento.</p>
                        </div>
                    <?php endif; ?>
                </article>
            </div>

        </div>

        <!-- ================= RESUMO + TOP PRODUTOS + TOP AGRICULTORES ================= -->
        <div class="row g-4 mb-4">

            <div class="col-lg-4">
                <article class="content-card sa-card">
                    <div class="card-heading">
                        <div>
                            <h4>Resumo do período</h4>
                            <p class="sa-card-sub"><?= $period ?> dias</p>
                        </div>
                    </div>
                    <div class="sa-summary">
                        <div class="sa-summary-row">
                            <span><i class="bi bi-cash-stack"></i> Receita</span>
                            <strong><?= money($periodRevenue) ?></strong>
                        </div>
                        <div class="sa-summary-row">
                            <span><i class="bi bi-receipt"></i> Pedidos</span>
                            <strong><?= $periodOrders ?></strong>
                        </div>
                        <div class="sa-summary-row">
                            <span><i class="bi bi-wallet2"></i> Ticket médio</span>
                            <strong><?= money($avgTicket) ?></strong>
                        </div>
                        <div class="sa-summary-row">
                            <span><i class="bi bi-graph-up"></i> Média diária</span>
                            <strong><?= money($period > 0 ? $periodRevenue / $period : 0) ?></strong>
                        </div>
                        <div class="sa-summary-row">
                            <span><i class="bi bi-trophy"></i> Melhor dia</span>
                            <?php
                            $best = ['label' => '—', 'revenue' => 0];
                            foreach ($daily as $d) if ($d['revenue'] > $best['revenue']) $best = $d;
                            ?>
                            <strong><?= $best['revenue'] > 0 ? $best['label'] . ' · ' . money($best['revenue']) : '—' ?></strong>
                        </div>
                    </div>
                </article>
            </div>

            <div class="col-lg-4">
                <article class="content-card sa-card">
                    <div class="card-heading">
                        <div>
                            <h4>Top produtos</h4>
                            <p class="sa-card-sub">Por vendas no período (sem cancelados)</p>
                        </div>
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
                        <?php endforeach; if (!$topProducts): ?>
                            <div class="empty-state py-4">
                                <p class="mb-0 text-muted">Sem dados.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            </div>

            <div class="col-lg-4">
                <article class="content-card sa-card">
                    <div class="card-heading">
                        <div>
                            <h4>Top agricultores</h4>
                            <p class="sa-card-sub">Por vendas no período (sem cancelados)</p>
                        </div>
                    </div>
                    <div class="sa-top-list">
                        <?php foreach ($topFarmers as $i => $f): ?>
                            <div class="sa-top-item">
                                <span class="sa-top-rank"><?= $i + 1 ?></span>
                                <div class="sa-top-info">
                                    <strong><?= e($f['name']) ?></strong>
                                    <small><?= (int)$f['orders_count'] ?> pedidos</small>
                                </div>
                                <span class="sa-top-value"><?= money($f['revenue']) ?></span>
                            </div>
                        <?php endforeach; if (!$topFarmers): ?>
                            <div class="empty-state py-4">
                                <p class="mb-0 text-muted">Sem dados.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            </div>

        </div>

    </section>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-gradient@0.6.0/dist/chartjs-plugin-gradient.min.js"></script>

<script>
(function () {
    if (typeof Chart === 'undefined') return;

    /* ---------- Tema: lê as CSS vars para os gráficos ---------- */
    function cssVar(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }
    function theme() {
        return {
            text: cssVar('--ink') || '#17332a',
            muted: cssVar('--muted') || '#6d7f77',
            grid: 'rgba(109,127,119,.14)',
            green: '#1c7c54',
            greenSoft: 'rgba(28,124,84,.14)',
            amber: '#de9a31',
            amberSoft: 'rgba(222,154,49,.18)'
        };
    }

    /* ---------- Helpers de formatação ---------- */
    function fmtMT(v) {
        return new Intl.NumberFormat('pt-MZ', { maximumFractionDigits: 0 }).format(v) + ' MT';
    }
    function fmtCompact(v) {
        return new Intl.NumberFormat('pt-MZ', { notation: 'compact', maximumFractionDigits: 1 }).format(v);
    }
    function fmtDateBR(iso) {
        var p = iso.split('-');
        return p[2] + '/' + p[1];
    }

    Chart.defaults.font.family = "'DM Sans', sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.color = theme().muted;

    /* Tooltip base reutilizável */
    function baseTooltip(extra) {
        return Object.assign({
            backgroundColor: 'rgba(23,51,42,.96)',
            titleColor: '#fff',
            bodyColor: '#e8f0ea',
            titleFont: { family: "'DM Sans', sans-serif", size: 12, weight: '700' },
            bodyFont:  { family: "'DM Sans', sans-serif", size: 12 },
            padding: 12,
            cornerRadius: 10,
            borderColor: 'rgba(255,255,255,.08)',
            borderWidth: 1,
            displayColors: false,
            boxPadding: 6
        }, extra || {});
    }

    /* ============================================================
       GRÁFICO 1: RECEITA (linha) + PEDIDOS (barras)
       ============================================================ */
    var main = document.getElementById('mainChart');
    if (main) {
        var t = theme();
        var labels  = [<?php $l = []; foreach ($daily as $d) $l[] = json_encode($d['label']); echo implode(',', $l); ?>];
        var revenues = [<?php echo implode(',', array_map(fn($d) => (float)$d['revenue'], $daily)); ?>];
        var orders   = [<?php echo implode(',', array_map(fn($d) => (int)$d['orders'], $daily)); ?>];

        var ctx = main.getContext('2d');
        var grad = ctx.createLinearGradient(0, 0, 0, 340);
        grad.addColorStop(0, 'rgba(28,124,84,.40)');
        grad.addColorStop(.6, 'rgba(28,124,84,.10)');
        grad.addColorStop(1, 'rgba(28,124,84,0)');

        new Chart(main, {
            data: {
                labels: labels,
                datasets: [
                    {
                        type: 'line',
                        label: 'Receita',
                        data: revenues,
                        yAxisID: 'y',
                        borderColor: t.green,
                        backgroundColor: grad,
                        borderWidth: 3,
                        fill: true,
                        tension: .38,
                        pointRadius: 0,
                        pointHoverRadius: 6,
                        pointHoverBackgroundColor: t.green,
                        pointHoverBorderColor: '#fff',
                        pointHoverBorderWidth: 3,
                        order: 1
                    },
                    {
                        type: 'bar',
                        label: 'Pedidos',
                        data: orders,
                        yAxisID: 'y1',
                        backgroundColor: t.amberSoft,
                        hoverBackgroundColor: t.amber,
                        borderRadius: 6,
                        borderSkipped: false,
                        maxBarThickness: 22,
                        order: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                animation: { duration: 800, easing: 'easeOutQuart' },
                plugins: {
                    legend: { display: false },
                    tooltip: baseTooltip({
                        callbacks: {
                            title: function (items) {
                                var i = items[0].dataIndex;
                                return <?php echo json_encode(date('d/m/Y', strtotime($since))) ?>.slice(0, 0) + items[0].label;
                            },
                            label: function (c) {
                                if (c.dataset.label === 'Receita') return '  Receita: ' + fmtMT(c.parsed.y);
                                return '  Pedidos: ' + c.parsed.y;
                            }
                        }
                    })
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: t.muted, maxRotation: 0, autoSkip: true, maxTicksLimit: 14 }
                    },
                    y: {
                        position: 'left',
                        beginAtZero: true,
                        grid: { color: t.grid, drawBorder: false },
                        ticks: { color: t.muted, callback: fmtCompact }
                    },
                    y1: {
                        position: 'right',
                        beginAtZero: true,
                        grid: { display: false },
                        ticks: { color: t.muted, precision: 0 }
                    }
                }
            }
        });
    }

    /* ============================================================
       GRÁFICO 2: CATEGORIAS (barras horizontais)
       ============================================================ */
    var catEl = document.getElementById('categoryChart');
    if (catEl) {
        var t2 = theme();
        var catLabels = [<?php $l = []; foreach ($cats as $c) $l[] = json_encode($c['name']); echo implode(',', $l); ?>];
        var catValues = [<?php echo implode(',', array_map(fn($c) => (int)$c['items'], $cats)); ?>];

        new Chart(catEl, {
            type: 'bar',
            data: {
                labels: catLabels,
                datasets: [{
                    label: 'Itens vendidos',
                    data: catValues,
                    backgroundColor: catValues.map(function (_, i) {
                        var palette = [
                            '#1c7c54','#2a8f63','#38a172','#4cb384',
                            '#7ac8a4','#a4d9c1','#c5e7d8','#e0f1e8'
                        ];
                        return palette[i] || '#1c7c54';
                    }),
                    borderRadius: 8,
                    borderSkipped: false,
                    maxBarThickness: 34
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 700, easing: 'easeOutQuart' },
                plugins: {
                    legend: { display: false },
                    tooltip: baseTooltip({
                        callbacks: {
                            label: function (c) { return '  ' + c.parsed.x + ' itens vendidos'; }
                        }
                    })
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: t2.grid, drawBorder: false },
                        ticks: { color: t2.muted, precision: 0 }
                    },
                    y: {
                        grid: { display: false },
                        ticks: { color: t2.text, font: { weight: '600' } }
                    }
                }
            }
        });
    }

    /* ============================================================
       GRÁFICO 3: MÉTODOS DE PAGAMENTO (doughnut)
       ============================================================ */
    var payEl = document.getElementById('payChart');
    if (payEl) {
        var t3 = theme();
        var payLabels = [<?php $l = []; foreach ($payMethods as $m) $l[] = json_encode($m['method'] ?: 'N/D'); echo implode(',', $l); ?>];
        var payValues = [<?php echo implode(',', array_map(fn($m) => (float)$m['total'], $payMethods)); ?>];
        var payTotals = payValues.reduce(function (a, b) { return a + b; }, 0);

        new Chart(payEl, {
            type: 'doughnut',
            data: {
                labels: payLabels,
                datasets: [{
                    data: payValues,
                    backgroundColor: ['#1c7c54','#38a172','#7ac8a4','#c5e7d8','#de9a31','#ef7a70'],
                    borderColor: cssVar('--surface') || '#fff',
                    borderWidth: 3,
                    hoverOffset: 8,
                    spacing: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                animation: { animateRotate: true, duration: 900, easing: 'easeOutQuart' },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: t3.text,
                            font: { weight: '600', size: 11 },
                            boxWidth: 10,
                            boxHeight: 10,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 14
                        }
                    },
                    tooltip: baseTooltip({
                        callbacks: {
                            label: function (c) {
                                var pct = payTotals > 0 ? ((c.parsed / payTotals) * 100).toFixed(1) : 0;
                                return '  ' + fmtMT(c.parsed) + '  ·  ' + pct + '%';
                            }
                        }
                    })
                }
            }
        });
    }

    /* ============================================================
       Re-render suave quando muda o tema
       ============================================================ */
    var lastTheme = document.documentElement.getAttribute('data-theme');
    new MutationObserver(function () {
        var cur = document.documentElement.getAttribute('data-theme');
        if (cur === lastTheme) return;
        lastTheme = cur;
        Chart.defaults.color = theme().muted;
        Object.values(Chart.instances).forEach(function (ch) {
            if (ch && ch.options && ch.options.scales) {
                Object.values(ch.options.scales).forEach(function (s) {
                    if (s.grid) s.grid.color = theme().grid;
                    if (s.ticks) s.ticks.color = theme().muted;
                });
            }
            ch.update('none');
        });
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
})();
</script>


<!-- ================= IMPRESSÃO / PDF ================= -->
<style>
.print-only { display: none; }
@media print {
    @page { size: A4; margin: 14mm 12mm; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .navbar, .navbar-pro, .dashboard-sidebar, .site-footer, .sa-hero-actions, .sa-period,
    .sa-toasts, #toast-container, .btn, form, .no-print, .skip-link { display: none !important; }
    html, body, .dashboard-layout, .dashboard-content, .sa-dash { background: #fff !important; color: #000 !important; }
    .dashboard-layout { display: block !important; min-height: 0 !important; }
    .dashboard-content { padding: 0 !important; margin: 0 !important; width: 100% !important; max-width: none !important; }
    .sa-hero { padding: 0 0 8px !important; margin-bottom: 12px !important; background: none !important; border: 0; border-bottom: 2px solid #000 !important; box-shadow: none !important; }
    .sa-hero-title, .sa-hero-sub, .sa-hero-eyebrow { color: #000 !important; }
    .content-card, .sa-card, .sa-kpi { box-shadow: none !important; border: 1px solid #bbb !important; break-inside: avoid; page-break-inside: avoid; }
    .sa-kpi-grid { gap: 8px !important; }
    .table-responsive, .sa-table-wrap, .sa-scroll-x { overflow: visible !important; }
    table { font-size: 10pt; }
    a { color: #000 !important; text-decoration: none !important; }
    canvas { max-width: 100% !important; }
    .print-only { display: block !important; }
}
</style>
<script>
/* Ajusta os gráficos ao tamanho da página antes de imprimir. */
window.addEventListener('beforeprint', function () {
    if (window.Chart && Chart.instances) Object.values(Chart.instances).forEach(function (c) { c.resize(); });
});
window.addEventListener('afterprint', function () {
    if (window.Chart && Chart.instances) Object.values(Chart.instances).forEach(function (c) { c.resize(); });
});
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>