<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';

require_role('farmer');

$page_title = 'Financeiro';
$fid = (int)user()['farmer_id'];
$pdo = db();
$payMethods = ['bank' => 'Transferência', 'mpesa' => 'M-Pesa', 'emola' => 'e-Mola', 'cash' => 'Dinheiro'];
$eligibleSql = 'SELECT oi.id, oi.order_id, o.order_number, oi.product_name, oi.quantity, oi.unit, oi.subtotal, oi.commission_rate, oi.commission_amount, oi.farmer_amount, o.created_at, pay.method AS pay_method, pay.status AS pay_status' . payout_eligible_sql() . ' AND oi.farmer_id = ? ORDER BY o.created_at';

/* ============================================================
   EXPORTAÇÃO CSV
   ============================================================ */
$export = (string)($_GET['export'] ?? '');
if ($export === 'items') {
    $s = $pdo->prepare($eligibleSql); $s->execute([$fid]);
    $rows = [];
    foreach ($s as $r) $rows[] = [$r['order_number'], date('d/m/Y', strtotime($r['created_at'])), $r['product_name'], number_format((float)$r['quantity'], 2, ',', '') . ' ' . $r['unit'], number_format((float)$r['subtotal'], 2, ',', ''), number_format((float)$r['commission_amount'], 2, ',', ''), number_format((float)$r['farmer_amount'], 2, ',', '')];
    csv_download('a_receber_' . date('Ymd') . '.csv', ['Pedido', 'Data', 'Produto', 'Quantidade', 'Subtotal', 'Comissão', 'Líquido'], $rows);
}
if ($export === 'payouts') {
    $s = $pdo->prepare('SELECT id, amount, items_count, status, method, reference, created_at, paid_at FROM farmer_payouts WHERE farmer_id = ? ORDER BY id DESC'); $s->execute([$fid]);
    $rows = [];
    foreach ($s as $r) $rows[] = [$r['id'], number_format((float)$r['amount'], 2, ',', ''), $r['items_count'], payout_status_label($r['status']), $r['method'] ? ($payMethods[$r['method']] ?? $r['method']) : '', $r['reference'], date('d/m/Y H:i', strtotime($r['created_at'])), $r['paid_at'] ? date('d/m/Y H:i', strtotime($r['paid_at'])) : ''];
    csv_download('repasses_' . date('Ymd') . '.csv', ['Repasse', 'Valor', 'Itens', 'Estado', 'Método', 'Referência', 'Criado em', 'Pago em'], $rows);
}

/* ============================================================
   DADOS
   ============================================================ */
$bal = farmer_balances($fid);
$s = $pdo->prepare('SELECT COALESCE(SUM(farmer_amount),0) FROM order_items WHERE farmer_id = ? AND status = "delivered" AND payout_id IS NULL'); $s->execute([$fid]);
$waiting = max(0, (float)$s->fetchColumn() - $bal['available']); // entregues mas ainda sem pagamento confirmado (ou com reembolso)
$s = $pdo->prepare('SELECT COALESCE(SUM(farmer_amount),0) FROM order_items WHERE farmer_id = ? AND status NOT IN ("delivered","cancelled")'); $s->execute([$fid]);
$inProgress = (float)$s->fetchColumn();

$s = $pdo->prepare($eligibleSql . ' LIMIT 100'); $s->execute([$fid]);
$eligibleItems = $s->fetchAll();

$perPage = 10;
[$page, $offset] = page_offset($perPage);
$c = $pdo->prepare('SELECT COUNT(*) FROM farmer_payouts WHERE farmer_id = ?'); $c->execute([$fid]); $totalPayouts = (int)$c->fetchColumn();
$s = $pdo->prepare('SELECT * FROM farmer_payouts WHERE farmer_id = ? ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset); $s->execute([$fid]);
$payouts = $s->fetchAll();
$rate = commission_percent();

include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow"><span class="sa-dot"></span> Financeiro</span>
                <h1 class="sa-hero-title">Os seus <em>ganhos</em></h1>
                <p class="sa-hero-sub">A plataforma retém uma comissão de <strong><?= e(rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',')) ?>%</strong> sobre o valor dos produtos (a taxa de entrega não é sua nem é descontada). Os itens entregues e pagos entram no saldo a receber e são pagos por repasse.</p>
            </div>
            <div class="sa-hero-actions">
                <a href="?export=items" class="btn btn-outline-secondary"><i class="bi bi-filetype-csv"></i> A receber (CSV)</a>
                <a href="?export=payouts" class="btn btn-outline-secondary"><i class="bi bi-filetype-csv"></i> Repasses (CSV)</a>
            </div>
        </header>

        <div class="sa-kpi-grid sa-kpi-grid-4">
            <article class="sa-kpi">
                <header class="sa-kpi-head"><span class="sa-kpi-icon sa-icon-info"><i class="bi bi-wallet2"></i></span></header>
                <span class="sa-kpi-label">A receber</span>
                <strong class="sa-kpi-value"><?= money($bal['available']) ?></strong>
                <footer class="sa-kpi-foot"><span class="sa-kpi-sub"><i class="bi bi-box-seam"></i> <?= $bal['available_items'] ?> item(ns) elegíveis</span></footer>
            </article>
            <article class="sa-kpi">
                <header class="sa-kpi-head"><span class="sa-kpi-icon sa-icon-warning"><i class="bi bi-hourglass-split"></i></span></header>
                <span class="sa-kpi-label">Em processamento</span>
                <strong class="sa-kpi-value"><?= money($bal['processing']) ?></strong>
                <footer class="sa-kpi-foot"><span class="sa-kpi-sub"><i class="bi bi-list-check"></i> <?= $bal['processing_count'] ?> repasse(s)</span></footer>
            </article>
            <article class="sa-kpi">
                <header class="sa-kpi-head"><span class="sa-kpi-icon sa-icon-success"><i class="bi bi-check2-circle"></i></span></header>
                <span class="sa-kpi-label">Pago</span>
                <strong class="sa-kpi-value"><?= money($bal['paid']) ?></strong>
                <footer class="sa-kpi-foot"><span class="sa-kpi-sub"><i class="bi bi-cash-stack"></i> <?= $bal['paid_count'] ?> repasse(s)</span></footer>
            </article>
            <article class="sa-kpi">
                <header class="sa-kpi-head"><span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-truck"></i></span></header>
                <span class="sa-kpi-label">Ainda não elegível</span>
                <strong class="sa-kpi-value"><?= money($waiting + $inProgress) ?></strong>
                <footer class="sa-kpi-foot"><span class="sa-kpi-sub" title="Itens por entregar ou entregues com pagamento por confirmar"><i class="bi bi-info-circle"></i> por entregar / pagamento por confirmar</span></footer>
            </article>
        </div>

        <div class="row g-4">
            <div class="col-xl-7">
                <article class="content-card sa-card">
                    <div class="card-heading sa-card-head">
                        <div><h4>Itens a receber</h4><p class="sa-card-sub">Entregues, com pagamento confirmado (ou dinheiro na entrega) e ainda sem repasse</p></div>
                    </div>
                    <?php if (!$eligibleItems): ?>
                        <p class="text-muted mb-0">Não tem itens a receber neste momento.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle sa-table">
                                <thead><tr><th>Pedido</th><th>Produto</th><th class="text-end">Subtotal</th><th class="text-end">Comissão</th><th class="text-end">Líquido</th></tr></thead>
                                <tbody>
                                <?php foreach ($eligibleItems as $it): ?>
                                    <tr>
                                        <td><a href="<?= base_path('agricultor/pedido-detalhes.php?id=' . (int)$it['order_id']) ?>"><?= e($it['order_number']) ?></a><br><small class="text-muted"><?= date('d/m/Y', strtotime($it['created_at'])) ?> · <?= e(payment_method_label((string)$it['pay_method'])) ?></small></td>
                                        <td><?= e($it['product_name']) ?><br><small class="text-muted"><?= e(rtrim(rtrim(number_format((float)$it['quantity'], 2, ',', ''), '0'), ',')) ?> <?= e($it['unit']) ?></small></td>
                                        <td class="text-end"><?= money($it['subtotal']) ?></td>
                                        <td class="text-end text-muted">−<?= money($it['commission_amount']) ?></td>
                                        <td class="text-end"><strong><?= money($it['farmer_amount']) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>
            </div>

            <div class="col-xl-5">
                <article class="content-card sa-card">
                    <div class="card-heading sa-card-head"><div><h4>Repasses</h4><p class="sa-card-sub"><?= $totalPayouts ?> registo(s)</p></div></div>
                    <?php if (!$payouts): ?>
                        <p class="text-muted mb-0">Ainda não recebeu repasses.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle sa-table">
                                <thead><tr><th>#</th><th class="text-end">Valor</th><th>Estado</th><th>Detalhes</th></tr></thead>
                                <tbody>
                                <?php foreach ($payouts as $p): ?>
                                    <tr>
                                        <td>#<?= (int)$p['id'] ?><br><small class="text-muted"><?= date('d/m/Y', strtotime($p['created_at'])) ?></small></td>
                                        <td class="text-end"><strong><?= money($p['amount']) ?></strong><br><small class="text-muted"><?= (int)$p['items_count'] ?> item(ns)</small></td>
                                        <td><span class="badge text-bg-<?= e(payout_status_class($p['status'])) ?>"><?= e(payout_status_label($p['status'])) ?></span></td>
                                        <td class="small"><?php if ($p['status'] === 'paid'): ?><?= e($payMethods[$p['method']] ?? (string)$p['method']) ?> · <?= e($p['reference']) ?><br><span class="text-muted"><?= date('d/m/Y H:i', strtotime($p['paid_at'])) ?></span><?php else: ?>—<?php endif; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?= pagination($page, $perPage, $totalPayouts) ?>
                    <?php endif; ?>
                </article>
            </div>
        </div>

    </section>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
