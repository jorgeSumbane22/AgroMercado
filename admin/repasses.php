<?php
require_once __DIR__.'/../includes/permissions.php';
require_role('superadmin');
require_once __DIR__.'/_helpers.php';

$page_title = 'Repasses';
$pdo = db();
$payMethods = ['bank' => 'Transferência', 'mpesa' => 'M-Pesa', 'emola' => 'e-Mola', 'cash' => 'Dinheiro'];

/* ============================================================
   AÇÕES (POST + CSRF): gerar / marcar pago / cancelar repasse
   ============================================================ */
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    $me = (int)user()['id'];
    try {
        if ($action === 'generate') {
            $fid = safe_int($_POST['farmer_id'] ?? 0);
            $s = $pdo->prepare('SELECT f.id, f.user_id, u.name FROM farmers f JOIN users u ON u.id = f.user_id WHERE f.id = ?');
            $s->execute([$fid]);
            $farmer = $s->fetch();
            if (!$farmer) throw new DomainException('Agricultor não encontrado.');
            [$pid, $amount, $n] = create_farmer_payout($fid, $me, mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 500));
            notify((int)$farmer['user_id'], 'Repasse em processamento', 'Foi gerado o repasse #' . $pid . ' de ' . money($amount) . ' referente a ' . $n . ' item(ns) entregues.');
            audit($me, 'create', 'payouts', 'Repasse #' . $pid . ' gerado para ' . $farmer['name'] . ': ' . number_format($amount, 2, '.', '') . ' MT (' . $n . ' itens)');
            flash('success', 'Repasse #' . $pid . ' de ' . money($amount) . ' gerado para ' . $farmer['name'] . '.');
        } elseif ($action === 'pay' || $action === 'cancel') {
            $pid = safe_int($_POST['payout_id'] ?? 0);
            $pdo->beginTransaction();
            $s = $pdo->prepare('SELECT fp.*, f.user_id AS farmer_user_id, u.name AS farmer_name FROM farmer_payouts fp JOIN farmers f ON f.id = fp.farmer_id JOIN users u ON u.id = f.user_id WHERE fp.id = ? FOR UPDATE');
            $s->execute([$pid]);
            $p = $s->fetch();
            if (!$p) throw new DomainException('Repasse não encontrado.');
            if ($p['status'] !== 'pending') throw new DomainException('Só repasses em processamento podem ser alterados.');
            if ($action === 'pay') {
                $method = (string)($_POST['method'] ?? '');
                $ref = mb_substr(trim((string)($_POST['reference'] ?? '')), 0, 100);
                if (!isset($payMethods[$method])) throw new DomainException('Selecione o método de pagamento.');
                if ($ref === '') throw new DomainException('Indique a referência do pagamento.');
                $pdo->prepare('UPDATE farmer_payouts SET status = "paid", method = ?, reference = ?, paid_at = NOW() WHERE id = ?')->execute([$method, $ref, $pid]);
                notify((int)$p['farmer_user_id'], 'Repasse pago', 'O repasse #' . $pid . ' de ' . money($p['amount']) . ' foi pago por ' . $payMethods[$method] . ' (ref. ' . $ref . ').');
                audit($me, 'update', 'payouts', 'Repasse #' . $pid . ' (' . $p['farmer_name'] . ', ' . number_format((float)$p['amount'], 2, '.', '') . ' MT) marcado como pago: ' . $method . ' ref. ' . $ref);
                $msg = 'Repasse #' . $pid . ' marcado como pago.';
            } else {
                $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 255);
                $pdo->prepare('UPDATE order_items SET payout_id = NULL WHERE payout_id = ?')->execute([$pid]);
                $pdo->prepare('UPDATE farmer_payouts SET status = "cancelled", notes = CONCAT_WS("\n", notes, ?) WHERE id = ?')->execute(['Cancelado: ' . ($reason !== '' ? $reason : 'sem motivo'), $pid]);
                notify((int)$p['farmer_user_id'], 'Repasse cancelado', 'O repasse #' . $pid . ' foi cancelado' . ($reason !== '' ? ': ' . $reason : '') . '. Os itens voltam ao saldo a receber.');
                audit($me, 'cancel', 'payouts', 'Repasse #' . $pid . ' (' . $p['farmer_name'] . ') cancelado; itens libertados.' . ($reason !== '' ? ' Motivo: ' . $reason : ''));
                $msg = 'Repasse #' . $pid . ' cancelado; os itens voltaram ao saldo elegível.';
            }
            $pdo->commit();
            flash('success', $msg);
        } else {
            throw new DomainException('Ação inválida.');
        }
    } catch (DomainException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $ex->getMessage());
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('admin/repasses: ' . $ex->getMessage());
        flash('danger', 'Não foi possível concluir a operação.');
    }
    redirect(admin_self_url());
}

/* ============================================================
   SALDOS ELEGÍVEIS POR AGRICULTOR
   ============================================================ */
$eligible = $pdo->query('SELECT oi.farmer_id, u.name AS farmer_name, COUNT(*) AS n, SUM(oi.subtotal) AS gross, SUM(oi.commission_amount) AS commission, SUM(oi.farmer_amount) AS net, MIN(o.created_at) AS oldest'
    . payout_eligible_sql('JOIN farmers f ON f.id = oi.farmer_id JOIN users u ON u.id = f.user_id')
    . ' GROUP BY oi.farmer_id, u.name ORDER BY net DESC')->fetchAll();
$eligibleTotal = array_sum(array_map(fn($r) => (float)$r['net'], $eligible));

/* ============================================================
   LISTA DE REPASSES: filtros + paginação + CSV
   ============================================================ */
$statuses = ['pending' => 'Em processamento', 'paid' => 'Pagos', 'cancelled' => 'Cancelados'];
$fStatus = (string)($_GET['status'] ?? '');
if (!isset($statuses[$fStatus])) $fStatus = '';
$fFarmer = safe_int($_GET['farmer'] ?? 0);
$fId = safe_int($_GET['id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$where = []; $params = [];
if ($fStatus !== '') { $where[] = 'fp.status = ?'; $params[] = $fStatus; }
if ($fFarmer > 0) { $where[] = 'fp.farmer_id = ?'; $params[] = $fFarmer; }
if ($fId > 0) { $where[] = 'fp.id = ?'; $params[] = $fId; }
if ($q !== '') { $where[] = '(u.name LIKE ? OR fp.reference LIKE ?)'; $like = '%' . $q . '%'; array_push($params, $like, $like); }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$fromSql = ' FROM farmer_payouts fp JOIN farmers f ON f.id = fp.farmer_id JOIN users u ON u.id = f.user_id LEFT JOIN users cu ON cu.id = fp.created_by';

if (($_GET['export'] ?? '') === 'csv') {
    $s = $pdo->prepare('SELECT fp.id, u.name, fp.amount, fp.items_count, fp.status, fp.method, fp.reference, fp.created_at, fp.paid_at, cu.name AS creator' . $fromSql . $whereSql . ' ORDER BY fp.id DESC');
    $s->execute($params);
    $rows = [];
    foreach ($s as $r) $rows[] = [$r['id'], $r['name'], number_format((float)$r['amount'], 2, ',', ''), $r['items_count'], payout_status_label($r['status']), $r['method'] ? ($payMethods[$r['method']] ?? $r['method']) : '', $r['reference'], date('d/m/Y H:i', strtotime($r['created_at'])), $r['paid_at'] ? date('d/m/Y H:i', strtotime($r['paid_at'])) : '', $r['creator']];
    audit(user()['id'], 'export', 'payouts', 'Exportou ' . count($rows) . ' repasses (CSV)');
    csv_download('repasses_' . date('Ymd_His') . '.csv', ['ID', 'Agricultor', 'Valor', 'Itens', 'Estado', 'Método', 'Referência', 'Criado em', 'Pago em', 'Criado por'], $rows);
}

$perPage = 20;
[$page, $offset] = page_offset($perPage);
$c = $pdo->prepare('SELECT COUNT(*)' . $fromSql . $whereSql);
$c->execute($params);
$totalFiltered = (int)$c->fetchColumn();
$s = $pdo->prepare('SELECT fp.*, u.name AS farmer_name, cu.name AS creator_name' . $fromSql . $whereSql . ' ORDER BY fp.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$s->execute($params);
$payouts = $s->fetchAll();

$sum = ['pending' => 0.0, 'paid' => 0.0, 'pending_n' => 0];
foreach ($pdo->query('SELECT status, COUNT(*) n, COALESCE(SUM(amount),0) a FROM farmer_payouts GROUP BY status') as $r) {
    if ($r['status'] === 'pending') { $sum['pending'] = (float)$r['a']; $sum['pending_n'] = (int)$r['n']; }
    if ($r['status'] === 'paid') $sum['paid'] = (float)$r['a'];
}
$farmersList = $pdo->query('SELECT f.id, u.name FROM farmers f JOIN users u ON u.id = f.user_id ORDER BY u.name')->fetchAll(PDO::FETCH_KEY_PAIR);

/* Detalhe de um repasse (?id=) */
$detailItems = [];
if ($fId > 0) {
    $s = $pdo->prepare('SELECT oi.*, o.order_number FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.payout_id = ? ORDER BY oi.id');
    $s->execute([$fId]);
    $detailItems = $s->fetchAll();
}
$baseQuery = array_filter(['status' => $fStatus ?: null, 'farmer' => $fFarmer ?: null, 'q' => $q !== '' ? $q : null]);

include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow"><span class="sa-dot"></span> Financeiro · Repasses</span>
                <h1 class="sa-hero-title">Repasses aos <em>agricultores</em></h1>
                <p class="sa-hero-sub">Comissão atual: <strong><?= e(rtrim(rtrim(number_format(commission_percent(), 2, ',', ''), '0'), ',')) ?>%</strong>. A taxa de entrega e a comissão ficam com a plataforma; o agricultor recebe o valor líquido dos itens entregues.</p>
            </div>
            <div class="sa-hero-actions">
                <a href="<?= base_path('admin/pagamentos.php') ?>" class="btn btn-outline-secondary"><i class="bi bi-credit-card"></i> Pagamentos</a>
                <a href="<?= base_path('admin/reembolsos.php') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i> Reembolsos</a>
            </div>
        </header>

        <div class="sa-kpi-grid sa-kpi-grid-3">
            <article class="sa-kpi">
                <header class="sa-kpi-head"><span class="sa-kpi-icon sa-icon-info"><i class="bi bi-wallet2"></i></span></header>
                <span class="sa-kpi-label">Saldo elegível</span>
                <strong class="sa-kpi-value"><?= money($eligibleTotal) ?></strong>
                <footer class="sa-kpi-foot"><span class="sa-kpi-sub"><i class="bi bi-people"></i> <?= count($eligible) ?> agricultor(es)</span></footer>
            </article>
            <article class="sa-kpi <?= $sum['pending_n'] > 0 ? 'sa-kpi-warn' : '' ?>">
                <header class="sa-kpi-head"><span class="sa-kpi-icon sa-icon-warning"><i class="bi bi-hourglass-split"></i></span></header>
                <span class="sa-kpi-label">Em processamento</span>
                <strong class="sa-kpi-value"><?= money($sum['pending']) ?></strong>
                <footer class="sa-kpi-foot"><span class="sa-kpi-sub"><i class="bi bi-list-check"></i> <?= $sum['pending_n'] ?> repasse(s)</span></footer>
            </article>
            <article class="sa-kpi">
                <header class="sa-kpi-head"><span class="sa-kpi-icon sa-icon-success"><i class="bi bi-check2-circle"></i></span></header>
                <span class="sa-kpi-label">Total pago</span>
                <strong class="sa-kpi-value"><?= money($sum['paid']) ?></strong>
                <footer class="sa-kpi-foot"><span class="sa-kpi-sub"><i class="bi bi-cash-stack"></i> desde o início</span></footer>
            </article>
        </div>

        <!-- Saldos elegíveis -->
        <article class="content-card sa-card mb-4">
            <div class="card-heading sa-card-head">
                <div>
                    <h4>Saldos elegíveis por agricultor</h4>
                    <p class="sa-card-sub">Itens entregues, com pagamento pago (ou em dinheiro na entrega), sem repasse e sem reembolso que os abranja.</p>
                </div>
            </div>
            <?php if (!$eligible): ?>
                <p class="text-muted mb-0">Não há saldos elegíveis neste momento.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle sa-table">
                        <thead><tr><th>Agricultor</th><th class="text-end">Itens</th><th class="text-end">Bruto</th><th class="text-end">Comissão</th><th class="text-end">A repassar</th><th>Mais antigo</th><th class="text-end">Ação</th></tr></thead>
                        <tbody>
                        <?php foreach ($eligible as $r): ?>
                            <tr>
                                <td><a href="?<?= e(http_build_query(['farmer' => (int)$r['farmer_id']])) ?>" class="text-reset"><?= e($r['farmer_name']) ?></a></td>
                                <td class="text-end"><?= (int)$r['n'] ?></td>
                                <td class="text-end"><?= money($r['gross']) ?></td>
                                <td class="text-end text-muted"><?= money($r['commission']) ?></td>
                                <td class="text-end"><strong><?= money($r['net']) ?></strong></td>
                                <td><?= date('d/m/Y', strtotime($r['oldest'])) ?></td>
                                <td class="text-end"><?= post_button(base_path('admin/repasses.php'), ['action' => 'generate', 'farmer_id' => (int)$r['farmer_id']], '<i class="bi bi-plus-circle"></i> Gerar repasse', 'btn btn-sm btn-primary', 'Gerar repasse de ' . money($r['net']) . ' para ' . $r['farmer_name'] . '?') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>

        <?php if ($fId > 0): ?>
        <article class="content-card sa-card mb-4">
            <div class="card-heading sa-card-head"><div><h4>Itens do repasse #<?= $fId ?></h4><p class="sa-card-sub"><?= count($detailItems) ?> item(ns)</p></div><a href="?" class="small">Limpar</a></div>
            <?php if (!$detailItems): ?>
                <p class="text-muted mb-0">Sem itens associados (repasse cancelado ou inexistente).</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle sa-table">
                        <thead><tr><th>Pedido</th><th>Produto</th><th class="text-end">Subtotal</th><th class="text-end">Comissão</th><th class="text-end">Agricultor</th></tr></thead>
                        <tbody>
                        <?php foreach ($detailItems as $it): ?>
                            <tr><td><a href="<?= base_path('admin/pedido.php?id=' . (int)$it['order_id']) ?>"><?= e($it['order_number']) ?></a></td><td><?= e($it['product_name']) ?></td><td class="text-end"><?= money($it['subtotal']) ?></td><td class="text-end"><?= money($it['commission_amount']) ?></td><td class="text-end"><strong><?= money($it['farmer_amount']) ?></strong></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>
        <?php endif; ?>

        <!-- Lista de repasses -->
        <article class="content-card sa-card">
            <div class="card-heading sa-card-head">
                <div>
                    <h4>Repasses</h4>
                    <p class="sa-card-sub"><?= $totalFiltered ?> registo(s)</p>
                </div>
                <div class="sa-toolbar">
                    <form method="get" class="sa-search" role="search">
                        <i class="bi bi-search"></i>
                        <?php if ($fStatus !== ''): ?><input type="hidden" name="status" value="<?= e($fStatus) ?>"><?php endif; ?>
                        <input type="text" name="q" value="<?= e($q) ?>" aria-label="Procurar repasse" placeholder="Agricultor ou referência…">
                        <select name="farmer" class="form-select form-select-sm w-auto ms-2" aria-label="Agricultor" onchange="this.form.submit()">
                            <option value="">Todos os agricultores</option>
                            <?php foreach ($farmersList as $fidOpt => $fname): ?>
                                <option value="<?= (int)$fidOpt ?>" <?= $fFarmer === (int)$fidOpt ? 'selected' : '' ?>><?= e($fname) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <div class="sa-filters">
                        <?php foreach (['' => 'Todos'] + $statuses as $key => $label): ?>
                            <a class="sa-filter text-decoration-none<?= $fStatus === $key ? ' active' : '' ?>" href="?<?= e(http_build_query(['status' => $key ?: null] + $baseQuery)) ?>"><?= e($label) ?></a>
                        <?php endforeach; ?>
                    </div>
                    <a href="?<?= e(http_build_query($baseQuery + ['export' => 'csv'])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a>
                </div>
            </div>

            <?php if (!$payouts): ?>
                <div class="empty-state py-5"><i class="bi bi-cash-stack" style="font-size:2.5rem;"></i><p class="mt-3 mb-0">Nenhum repasse encontrado.</p></div>
            <?php else: ?>
                <div class="sa-scroll-x">
                    <table class="table align-middle sa-table">
                        <thead><tr><th>#</th><th>Agricultor</th><th class="text-end">Valor</th><th class="text-end">Itens</th><th>Estado</th><th>Criado</th><th>Pagamento</th><th class="text-end">Ações</th></tr></thead>
                        <tbody>
                        <?php foreach ($payouts as $p): ?>
                            <tr>
                                <td><a href="?<?= e(http_build_query(['id' => (int)$p['id']])) ?>">#<?= (int)$p['id'] ?></a></td>
                                <td><?= e($p['farmer_name']) ?></td>
                                <td class="text-end"><strong><?= money($p['amount']) ?></strong></td>
                                <td class="text-end"><?= (int)$p['items_count'] ?></td>
                                <td><span class="badge text-bg-<?= e(payout_status_class($p['status'])) ?>"><?= e(payout_status_label($p['status'])) ?></span></td>
                                <td class="small"><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?><?= $p['creator_name'] ? '<br><span class="text-muted">' . e($p['creator_name']) . '</span>' : '' ?></td>
                                <td class="small"><?php if ($p['status'] === 'paid'): ?><?= e($payMethods[$p['method']] ?? (string)$p['method']) ?> · <?= e($p['reference']) ?><br><span class="text-muted"><?= date('d/m/Y H:i', strtotime($p['paid_at'])) ?></span><?php elseif ($p['notes']): ?><span class="text-muted"><?= nl2br(e($p['notes'])) ?></span><?php else: ?>—<?php endif; ?></td>
                                <td class="text-end">
                                    <?php if ($p['status'] === 'pending'): ?>
                                        <form method="post" class="d-flex flex-wrap gap-1 justify-content-end mb-1" data-confirm="Confirmar o pagamento deste repasse?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="pay"><input type="hidden" name="payout_id" value="<?= (int)$p['id'] ?>">
                                            <select name="method" class="form-select form-select-sm w-auto" required aria-label="Método">
                                                <?php foreach ($payMethods as $mk => $ml): ?><option value="<?= e($mk) ?>"><?= e($ml) ?></option><?php endforeach; ?>
                                            </select>
                                            <input name="reference" class="form-control form-control-sm w-auto" maxlength="100" required placeholder="Referência" aria-label="Referência">
                                            <button class="btn btn-sm btn-success"><i class="bi bi-check2-circle"></i> Pago</button>
                                        </form>
                                        <form method="post" class="d-flex gap-1 justify-content-end" data-confirm="Cancelar este repasse? Os itens voltam ao saldo elegível.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="cancel"><input type="hidden" name="payout_id" value="<?= (int)$p['id'] ?>">
                                            <input name="reason" class="form-control form-control-sm w-auto" maxlength="255" placeholder="Motivo (opcional)" aria-label="Motivo">
                                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> Cancelar</button>
                                        </form>
                                    <?php else: ?>
                                        <a href="?<?= e(http_build_query(['id' => (int)$p['id']])) ?>" class="btn btn-sm btn-outline-secondary">Ver itens</a>
                                    <?php endif; ?>
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
