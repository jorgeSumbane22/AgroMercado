<?php
require_once __DIR__.'/../includes/permissions.php';
require_role('superadmin');
require_once __DIR__.'/_helpers.php';

$page_title = 'Notificações';
$pdo = db();
$uid = (int)user()['id'];

/* ============================================================
   AÇÃO: marcar uma / todas como lidas
   ============================================================ */
if (is_post() && isset($_POST['mark_read'])) {
    verify_csrf($_POST['csrf'] ?? '');
    $id = safe_int($_POST['mark_read'] ?? 0);
    if ($id > 0) {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
        flash('success', 'Notificação marcada como lida.');
    } else {
        $s = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        $s->execute([$uid]);
        flash('success', $s->rowCount() . ' notificação(ões) marcada(s) como lida(s).');
    }
    redirect(admin_self_url());
}

/* ============================================================
   FILTRO + PAGINAÇÃO (não lidas primeiro)
   ============================================================ */
$filters = ['all' => 'Todas', 'unread' => 'Não lidas', 'read' => 'Lidas'];
$filter = (string)($_GET['f'] ?? 'all');
if (!isset($filters[$filter])) $filter = 'all';
$where = ['all' => '', 'unread' => ' AND is_read = 0', 'read' => ' AND is_read = 1'][$filter];

$c = $pdo->prepare('SELECT COUNT(*) total, COALESCE(SUM(is_read = 0), 0) unread, COALESCE(SUM(DATE(created_at) = CURDATE()), 0) today FROM notifications WHERE user_id = ?');
$c->execute([$uid]);
$counts = array_map('intval', $c->fetch());
$counts['read'] = $counts['total'] - $counts['unread'];

$perPage = 20;
[$page, $offset] = page_offset($perPage);
$totalFiltered = $counts[$filter === 'all' ? 'total' : $filter];

$s = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ?$where ORDER BY is_read ASC, created_at DESC, id DESC LIMIT $perPage OFFSET $offset");
$s->execute([$uid]);
$notes = $s->fetchAll();

/* ============================================================
   HELPERS
   ============================================================ */
function admin_notif_meta(array $n): array {
    $all = mb_strtolower((string)($n['title'] ?? '') . ' ' . (string)($n['body'] ?? ''));
    if (str_contains($all, 'document') || str_contains($all, 'validade')) return ['warning', 'file-earmark-text', 'Documentos'];
    if (str_contains($all, 'denúncia') || str_contains($all, 'denuncia')) return ['danger', 'flag', 'Denúncia'];
    if (str_contains($all, 'pedido') || str_contains($all, 'encomenda')) return ['primary', 'receipt', 'Pedido'];
    if (str_contains($all, 'pagamento') || str_contains($all, 'reembolso') || str_contains($all, 'repasse')) return ['success', 'cash-coin', 'Pagamento'];
    if (str_contains($all, 'verific') || str_contains($all, 'agricultor')) return ['info', 'shield-check', 'Verificação'];
    if (str_contains($all, 'stock')) return ['warning', 'box-seam', 'Stock'];
    return ['secondary', 'bell', 'Geral'];
}
function admin_notif_day(string $day): string {
    if ($day === date('Y-m-d')) return 'Hoje';
    if ($day === date('Y-m-d', strtotime('-1 day'))) return 'Ontem';
    return date('d/m/Y', strtotime($day));
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
                    <span class="sa-dot"></span> Centro de alertas · SuperAdmin
                </span>
                <h1 class="sa-hero-title">As suas <em>notificações</em></h1>
                <p class="sa-hero-sub">
                    <?php if ($counts['unread'] > 0): ?>
                        Tem <strong><?= $counts['unread'] ?></strong> notificação(ões) não lida(s).
                    <?php else: ?>
                        Está tudo em ordem. Sem notificações por ler.
                    <?php endif; ?>
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('admin/dashboard.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <?php if ($counts['unread'] > 0): ?>
                    <?= post_button('', ['mark_read' => 0], '<i class="bi bi-check2-all"></i> Marcar todas como lidas', 'btn btn-primary') ?>
                <?php endif; ?>
            </div>
        </header>

        <!-- ================= KPIs ================= -->
        <div class="sa-kpi-grid sa-kpi-grid-3">
            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-bell"></i></span>
                </header>
                <span class="sa-kpi-label">Total</span>
                <strong class="sa-kpi-value"><?= $counts['total'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-inbox"></i> notificações recebidas</span>
                </footer>
            </article>

            <article class="sa-kpi <?= $counts['unread'] > 0 ? 'sa-kpi-warn' : '' ?>">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-warning"><i class="bi bi-bell-fill"></i></span>
                    <?php if ($counts['unread'] > 0): ?>
                        <span class="sa-kpi-badge sa-badge-warn">por ler</span>
                    <?php endif; ?>
                </header>
                <span class="sa-kpi-label">Não lidas</span>
                <strong class="sa-kpi-value"><?= $counts['unread'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-envelope-open"></i> a aguardar atenção</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-info"><i class="bi bi-calendar-check"></i></span>
                </header>
                <span class="sa-kpi-label">Hoje</span>
                <strong class="sa-kpi-value"><?= $counts['today'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-clock-history"></i> recebidas hoje</span>
                </footer>
            </article>
        </div>

        <!-- ================= FILTROS ================= -->
        <nav class="sa-filters sa-filters-standalone" aria-label="Filtrar notificações">
            <?php foreach ($filters as $k => $label): ?>
                <a class="sa-filter<?= $k === $filter ? ' active' : '' ?>" <?= $k === $filter ? 'aria-current="page"' : '' ?>
                   href="?f=<?= e($k) ?>"><?= e($label) ?> (<?= $counts[$k === 'all' ? 'total' : $k] ?>)</a>
            <?php endforeach; ?>
        </nav>

        <!-- ================= LISTA ================= -->
        <article class="content-card sa-card">
            <?php if (empty($notes)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-bell-slash" style="font-size:2.5rem;"></i>
                    <p class="mt-3 mb-0"><?= $filter === 'all' ? 'Não tem notificações.' : 'Nenhuma notificação neste filtro.' ?></p>
                </div>
            <?php else: ?>
                <div class="sa-notif-list">
                    <?php $lastDay = null;
                    foreach ($notes as $n):
                        $isUnread = empty($n['is_read']);
                        $day = substr((string)$n['created_at'], 0, 10);
                        // Não lidas aparecem primeiro, num bloco próprio; depois as lidas agrupadas por dia.
                        $groupKey = $isUnread ? 'unread' : $day;
                        if ($groupKey !== $lastDay):
                            $lastDay = $groupKey; ?>
                            <div class="sa-notif-day">
                                <span class="sa-notif-day-label">
                                    <i class="bi bi-<?= $isUnread ? 'bell-fill' : 'calendar3' ?>"></i>
                                    <?= $isUnread ? 'Por ler' : e(admin_notif_day($day)) ?>
                                </span>
                            </div>
                        <?php endif;
                        [$typeCls, $typeIcon, $typeLabel] = admin_notif_meta($n); ?>
                        <div class="sa-notif <?= $isUnread ? 'is-unread' : '' ?>">
                            <span class="sa-notif-icon sa-notif-<?= $typeCls ?>" title="<?= e($typeLabel) ?>">
                                <i class="bi bi-<?= $typeIcon ?>"></i>
                            </span>
                            <div class="sa-notif-body">
                                <div class="sa-notif-head">
                                    <strong><?= e($n['title']) ?></strong>
                                    <?php if ($isUnread): ?>
                                        <span class="sa-notif-dot" aria-label="Não lida"></span>
                                    <?php endif; ?>
                                </div>
                                <p><?= nl2br(e($n['body'])) ?></p>
                                <small class="sa-notif-time">
                                    <i class="bi bi-clock"></i>
                                    <?= date('d/m/Y H:i', strtotime($n['created_at'])) ?>
                                </small>
                            </div>
                            <?php if ($isUnread): ?>
                                <form method="post" class="sa-notif-action">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="mark_read" value="<?= (int)$n['id'] ?>">
                                    <button type="submit" class="sa-notif-read" title="Marcar como lida" aria-label="Marcar «<?= e($n['title']) ?>» como lida">
                                        <i class="bi bi-check2"></i>
                                        <span>Lida</span>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-4">
                    <?= pagination($page, $perPage, $totalFiltered) ?>
                </div>
            <?php endif; ?>
        </article>

    </section>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
