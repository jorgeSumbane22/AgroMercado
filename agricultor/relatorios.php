<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';

require_role('farmer');

$page_title = 'Relatórios';
$fid = user()['farmer_id'];
$pdo = db();

/* ============================================================
   PERÍODO
   ============================================================ */
$period = $_GET['period'] ?? 'month';
if (!in_array($period, ['week', 'month', 'year'], true)) $period = 'month';

$date = match ($period) {
    'year'  => date('Y-01-01'),
    'week'  => date('Y-m-d', strtotime('-7 days')),
    default => date('Y-m-01'),
};

$periodLabel = match ($period) {
    'year'  => 'Este ano',
    'week'  => 'Últimos 7 dias',
    default => 'Este mês',
};

/* ============================================================
   ESTATÍSTICAS DO PERÍODO
   ============================================================ */
$s = $pdo->prepare('
    SELECT
        COUNT(DISTINCT o.id) AS orders_count,
        COALESCE(SUM(oi.subtotal), 0) AS revenue,
        COALESCE(SUM(oi.quantity), 0) AS quantity
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE oi.farmer_id = ?
      AND o.created_at >= ?
      AND oi.status <> "cancelled"
');
$s->execute([$fid, $date]);
$stats = $s->fetch() ?: ['orders_count' => 0, 'revenue' => 0, 'quantity' => 0];

$avgTicket = $stats['orders_count'] > 0
    ? (float)$stats['revenue'] / (int)$stats['orders_count']
    : 0;
$avgPerOrder = $stats['orders_count'] > 0
    ? (float)$stats['quantity'] / (int)$stats['orders_count']
    : 0;

/* ============================================================
   RECEITA POR DIA (gráfico)
   ============================================================ */
$s = $pdo->prepare('
    SELECT
        DATE(o.created_at) AS day,
        SUM(oi.subtotal) AS revenue,
        COUNT(DISTINCT o.id) AS orders_count
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE oi.farmer_id = ?
      AND o.created_at >= ?
      AND oi.status <> "cancelled"
    GROUP BY DATE(o.created_at)
    ORDER BY day
');
$s->execute([$fid, $date]);
$chart = $s->fetchAll();

/* Se for "month" e não houver dias, preenche com zeros até hoje */
$chartByDay = [];
foreach ($chart as $c) {
    $chartByDay[$c['day']] = $c;
}

/* Construir série completa */
$daily = [];
$startTs = strtotime($date);
$endTs   = strtotime('today');

/* Se período "week" ou "month", limita o range para não ficar gigante */
$maxDays = ($period === 'year') ? 366 : 31;
$rangeEnd = $endTs;
$rangeStart = max($startTs, $endTs - ($maxDays - 1) * 86400);

for ($ts = $rangeStart; $ts <= $rangeEnd; $ts += 86400) {
    $day = date('Y-m-d', $ts);
    $row = $chartByDay[$day] ?? null;
    $daily[] = [
        'day'          => $day,
        'label'        => date('d/m', $ts),
        'revenue'      => (float)($row['revenue'] ?? 0),
        'orders_count' => (int)($row['orders_count'] ?? 0),
    ];
}

$maxRev = 0;
$totalRevRange = 0;
foreach ($daily as $d) {
    if ($d['revenue'] > $maxRev) $maxRev = $d['revenue'];
    $totalRevRange += $d['revenue'];
}
$avgDaily = count($daily) > 0 ? $totalRevRange / count($daily) : 0;

/* Melhor dia */
$bestDay = ['label' => '—', 'revenue' => 0];
foreach ($daily as $d) {
    if ($d['revenue'] > $bestDay['revenue']) {
        $bestDay = ['label' => $d['label'], 'revenue' => $d['revenue']];
    }
}

/* ============================================================
   TOP PRODUTOS DO PERÍODO
   ============================================================ */
$s = $pdo->prepare('
    SELECT
        oi.product_name,
        SUM(oi.quantity) AS quantity,
        SUM(oi.subtotal) AS revenue
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE oi.farmer_id = ?
      AND o.created_at >= ?
      AND oi.status <> "cancelled"
    GROUP BY oi.product_id, oi.product_name
    ORDER BY revenue DESC
    LIMIT 5
');
$s->execute([$fid, $date]);
$topProducts = $s->fetchAll();

$maxProdRev = 0;
foreach ($topProducts as $p) {
    if ((float)$p['revenue'] > $maxProdRev) $maxProdRev = (float)$p['revenue'];
}

/* ============================================================
   EXPORTAÇÃO CSV (receita diária do período, sem cancelados)
   ============================================================ */
if (($_GET['export'] ?? '') === 'csv') {
    $rows = array_map(fn($d) => [date('d/m/Y', strtotime($d['day'])), $d['orders_count'], number_format($d['revenue'], 2, ',', '')], $daily);
    $rows[] = ['Total', (int)$stats['orders_count'], number_format((float)$stats['revenue'], 2, ',', '')];
    audit(user()['id'], 'export', 'reports', 'Agricultor exportou relatório ' . $period . ' (CSV)');
    csv_download('relatorio_' . $period . '_' . date('Ymd') . '.csv', ['Dia', 'Pedidos', 'Receita (MT)'], $rows);
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
                <h1 class="sa-hero-title">Relatórios de <em>vendas</em></h1>
                <p class="sa-hero-sub">
                    <?= e($periodLabel) ?> ·
                    <strong><?= (int)$stats['orders_count'] ?></strong> pedidos ·
                    <strong><?= money($stats['revenue']) ?></strong> receita.
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('agricultor/dashboard.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <a href="?period=<?= e($period) ?>&amp;export=csv" class="btn btn-outline-secondary">
                    <i class="bi bi-filetype-csv"></i> Exportar CSV
                </a>
                <a href="<?= base_path('agricultor/vendas.php') ?>" class="btn btn-primary">
                    <i class="bi bi-graph-up-arrow"></i> Vendas
                </a>
            </div>
        </header>

        <!-- ================= PERÍODO ================= -->
        <div class="sa-period">
            <span class="sa-period-label"><i class="bi bi-calendar-range"></i> Período:</span>
            <div class="sa-period-tabs" role="tablist">
                <a href="?period=week"  class="sa-period-tab <?= $period === 'week'  ? 'active' : '' ?>">Últimos 7 dias</a>
                <a href="?period=month" class="sa-period-tab <?= $period === 'month' ? 'active' : '' ?>">Este mês</a>
                <a href="?period=year"  class="sa-period-tab <?= $period === 'year'  ? 'active' : '' ?>">Este ano</a>
            </div>
        </div>

        <!-- ================= KPIs ================= -->
        <div class="sa-kpi-grid sa-kpi-grid-4">

            <article class="sa-kpi sa-kpi-hero">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-cash-stack"></i></span>
                    <span class="sa-kpi-badge sa-badge-up">
                        <i class="bi bi-calendar3"></i> <?= e($periodLabel) ?>
                    </span>
                </header>
                <span class="sa-kpi-label">Receita</span>
                <strong class="sa-kpi-value"><?= money($stats['revenue']) ?></strong>

                <svg class="sa-spark" viewBox="0 0 200 50" preserveAspectRatio="none" aria-hidden="true">
                    <?php
                    $pts = [];
                    $n = count($daily);
                    foreach ($daily as $i => $d) {
                        $x = $n > 1 ? ($i / ($n - 1)) * 200 : 0;
                        $y = 50 - ($maxRev > 0 ? ($d['revenue'] / $maxRev) * 45 : 0) - 2;
                        $pts[] = round($x, 1) . ',' . round($y, 1);
                    }
                    $path = 'M' . implode(' L', $pts);
                    ?>
                    <defs>
                        <linearGradient id="relSpark" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="var(--green)" stop-opacity=".35"/>
                            <stop offset="100%" stop-color="var(--green)" stop-opacity="0"/>
                        </linearGradient>
                    </defs>
                    <path d="<?= $path ?> L200,50 L0,50 Z" fill="url(#relSpark)"/>
                    <path d="<?= $path ?>" fill="none" stroke="var(--green)" stroke-width="2"
                          stroke-linecap="round" stroke-linejoin="round"/>
                </svg>

                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-wallet2"></i> Ticket <?= money($avgTicket) ?></span>
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
                        <i class="bi bi-graph-up"></i> <?= number_format($avgPerOrder, 1, ',', '.') ?> unid./pedido
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
                    <span class="sa-kpi-sub"><i class="bi bi-basket"></i> unidades no total</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-calendar-check"></i></span>
                </header>
                <span class="sa-kpi-label">Média diária</span>
                <strong class="sa-kpi-value"><?= money($avgDaily) ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub">
                        <i class="bi bi-trophy"></i>
                        Melhor dia <?= e($bestDay['label']) ?>
                    </span>
                </footer>
            </article>

        </div>

        <!-- ================= GRÁFICO ================= -->
        <article class="content-card sa-card mb-4">
            <div class="card-heading">
                <div>
                    <h4>Receita ao longo do período</h4>
                    <p class="sa-card-sub"><?= count($daily) ?> dias · excluindo cancelados</p>
                </div>
                <div class="sa-chart-legend">
                    <span><i class="bi bi-circle-fill" style="color:#1c7c54"></i> Receita</span>
                </div>
            </div>

            <?php if (empty($chart)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-graph-up" style="font-size:2.5rem;"></i>
                    <p class="mt-3 mb-0">Sem vendas registadas neste período.</p>
                </div>
            <?php else: ?>
                <div class="sa-chart-box sa-chart-box-lg">
                    <canvas id="salesChart"></canvas>
                </div>
            <?php endif; ?>
        </article>

        <!-- ================= TOP PRODUTOS + TABELA DIÁRIA ================= -->
        <div class="row g-4">

            <!-- Top produtos -->
            <div class="col-lg-5">
                <article class="content-card sa-card">
                    <div class="card-heading">
                        <div>
                            <h4>Top produtos do período</h4>
                            <p class="sa-card-sub">Por receita gerada</p>
                        </div>
                    </div>

                    <?php if (empty($topProducts)): ?>
                        <div class="empty-state py-4">
                            <i class="bi bi-basket2" style="font-size:2rem;"></i>
                            <p class="mt-3 mb-0">Sem produtos vendidos.</p>
                        </div>
                    <?php else: ?>
                        <div class="sa-top-list">
                            <?php foreach ($topProducts as $i => $p):
                                $rev = (float)$p['revenue'];
                                $pct = $maxProdRev > 0 ? round(($rev / $maxProdRev) * 100) : 0;
                                $rankIcon = $i === 0 ? 'trophy-fill' : ($i === 1 ? 'award-fill' : ($i === 2 ? 'award-fill' : ''));
                                $rankClass = $i === 0 ? 'sa-rank-gold' : ($i === 1 ? 'sa-rank-silver' : ($i === 2 ? 'sa-rank-bronze' : ''));
                            ?>
                                <div class="sa-top-item">
                                    <span class="sa-rank <?= $rankClass ?>">
                                        <?php if ($rankIcon): ?>
                                            <i class="bi bi-<?= $rankIcon ?>"></i>
                                        <?php else: ?>
                                            <?= $i + 1 ?>
                                        <?php endif; ?>
                                    </span>
                                    <div class="sa-top-info">
                                        <strong><?= e($p['product_name']) ?></strong>
                                        <small><?= number_format((float)$p['quantity'], 0, ',', ' ') ?> unid.</small>
                                        <div class="sa-progress-bar-mini">
                                            <span style="width:<?= $pct ?>%"></span>
                                        </div>
                                    </div>
                                    <span class="sa-top-value"><?= money($rev) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            </div>

            <!-- Tabela diária -->
            <div class="col-lg-7">
                <article class="content-card sa-card">
                    <div class="card-heading">
                        <div>
                            <h4>Detalhe diário</h4>
                            <p class="sa-card-sub"><?= count($daily) ?> registos</p>
                        </div>
                    </div>

                    <?php if (empty($chart)): ?>
                        <div class="empty-state py-4">
                            <i class="bi bi-calendar-x" style="font-size:2rem;"></i>
                            <p class="mt-3 mb-0">Sem dias com vendas.</p>
                        </div>
                    <?php else: ?>
                        <div class="sa-daily-list">
                            <?php foreach (array_reverse($daily) as $d):
                                $hasSales = $d['revenue'] > 0;
                                if (!$hasSales) continue; // mostra só dias com vendas
                            ?>
                                <div class="sa-daily-row">
                                    <div class="sa-daily-date">
                                        <strong><?= date('d', strtotime($d['day'])) ?></strong>
                                        <small><?= date('M', strtotime($d['day'])) ?></small>
                                    </div>
                                    <div class="sa-daily-info">
                                        <strong><?= money($d['revenue']) ?></strong>
                                        <small><?= (int)$d['orders_count'] ?> pedido<?= $d['orders_count'] !== 1 ? 's' : '' ?></small>
                                    </div>
                                    <div class="sa-daily-bar">
                                        <?php $pct = $maxRev > 0 ? round(($d['revenue'] / $maxRev) * 100) : 0; ?>
                                        <span style="width:<?= $pct ?>%"></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            </div>

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

    var el = document.getElementById('salesChart');
    if (!el) return;

    var labels  = [<?php $l = []; foreach ($daily as $d) $l[] = json_encode($d['label']); echo implode(',', $l); ?>];
    var values  = [<?php echo implode(',', array_map(fn($d) => (float)$d['revenue'], $daily)); ?>];
    var orders  = [<?php echo implode(',', array_map(fn($d) => (int)$d['orders_count'], $daily)); ?>];

    var ctx = el.getContext('2d');
    var grad = ctx.createLinearGradient(0, 0, 0, 360);
    grad.addColorStop(0, 'rgba(28,124,84,.40)');
    grad.addColorStop(.6, 'rgba(28,124,84,.10)');
    grad.addColorStop(1, 'rgba(28,124,84,0)');

    new Chart(el, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Receita',
                data: values,
                borderColor: '#1c7c54',
                backgroundColor: grad,
                borderWidth: 2.5,
                fill: true,
                tension: .38,
                pointRadius: 0,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: '#1c7c54',
                pointHoverBorderColor: '#fff',
                pointHoverBorderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            animation: { duration: 800, easing: 'easeOutQuart' },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(23,51,42,.96)',
                    titleColor: '#fff',
                    bodyColor: '#e8f0ea',
                    padding: 12,
                    cornerRadius: 10,
                    borderColor: 'rgba(255,255,255,.08)',
                    borderWidth: 1,
                    displayColors: false,
                    callbacks: {
                        label: function (c) {
                            var i = c.dataIndex;
                            var money = new Intl.NumberFormat('pt-MZ', { maximumFractionDigits: 0 }).format(c.parsed.y);
                            return [
                                '  Receita: ' + money + ' MT',
                                '  Pedidos: ' + orders[i]
                            ];
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: cssVar('--muted') || '#6d7f77', maxRotation: 0, autoSkip: true, maxTicksLimit: 14 }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(109,127,119,.12)', drawBorder: false },
                    ticks: {
                        color: cssVar('--muted') || '#6d7f77',
                        callback: function (v) {
                            return new Intl.NumberFormat('pt-MZ', { notation: 'compact' }).format(v);
                        }
                    }
                }
            }
        }
    });

    /* Re-render tema */
    new MutationObserver(function () {
        var chart = Chart.getChart(el);
        if (!chart) return;
        chart.options.scales.x.ticks.color = cssVar('--muted') || '#6d7f77';
        chart.options.scales.y.ticks.color = cssVar('--muted') || '#6d7f77';
        chart.update('none');
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
})();
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>