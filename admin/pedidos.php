<?php require_once __DIR__.'/../includes/permissions.php'; ?>
<?php
require_role('superadmin');
require_once __DIR__.'/_helpers.php';
$pdo = db();

/* ============================================================
   FILTROS (status, comprador, datas, nº do pedido)
   ============================================================ */
$statuses = ['pending','confirmed','preparing','ready','shipping','delivered','cancelled'];
$fStatus = (string)($_GET['status'] ?? '');
if (!in_array($fStatus, $statuses, true)) $fStatus = '';
$fBuyer = max(0, safe_int($_GET['buyer'] ?? 0));
$fFrom  = admin_valid_date($_GET['from'] ?? '');
$fTo    = admin_valid_date($_GET['to'] ?? '');
$q      = trim((string)($_GET['q'] ?? ''));

$where = []; $params = [];
if ($fStatus !== '') { $where[] = 'o.status=?'; $params[] = $fStatus; }
if ($fBuyer)         { $where[] = 'o.buyer_id=?'; $params[] = $fBuyer; }
if ($fFrom !== '')   { $where[] = 'o.created_at >= ?'; $params[] = $fFrom . ' 00:00:00'; }
if ($fTo !== '')     { $where[] = 'o.created_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $fTo; }
if ($q !== '')       { $where[] = '(o.order_number LIKE ? OR u.name LIKE ?)'; $like = '%' . $q . '%'; array_push($params, $like, $like); }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$fromSql = '
    FROM orders o
    JOIN buyers b ON b.id = o.buyer_id
    JOIN users u ON u.id = b.user_id
    LEFT JOIN payments pay ON pay.order_id = o.id';

/* ---------- Exportação CSV da lista filtrada ---------- */
if (($_GET['export'] ?? '') === 'csv') {
    $s = $pdo->prepare('SELECT o.order_number, o.created_at, u.name, u.email, o.status, o.payment_status, pay.method, o.subtotal, o.delivery_fee, o.total, o.province, o.district' . $fromSql . $whereSql . ' ORDER BY o.created_at DESC');
    $s->execute($params);
    $rows = [];
    foreach ($s as $r) {
        $rows[] = [$r['order_number'], date('d/m/Y H:i', strtotime($r['created_at'])), $r['name'], $r['email'], order_status_label($r['status']), payment_status_label($r['payment_status']),
            payment_method_label((string)$r['method']), number_format((float)$r['subtotal'], 2, ',', ''), number_format((float)$r['delivery_fee'], 2, ',', ''), number_format((float)$r['total'], 2, ',', ''), $r['province'], $r['district']];
    }
    audit(user()['id'], 'export', 'orders', 'Exportou ' . count($rows) . ' pedidos (CSV)');
    csv_download('pedidos_' . date('Ymd_His') . '.csv', ['Pedido', 'Data', 'Comprador', 'Email', 'Estado', 'Pagamento', 'Método', 'Subtotal', 'Entrega', 'Total', 'Província', 'Distrito'], $rows);
}

/* ---------- Paginação ---------- */
$perPage = 20;
[$page, $offset] = page_offset($perPage);
$c = $pdo->prepare('SELECT COUNT(*)' . $fromSql . $whereSql);
$c->execute($params);
$totalFiltered = (int)$c->fetchColumn();
$s = $pdo->prepare('SELECT o.*, u.name AS buyer_name, pay.method AS pay_method' . $fromSql . $whereSql . ' ORDER BY o.created_at DESC, o.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$s->execute($params);
$orders = $s->fetchAll();

$buyerName = null;
if ($fBuyer) {
    $bn = $pdo->prepare('SELECT u.name FROM buyers b JOIN users u ON u.id=b.user_id WHERE b.id=?');
    $bn->execute([$fBuyer]);
    $buyerName = $bn->fetchColumn() ?: null;
}

/* ============================================================
   ESTATÍSTICAS (todos os pedidos)
   ============================================================ */
$counts = ['total' => 0, 'pending' => 0, 'shipping' => 0, 'delivered' => 0, 'cancelled' => 0, 'revenue' => 0, 'today' => 0];
$valid = 0;
foreach ($pdo->query('SELECT status, COUNT(*) n, COALESCE(SUM(total),0) t, COALESCE(SUM(DATE(created_at)=CURDATE()),0) today FROM orders GROUP BY status') as $row) {
    $counts['total'] += (int)$row['n'];
    $counts['today'] += (int)$row['today'];
    if (isset($counts[$row['status']])) $counts[$row['status']] = (int)$row['n'];
    if ($row['status'] !== 'cancelled') { $counts['revenue'] += (float)$row['t']; $valid += (int)$row['n']; }
}
$avgTicket = $valid > 0 ? $counts['revenue'] / $valid : 0;

$hasFilters = $fStatus !== '' || $fBuyer || $fFrom !== '' || $fTo !== '' || $q !== '';
$exportQuery = http_build_query(array_filter(['status' => $fStatus ?: null, 'buyer' => $fBuyer ?: null, 'from' => $fFrom ?: null, 'to' => $fTo ?: null, 'q' => $q !== '' ? $q : null, 'export' => 'csv']));

$page_title = 'Pedidos';
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
                <h1 class="sa-hero-title">Todos os <em>pedidos</em></h1>
                <p class="sa-hero-sub">
                    <?php if ($counts['pending'] > 0): ?>
                        <strong><?= $counts['pending'] ?></strong> pedidos aguardam confirmação.
                    <?php else: ?>
                        Todos os pedidos estão processados.
                    <?php endif; ?>
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('admin/dashboard.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <a href="?<?= e($exportQuery) ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-filetype-csv"></i> Exportar CSV
                </a>
                <a href="<?= base_path('admin/relatorios.php') ?>" class="btn btn-primary">
                    <i class="bi bi-bar-chart-line"></i> Relatórios
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
                    <span class="sa-kpi-sub"><i class="bi bi-clock-history"></i> <?= $counts['pending'] ?> pendentes · <?= $counts['cancelled'] ?> cancelados</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-info"><i class="bi bi-truck"></i></span>
                </header>
                <span class="sa-kpi-label">Em entrega</span>
                <strong class="sa-kpi-value"><?= $counts['shipping'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-box-seam"></i> a caminho</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-check2-circle"></i></span>
                </header>
                <span class="sa-kpi-label">Entregues</span>
                <strong class="sa-kpi-value"><?= $counts['delivered'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-bag-check"></i> completados</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-cash-stack"></i></span>
                </header>
                <span class="sa-kpi-label">Volume (não cancelados)</span>
                <strong class="sa-kpi-value"><?= money($counts['revenue']) ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-wallet2"></i> Ticket <?= money($avgTicket) ?></span>
                </footer>
            </article>

        </div>

        <!-- ================= TABELA ================= -->
        <article class="content-card sa-card">

            <div class="card-heading sa-card-head">
                <div>
                    <h4>Lista de pedidos</h4>
                    <p class="sa-card-sub"><?= $totalFiltered ?> registos<?= $buyerName ? ' · comprador: ' . e($buyerName) : '' ?></p>
                </div>
            </div>

            <form method="get" class="row g-2 align-items-end mb-3" role="search">
                <div class="col-md-3">
                    <label class="form-label small" for="fq">Nº do pedido / comprador</label>
                    <input type="text" id="fq" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="AGR-…">
                </div>
                <div class="col-md-2">
                    <label class="form-label small" for="fstatus">Estado</label>
                    <select id="fstatus" name="status" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <?php foreach ($statuses as $stt): ?>
                            <option value="<?= $stt ?>" <?= $fStatus === $stt ? 'selected' : '' ?>><?= e(order_status_label($stt)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small" for="ffrom">De</label>
                    <input type="date" id="ffrom" name="from" value="<?= e($fFrom) ?>" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small" for="fto">Até</label>
                    <input type="date" id="fto" name="to" value="<?= e($fTo) ?>" class="form-control form-control-sm">
                </div>
                <div class="col-md-1">
                    <label class="form-label small" for="fbuyer">Comprador ID</label>
                    <input type="number" min="1" id="fbuyer" name="buyer" value="<?= $fBuyer ?: '' ?>" class="form-control form-control-sm">
                </div>
                <div class="col-md-2 d-flex gap-1">
                    <button class="btn btn-sm btn-primary flex-fill"><i class="bi bi-funnel"></i> Filtrar</button>
                    <?php if ($hasFilters): ?><a href="<?= base_path('admin/pedidos.php') ?>" class="btn btn-sm btn-outline-secondary" title="Limpar filtros"><i class="bi bi-x-lg"></i></a><?php endif; ?>
                </div>
            </form>

            <?php if (empty($orders)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-receipt" style="font-size:2.5rem;"></i>
                    <p class="mt-3 mb-0"><?= $hasFilters ? 'Nenhum resultado encontrado.' : 'Sem pedidos registados.' ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive sa-table-wrap">
                    <table class="table align-middle sa-table" id="ordersTable">
                        <thead>
                            <tr>
                                <th>Pedido</th>
                                <th>Comprador</th>
                                <th>Data</th>
                                <th class="text-end">Total</th>
                                <th>Pagamento</th>
                                <th>Estado</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($orders as $o): ?>
                            <tr data-status="<?= e($o['status']) ?>">
                                <td>
                                    <a href="<?= base_path('admin/pedido.php?id=' . (int)$o['id']) ?>" class="sa-order-id text-decoration-none">
                                        <i class="bi bi-receipt"></i>
                                        <?= e($o['order_number']) ?>
                                    </a>
                                </td>
                                <td>
                                    <div class="sa-user-cell">
                                        <span class="sa-avatar">
                                            <?= e(mb_strtoupper(mb_substr($o['buyer_name'] ?? '?', 0, 1))) ?>
                                        </span>
                                        <div class="sa-user-info">
                                            <strong><a href="?buyer=<?= (int)$o['buyer_id'] ?>" class="text-reset" title="Pedidos deste comprador"><?= e($o['buyer_name']) ?></a></strong>
                                            <small>#<?= (int)$o['id'] ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="sa-date">
                                        <?= date('d/m/Y', strtotime($o['created_at'])) ?>
                                        <small><?= date('H:i', strtotime($o['created_at'])) ?></small>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <strong class="sa-price"><?= money($o['total']) ?></strong>
                                </td>
                                <td>
                                    <small class="d-block"><?= e(payment_method_label((string)$o['pay_method'])) ?></small>
                                    <small class="text-muted"><?= e(payment_status_label($o['payment_status'])) ?></small>
                                </td>
                                <td><?= order_status_badge($o['status']) ?></td>
                                <td class="text-end">
                                    <a href="<?= base_path('admin/pedido.php?id=' . (int)$o['id']) ?>" class="sa-btn sa-btn-ghost" title="Ver detalhe">
                                        <i class="bi bi-eye"></i> <span>Ver</span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3"><?= pagination($page, $perPage, $totalFiltered) ?></div>
            <?php endif; ?>

        </article>

    </section>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
