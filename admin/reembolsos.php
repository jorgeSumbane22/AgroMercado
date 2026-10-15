<?php
require_once __DIR__.'/../includes/permissions.php';
require_role('superadmin');
require_once __DIR__.'/_helpers.php';

$page_title = 'Reembolsos';
$pdo = db();

/* ============================================================
   AÇÕES (POST + CSRF): processar / rejeitar reembolso
   ============================================================ */
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    $rid = safe_int($_POST['refund_id'] ?? 0);
    $me = (int)user()['id'];
    try {
        $pdo->beginTransaction();
        $s = $pdo->prepare('SELECT r.*, o.order_number, o.status AS order_status FROM refunds r JOIN orders o ON o.id = r.order_id WHERE r.id = ? FOR UPDATE');
        $s->execute([$rid]);
        $r = $s->fetch();
        if (!$r) throw new DomainException('Reembolso não encontrado.');
        if ($r['status'] !== 'requested') throw new DomainException('Este reembolso já foi tratado.');
        $orderId = (int)$r['order_id'];
        if ($action === 'process') {
            $ref = mb_substr(trim((string)($_POST['reference'] ?? '')), 0, 100);
            if ($ref === '') throw new DomainException('Indique a referência da devolução.');
            $pdo->prepare('UPDATE refunds SET status = "processed", reference = ?, processed_by = ?, processed_at = NOW() WHERE id = ?')->execute([$ref, $me, $rid]);
            // Reembolso total (pedido cancelado ou soma processada ≥ valor pago): o pagamento passa a "refunded".
            $pay = order_payment_locked($pdo, $orderId);
            $s = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM refunds WHERE order_id = ? AND status = "processed"');
            $s->execute([$orderId]);
            $processed = (float)$s->fetchColumn();
            $full = $pay && ($r['order_status'] === 'cancelled' || $processed + 0.005 >= (float)$pay['amount']);
            if ($full) { $pdo->prepare('UPDATE payments SET status = "refunded" WHERE id = ?')->execute([$pay['id']]); sync_order_payment_status($pdo, $orderId); }
            notify_buyer_of_order($orderId, 'Reembolso processado', 'O reembolso de ' . money($r['amount']) . ' do pedido ' . $r['order_number'] . ' foi processado (ref. ' . $ref . ').');
            audit($me, 'update', 'refunds', 'Reembolso #' . $rid . ' (pedido ' . $r['order_number'] . ', ' . number_format((float)$r['amount'], 2, '.', '') . ' MT) processado, ref. ' . $ref . ($full ? '; pagamento marcado como reembolsado' : ''));
            $msg = 'Reembolso #' . $rid . ' processado.' . ($full ? ' O pagamento ficou como reembolsado.' : '');
        } elseif ($action === 'reject') {
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === '') throw new DomainException('Indique o motivo da rejeição.');
            $newReason = mb_substr(trim(($r['reason'] ? $r['reason'] . ' | ' : '') . 'Rejeitado: ' . $reason), 0, 255);
            $pdo->prepare('UPDATE refunds SET status = "rejected", reason = ?, processed_by = ?, processed_at = NOW() WHERE id = ?')->execute([$newReason, $me, $rid]);
            notify_buyer_of_order($orderId, 'Reembolso rejeitado', 'O pedido de reembolso de ' . money($r['amount']) . ' do pedido ' . $r['order_number'] . ' foi rejeitado. Motivo: ' . $reason);
            audit($me, 'update', 'refunds', 'Reembolso #' . $rid . ' (pedido ' . $r['order_number'] . ') rejeitado. Motivo: ' . $reason);
            $msg = 'Reembolso #' . $rid . ' rejeitado.';
        } else {
            throw new DomainException('Ação inválida.');
        }
        $pdo->commit();
        flash('success', $msg);
    } catch (DomainException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $ex->getMessage());
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('admin/reembolsos: ' . $ex->getMessage());
        flash('danger', 'Não foi possível atualizar o reembolso.');
    }
    redirect(admin_self_url());
}

/* ============================================================
   FILTROS + PAGINAÇÃO + CSV
   ============================================================ */
$statuses = ['requested' => 'Pedidos', 'processed' => 'Processados', 'rejected' => 'Rejeitados'];
$fStatus = (string)($_GET['status'] ?? '');
if (!isset($statuses[$fStatus])) $fStatus = '';
$q = trim((string)($_GET['q'] ?? ''));
$where = []; $params = [];
if ($fStatus !== '') { $where[] = 'r.status = ?'; $params[] = $fStatus; }
if ($q !== '') { $where[] = '(o.order_number LIKE ? OR u.name LIKE ? OR r.reference LIKE ?)'; $like = '%' . $q . '%'; array_push($params, $like, $like, $like); }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$fromSql = ' FROM refunds r JOIN orders o ON o.id = r.order_id JOIN buyers b ON b.id = o.buyer_id JOIN users u ON u.id = b.user_id LEFT JOIN payments pay ON pay.id = r.payment_id LEFT JOIN users pu ON pu.id = r.processed_by';

if (($_GET['export'] ?? '') === 'csv') {
    $s = $pdo->prepare('SELECT r.id, o.order_number, u.name, r.amount, pay.method, r.status, r.reason, r.reference, r.created_at, r.processed_at, pu.name AS processed_name' . $fromSql . $whereSql . ' ORDER BY r.id DESC');
    $s->execute($params);
    $rows = [];
    foreach ($s as $r) $rows[] = [$r['id'], $r['order_number'], $r['name'], number_format((float)$r['amount'], 2, ',', ''), payment_method_label((string)$r['method']), refund_status_label($r['status']), $r['reason'], $r['reference'], date('d/m/Y H:i', strtotime($r['created_at'])), $r['processed_at'] ? date('d/m/Y H:i', strtotime($r['processed_at'])) : '', $r['processed_name']];
    audit(user()['id'], 'export', 'refunds', 'Exportou ' . count($rows) . ' reembolsos (CSV)');
    csv_download('reembolsos_' . date('Ymd_His') . '.csv', ['ID', 'Pedido', 'Comprador', 'Valor', 'Método', 'Estado', 'Motivo', 'Referência', 'Criado em', 'Tratado em', 'Tratado por'], $rows);
}

$perPage = 20;
[$page, $offset] = page_offset($perPage);
$c = $pdo->prepare('SELECT COUNT(*)' . $fromSql . $whereSql);
$c->execute($params);
$totalFiltered = (int)$c->fetchColumn();
$s = $pdo->prepare('SELECT r.*, o.order_number, o.status AS order_status, u.name AS buyer_name, pay.method AS pay_method, pay.amount AS pay_amount, pay.status AS pay_status, pu.name AS processed_name' . $fromSql . $whereSql . ' ORDER BY (r.status = "requested") DESC, r.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$s->execute($params);
$refunds = $s->fetchAll();

$sum = ['requested' => [0, 0.0], 'processed' => [0, 0.0], 'rejected' => [0, 0.0]];
foreach ($pdo->query('SELECT status, COUNT(*) n, COALESCE(SUM(amount),0) a FROM refunds GROUP BY status') as $row) $sum[$row['status']] = [(int)$row['n'], (float)$row['a']];
$baseQuery = array_filter(['status' => $fStatus ?: null, 'q' => $q !== '' ? $q : null]);

include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow"><span class="sa-dot"></span> Financeiro · Reembolsos</span>
                <h1 class="sa-hero-title">Gestão de <em>reembolsos</em></h1>
                <p class="sa-hero-sub">Criados automaticamente quando um pedido pago é cancelado (total ou parcialmente) ou manualmente na página do pedido. Itens abrangidos por reembolsos não entram nos repasses.</p>
            </div>
            <div class="sa-hero-actions">
                <a href="<?= base_path('admin/pagamentos.php') ?>" class="btn btn-outline-secondary"><i class="bi bi-credit-card"></i> Pagamentos</a>
                <a href="<?= base_path('admin/repasses.php') ?>" class="btn btn-outline-secondary"><i class="bi bi-cash-stack"></i> Repasses</a>
            </div>
        </header>

        <div class="sa-kpi-grid sa-kpi-grid-3">
            <article class="sa-kpi <?= $sum['requested'][0] > 0 ? 'sa-kpi-warn' : '' ?>">
                <header class="sa-kpi-head"><span class="sa-kpi-icon sa-icon-warning"><i class="bi bi-hourglass-split"></i></span></header>
                <span class="sa-kpi-label">Por tratar</span>
                <strong class="sa-kpi-value"><?= money($sum['requested'][1]) ?></strong>
                <footer class="sa-kpi-foot"><span class="sa-kpi-sub"><i class="bi bi-list-check"></i> <?= $sum['requested'][0] ?> pedido(s)</span></footer>
            </article>
            <article class="sa-kpi">
                <header class="sa-kpi-head"><span class="sa-kpi-icon sa-icon-success"><i class="bi bi-check2-circle"></i></span></header>
                <span class="sa-kpi-label">Processados</span>
                <strong class="sa-kpi-value"><?= money($sum['processed'][1]) ?></strong>
                <footer class="sa-kpi-foot"><span class="sa-kpi-sub"><i class="bi bi-arrow-counterclockwise"></i> <?= $sum['processed'][0] ?> reembolso(s)</span></footer>
            </article>
            <article class="sa-kpi">
                <header class="sa-kpi-head"><span class="sa-kpi-icon sa-icon-danger"><i class="bi bi-x-octagon"></i></span></header>
                <span class="sa-kpi-label">Rejeitados</span>
                <strong class="sa-kpi-value"><?= $sum['rejected'][0] ?></strong>
                <footer class="sa-kpi-foot"><span class="sa-kpi-sub"><i class="bi bi-cash"></i> <?= money($sum['rejected'][1]) ?></span></footer>
            </article>
        </div>

        <article class="content-card sa-card">
            <div class="card-heading sa-card-head">
                <div>
                    <h4>Reembolsos</h4>
                    <p class="sa-card-sub"><?= $totalFiltered ?> registo(s)</p>
                </div>
                <div class="sa-toolbar">
                    <form method="get" class="sa-search" role="search">
                        <i class="bi bi-search"></i>
                        <?php if ($fStatus !== ''): ?><input type="hidden" name="status" value="<?= e($fStatus) ?>"><?php endif; ?>
                        <input type="text" name="q" value="<?= e($q) ?>" aria-label="Procurar reembolso" placeholder="Pedido, comprador ou referência…">
                    </form>
                    <div class="sa-filters">
                        <?php foreach (['' => 'Todos'] + $statuses as $key => $label): ?>
                            <a class="sa-filter text-decoration-none<?= $fStatus === $key ? ' active' : '' ?>" href="?<?= e(http_build_query(['status' => $key ?: null] + $baseQuery)) ?>"><?= e($label) ?></a>
                        <?php endforeach; ?>
                    </div>
                    <a href="?<?= e(http_build_query($baseQuery + ['export' => 'csv'])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a>
                </div>
            </div>

            <?php if (!$refunds): ?>
                <div class="empty-state py-5"><i class="bi bi-arrow-counterclockwise" style="font-size:2.5rem;"></i><p class="mt-3 mb-0">Nenhum reembolso encontrado.</p></div>
            <?php else: ?>
                <div class="sa-scroll-x">
                    <table class="table align-middle sa-table">
                        <thead><tr><th>#</th><th>Pedido</th><th>Comprador</th><th class="text-end">Valor</th><th>Pagamento</th><th>Motivo</th><th>Estado</th><th class="text-end">Ações</th></tr></thead>
                        <tbody>
                        <?php foreach ($refunds as $r): ?>
                            <tr>
                                <td>#<?= (int)$r['id'] ?><br><small class="text-muted"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></small></td>
                                <td><a href="<?= base_path('admin/pedido.php?id=' . (int)$r['order_id']) ?>"><?= e($r['order_number']) ?></a><br><?= order_status_badge($r['order_status']) ?></td>
                                <td><?= e($r['buyer_name']) ?></td>
                                <td class="text-end"><strong><?= money($r['amount']) ?></strong><?php if ($r['pay_amount'] !== null): ?><br><small class="text-muted">de <?= money($r['pay_amount']) ?></small><?php endif; ?></td>
                                <td class="small"><?= e(payment_method_label((string)$r['pay_method'])) ?><br><span class="text-muted"><?= e(payment_status_label((string)$r['pay_status'])) ?></span></td>
                                <td class="small"><?= e($r['reason'] ?: '—') ?></td>
                                <td><span class="badge text-bg-<?= e(refund_status_class($r['status'])) ?>"><?= e(refund_status_label($r['status'])) ?></span><?php if ($r['status'] !== 'requested'): ?><br><small class="text-muted"><?= $r['reference'] ? 'Ref. ' . e($r['reference']) . '<br>' : '' ?><?= $r['processed_at'] ? date('d/m/Y H:i', strtotime($r['processed_at'])) : '' ?><?= $r['processed_name'] ? ' · ' . e($r['processed_name']) : '' ?></small><?php endif; ?></td>
                                <td class="text-end">
                                    <?php if ($r['status'] === 'requested'): ?>
                                        <form method="post" class="d-flex gap-1 justify-content-end mb-1" data-confirm="Marcar este reembolso como processado (dinheiro devolvido)?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="process"><input type="hidden" name="refund_id" value="<?= (int)$r['id'] ?>">
                                            <input name="reference" class="form-control form-control-sm w-auto" maxlength="100" required placeholder="Referência" aria-label="Referência da devolução">
                                            <button class="btn btn-sm btn-success" title="Marcar como processado"><i class="bi bi-check2-circle"></i> Processado</button>
                                        </form>
                                        <form method="post" class="d-flex gap-1 justify-content-end" data-confirm="Rejeitar este pedido de reembolso?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="reject"><input type="hidden" name="refund_id" value="<?= (int)$r['id'] ?>">
                                            <input name="reason" class="form-control form-control-sm w-auto" maxlength="200" required placeholder="Motivo" aria-label="Motivo da rejeição">
                                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> Rejeitar</button>
                                        </form>
                                    <?php else: ?>—<?php endif; ?>
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
