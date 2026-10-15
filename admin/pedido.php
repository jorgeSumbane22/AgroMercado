<?php require_once __DIR__.'/../includes/permissions.php'; ?>
<?php
require_role('superadmin');
require_once __DIR__.'/_helpers.php';
$pdo = db();

$id = safe_int($_GET['id'] ?? ($_POST['id'] ?? 0));

/** Carrega o pedido com comprador e pagamento. */
function admin_load_order(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare('
        SELECT o.*, u.id AS buyer_user_id, u.name AS buyer_name, u.email AS buyer_email, u.phone AS buyer_phone,
               pay.id AS payment_id, pay.method AS pay_method, pay.status AS pay_status, pay.amount AS pay_amount,
               pay.reference AS pay_reference, pay.paid_at AS pay_paid_at
        FROM orders o
        JOIN buyers b ON b.id = o.buyer_id
        JOIN users u ON u.id = b.user_id
        LEFT JOIN payments pay ON pay.order_id = o.id
        WHERE o.id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

$order = admin_load_order($pdo, $id);
if (!$order) {
    flash('danger', 'Pedido não encontrado.');
    redirect(base_path('admin/pedidos.php'));
}

/* ============================================================
   AÇÕES (POST + CSRF): avançar estado / cancelar pedido
   ============================================================ */
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    $action = $_POST['action'] ?? '';
    $back = base_path('admin/pedido.php?id=' . $id);
    try {
        $pdo->beginTransaction();
        $s = $pdo->prepare('SELECT oi.id, oi.product_id, oi.quantity, oi.status, f.user_id AS farmer_user_id FROM order_items oi JOIN farmers f ON f.id = oi.farmer_id WHERE oi.order_id = ? FOR UPDATE');
        $s->execute([$id]);
        $items = $s->fetchAll();

        if ($action === 'status') {
            $to = (string)($_POST['status'] ?? '');
            if (!in_array($to, ['confirmed', 'preparing', 'ready', 'shipping', 'delivered'], true)) throw new DomainException('Estado inválido.');
            $target = array_values(array_filter($items, fn($i) => $i['status'] !== 'cancelled' && can_transition($i['status'], $to)));
            if (!$target) throw new DomainException('Nenhum item pode passar para "' . order_status_label($to) . '" a partir do estado atual.');
            $ids = array_map(fn($i) => (int)$i['id'], $target);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE order_items SET status=? WHERE id IN ($in)")->execute(array_merge([$to], $ids));
            $new = recalc_order_status($pdo, $id);
            notify_buyer_of_order($id, 'Pedido atualizado', 'O pedido ' . $order['order_number'] . ' está agora: ' . order_status_label($new) . '.');
            audit(user()['id'], 'update', 'orders', 'Admin: ' . count($ids) . ' item(ns) do pedido ' . $order['order_number'] . ' → ' . $to . ' (estado do pedido: ' . $new . ')');
            $pdo->commit();
            $skipped = count(array_filter($items, fn($i) => $i['status'] !== 'cancelled')) - count($ids);
            flash('success', count($ids) . ' item(ns) atualizado(s) para "' . order_status_label($to) . '".' . ($skipped > 0 ? ' ' . $skipped . ' item(ns) não podiam fazer esta transição e mantiveram o estado.' : ''));
        } elseif ($action === 'cancel') {
            $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 255);
            if ($reason === '') throw new DomainException('Indique o motivo do cancelamento.');
            $target = array_values(array_filter($items, fn($i) => !in_array($i['status'], ['cancelled', 'delivered'], true)));
            if (!$target) throw new DomainException('Não há itens por cancelar (todos entregues ou já cancelados).');
            $ids = array_map(fn($i) => (int)$i['id'], $target);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE order_items SET status='cancelled' WHERE id IN ($in)")->execute($ids);
            restore_item_stock($pdo, $target, 'Cancelamento do pedido ' . $order['order_number'] . ' (SuperAdmin)');
            $pdo->prepare('UPDATE orders SET cancel_reason=? WHERE id=?')->execute([$reason, $id]);
            $new = recalc_order_status($pdo, $id);
            notify_buyer_of_order($id, 'Pedido cancelado', 'O pedido ' . $order['order_number'] . ' foi cancelado pela administração' . ($new !== 'cancelled' ? ' (itens não entregues)' : '') . '. Motivo: ' . $reason);
            foreach (array_unique(array_map(fn($i) => (int)$i['farmer_user_id'], $target)) as $fu) {
                notify($fu, 'Pedido cancelado', 'Os seus itens do pedido ' . $order['order_number'] . ' foram cancelados pela administração. Motivo: ' . $reason);
            }
            audit(user()['id'], 'cancel', 'orders', 'Admin cancelou ' . count($ids) . ' item(ns) do pedido ' . $order['order_number'] . ' (estado: ' . $new . '). Motivo: ' . $reason);
            $pdo->commit();
            flash('success', 'Pedido cancelado. Stock reposto para ' . count($ids) . ' item(ns).');
        } else {
            throw new DomainException('Ação inválida.');
        }
    } catch (DomainException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $ex->getMessage());
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('admin/pedido.php: ' . $ex->getMessage());
        flash('danger', 'Não foi possível atualizar o pedido.');
    }
    redirect($back);
}

/* ============================================================
   DADOS
   ============================================================ */
$s = $pdo->prepare('
    SELECT oi.*, u.name AS farmer_name, f.id AS farmer_id
    FROM order_items oi
    JOIN farmers f ON f.id = oi.farmer_id
    JOIN users u ON u.id = f.user_id
    WHERE oi.order_id = ?
    ORDER BY oi.id');
$s->execute([$id]);
$items = $s->fetchAll();

$activeItems = array_filter($items, fn($i) => $i['status'] !== 'cancelled');
$nextOptions = [];
foreach ($activeItems as $i) foreach (order_status_next($i['status']) as $n) if ($n !== 'cancelled') $nextOptions[$n] = true;
$rank = order_status_rank();
$nextOptions = array_keys($nextOptions);
usort($nextOptions, fn($a, $b) => $rank[$a] <=> $rank[$b]);
$canCancel = (bool)array_filter($items, fn($i) => !in_array($i['status'], ['cancelled', 'delivered'], true));

$page_title = 'Pedido ' . $order['order_number'];
include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Operações · Pedido
                </span>
                <h1 class="sa-hero-title">Pedido <em><?= e($order['order_number']) ?></em></h1>
                <p class="sa-hero-sub">
                    Criado a <?= date('d/m/Y H:i', strtotime($order['created_at'])) ?> · <?= order_status_badge($order['status']) ?>
                </p>
            </div>
            <div class="sa-hero-actions">
                <a href="<?= base_path('admin/pedidos.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Pedidos
                </a>
                <?php if ($order['payment_id']): ?>
                    <a href="<?= base_path('admin/pagamentos.php?q=' . urlencode($order['order_number'])) ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-credit-card"></i> Pagamento
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($order['status'] === 'cancelled' || $order['cancel_reason']): ?>
            <div class="alert alert-<?= $order['status'] === 'cancelled' ? 'danger' : 'warning' ?>">
                <i class="bi bi-x-octagon me-1"></i>
                <strong><?= $order['status'] === 'cancelled' ? 'Pedido cancelado.' : 'Itens cancelados.' ?></strong>
                <?php if ($order['cancel_reason']): ?>Motivo: <?= e($order['cancel_reason']) ?><?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-8">

                <!-- Itens -->
                <article class="content-card sa-card mb-4">
                    <div class="card-heading sa-card-head">
                        <div>
                            <h4>Itens</h4>
                            <p class="sa-card-sub"><?= count($items) ?> item(ns)</p>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle sa-table">
                            <thead>
                                <tr>
                                    <th>Produto</th>
                                    <th>Agricultor</th>
                                    <th class="text-end">Preço</th>
                                    <th class="text-end">Qtd.</th>
                                    <th class="text-end">Subtotal</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($items as $it): ?>
                                <tr>
                                    <td>
                                        <a href="<?= base_path('produto.php?id=' . (int)$it['product_id']) ?>" target="_blank" rel="noopener"><?= e($it['product_name']) ?></a>
                                    </td>
                                    <td><a href="<?= base_path('agricultor.php?id=' . (int)$it['farmer_id']) ?>" target="_blank" rel="noopener" class="text-reset"><?= e($it['farmer_name']) ?></a></td>
                                    <td class="text-end"><?= money($it['unit_price']) ?></td>
                                    <td class="text-end"><?= e(rtrim(rtrim(number_format((float)$it['quantity'], 2, ',', ''), '0'), ',')) ?> <?= e($it['unit']) ?></td>
                                    <td class="text-end"><strong><?= money($it['subtotal']) ?></strong></td>
                                    <td><?= order_status_badge($it['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr><td colspan="4" class="text-end">Subtotal</td><td class="text-end"><?= money($order['subtotal']) ?></td><td></td></tr>
                                <tr><td colspan="4" class="text-end">Taxa de entrega</td><td class="text-end"><?= money($order['delivery_fee']) ?></td><td></td></tr>
                                <tr><td colspan="4" class="text-end"><strong>Total</strong></td><td class="text-end"><strong><?= money($order['total']) ?></strong></td><td></td></tr>
                            </tfoot>
                        </table>
                    </div>
                </article>

                <!-- Ações -->
                <article class="content-card sa-card">
                    <div class="card-heading sa-card-head">
                        <div>
                            <h4>Ações do administrador</h4>
                            <p class="sa-card-sub">As alterações são aplicadas aos itens e o estado do pedido é recalculado.</p>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <?php if ($nextOptions): ?>
                                <form method="post" data-confirm="Atualizar o estado dos itens deste pedido?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                                    <label class="form-label" for="newStatus">Avançar itens para</label>
                                    <div class="input-group">
                                        <select id="newStatus" name="status" class="form-select" required>
                                            <?php foreach ($nextOptions as $opt): ?>
                                                <option value="<?= e($opt) ?>"><?= e(order_status_label($opt)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-primary"><i class="bi bi-arrow-right-circle"></i> Aplicar</button>
                                    </div>
                                    <small class="text-muted">Só os itens com transição válida são alterados.</small>
                                </form>
                            <?php else: ?>
                                <p class="text-muted mb-0"><i class="bi bi-info-circle"></i> Não há transições de estado disponíveis.</p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <?php if ($canCancel): ?>
                                <form method="post" data-confirm="Cancelar este pedido? Os itens não entregues serão cancelados e o stock reposto.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                                    <label class="form-label" for="cancelReason">Motivo do cancelamento *</label>
                                    <textarea id="cancelReason" name="reason" class="form-control mb-2" rows="2" maxlength="255" required placeholder="Ex.: pedido duplicado, falta de stock…"></textarea>
                                    <button class="btn btn-outline-danger w-100"><i class="bi bi-x-octagon"></i> Cancelar pedido</button>
                                </form>
                            <?php else: ?>
                                <p class="text-muted mb-0"><i class="bi bi-info-circle"></i> Nada a cancelar (itens entregues ou já cancelados).</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            </div>

            <div class="col-lg-4">
                <article class="content-card sa-card mb-4">
                    <div class="card-heading"><div><h4><i class="bi bi-person"></i> Comprador</h4></div></div>
                    <p class="mb-1"><strong><?= e($order['buyer_name']) ?></strong></p>
                    <p class="mb-1 small"><i class="bi bi-envelope"></i> <?= e($order['buyer_email']) ?></p>
                    <?php if ($order['buyer_phone']): ?><p class="mb-1 small"><i class="bi bi-telephone"></i> <?= e($order['buyer_phone']) ?></p><?php endif; ?>
                    <a href="<?= base_path('admin/pedidos.php?buyer=' . (int)$order['buyer_id']) ?>" class="small">Ver todos os pedidos deste comprador</a>
                </article>

                <article class="content-card sa-card mb-4">
                    <div class="card-heading"><div><h4><i class="bi bi-truck"></i> Entrega</h4></div></div>
                    <p class="mb-1"><strong><?= e($order['delivery_name']) ?></strong></p>
                    <p class="mb-1 small"><i class="bi bi-telephone"></i> <?= e($order['delivery_phone']) ?> · <i class="bi bi-envelope"></i> <?= e($order['delivery_email']) ?></p>
                    <p class="mb-1 small"><i class="bi bi-geo-alt"></i> <?= e($order['address']) ?>, <?= e($order['district']) ?>, <?= e($order['city']) ?> — <?= e($order['province']) ?></p>
                    <p class="mb-1 small"><i class="bi bi-cash"></i> Taxa de entrega: <strong><?= money($order['delivery_fee']) ?></strong></p>
                    <p class="mb-1 small"><i class="bi bi-calendar-event"></i> Prazo estimado: <strong><?= $order['delivery_eta_days'] !== null ? (int)$order['delivery_eta_days'] . ' dia(s)' : '—' ?></strong></p>
                    <?php if ($order['notes']): ?><p class="mb-0 small text-muted"><i class="bi bi-chat-left-text"></i> <?= nl2br(e($order['notes'])) ?></p><?php endif; ?>
                </article>

                <article class="content-card sa-card">
                    <div class="card-heading"><div><h4><i class="bi bi-credit-card"></i> Pagamento</h4></div></div>
                    <?php if ($order['payment_id']): ?>
                        <p class="mb-1 small">Método: <strong><?= e(payment_method_label((string)$order['pay_method'])) ?></strong></p>
                        <p class="mb-1 small">Estado: <strong><?= e(payment_status_label((string)$order['pay_status'])) ?></strong></p>
                        <p class="mb-1 small">Valor: <strong><?= money($order['pay_amount']) ?></strong></p>
                        <p class="mb-1 small">Referência: <?= e($order['pay_reference'] ?: '—') ?></p>
                        <p class="mb-0 small">Pago em: <?= $order['pay_paid_at'] ? date('d/m/Y H:i', strtotime($order['pay_paid_at'])) : '—' ?></p>
                    <?php else: ?>
                        <p class="text-muted mb-0">Sem registo de pagamento. Estado no pedido: <?= e(payment_status_label($order['payment_status'])) ?></p>
                    <?php endif; ?>
                </article>
            </div>
        </div>

    </section>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
