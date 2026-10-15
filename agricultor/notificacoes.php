<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';

require_role('farmer');

$page_title = 'Notificações';
$pdo = db();

/* ============================================================
   AÇÃO: marcar como lida
   ============================================================ */
if (is_post() && isset($_POST['mark_read'])) {
    verify_csrf($_POST['csrf'] ?? '');
    $id = safe_int($_POST['mark_read'] ?? 0);
    if ($id > 0) {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')
            ->execute([$id, user()['id']]);
    } else {
        /* Marcar todas */
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')
            ->execute([user()['id']]);
    }
    flash('success', 'Notificações atualizadas.');
    redirect(base_path('agricultor/notificacoes.php'));
}

/* ============================================================
   LISTA DE NOTIFICAÇÕES
   ============================================================ */
$s = $pdo->prepare('
    SELECT *
    FROM notifications
    WHERE user_id = ?
    ORDER BY is_read ASC, created_at DESC
');
$s->execute([user()['id']]);
$notes = $s->fetchAll();

/* ============================================================
   ESTATÍSTICAS
   ============================================================ */
$counts = [
    'total'  => count($notes),
    'unread' => 0,
    'today'  => 0,
];
$today = date('Y-m-d');

foreach ($notes as $n) {
    if (empty($n['is_read'])) $counts['unread']++;
    if (substr((string)($n['created_at'] ?? ''), 0, 10) === $today) $counts['today']++;
}

/* ============================================================
   AGRUPAR POR DIA
   ============================================================ */
$grouped = [];
foreach ($notes as $n) {
    $day = substr((string)$n['created_at'], 0, 10);
    $grouped[$day][] = $n;
}

/* ============================================================
   HELPERS
   ============================================================ */
function day_label(string $day): string {
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    if ($day === $today) return 'Hoje';
    if ($day === $yesterday) return 'Ontem';
    return date('d \d\e M \d\e Y', strtotime($day));
}

function notif_meta(array $n): array {
    $title = strtolower((string)($n['title'] ?? ''));
    $body  = strtolower((string)($n['body'] ?? ''));
    $all   = $title . ' ' . $body;

    if (str_contains($all, 'pedido') || str_contains($all, 'encomenda')) {
        return ['primary', 'receipt',                 'Pedido'];
    }
    if (str_contains($all, 'pagamento') || str_contains($all, 'pago') || str_contains($all, 'm-pesa')) {
        return ['success', 'cash-coin',               'Pagamento'];
    }
    if (str_contains($all, 'stock') || str_contains($all, 'inventário')) {
        return ['warning', 'box-seam',                'Stock'];
    }
    if (str_contains($all, 'verific') || str_contains($all, 'aprov')) {
        return ['info',    'shield-check',            'Verificação'];
    }
    if (str_contains($all, 'cancel') || str_contains($all, 'rejeit') || str_contains($all, 'erro')) {
        return ['danger',  'exclamation-octagon',     'Alerta'];
    }
    if (str_contains($all, 'sistema') || str_contains($all, 'boas-vindas') || str_contains($all, 'conta')) {
        return ['info',    'info-circle',             'Sistema'];
    }
    return ['secondary', 'bell',                      'Geral'];
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
                    <span class="sa-dot"></span> Centro de alertas
                </span>
                <h1 class="sa-hero-title">As suas <em>notificações</em></h1>
                <p class="sa-hero-sub">
                    <?php if ($counts['unread'] > 0): ?>
                        Tem <strong><?= $counts['unread'] ?></strong> notificações não lidas.
                    <?php else: ?>
                        Está tudo em ordem. Sem notificações pendentes.
                    <?php endif; ?>
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('agricultor/dashboard.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>

                <?php if ($counts['unread'] > 0): ?>
                    <form method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="mark_read" value="0">
                        <button class="btn btn-primary" type="submit">
                            <i class="bi bi-check2-all"></i> Marcar todas como lidas
                        </button>
                    </form>
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
                    <span class="sa-kpi-sub"><i class="bi bi-clock-history"></i> nas últimas 24h</span>
                </footer>
            </article>

        </div>

        <!-- ================= FILTROS ================= -->
        <?php if (!empty($notes)): ?>
        <div class="sa-filters sa-filters-standalone" role="tablist">
            <button class="sa-filter active" data-filter="all">Todas (<?= $counts['total'] ?>)</button>
            <button class="sa-filter" data-filter="unread">Não lidas (<?= $counts['unread'] ?>)</button>
            <button class="sa-filter" data-filter="read">Lidas (<?= $counts['total'] - $counts['unread'] ?>)</button>
        </div>
        <?php endif; ?>

        <!-- ================= LISTA ================= -->
        <article class="content-card sa-card">

            <?php if (empty($notes)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-bell-slash" style="font-size:2.5rem;"></i>
                    <p class="mt-3 mb-0">Não tem notificações.</p>
                </div>
            <?php else: ?>
                <div class="sa-notif-list" id="notifList">
                    <?php foreach ($grouped as $day => $items): ?>
                        <div class="sa-notif-day">
                            <span class="sa-notif-day-label">
                                <i class="bi bi-calendar3"></i>
                                <?= e(day_label($day)) ?>
                            </span>
                        </div>

                        <?php foreach ($items as $n):
                            $isUnread = empty($n['is_read']);
                            [$typeCls, $typeIcon, $typeLabel] = notif_meta($n);
                        ?>
                            <div class="sa-notif <?= $isUnread ? 'is-unread' : '' ?>"
                                 data-read="<?= $isUnread ? 'unread' : 'read' ?>">
                                <span class="sa-notif-icon sa-notif-<?= $typeCls ?>">
                                    <i class="bi bi-<?= $typeIcon ?>"></i>
                                </span>

                                <div class="sa-notif-body">
                                    <div class="sa-notif-head">
                                        <strong><?= e($n['title']) ?></strong>
                                        <?php if ($isUnread): ?>
                                            <span class="sa-notif-dot"></span>
                                        <?php endif; ?>
                                    </div>
                                    <p><?= e($n['body']) ?></p>
                                    <small class="sa-notif-time">
                                        <i class="bi bi-clock"></i>
                                        <?= date('H:i', strtotime($n['created_at'])) ?>
                                    </small>
                                </div>

                                <?php if ($isUnread): ?>
                                    <form method="post" class="sa-notif-action">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="mark_read" value="<?= (int)$n['id'] ?>">
                                        <button type="submit"
                                                class="sa-notif-read"
                                                title="Marcar como lida">
                                            <i class="bi bi-check2"></i>
                                            <span>Lida</span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>

                <div id="noResults" class="empty-state py-5" style="display:none;">
                    <i class="bi bi-search" style="font-size:2rem;"></i>
                    <p class="mt-3 mb-0">Nenhuma notificação neste filtro.</p>
                </div>
            <?php endif; ?>

        </article>

    </section>
</div>

<script>
(function () {
    var list    = document.getElementById('notifList');
    var noRes   = document.getElementById('noResults');
    var filters = document.querySelectorAll('.sa-filter');
    if (!list) return;

    var items = Array.from(list.querySelectorAll('.sa-notif'));
    var currentFilter = 'all';

    function applyFilter() {
        var visible = 0;
        items.forEach(function (el) {
            var matches = currentFilter === 'all' || el.dataset.read === currentFilter;
            el.style.display = matches ? '' : 'none';
            if (matches) visible++;
        });

        /* Esconde os cabeçalhos de dia sem itens visíveis */
        list.querySelectorAll('.sa-notif-day').forEach(function (day) {
            var next = day.nextElementSibling;
            var hasVisible = false;
            while (next && !next.classList.contains('sa-notif-day')) {
                if (next.style.display !== 'none') { hasVisible = true; break; }
                next = next.nextElementSibling;
            }
            day.style.display = hasVisible ? '' : 'none';
        });

        if (noRes) noRes.style.display = visible === 0 ? '' : 'none';
    }

    filters.forEach(function (btn) {
        btn.addEventListener('click', function () {
            filters.forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            currentFilter = btn.dataset.filter;
            applyFilter();
        });
    });
})();
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>