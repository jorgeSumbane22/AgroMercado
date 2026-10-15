<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';

require_role('farmer');

$page_title = 'Detalhes do pedido';
$fid = user()['farmer_id'];
$pdo = db();
$id  = safe_int($_GET['id'] ?? 0);

/* ============================================================
   CARREGAR PEDIDO (só se contém itens deste agricultor)
   ============================================================ */
$s = $pdo->prepare('
    SELECT
        o.*,
        u.name  AS buyer_name,
        u.email AS buyer_email,
        u.phone AS buyer_phone
    FROM orders o
    JOIN buyers b ON b.id = o.buyer_id
    JOIN users u ON u.id = b.user_id
    WHERE o.id = ? AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND oi.farmer_id = ?)
');
$s->execute([$id, $fid]);
$order = $s->fetch();

if (!$order) {
    flash('danger', 'Pedido não encontrado.');
    redirect(base_path('agricultor/pedidos.php'));
}

/* ============================================================
   ITENS DO PEDIDO (só deste agricultor)
   ============================================================ */
$s = $pdo->prepare('
    SELECT oi.*, p.main_image, c.name AS category_name
    FROM order_items oi
    LEFT JOIN products p ON p.id = oi.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE oi.order_id = ? AND oi.farmer_id = ?
    ORDER BY oi.id
');
$s->execute([$id, $fid]);
$items = $s->fetchAll();

$itemsCount = count($items);
$itemsTotal = 0;
$itemsQty   = 0;
$itemsCommission = 0;
$itemsNet = 0;
foreach ($items as $i) {
    if ($i['status'] === 'cancelled') continue;
    $itemsTotal += (float)($i['subtotal'] ?? 0);
    $itemsQty   += (float)($i['quantity'] ?? 0);
    $itemsCommission += (float)($i['commission_amount'] ?? 0);
    $itemsNet += (float)($i['farmer_amount'] ?? 0);
}

/* Mensagens com o comprador (fio deste agricultor); ao abrir, as recebidas ficam lidas. */
$thread = order_thread($id, (int)$fid);
mark_thread_read($id, (int)$fid, (int)user()['id']);

/* ============================================================
   HELPERS DE ESTADO / TIMELINE
   ============================================================ */
function order_meta(string $st): array {
    $map = [
        'pending'    => ['warning', 'hourglass-split',   'Pendente'],
        'confirmed'  => ['info',    'check-circle-fill', 'Confirmado'],
        'preparing'  => ['info',    'box-seam-fill',     'Em preparação'],
        'ready'      => ['primary', 'bag-check-fill',    'Pronto'],
        'shipping'   => ['info',    'truck',             'Em entrega'],
        'delivered'  => ['success', 'check2-circle',     'Entregue'],
        'cancelled'  => ['danger',  'x-octagon-fill',    'Cancelado'],
    ];
    return $map[$st] ?? ['secondary', 'circle', ucfirst($st)];
}

/* Estado dos itens deste agricultor (um pedido pode ter vários agricultores):
   o estado é o do item ativo menos avançado; cancelado se todos estiverem cancelados. */
$rank = order_status_rank();
$myActive = array_values(array_filter(array_column($items, 'status'), fn($x) => $x !== 'cancelled'));
if ($myActive) { usort($myActive, fn($a, $b) => $rank[$a] <=> $rank[$b]); $myStatus = $myActive[0]; } else { $myStatus = 'cancelled'; }
$mixedStatuses = count(array_unique($myActive)) > 1;

/* Próximos estados válidos para TODOS os itens ativos deste agricultor (a API exige isso) */
$nextStatuses = null;
foreach (array_unique($myActive) as $st) { $n = order_status_next($st); $nextStatuses = $nextStatuses === null ? $n : array_values(array_intersect($nextStatuses, $n)); }
$nextStatuses = $nextStatuses ?? [];
$actionLabels = ['confirmed' => 'Confirmar pedido', 'preparing' => 'Iniciar preparação', 'ready' => 'Marcar como pronto', 'shipping' => 'Enviar / em transporte', 'delivered' => 'Marcar como entregue', 'cancelled' => 'Cancelar os meus itens'];

/* Timeline */
$timeline = [
    'pending'   => ['Pendente',    'hourglass-split'],
    'confirmed' => ['Confirmado',  'check-circle-fill'],
    'preparing' => ['Em preparação', 'box-seam-fill'],
    'ready'     => ['Pronto',      'bag-check-fill'],
    'shipping'  => ['Em entrega',  'truck'],
    'delivered' => ['Entregue',    'check2-circle'],
];
$timelineKeys = array_keys($timeline);
$currentIdx = array_search($myStatus, $timelineKeys, true);
$isCancelled = $myStatus === 'cancelled';

[$stCls, $stIcon, $stLabel] = order_meta($myStatus);
$eta = (int)($order['delivery_eta_days'] ?? 0);

include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <!-- ================= HERO ================= -->
        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Pedido · <?= e($order['order_number'] ?? '#' . $order['id']) ?>
                </span>
                <h1 class="sa-hero-title">
                    Detalhes do <em>pedido</em>
                </h1>
                <p class="sa-hero-sub">
                    Recebido em
                    <strong><?= date('d M Y', strtotime($order['created_at'])) ?></strong> às
                    <strong><?= date('H:i', strtotime($order['created_at'])) ?></strong> ·
                    Os seus itens:
                    <span class="sa-badge sa-badge-<?= $stCls ?> sa-badge-inline">
                        <i class="bi bi-<?= $stIcon ?>"></i>
                        <?= $stLabel ?>
                    </span>
                    · Pedido: <?= order_status_badge($order['status']) ?>
                </p>
            </div>
            <div class="sa-hero-actions">
                <a href="<?= base_path('agricultor/pedidos.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
                <a class="btn btn-primary" href="<?= base_path('recibo.php?id=' . (int)$order['id']) ?>" target="_blank" rel="noopener">
                    <i class="bi bi-printer"></i> Recibo
                </a>
            </div>
        </header>

        <!-- ================= TIMELINE ================= -->
        <?php if (!$isCancelled): ?>
        <article class="content-card sa-card mb-4">
            <div class="card-heading">
                <div>
                    <h4>Progresso dos seus itens</h4>
                    <p class="sa-card-sub">Estado dos seus itens: <?= $stLabel ?> · Estado geral do pedido: <?= e(order_status_label($order['status'])) ?></p>
                </div>
            </div>

            <div class="sa-timeline">
                <?php foreach ($timeline as $key => $info):
                    [$label, $icon] = $info;
                    $idx = array_search($key, $timelineKeys, true);
                    $state = 'waiting';
                    if ($currentIdx !== false) {
                        if ($idx < $currentIdx)  $state = 'done';
                        if ($idx === $currentIdx) $state = 'current';
                    }
                ?>
                    <div class="sa-timeline-step sa-tl-<?= $state ?>">
                        <span class="sa-timeline-icon">
                            <i class="bi bi-<?= $state === 'done' ? 'check-lg' : $icon ?>"></i>
                        </span>
                        <small><?= e($label) ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
        <?php else: ?>
        <div class="sa-alert sa-alert-danger mb-4">
            <span class="sa-alert-icon"><i class="bi bi-x-octagon-fill"></i></span>
            <span class="sa-alert-body">
                <strong>Os seus itens deste pedido foram cancelados.</strong> Não é possível alterar o estado.
                <?php if (!empty($order['cancel_reason'])): ?> Motivo: <?= e($order['cancel_reason']) ?><?php endif; ?>
            </span>
        </div>
        <?php endif; ?>

        <!-- ================= CONTEÚDO ================= -->
        <div class="row g-4">

            <!-- ===== COLUNA ESQUERDA: ITENS ===== -->
            <div class="col-lg-8">

                <article class="content-card sa-card">
                    <div class="card-heading">
                        <div>
                            <h4>Os seus itens neste pedido</h4>
                            <p class="sa-card-sub"><?= $itemsCount ?> produto<?= $itemsCount !== 1 ? 's' : '' ?> · <?= number_format($itemsQty, 0, ',', ' ') ?> unidades</p>
                        </div>
                        <span class="sa-badge sa-badge-<?= $stCls ?>">
                            <i class="bi bi-<?= $stIcon ?>"></i>
                            <?= $stLabel ?>
                        </span>
                    </div>

                    <?php if (empty($items)): ?>
                        <div class="empty-state py-5">
                            <i class="bi bi-box" style="font-size:2.5rem;"></i>
                            <p class="mt-3 mb-0">Sem itens neste pedido.</p>
                        </div>
                    <?php else: ?>
                        <div class="sa-order-items">
                            <?php foreach ($items as $i):
                                $img = !empty($i['main_image']) ? get_product_image($i['main_image']) : '';
                            ?>
                                <div class="sa-order-item">
                                    <div class="sa-order-item-media">
                                        <?php if ($img): ?>
                                            <img src="<?= e($img) ?>" alt="<?= e($i['product_name']) ?>">
                                        <?php else: ?>
                                            <span class="sa-order-item-placeholder">
                                                <i class="bi bi-basket2"></i>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="sa-order-item-info">
                                        <strong><?= e($i['product_name']) ?></strong>
                                        <small>
                                            <?php if (!empty($i['category_name'])): ?>
                                                <?= e($i['category_name']) ?> ·
                                            <?php endif; ?>
                                            <?= number_format((float)$i['quantity'], 2, ',', '.') ?>
                                            <?= e($i['unit']) ?>
                                            × <?= money($i['unit_price']) ?>
                                        </small>
                                        <div class="mt-1"><?= order_status_badge($i['status']) ?></div>
                                    </div>

                                    <div class="sa-order-item-price">
                                        <strong<?= $i['status'] === 'cancelled' ? ' class="text-decoration-line-through text-muted"' : '' ?>><?= money($i['subtotal']) ?></strong>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Resumo -->
                        <div class="sa-order-summary">
                            <div class="sa-order-summary-row">
                                <span>Os seus itens (excl. cancelados)</span>
                                <strong><?= money($itemsTotal) ?></strong>
                            </div>
                            <div class="sa-order-summary-row">
                                <span>Comissão da plataforma</span>
                                <strong>−<?= money($itemsCommission) ?></strong>
                            </div>
                            <div class="sa-order-summary-row">
                                <span>Valor líquido a receber</span>
                                <strong class="text-success"><?= money($itemsNet) ?></strong>
                            </div>
                            <div class="sa-order-summary-row">
                                <span>Subtotal do pedido</span>
                                <strong><?= money($order['subtotal']) ?></strong>
                            </div>
                            <div class="sa-order-summary-row">
                                <span>Taxa de entrega</span>
                                <strong><?= (float)$order['delivery_fee'] > 0 ? money($order['delivery_fee']) : 'Grátis' ?></strong>
                            </div>
                            <?php if ($eta > 0): ?>
                                <div class="sa-order-summary-row">
                                    <span>Prazo estimado de entrega</span>
                                    <strong><?= $eta ?> dia<?= $eta !== 1 ? 's' : '' ?> (até <?= date('d/m/Y', strtotime($order['created_at'].' +'.$eta.' days')) ?>)</strong>
                                </div>
                            <?php endif; ?>
                            <div class="sa-order-summary-row sa-order-summary-total">
                                <span>Total do pedido</span>
                                <strong><?= money($order['total']) ?></strong>
                            </div>
                        </div>
                    <?php endif; ?>
                </article>

                <!-- Mensagens -->
                <article class="content-card sa-card mt-4" id="mensagens">
                    <div class="card-heading">
                        <div>
                            <h4>Mensagens com o comprador</h4>
                            <p class="sa-card-sub">Conversa sobre os seus itens deste pedido</p>
                        </div>
                    </div>
                    <?= order_thread_html($thread, (int)$order['id'], (int)$fid, (int)user()['id'], 'Conversa com ' . ($order['buyer_name'] ?? 'o comprador')) ?>
                </article>

            </div>

            <!-- ===== COLUNA DIREITA ===== -->
            <div class="col-lg-4">

                <!-- Ações -->
                <?php if (!$isCancelled): ?>
                <article class="content-card sa-card mb-4">
                    <div class="card-heading">
                        <div class="sa-card-head-icon">
                            <span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-lightning-charge"></i></span>
                            <div>
                                <h4>Atualizar estado</h4>
                                <p class="sa-card-sub">Aplica-se apenas aos seus itens</p>
                            </div>
                        </div>
                    </div>

                    <?php if ($mixedStatuses): ?>
                        <p class="small text-muted">Os seus itens estão em estados diferentes; só são mostradas as ações válidas para todos eles.</p>
                    <?php endif; ?>
                    <div class="sa-status-actions">
                        <div class="sa-status-btn is-current">
                            <span class="sa-status-btn-icon"><i class="bi bi-<?= $stIcon ?>"></i></span>
                            <span class="sa-status-btn-label">
                                <strong>Estado atual</strong>
                                <small><?= $stLabel ?></small>
                            </span>
                            <span class="sa-status-btn-dot"></span>
                        </div>
                        <?php if (!$nextStatuses): ?>
                            <p class="small text-muted mb-0 mt-2">Não há mais ações disponíveis para os seus itens.</p>
                        <?php endif; ?>
                        <?php foreach ($nextStatuses as $st):
                            [$cls, $icon, $label] = order_meta($st);
                            $isCancel = $st === 'cancelled';
                            $confirmMsg = $isCancel
                                ? 'Cancelar os seus itens deste pedido? O stock será reposto e o comprador será notificado.'
                                : 'Alterar o estado dos seus itens para "' . order_status_label($st) . '"?';
                        ?>
                            <button type="button"
                                    class="sa-status-btn<?= $isCancel ? ' text-danger' : '' ?>"
                                    data-order-status
                                    data-order-id="<?= (int)$order['id'] ?>"
                                    data-status="<?= e($st) ?>"
                                    data-confirm="<?= e($confirmMsg) ?>"
                                    <?= $isCancel ? 'data-reason="Cancelado pelo agricultor."' : '' ?>>
                                <span class="sa-status-btn-icon">
                                    <i class="bi bi-<?= $icon ?>"></i>
                                </span>
                                <span class="sa-status-btn-label">
                                    <strong><?= e($actionLabels[$st] ?? $label) ?></strong>
                                    <small><?= e($label) ?></small>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </article>
                <?php endif; ?>

                <!-- Comprador -->
                <article class="content-card sa-card mb-4">
                    <div class="card-heading">
                        <div class="sa-card-head-icon">
                            <span class="sa-kpi-icon sa-icon-info"><i class="bi bi-person-badge"></i></span>
                            <div>
                                <h4>Comprador</h4>
                                <p class="sa-card-sub">Quem fez o pedido</p>
                            </div>
                        </div>
                    </div>

                    <div class="sa-info-list">
                        <div class="sa-info-row">
                            <span class="sa-info-label"><i class="bi bi-person"></i> Nome</span>
                            <strong><?= e($order['buyer_name'] ?? '—') ?></strong>
                        </div>
                        <?php if (!empty($order['buyer_email'])): ?>
                        <div class="sa-info-row">
                            <span class="sa-info-label"><i class="bi bi-envelope"></i> Email</span>
                            <a href="mailto:<?= e($order['buyer_email']) ?>" class="sa-info-link">
                                <?= e($order['buyer_email']) ?>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($order['buyer_phone'])): ?>
                        <div class="sa-info-row">
                            <span class="sa-info-label"><i class="bi bi-telephone"></i> Telefone</span>
                            <a href="tel:<?= e($order['buyer_phone']) ?>" class="sa-info-link">
                                <?= e($order['buyer_phone']) ?>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </article>

                <!-- Entrega -->
                <?php if (!empty($order['delivery_name']) || !empty($order['address'])): ?>
                <article class="content-card sa-card">
                    <div class="card-heading">
                        <div class="sa-card-head-icon">
                            <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-geo-alt"></i></span>
                            <div>
                                <h4>Entrega</h4>
                                <p class="sa-card-sub">Destino do pedido</p>
                            </div>
                        </div>
                    </div>

                    <div class="sa-info-list">
                        <?php if (!empty($order['delivery_name'])): ?>
                        <div class="sa-info-row">
                            <span class="sa-info-label"><i class="bi bi-person-check"></i> Destinatário</span>
                            <strong><?= e($order['delivery_name']) ?></strong>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($order['delivery_phone'])): ?>
                        <div class="sa-info-row">
                            <span class="sa-info-label"><i class="bi bi-telephone"></i> Telefone</span>
                            <a href="tel:<?= e($order['delivery_phone']) ?>" class="sa-info-link">
                                <?= e($order['delivery_phone']) ?>
                            </a>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($order['address'])): ?>
                        <div class="sa-info-row sa-info-row-block">
                            <span class="sa-info-label"><i class="bi bi-pin-map"></i> Endereço</span>
                            <strong>
                                <?= e($order['address']) ?><?php if (!empty($order['district'])): ?>,
                                    <?= e($order['district']) ?><?php endif; ?><?php if (!empty($order['city'])): ?>,
                                    <?= e($order['city']) ?><?php endif; ?><?php if (!empty($order['province'])): ?>,
                                    <?= e($order['province']) ?><?php endif; ?>
                            </strong>
                        </div>
                        <?php endif; ?>

                        <div class="sa-info-row">
                            <span class="sa-info-label"><i class="bi bi-truck"></i> Taxa de entrega</span>
                            <strong><?= (float)$order['delivery_fee'] > 0 ? money($order['delivery_fee']) : 'Grátis' ?></strong>
                        </div>
                        <?php if ($eta > 0): ?>
                        <div class="sa-info-row">
                            <span class="sa-info-label"><i class="bi bi-calendar-event"></i> Prazo estimado</span>
                            <strong><?= $eta ?> dia<?= $eta !== 1 ? 's' : '' ?></strong>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($order['notes'])): ?>
                        <div class="sa-info-row sa-info-row-block">
                            <span class="sa-info-label"><i class="bi bi-chat-left-text"></i> Notas</span>
                            <strong><?= nl2br(e($order['notes'])) ?></strong>
                        </div>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endif; ?>

            </div>
        </div>

    </section>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
