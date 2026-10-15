<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';

require_role('farmer');

$page_title = 'Clientes';
$fid = user()['farmer_id'];
$pdo = db();

/* ============================================================
   LISTA DE CLIENTES (quem já comprou deste agricultor)
   ============================================================ */
$s = $pdo->prepare('
    SELECT
        u.id,
        u.name,
        u.email,
        u.phone,
        COUNT(DISTINCT CASE WHEN oi.status <> "cancelled" THEN o.id END) AS orders_count,
        MAX(CASE WHEN oi.status <> "cancelled" THEN o.created_at END) AS last_order,
        COALESCE(SUM(CASE WHEN oi.status <> "cancelled" THEN oi.subtotal ELSE 0 END), 0) AS total_spent
    FROM orders o
    JOIN buyers b ON b.id = o.buyer_id
    JOIN users u ON u.id = b.user_id
    JOIN order_items oi ON oi.order_id = o.id
    WHERE oi.farmer_id = ?
    GROUP BY u.id, u.name, u.email, u.phone
    HAVING orders_count > 0
    ORDER BY last_order DESC
');
$s->execute([$fid]);
$clients = $s->fetchAll();

/* ============================================================
   ESTATÍSTICAS
   ============================================================ */
$counts = [
    'total'      => count($clients),
    'recurring'  => 0,   // 3+ pedidos
    'new'        => 0,   // 1 pedido
    'inactive'   => 0,   // último pedido > 60 dias
    'revenue'    => 0,
];
$today = time();

foreach ($clients as $c) {
    $ordersN = (int)($c['orders_count'] ?? 0);

    if ($ordersN >= 3) $counts['recurring']++;
    elseif ($ordersN === 1) $counts['new']++;

    $lastTs = !empty($c['last_order']) ? strtotime($c['last_order']) : 0;
    $daysSince = $lastTs > 0 ? (int)floor(($today - $lastTs) / 86400) : 999;
    if ($daysSince > 60) $counts['inactive']++;

    $counts['revenue'] += (float)($c['total_spent'] ?? 0);
}

/* ============================================================
   HELPERS
   ============================================================ */
function days_ago(string $date): string {
    if (!$date) return '—';
    $diff = (int)floor((time() - strtotime($date)) / 86400);
    if ($diff <= 0) return 'hoje';
    if ($diff === 1) return 'ontem';
    if ($diff < 7) return "há {$diff} dias";
    if ($diff < 30) return 'há ' . floor($diff / 7) . ' sem.';
    if ($diff < 365) return 'há ' . floor($diff / 30) . ' meses';
    return 'há ' . floor($diff / 365) . ' ano(s)';
}

function client_tier(int $orders): array {
    if ($orders >= 5) return ['gold',   'trophy-fill',              'VIP'];
    if ($orders >= 3) return ['primary','star-fill',                'Recorrente'];
    if ($orders === 2) return ['info',  'arrow-repeat',             'Repetido'];
    return ['secondary', 'person-plus-fill',                        'Novo'];
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
                    <span class="sa-dot"></span> Relacionamento · Clientes
                </span>
                <h1 class="sa-hero-title">Os meus <em>clientes</em></h1>
                <p class="sa-hero-sub">
                    <?php if ($counts['recurring'] > 0): ?>
                        <strong><?= $counts['recurring'] ?></strong> clientes recorrentes ·
                        <strong><?= $counts['new'] ?></strong> novos.
                    <?php else: ?>
                        <strong><?= $counts['total'] ?></strong> clientes no total.
                    <?php endif; ?>
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('agricultor/dashboard.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <a href="<?= base_path('agricultor/pedidos.php') ?>" class="btn btn-primary">
                    <i class="bi bi-receipt"></i> Ver pedidos
                </a>
            </div>
        </header>

        <!-- ================= KPIs ================= -->
        <div class="sa-kpi-grid sa-kpi-grid-4">

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-people"></i></span>
                </header>
                <span class="sa-kpi-label">Total</span>
                <strong class="sa-kpi-value"><?= $counts['total'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-bag-check"></i> clientes compradores</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-star-fill"></i></span>
                </header>
                <span class="sa-kpi-label">Recorrentes</span>
                <strong class="sa-kpi-value"><?= $counts['recurring'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-arrow-repeat"></i> 3+ pedidos</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-info"><i class="bi bi-person-plus"></i></span>
                </header>
                <span class="sa-kpi-label">Novos</span>
                <strong class="sa-kpi-value"><?= $counts['new'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-lightning"></i> 1º pedido</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-cash-stack"></i></span>
                </header>
                <span class="sa-kpi-label">Receita gerada</span>
                <strong class="sa-kpi-value"><?= money($counts['revenue']) ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub">
                        <i class="bi bi-wallet2"></i>
                        Ticket <?= money($counts['total'] > 0 ? $counts['revenue'] / $counts['total'] : 0) ?>
                    </span>
                </footer>
            </article>

        </div>

        <!-- ================= TABELA ================= -->
        <article class="content-card sa-card">

            <div class="card-heading sa-card-head">
                <div>
                    <h4>Lista de clientes</h4>
                    <p class="sa-card-sub"><?= $counts['total'] ?> registos</p>
                </div>

                <div class="sa-toolbar">
                    <div class="sa-search">
                        <i class="bi bi-search"></i>
                        <input type="text" id="clientSearch" placeholder="Procurar por nome, email ou telefone…">
                    </div>

                    <div class="sa-filters" role="tablist">
                        <button class="sa-filter active" data-filter="all">Todos</button>
                        <button class="sa-filter" data-filter="recurring">Recorrentes</button>
                        <button class="sa-filter" data-filter="new">Novos</button>
                        <button class="sa-filter" data-filter="inactive">Inativos</button>
                    </div>
                </div>
            </div>

            <?php if (empty($clients)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-people" style="font-size:2.5rem;"></i>
                    <p class="mt-3 mb-1">Ainda não tem clientes.</p>
                    <a href="<?= base_path('agricultor/produtos.php') ?>" class="btn btn-primary mt-2">
                        <i class="bi bi-basket2"></i> Ver os meus produtos
                    </a>
                </div>
            <?php else: ?>
                <div class="sa-scroll-x">
                    <table class="table align-middle sa-table sa-clients-table" id="clientsTable">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Contacto</th>
                                <th class="text-center">Pedidos</th>
                                <th class="text-end">Total gasto</th>
                                <th>Último pedido</th>
                                <th class="text-end sa-clients-col-action">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($clients as $c):
                            $ordersN = (int)$c['orders_count'];
                            [$tierCls, $tierIcon, $tierLabel] = client_tier($ordersN);

                            $lastTs = !empty($c['last_order']) ? strtotime($c['last_order']) : 0;
                            $daysSince = $lastTs > 0 ? (int)floor((time() - $lastTs) / 86400) : 999;
                            $isInactive = $daysSince > 60;

                            $search = strtolower(
                                ($c['name'] ?? '') . ' ' .
                                ($c['email'] ?? '') . ' ' .
                                ($c['phone'] ?? '')
                            );

                            $cat = $isInactive ? 'inactive' : ($ordersN >= 3 ? 'recurring' : ($ordersN === 1 ? 'new' : 'other'));
                        ?>
                            <tr data-cat="<?= $cat ?>" data-search="<?= e($search) ?>">
                                <td>
                                    <div class="sa-user-cell">
                                        <span class="sa-avatar">
                                            <?= e(strtoupper(substr($c['name'] ?? '?', 0, 1))) ?>
                                        </span>
                                        <div class="sa-user-info">
                                            <strong><?= e($c['name']) ?></strong>
                                            <span class="sa-badge sa-badge-<?= $tierCls ?> sa-badge-sm">
                                                <i class="bi bi-<?= $tierIcon ?>"></i>
                                                <?= $tierLabel ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="sa-contact">
                                        <?php if (!empty($c['email'])): ?>
                                            <span><i class="bi bi-envelope"></i> <?= e($c['email']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($c['phone'])): ?>
                                            <span><i class="bi bi-telephone"></i> <?= e($c['phone']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span class="sa-count <?= $ordersN >= 3 ? 'sa-count-primary' : '' ?>">
                                        <?= $ordersN ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <strong class="sa-price"><?= money($c['total_spent'] ?? 0) ?></strong>
                                </td>
                                <td>
                                    <div class="sa-date-cell">
                                        <strong><?= date('d M Y', $lastTs ?: time()) ?></strong>
                                        <small class="<?= $isInactive ? 'text-warn' : '' ?>">
                                            <?= days_ago($c['last_order'] ?? '') ?>
                                        </small>
                                    </div>
                                </td>
                                <td class="text-end sa-clients-col-action">
                                    <a href="<?= base_path('agricultor/pedidos.php?cliente=' . urlencode($c['name'])) ?>"
                                       class="sa-btn sa-btn-ghost"
                                       title="Ver pedidos do cliente">
                                        <i class="bi bi-receipt"></i>
                                        <span>Pedidos</span>
                                    </a>
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
    var input   = document.getElementById('clientSearch');
    var table   = document.getElementById('clientsTable');
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
            var matchesFilter = currentFilter === 'all' || r.dataset.cat === currentFilter;
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
})();
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>