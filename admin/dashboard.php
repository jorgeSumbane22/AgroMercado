<?php require_once __DIR__.'/../includes/permissions.php'; ?>
<?php
require_role('superadmin');
$page_title = 'Dashboard';
$pdo = db();

/* ============================================================
   HELPERS À PROVA DE FALHAS
   ============================================================ */
function d_count(PDO $pdo, string $sql): int {
    try { return (int)$pdo->query($sql)->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}
function d_value(PDO $pdo, string $sql, $default = 0) {
    try { $v = $pdo->query($sql)->fetchColumn(); return $v === false ? $default : $v; }
    catch (Throwable $e) { return $default; }
}
function d_rows(PDO $pdo, string $sql): array {
    try { return $pdo->query($sql)->fetchAll() ?: []; }
    catch (Throwable $e) { return []; }
}
function d_first_col(PDO $pdo, string $table): array {
    try { return $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN); }
    catch (Throwable $e) { return []; }
}
function d_has(PDO $pdo, string $table, string $col): bool {
    static $cache = [];
    $k = "$table.$col";
    if (!isset($cache[$k])) $cache[$k] = in_array($col, d_first_col($pdo, $table), true);
    return $cache[$k];
}
function d_pick(PDO $pdo, string $table, array $cols, ?string $fallback = null): ?string {
    foreach ($cols as $c) if (d_has($pdo, $table, $c)) return $c;
    return $fallback;
}

/* ============================================================
   DETEÇÃO DE COLUNAS (com fallback seguro)
   ============================================================ */
$col_total    = d_pick($pdo, 'orders', ['total','total_amount','grand_total','amount'], 'total');
$col_status   = d_pick($pdo, 'orders', ['status'], 'status');
$col_num      = d_pick($pdo, 'orders', ['order_number','number','code','reference'], null);
$col_created  = d_pick($pdo, 'orders', ['created_at','created','date'], 'created_at');

/* ============================================================
   KPIs — só COUNT e SUM seguros
   ============================================================ */
$stats = [
    'farmers'  => d_count($pdo, 'SELECT COUNT(*) FROM farmers'),
    'buyers'   => d_count($pdo, 'SELECT COUNT(*) FROM buyers'),
    'products' => d_count($pdo, 'SELECT COUNT(*) FROM products'),
    'orders'   => d_count($pdo, 'SELECT COUNT(*) FROM orders'),
    // Receita = pagamentos efetivamente pagos de pedidos não cancelados.
    'revenue'  => (float)d_value($pdo, "SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.status='paid' AND o.`$col_status` <> 'cancelled'", 0),
    'paid_orders' => d_count($pdo, "SELECT COUNT(*) FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.status='paid' AND o.`$col_status` <> 'cancelled'"),
    'volume'   => (float)d_value($pdo, "SELECT COALESCE(SUM(`$col_total`),0) FROM orders WHERE `$col_status` <> 'cancelled'", 0),
    'pending'  => d_count($pdo, "SELECT COUNT(*) FROM orders WHERE `$col_status` = 'pending'"),
    'today'    => d_count($pdo, "SELECT COUNT(*) FROM orders WHERE DATE(`$col_created`) = CURDATE()"),
    'verif_farmers' => d_count($pdo, "SELECT COUNT(*) FROM farmers WHERE verification_status = 'pending'"),
    'verif_docs'    => d_count($pdo, "SELECT COUNT(*) FROM farmer_documents WHERE status = 'pending'"),
    'complaints'    => d_count($pdo, "SELECT COUNT(*) FROM complaints WHERE status IN ('open','in_review')"),
];
$stats['verifications'] = $stats['verif_farmers'] + $stats['verif_docs'];

/* ============================================================
   RECEITA RECEBIDA — últimos 7 dias (uma só query agrupada)
   ============================================================ */
$byDay = [];
foreach (d_rows($pdo, "SELECT DATE(COALESCE(p.paid_at, p.created_at)) d, COALESCE(SUM(p.amount),0) v
                       FROM payments p JOIN orders o ON o.id = p.order_id
                       WHERE p.status = 'paid' AND o.`$col_status` <> 'cancelled'
                         AND COALESCE(p.paid_at, p.created_at) >= CURDATE() - INTERVAL 6 DAY
                       GROUP BY d") as $r) {
    $byDay[$r['d']] = (float)$r['v'];
}
$diasCurtos = ['Mon'=>'Seg','Tue'=>'Ter','Wed'=>'Qua','Thu'=>'Qui','Fri'=>'Sex','Sat'=>'Sáb','Sun'=>'Dom'];
$chart = [];
$maxChart = 0;
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i day"));
    $val = $byDay[$day] ?? 0;
    $chart[] = [
        'label' => $diasCurtos[date('D', strtotime($day))] ?? date('d/m', strtotime($day)),
        'date'  => $day,
        'value' => $val,
    ];
    if ($val > $maxChart) $maxChart = $val;
}
$weekRevenue = array_sum(array_column($chart, 'value'));
$avgTicket   = $stats['paid_orders'] > 0 ? $stats['revenue'] / $stats['paid_orders'] : 0;

/* ============================================================
   PEDIDOS RECENTES
   ============================================================ */
$selectNum = $col_num ? "o.`$col_num` AS num" : "CONCAT('#', o.id) AS num";
$recent = d_rows($pdo, "
    SELECT o.id, $selectNum,
           o.`$col_total` AS total,
           o.`$col_status` AS status,
           COALESCE(u.name, '—') AS buyer_name
    FROM orders o
    LEFT JOIN buyers b ON b.id = o.buyer_id
    LEFT JOIN users u ON u.id = b.user_id
    ORDER BY o.`$col_created` DESC
    LIMIT 6
");

/* ============================================================
   SAUDAÇÃO
   ============================================================ */
$h = (int)date('H');
$greeting = $h < 12 ? 'Bom dia' : ($h < 19 ? 'Boa tarde' : 'Boa noite');
$months = [1=>'janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
$dias   = ['Sunday'=>'domingo','Monday'=>'segunda','Tuesday'=>'terça','Wednesday'=>'quarta','Thursday'=>'quinta','Friday'=>'sexta','Saturday'=>'sábado'];
$hoje   = ($dias[date('l')] ?? '') . ', ' . (int)date('d') . ' de ' . ($months[(int)date('n')] ?? '');

include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <!-- ================= HERO ================= -->
        <header class="sa-hero">
            <div class="sa-hero-content">
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Sistema ativo · <?= e($hoje) ?>
                </span>
                <h1 class="sa-hero-title">
                    <?= $greeting ?>, <em>SuperAdmin.</em>
                </h1>
                <p class="sa-hero-sub">
                    A plataforma está <strong>saudável</strong>.
                    <?= $stats['pending'] > 0
                        ? "Existem <strong>{$stats['pending']} pedidos</strong> pendentes."
                        : "Sem pedidos pendentes." ?>
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('admin/relatorios.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-bar-chart-line"></i> Relatórios
                </a>
                <a href="<?= base_path('admin/pedidos.php') ?>" class="btn btn-primary">
                    <i class="bi bi-receipt"></i> Ver pedidos
                    <?php if ($stats['pending'] > 0): ?>
                        <span class="sa-pill"><?= $stats['pending'] ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </header>

        <!-- ================= ALERTAS ================= -->
        <?php if ($stats['pending'] > 0 || $stats['verifications'] > 0 || $stats['complaints'] > 0): ?>
        <div class="sa-alerts">
            <?php if ($stats['pending'] > 0): ?>
            <a href="<?= base_path('admin/pedidos.php?status=pending') ?>" class="sa-alert sa-alert-info">
                <span class="sa-alert-icon"><i class="bi bi-clock-history"></i></span>
                <span class="sa-alert-body">
                    <strong><?= $stats['pending'] ?> pedidos</strong> aguardam processamento
                </span>
                <i class="bi bi-arrow-right sa-alert-arrow"></i>
            </a>
            <?php endif; ?>
            <?php if ($stats['verifications'] > 0): ?>
            <a href="<?= base_path('admin/verificacoes.php') ?>" class="sa-alert sa-alert-info">
                <span class="sa-alert-icon"><i class="bi bi-patch-check"></i></span>
                <span class="sa-alert-body">
                    <strong><?= $stats['verif_farmers'] ?> perfis</strong> e <strong><?= $stats['verif_docs'] ?> documentos</strong> aguardam verificação
                </span>
                <i class="bi bi-arrow-right sa-alert-arrow"></i>
            </a>
            <?php endif; ?>
            <?php if ($stats['complaints'] > 0): ?>
            <a href="<?= base_path('admin/denuncias.php') ?>" class="sa-alert sa-alert-info">
                <span class="sa-alert-icon"><i class="bi bi-flag"></i></span>
                <span class="sa-alert-body">
                    <strong><?= $stats['complaints'] ?> denúncias</strong> abertas ou em análise
                </span>
                <i class="bi bi-arrow-right sa-alert-arrow"></i>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ================= KPIs ================= -->
        <div class="sa-kpi-grid">

            <!-- Receita -->
            <article class="sa-kpi sa-kpi-hero">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-cash-stack"></i></span>
                    <span class="sa-kpi-badge sa-badge-up">
                        <i class="bi bi-arrow-up-right"></i> <?= $stats['today'] ?> hoje
                    </span>
                </header>
                <span class="sa-kpi-label" title="Pagamentos com estado Pago, de pedidos não cancelados">Receita recebida (pagamentos pagos)</span>
                <strong class="sa-kpi-value"><?= money($stats['revenue']) ?></strong>

                <svg class="sa-spark" viewBox="0 0 200 50" preserveAspectRatio="none" aria-hidden="true">
                    <?php
                    $pts = [];
                    $n = count($chart);
                    foreach ($chart as $i => $d) {
                        $x = $n > 1 ? ($i / ($n - 1)) * 200 : 0;
                        $y = 50 - ($maxChart > 0 ? ($d['value'] / $maxChart) * 45 : 0) - 2;
                        $pts[] = round($x, 1) . ',' . round($y, 1);
                    }
                    $path = 'M' . implode(' L', $pts);
                    ?>
                    <defs>
                        <linearGradient id="sg" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="var(--green)" stop-opacity=".35"/>
                            <stop offset="100%" stop-color="var(--green)" stop-opacity="0"/>
                        </linearGradient>
                    </defs>
                    <path d="<?= $path ?> L200,50 L0,50 Z" fill="url(#sg)"/>
                    <path d="<?= $path ?>" fill="none" stroke="var(--green)" stroke-width="2"
                          stroke-linecap="round" stroke-linejoin="round"/>
                </svg>

                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-receipt"></i> <?= $stats['paid_orders'] ?> pagos de <?= $stats['orders'] ?> pedidos</span>
                    <span class="sa-kpi-sub"><i class="bi bi-wallet2"></i> Ticket <?= money($avgTicket) ?></span>
                </footer>
            </article>

            <!-- Utilizadores -->
            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-info"><i class="bi bi-people"></i></span>
                </header>
                <span class="sa-kpi-label">Utilizadores</span>
                <strong class="sa-kpi-value"><?= number_format($stats['farmers'] + $stats['buyers'], 0, ',', ' ') ?></strong>
                <div class="sa-mini-split">
                    <div>
                        <span class="sa-mini-label">Agricultores</span>
                        <strong class="sa-mini-value"><?= $stats['farmers'] ?></strong>
                    </div>
                    <div>
                        <span class="sa-mini-label">Compradores</span>
                        <strong class="sa-mini-value"><?= $stats['buyers'] ?></strong>
                    </div>
                </div>
            </article>

            <!-- Produtos -->
            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-basket2"></i></span>
                </header>
                <span class="sa-kpi-label">Produtos</span>
                <strong class="sa-kpi-value"><?= $stats['products'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-shop"></i> no catálogo</span>
                </footer>
            </article>

            <!-- Pendentes -->
            <article class="sa-kpi sa-kpi-warn">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-danger"><i class="bi bi-clock-history"></i></span>
                    <?php if ($stats['pending'] > 0): ?>
                        <span class="sa-kpi-badge sa-badge-warn">ação necessária</span>
                    <?php endif; ?>
                </header>
                <span class="sa-kpi-label">Pedidos pendentes</span>
                <strong class="sa-kpi-value"><?= $stats['pending'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-hourglass-split"></i> a aguardar</span>
                </footer>
            </article>

        </div>

        <!-- ================= CONTEÚDO PRINCIPAL ================= -->
        <div class="sa-main-grid">

            <div class="sa-col-main">

                <!-- Gráfico 7 dias -->
                <article class="content-card sa-card">
                    <div class="card-heading">
                        <div>
                            <h4>Receita recebida — últimos 7 dias</h4>
                            <p class="sa-card-sub">Pagamentos pagos, excluindo pedidos cancelados</p>
                        </div>
                        <span class="sa-chip">
                            <i class="bi bi-calendar3"></i>
                            <?= date('d/m', strtotime($chart[0]['date'])) ?>
                            –
                            <?= date('d/m', strtotime($chart[6]['date'])) ?>
                        </span>
                    </div>

                    <div class="sa-chart">
                        <?php foreach ($chart as $d): ?>
                            <?php $h = $maxChart > 0 ? max(6, round(($d['value'] / $maxChart) * 100)) : 6; ?>
                            <div class="sa-chart-col" title="<?= e($d['label']) ?>: <?= money($d['value']) ?>">
                                <span class="sa-chart-bar" style="height:<?= $h ?>%">
                                    <span class="sa-chart-tip"><?= money($d['value']) ?></span>
                                </span>
                                <span class="sa-chart-day"><?= e($d['label']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <footer class="sa-chart-foot">
                        <div>
                            <span class="sa-chart-total-label">Total da semana</span>
                            <strong class="sa-chart-total"><?= money($weekRevenue) ?></strong>
                        </div>
                        <div class="text-end">
                            <span class="sa-chart-total-label">Média diária</span>
                            <strong class="sa-chart-total"><?= money($weekRevenue / 7) ?></strong>
                        </div>
                    </footer>
                </article>

                <!-- Pedidos recentes -->
                <article class="content-card sa-card">
                    <div class="card-heading">
                        <div>
                            <h4>Pedidos recentes</h4>
                            <p class="sa-card-sub">Últimas transações na plataforma</p>
                        </div>
                        <a href="<?= base_path('admin/pedidos.php') ?>" class="sa-link-arrow">
                            Ver todos <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>

                    <?php if (empty($recent)): ?>
                        <div class="empty-state py-5">
                            <i class="bi bi-inbox" style="font-size:2.5rem;"></i>
                            <p class="mt-3 mb-0">Sem pedidos recentes.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle sa-table">
                                <thead>
                                    <tr>
                                        <th>Pedido</th>
                                        <th>Comprador</th>
                                        <th>Total</th>
                                        <th>Estado</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($recent as $o): ?>
                                    <tr>
                                        <td>
                                            <a href="<?= base_path('admin/pedido.php?id='.(int)$o['id']) ?>" class="sa-order-id text-decoration-none">
                                                <i class="bi bi-receipt"></i>
                                                <?= e($o['num']) ?>
                                            </a>
                                        </td>
                                        <td>
                                            <div class="sa-user-cell">
                                                <span class="sa-avatar">
                                                    <?= e(mb_strtoupper(mb_substr($o['buyer_name'] ?? '?', 0, 1))) ?>
                                                </span>
                                                <span><?= e($o['buyer_name']) ?></span>
                                            </div>
                                        </td>
                                        <td><strong class="sa-price"><?= money($o['total']) ?></strong></td>
                                        <td>
                                            <?php if (function_exists('order_status_badge')): ?>
                                                <?= order_status_badge($o['status']) ?>
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><?= e($o['status']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="<?= base_path('admin/pedido.php?id='.(int)$o['id']) ?>"
                                               class="sa-row-action" title="Ver detalhes">
                                                <i class="bi bi-arrow-right"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>

            </div>

            <aside class="sa-col-side">

                <!-- Ações rápidas -->
                <article class="content-card sa-card">
                    <div class="card-heading">
                        <div>
                            <h4>Ações rápidas</h4>
                            <p class="sa-card-sub">Atalhos frequentes</p>
                        </div>
                    </div>
                    <div class="sa-quick-actions">
                        <a href="<?= base_path('admin/utilizadores.php') ?>" class="sa-quick">
                            <span class="sa-quick-icon"><i class="bi bi-people"></i></span>
                            <span>
                                <strong>Utilizadores</strong>
                                <small>Gerir contas</small>
                            </span>
                            <i class="bi bi-chevron-right sa-quick-arrow"></i>
                        </a>
                        <a href="<?= base_path('admin/produtos.php') ?>" class="sa-quick">
                            <span class="sa-quick-icon"><i class="bi bi-box-seam"></i></span>
                            <span>
                                <strong>Produtos</strong>
                                <small>Catálogo</small>
                            </span>
                            <i class="bi bi-chevron-right sa-quick-arrow"></i>
                        </a>
                        <a href="<?= base_path('admin/pedidos.php') ?>" class="sa-quick">
                            <span class="sa-quick-icon"><i class="bi bi-receipt"></i></span>
                            <span>
                                <strong>Pedidos</strong>
                                <small>Gerir encomendas</small>
                            </span>
                            <i class="bi bi-chevron-right sa-quick-arrow"></i>
                        </a>
                        <a href="<?= base_path('admin/verificacoes.php') ?>" class="sa-quick">
                            <span class="sa-quick-icon"><i class="bi bi-shield-check"></i></span>
                            <span>
                                <strong>Verificações</strong>
                                <small><?= $stats['verifications'] > 0 ? $stats['verifications'] . ' pendentes' : 'Aprovar contas' ?></small>
                            </span>
                            <i class="bi bi-chevron-right sa-quick-arrow"></i>
                        </a>
                        <a href="<?= base_path('admin/denuncias.php') ?>" class="sa-quick">
                            <span class="sa-quick-icon"><i class="bi bi-flag"></i></span>
                            <span>
                                <strong>Denúncias</strong>
                                <small><?= $stats['complaints'] ?> abertas</small>
                            </span>
                            <i class="bi bi-chevron-right sa-quick-arrow"></i>
                        </a>
                        <a href="<?= base_path('admin/zonas.php') ?>" class="sa-quick">
                            <span class="sa-quick-icon"><i class="bi bi-truck"></i></span>
                            <span>
                                <strong>Zonas de entrega</strong>
                                <small>Taxas e prazos</small>
                            </span>
                            <i class="bi bi-chevron-right sa-quick-arrow"></i>
                        </a>
                        <a href="<?= base_path('admin/perfil.php') ?>" class="sa-quick">
                            <span class="sa-quick-icon"><i class="bi bi-person-gear"></i></span>
                            <span>
                                <strong>O meu perfil</strong>
                                <small>Dados e password</small>
                            </span>
                            <i class="bi bi-chevron-right sa-quick-arrow"></i>
                        </a>
                    </div>
                </article>

                <!-- Resumo -->
                <article class="content-card sa-card">
                    <div class="card-heading">
                        <div>
                            <h4>Resumo do dia</h4>
                            <p class="sa-card-sub">Hoje, <?= date('d/m/Y') ?></p>
                        </div>
                    </div>
                    <div class="sa-summary">
                        <div class="sa-summary-row">
                            <span><i class="bi bi-cart-check"></i> Pedidos hoje</span>
                            <strong><?= $stats['today'] ?></strong>
                        </div>
                        <div class="sa-summary-row">
                            <span><i class="bi bi-clock-history"></i> Pendentes</span>
                            <strong><?= $stats['pending'] ?></strong>
                        </div>
                        <div class="sa-summary-row">
                            <span><i class="bi bi-people"></i> Utilizadores</span>
                            <strong><?= $stats['farmers'] + $stats['buyers'] ?></strong>
                        </div>
                        <div class="sa-summary-row">
                            <span><i class="bi bi-basket2"></i> Produtos</span>
                            <strong><?= $stats['products'] ?></strong>
                        </div>
                        <div class="sa-summary-row">
                            <span><i class="bi bi-patch-check"></i> Verificações pendentes</span>
                            <strong><?= $stats['verifications'] ?></strong>
                        </div>
                        <div class="sa-summary-row">
                            <span><i class="bi bi-flag"></i> Denúncias abertas</span>
                            <strong><?= $stats['complaints'] ?></strong>
                        </div>
                        <div class="sa-summary-row">
                            <span><i class="bi bi-graph-up"></i> Volume (não cancelados)</span>
                            <strong><?= money($stats['volume']) ?></strong>
                        </div>
                    </div>
                </article>

            </aside>
        </div>

    </section>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
<?php include __DIR__.'/../includes/footer.php'; ?>
