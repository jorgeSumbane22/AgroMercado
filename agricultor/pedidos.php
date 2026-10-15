<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';

require_role('farmer');

$page_title = 'Pedidos recebidos';
$fid = user()['farmer_id'];
$pdo = db();

/* ============================================================
   ESTADO DOS ITENS DESTE AGRICULTOR POR PEDIDO
   (item ativo menos avançado; "cancelado" se todos cancelados)
   ============================================================ */
$statusKeys = ['pending', 'confirmed', 'preparing', 'ready', 'shipping', 'delivered', 'cancelled'];
$myStatusSql = "COALESCE(ELT(MIN(CASE WHEN oi.status <> 'cancelled' THEN FIELD(oi.status,'pending','confirmed','preparing','ready','shipping','delivered') END),'pending','confirmed','preparing','ready','shipping','delivered'),'cancelled')";
$filter = in_array($_GET['status'] ?? '', $statusKeys, true) ? $_GET['status'] : 'all';
$q = trim((string)($_GET['q'] ?? ''));

$where = 'oi.farmer_id = ?';
$params = [$fid];
if ($q !== '') { $where .= ' AND (o.order_number LIKE ? OR u.name LIKE ?)'; $params[] = '%'.$q.'%'; $params[] = '%'.$q.'%'; }
$having = $filter !== 'all' ? ' HAVING my_status = ?' : '';
$hParams = $filter !== 'all' ? [$filter] : [];

$base = "
    SELECT
        o.*,
        u.name AS buyer_name,
        COUNT(DISTINCT oi.id) AS items_count,
        COALESCE(SUM(CASE WHEN oi.status <> 'cancelled' THEN oi.subtotal ELSE 0 END), 0) AS farmer_total,
        $myStatusSql AS my_status
    FROM orders o
    JOIN buyers b ON b.id = o.buyer_id
    JOIN users u ON u.id = b.user_id
    JOIN order_items oi ON oi.order_id = o.id
    WHERE $where
    GROUP BY o.id$having";

$s = $pdo->prepare("SELECT COUNT(*) FROM ($base) t");
$s->execute(array_merge($params, $hParams));
$filteredTotal = (int)$s->fetchColumn();

$perPage = 15;
[$page, $offset] = page_offset($perPage);
$s = $pdo->prepare("$base ORDER BY o.created_at DESC LIMIT $perPage OFFSET $offset");
$s->execute(array_merge($params, $hParams));
$orders = $s->fetchAll();

/* ============================================================
   ESTATÍSTICAS (sobre todos os pedidos deste agricultor)
   ============================================================ */
$s = $pdo->prepare("
    SELECT $myStatusSql AS my_status,
           COALESCE(SUM(CASE WHEN oi.status <> 'cancelled' THEN oi.subtotal ELSE 0 END), 0) AS farmer_total,
           DATE(o.created_at) AS d
    FROM orders o JOIN order_items oi ON oi.order_id = o.id
    WHERE oi.farmer_id = ?
    GROUP BY o.id
");
$s->execute([$fid]);
$all = $s->fetchAll();

$counts = array_fill_keys($statusKeys, 0) + ['total' => count($all), 'revenue' => 0, 'today' => 0];
$today = date('Y-m-d');
foreach ($all as $o) {
    $counts[$o['my_status']]++;
    $counts['revenue'] += (float)$o['farmer_total'];
    if ($o['d'] === $today) $counts['today']++;
}
$nonCancelled = $counts['total'] - $counts['cancelled'];

/* ============================================================
   HELPERS DE ESTADO
   ============================================================ */
function order_meta(string $st): array {
    $map = [
        'pending'    => ['warning', 'hourglass-split',        'Pendente'],
        'confirmed'  => ['info',    'check-circle-fill',      'Confirmado'],
        'preparing'  => ['info',    'box-seam-fill',          'Em preparação'],
        'ready'      => ['primary', 'bag-check-fill',         'Pronto'],
        'shipping'   => ['info',    'truck',                  'Em entrega'],
        'delivered'  => ['success', 'check2-circle',          'Entregue'],
        'cancelled'  => ['danger',  'x-octagon-fill',         'Cancelado'],
    ];
    return $map[$st] ?? ['secondary', 'circle', ucfirst($st)];
}
function orders_filter_url(string $st, string $q): string { $p = []; if ($st !== 'all') $p['status'] = $st; if ($q !== '') $p['q'] = $q; return '?' . http_build_query($p); }

include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <!-- ================= HERO ================= -->
        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Operações · Pedidos
                </span>
                <h1 class="sa-hero-title">Pedidos <em>recebidos</em></h1>
                <p class="sa-hero-sub">
                    <?php if ($counts['pending'] > 0): ?>
                        <strong><?= $counts['pending'] ?> pedidos</strong> aguardam confirmação.
                    <?php elseif ($counts['today'] > 0): ?>
                        <strong><?= $counts['today'] ?></strong> pedidos recebidos hoje.
                    <?php else: ?>
                        Sem pedidos pendentes.
                    <?php endif; ?>
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('agricultor/dashboard.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <a href="<?= base_path('agricultor/produtos.php') ?>" class="btn btn-primary">
                    <i class="bi bi-basket2"></i> Ver produtos
                </a>
            </div>
        </header>

        <!-- ================= KPIs ================= -->
        <div class="sa-kpi-grid sa-kpi-grid-4">

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-receipt"></i></span>
                    <?php if ($counts['today'] > 0): ?>
                        <span class="sa-kpi-badge sa-badge-up">
                            <i class="bi bi-arrow-up-right"></i> <?= $counts['today'] ?> hoje
                        </span>
                    <?php endif; ?>
                </header>
                <span class="sa-kpi-label">Total</span>
                <strong class="sa-kpi-value"><?= $counts['total'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-list-check"></i> pedidos recebidos</span>
                </footer>
            </article>

            <article class="sa-kpi <?= $counts['pending'] > 0 ? 'sa-kpi-warn' : '' ?>">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-warning"><i class="bi bi-hourglass-split"></i></span>
                    <?php if ($counts['pending'] > 0): ?>
                        <span class="sa-kpi-badge sa-badge-warn">ação necessária</span>
                    <?php endif; ?>
                </header>
                <span class="sa-kpi-label">Pendentes</span>
                <strong class="sa-kpi-value"><?= $counts['pending'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-clock-history"></i> aguardam confirmação</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-check2-circle"></i></span>
                </header>
                <span class="sa-kpi-label">Entregues</span>
                <strong class="sa-kpi-value"><?= $counts['delivered'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-bag-check"></i> concluídos</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-cash-stack"></i></span>
                </header>
                <span class="sa-kpi-label">Receita</span>
                <strong class="sa-kpi-value"><?= money($counts['revenue']) ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub">
                        <i class="bi bi-wallet2"></i>
                        Ticket <?= money($nonCancelled > 0 ? $counts['revenue'] / $nonCancelled : 0) ?>
                    </span>
                </footer>
            </article>

        </div>

        <!-- ================= TABELA ================= -->
        <article class="content-card sa-card">

            <div class="card-heading sa-card-head">
                <div>
                    <h4>Lista de pedidos</h4>
                    <p class="sa-card-sub"><?= $filteredTotal ?> registo<?= $filteredTotal !== 1 ? 's' : '' ?><?= $filter !== 'all' ? ' · filtro: '.e(order_status_label($filter)) : '' ?> · estado = estado dos seus itens</p>
                </div>

                <div class="sa-toolbar">
                    <form method="get" class="sa-search">
                        <i class="bi bi-search"></i>
                        <?php if ($filter !== 'all'): ?><input type="hidden" name="status" value="<?= e($filter) ?>"><?php endif; ?>
                        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Procurar por nº pedido ou cliente…">
                    </form>

                    <div class="sa-filters">
                        <a class="sa-filter<?= $filter === 'all' ? ' active' : '' ?>" href="<?= e(orders_filter_url('all', $q)) ?>">Todos</a>
                        <?php foreach ($statusKeys as $sk): ?>
                            <a class="sa-filter<?= $filter === $sk ? ' active' : '' ?>" href="<?= e(orders_filter_url($sk, $q)) ?>"><?= e(order_status_label($sk)) ?> <small>(<?= (int)$counts[$sk] ?>)</small></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php if (empty($orders)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-inbox" style="font-size:2.5rem;"></i>
                    <p class="mt-3 mb-1"><?= $counts['total'] > 0 ? 'Nenhum pedido corresponde ao filtro.' : 'Ainda não recebeu pedidos.' ?></p>
                    <a href="<?= base_path('agricultor/produtos.php') ?>" class="btn btn-primary mt-2">
                        <i class="bi bi-basket2"></i> Ver os meus produtos
                    </a>
                </div>
            <?php else: ?>
                <div class="sa-scroll-x">
                    <table class="table align-middle sa-table sa-orders-table" id="ordersTable">
                        <thead>
                            <tr>
                                <th>Pedido</th>
                                <th>Cliente</th>
                                <th class="text-center">Itens</th>
                                <th class="text-end">Total</th>
                                <th>Os seus itens</th>
                                <th>Pedido (geral)</th>
                                <th class="text-end sa-orders-col-action">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php $unreadMsgs = unread_messages_by_order(current_user_id(), (int)user()['farmer_id']); ?>
                        <?php foreach ($orders as $o):
                            $st = $o['my_status'];
                            [$badgeCls, $badgeIcon, $badgeLabel] = order_meta($st);
                            $num = $o['order_number'] ?? ('#' . $o['id']);
                            $total = $o['farmer_total'] ?? $o['total'] ?? 0;
                        ?>
                            <tr>
                                <td>
                                    <div class="sa-order-cell">
                                        <span class="sa-order-icon">
                                            <i class="bi bi-receipt"></i>
                                        </span>
                                        <div class="sa-order-info">
                                            <strong><?= e($num) ?><?php if (!empty($unreadMsgs[(int)$o['id']])): ?> <span class="badge rounded-pill text-bg-warning" title="Mensagens por ler"><i class="bi bi-chat-dots me-1" aria-hidden="true"></i><?= $unreadMsgs[(int)$o['id']] ?></span><?php endif; ?></strong>
                                            <small>
                                                <?= date('d M Y', strtotime($o['created_at'])) ?>
                                                · <?= date('H:i', strtotime($o['created_at'])) ?>
                                            </small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="sa-user-cell">
                                        <span class="sa-avatar">
                                            <?= e(strtoupper(substr($o['buyer_name'] ?? '?', 0, 1))) ?>
                                        </span>
                                        <span><?= e($o['buyer_name'] ?? '—') ?></span>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span class="sa-count"><?= (int)$o['items_count'] ?></span>
                                </td>
                                <td class="text-end">
                                    <strong class="sa-price"><?= money($total) ?></strong>
                                </td>
                                <td>
                                    <span class="sa-badge sa-badge-<?= $badgeCls ?>">
                                        <i class="bi bi-<?= $badgeIcon ?>"></i>
                                        <?= $badgeLabel ?>
                                    </span>
                                </td>
                                <td><?= order_status_badge($o['status']) ?></td>
                                <td class="text-end sa-orders-col-action">
                                    <a href="<?= base_path('agricultor/pedido-detalhes.php?id='.(int)$o['id']) ?>"
                                       class="sa-btn sa-btn-ghost"
                                       title="Ver detalhes">
                                        <i class="bi bi-eye"></i>
                                        <span>Detalhes</span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3"><?= pagination($page, $perPage, $filteredTotal) ?></div>
            <?php endif; ?>

        </article>

    </section>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
