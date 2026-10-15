<?php
require_once __DIR__.'/../includes/permissions.php';
require_role('superadmin');
require_once __DIR__.'/_helpers.php';

$page_title = 'Pagamentos';
$pdo = db();

/* ============================================================
   AÇÃO: atualizar estado de pagamento (POST + CSRF)
   ============================================================ */
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    $id = safe_int($_POST['payment_id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    $s = $pdo->prepare('SELECT pay.*, o.order_number, o.status AS order_status FROM payments pay JOIN orders o ON o.id=pay.order_id WHERE pay.id=?');
    $s->execute([$id]);
    $pay = $s->fetch();

    if (!$pay || !in_array($status, ['paid', 'failed', 'refunded', 'pending'], true)) {
        flash('danger', 'Pagamento ou estado inválido.');
    } elseif ($pay['status'] === $status) {
        flash('info', 'O pagamento já está "' . payment_status_label($status) . '".');
    } elseif ($pay['order_status'] === 'cancelled' && in_array($status, ['paid', 'pending'], true)) {
        flash('warning', 'O pedido ' . $pay['order_number'] . ' está cancelado: o pagamento só pode ficar falhado ou reembolsado.');
    } else {
        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE payments SET status=?, paid_at=CASE WHEN ?="paid" THEN NOW() WHEN ? IN ("pending","failed") THEN NULL ELSE paid_at END WHERE id=?')
                ->execute([$status, $status, $status, $id]);
            sync_order_payment_status($pdo, (int)$pay['order_id']);
            notify_buyer_of_order((int)$pay['order_id'], 'Pagamento atualizado', 'O pagamento do pedido ' . $pay['order_number'] . ' está agora: ' . payment_status_label($status) . '.');
            audit(current_user_id(), 'update', 'payments', 'Pagamento #' . $id . ' (pedido ' . $pay['order_number'] . '): ' . $pay['status'] . ' → ' . $status);
            $pdo->commit();
            flash('success', 'Pagamento atualizado.');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('admin/pagamentos: ' . $ex->getMessage());
            flash('danger', 'Não foi possível atualizar o pagamento.');
        }
    }
    redirect(admin_self_url());
}

/* ============================================================
   FILTROS + PAGINAÇÃO
   ============================================================ */
$payStatuses = ['paid' => 'Pagos', 'pending' => 'Pendentes', 'failed' => 'Falhados', 'refunded' => 'Reembolsados'];
$methods = ['cash', 'mpesa', 'emola', 'bank', 'card'];
$fStatus = (string)($_GET['status'] ?? '');
if (!isset($payStatuses[$fStatus])) $fStatus = '';
$fMethod = (string)($_GET['method'] ?? '');
if (!in_array($fMethod, $methods, true)) $fMethod = '';
$q = trim((string)($_GET['q'] ?? ''));
$where = []; $params = [];
if ($fStatus !== '') { $where[] = 'pay.status=?'; $params[] = $fStatus; }
if ($fMethod !== '') { $where[] = 'pay.method=?'; $params[] = $fMethod; }
if ($q !== '') { $where[] = '(pay.reference LIKE ? OR o.order_number LIKE ? OR u.name LIKE ?)'; $like = '%' . $q . '%'; array_push($params, $like, $like, $like); }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$fromSql = '
    FROM payments pay
    JOIN orders o ON o.id = pay.order_id
    JOIN buyers b ON b.id = o.buyer_id
    JOIN users u ON u.id = b.user_id';

if (($_GET['export'] ?? '') === 'csv') {
    $s = $pdo->prepare('SELECT pay.id, pay.reference, o.order_number, u.name, pay.amount, pay.method, pay.status, pay.paid_at, pay.created_at' . $fromSql . $whereSql . ' ORDER BY pay.created_at DESC');
    $s->execute($params);
    $rows = [];
    foreach ($s as $r) {
        $rows[] = [$r['id'], $r['reference'], $r['order_number'], $r['name'], number_format((float)$r['amount'], 2, ',', ''), payment_method_label($r['method']), payment_status_label($r['status']),
            $r['paid_at'] ? date('d/m/Y H:i', strtotime($r['paid_at'])) : '', date('d/m/Y H:i', strtotime($r['created_at']))];
    }
    audit(user()['id'], 'export', 'payments', 'Exportou ' . count($rows) . ' pagamentos (CSV)');
    csv_download('pagamentos_' . date('Ymd_His') . '.csv', ['ID', 'Referência', 'Pedido', 'Comprador', 'Valor', 'Método', 'Estado', 'Pago em', 'Criado em'], $rows);
}

$perPage = 20;
[$page, $offset] = page_offset($perPage);
$c = $pdo->prepare('SELECT COUNT(*)' . $fromSql . $whereSql);
$c->execute($params);
$totalFiltered = (int)$c->fetchColumn();
$s = $pdo->prepare('SELECT pay.*, o.order_number, o.status AS order_status, o.discount, u.name AS buyer_name,
    (SELECT COUNT(*) FROM refunds r WHERE r.order_id = o.id) AS refunds_count,
    (SELECT COUNT(*) FROM refunds r WHERE r.order_id = o.id AND r.status = "requested") AS refunds_open' . $fromSql . $whereSql . ' ORDER BY pay.created_at DESC, pay.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$s->execute($params);
$payments = $s->fetchAll();

/* ============================================================
   ESTATÍSTICAS (todos os pagamentos)
   ============================================================ */
$counts = ['total' => 0, 'paid' => 0, 'pending' => 0, 'failed' => 0, 'refunded' => 0, 'revenue' => 0, 'pending_amount' => 0];
foreach ($pdo->query('SELECT status, COUNT(*) n, COALESCE(SUM(amount),0) a FROM payments GROUP BY status') as $row) {
    $counts['total'] += (int)$row['n'];
    if (isset($counts[$row['status']])) $counts[$row['status']] = (int)$row['n'];
    if ($row['status'] === 'paid') $counts['revenue'] = (float)$row['a'];
    if ($row['status'] === 'pending') $counts['pending_amount'] = (float)$row['a'];
}
$methodCount = [];
foreach ($pdo->query('SELECT method, COUNT(*) n FROM payments GROUP BY method ORDER BY n DESC') as $row) $methodCount[payment_method_label($row['method'])] = (int)$row['n'];
$refundsOpen = (int)$pdo->query('SELECT COUNT(*) FROM refunds WHERE status = "requested"')->fetchColumn();
$hasFilters = $fStatus !== '' || $fMethod !== '' || $q !== '';
$exportQuery = http_build_query(array_filter(['status' => $fStatus ?: null, 'method' => $fMethod ?: null, 'q' => $q !== '' ? $q : null, 'export' => 'csv']));

include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <!-- ================= HERO ================= -->
        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Financeiro · Pagamentos
                </span>
                <h1 class="sa-hero-title">Gestão de <em>pagamentos</em></h1>
                <p class="sa-hero-sub">
                    <?php if ($counts['pending'] > 0): ?>
                        <strong><?= $counts['pending'] ?></strong> pagamentos pendentes · <strong><?= money($counts['pending_amount']) ?></strong> por conciliar.
                    <?php else: ?>
                        Todos os pagamentos estão conciliados.
                    <?php endif; ?>
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('admin/dashboard.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <a href="<?= base_path('admin/reembolsos.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-counterclockwise"></i> Reembolsos
                    <?php if ($refundsOpen > 0): ?><span class="sa-pill"><?= $refundsOpen ?></span><?php endif; ?>
                </a>
                <a href="<?= base_path('admin/pedidos.php') ?>" class="btn btn-primary">
                    <i class="bi bi-receipt"></i> Ver pedidos
                </a>
            </div>
        </header>

        <!-- ================= AVISO ================= -->
        <div class="sa-notice">
            <span class="sa-notice-icon"><i class="bi bi-shield-lock"></i></span>
            <div class="sa-notice-body">
                <strong>Modo de reconciliação manual</strong>
                <p>M-Pesa/e-Mola devem ser ativados apenas após configurar credenciais reais e o segredo de webhook em <code>app_settings</code>.</p>
            </div>
        </div>

        <!-- ================= KPIs ================= -->
        <div class="sa-kpi-grid sa-kpi-grid-4">

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-credit-card-2-front"></i></span>
                </header>
                <span class="sa-kpi-label">Total</span>
                <strong class="sa-kpi-value"><?= $counts['total'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-list-check"></i> transações</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-cash-stack"></i></span>
                </header>
                <span class="sa-kpi-label">Receita recebida</span>
                <strong class="sa-kpi-value"><?= money($counts['revenue']) ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-check2-circle"></i> <?= $counts['paid'] ?> pagos</span>
                </footer>
            </article>

            <article class="sa-kpi <?= $counts['pending'] > 0 ? 'sa-kpi-warn' : '' ?>">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-warning"><i class="bi bi-hourglass-split"></i></span>
                    <?php if ($counts['pending'] > 0): ?>
                        <span class="sa-kpi-badge sa-badge-warn">por conciliar</span>
                    <?php endif; ?>
                </header>
                <span class="sa-kpi-label">Pendentes</span>
                <strong class="sa-kpi-value"><?= money($counts['pending_amount']) ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-clock-history"></i> <?= $counts['pending'] ?> transações</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-danger"><i class="bi bi-x-octagon"></i></span>
                </header>
                <span class="sa-kpi-label">Falhados / Reembolsados</span>
                <strong class="sa-kpi-value"><?= $counts['failed'] + $counts['refunded'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-exclamation-triangle"></i> requerem atenção</span>
                </footer>
            </article>

        </div>

        <!-- ================= MÉTODOS ================= -->
        <?php if (!empty($methodCount)): ?>
        <div class="sa-cat-chips">
            <span class="sa-cat-label"><i class="bi bi-wallet2"></i> Métodos usados:</span>
            <?php $i = 0; foreach ($methodCount as $m => $n): if ($i++ >= 6) break; ?>
                <span class="sa-cat-chip">
                    <?= e($m) ?>
                    <strong><?= (int)$n ?></strong>
                </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ================= TABELA ================= -->
        <article class="content-card sa-card">

            <div class="card-heading sa-card-head">
                <div>
                    <h4>Lista de pagamentos</h4>
                    <p class="sa-card-sub"><?= $totalFiltered ?> registos</p>
                </div>

                <div class="sa-toolbar">
                    <form method="get" class="sa-search" role="search">
                        <i class="bi bi-search"></i>
                        <?php if ($fStatus !== ''): ?><input type="hidden" name="status" value="<?= e($fStatus) ?>"><?php endif; ?>
                        <input type="text" name="q" value="<?= e($q) ?>" aria-label="Procurar pagamento" placeholder="Procurar por referência, pedido ou comprador…">
                        <select name="method" class="form-select form-select-sm w-auto ms-2" aria-label="Método" data-autosubmit>
                            <option value="">Todos os métodos</option>
                            <?php foreach ($methods as $m): ?>
                                <option value="<?= $m ?>" <?= $fMethod === $m ? 'selected' : '' ?>><?= e(payment_method_label($m)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>

                    <div class="sa-filters">
                        <?php foreach (['' => 'Todos'] + $payStatuses as $key => $label): ?>
                            <a class="sa-filter text-decoration-none<?= $fStatus === $key ? ' active' : '' ?>" href="?<?= e(http_build_query(array_filter(['status' => $key ?: null, 'method' => $fMethod ?: null, 'q' => $q !== '' ? $q : null]))) ?>"><?= $label ?></a>
                        <?php endforeach; ?>
                    </div>
                    <a href="?<?= e($exportQuery) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a>
                </div>
            </div>

            <?php if (empty($payments)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-credit-card" style="font-size:2.5rem;"></i>
                    <p class="mt-3 mb-0"><?= $hasFilters ? 'Nenhum resultado encontrado.' : 'Ainda não existem pagamentos.' ?></p>
                </div>
            <?php else: ?>

                <!-- Scroll horizontal garantido em ecrãs pequenos -->
                <div class="sa-scroll-x">
                    <table class="table align-middle sa-table sa-pay-table" id="payTable">
                        <thead>
                            <tr>
                                <th>Referência</th>
                                <th>Pedido</th>
                                <th>Comprador</th>
                                <th class="text-end">Valor</th>
                                <th>Método</th>
                                <th>Estado</th>
                                <th class="text-end sa-pay-col-action">Alterar estado</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($payments as $p):
                            $st = $p['status'] ?? 'pending';
                            $badgeMap = [
                                'paid'     => ['success', 'check-circle-fill',      'Pago'],
                                'pending'  => ['warning', 'hourglass-split',        'Pendente'],
                                'failed'   => ['danger',  'x-octagon-fill',         'Falhou'],
                                'refunded' => ['info',    'arrow-counterclockwise','Reembolsado'],
                            ];
                            [$badgeCls, $badgeIcon, $badgeLabel] =
                                $badgeMap[$st] ?? ['secondary', 'circle', ucfirst($st)];

                        ?>
                            <tr data-status="<?= e($st) ?>">
                                <td>
                                    <span class="sa-ref">
                                        <i class="bi bi-upc-scan"></i>
                                        <?= e($p['reference']) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?= base_path('admin/pedido.php?id='.(int)$p['order_id']) ?>"
                                       class="sa-order-link">
                                        <i class="bi bi-receipt"></i>
                                        <?= e($p['order_number']) ?>
                                    </a>
                                </td>
                                <td>
                                    <div class="sa-user-cell">
                                        <span class="sa-avatar">
                                            <?= e(mb_strtoupper(mb_substr($p['buyer_name'] ?? '?', 0, 1))) ?>
                                        </span>
                                        <span><?= e($p['buyer_name']) ?></span>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <strong class="sa-price"><?= money($p['amount']) ?></strong>
                                    <?php if ((float)$p['discount'] > 0): ?><br><small class="text-success" title="Desconto de cupão já deduzido">desc. −<?= money($p['discount']) ?></small><?php endif; ?>
                                    <?php if ((int)$p['refunds_count'] > 0): ?><br><a href="<?= base_path('admin/reembolsos.php?q=' . urlencode($p['order_number'])) ?>" class="small<?= (int)$p['refunds_open'] > 0 ? ' text-danger' : '' ?>"><i class="bi bi-arrow-counterclockwise"></i> <?= (int)$p['refunds_count'] ?> reembolso(s)<?= (int)$p['refunds_open'] > 0 ? ' · ' . (int)$p['refunds_open'] . ' por tratar' : '' ?></a><?php endif; ?>
                                </td>
                                <td>
                                    <span class="sa-method">
                                        <i class="bi bi-credit-card-2-back"></i>
                                        <?= e(payment_method_label((string)$p['method'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="sa-badge sa-badge-<?= $badgeCls ?>">
                                        <i class="bi bi-<?= $badgeIcon ?>"></i>
                                        <?= $badgeLabel ?>
                                    </span>
                                </td>
                                <td class="text-end sa-pay-col-action">
                                    <form method="post" class="sa-pay-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">

                                        <div class="sa-pay-control">
                                            <select name="status"
                                                    class="sa-pay-select"
                                                    aria-label="Alterar estado do pagamento">
                                                <option value="pending"  <?= $st === 'pending'  ? 'selected' : '' ?>>Pendente</option>
                                                <option value="paid"     <?= $st === 'paid'     ? 'selected' : '' ?>>Pago</option>
                                                <option value="failed"   <?= $st === 'failed'   ? 'selected' : '' ?>>Falhou</option>
                                                <option value="refunded" <?= $st === 'refunded' ? 'selected' : '' ?>>Reembolsado</option>
                                            </select>
                                            <i class="bi bi-chevron-down sa-pay-chevron"></i>
                                        </div>

                                        <button type="submit" class="sa-pay-save" title="Guardar alteração">
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

                <div class="mt-3"><?= pagination($page, $perPage, $totalFiltered) ?></div>
            <?php endif; ?>

        </article>

    </section>
</div>

<script>
(function () {
    /* ---------- Filtro de método: submete ao mudar ---------- */
    document.querySelectorAll('[data-autosubmit]').forEach(function (sel) {
        sel.addEventListener('change', function () { sel.form.submit(); });
    });

    /* ---------- Marca form como "alterado" ---------- */
    document.querySelectorAll('.sa-pay-select').forEach(function (sel) {
        var form = sel.closest('form');
        var original = sel.value;

        sel.addEventListener('change', function () {
            var changed = sel.value !== original;
            form.classList.toggle('is-dirty', changed);
            var btn = form.querySelector('.sa-pay-save');
            if (btn) btn.classList.toggle('pulse', changed);
        });
    });
})();
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>