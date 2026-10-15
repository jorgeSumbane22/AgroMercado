<?php require_once __DIR__.'/../includes/permissions.php'; ?>
<?php
require_role('superadmin');
require_once __DIR__.'/_helpers.php';
$pdo = db();

/* ============================================================
   AÇÕES (POST + CSRF): bloquear / desbloquear comprador
   ============================================================ */
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    $buyerId = safe_int($_POST['id'] ?? 0);
    $s = $pdo->prepare('SELECT user_id FROM buyers WHERE id=?');
    $s->execute([$buyerId]);
    $uid = (int)$s->fetchColumn();
    $action = $_POST['action'] ?? '';
    $map = ['block' => 'blocked', 'unblock' => 'active'];
    if (!$uid) flash('danger', 'Comprador não encontrado.');
    elseif (!isset($map[$action])) flash('danger', 'Ação inválida.');
    else admin_set_user_status($uid, $map[$action]);
    redirect(admin_self_url());
}

/* ============================================================
   FILTROS + PAGINAÇÃO
   ============================================================ */
$filter = (string)($_GET['estado'] ?? 'all');
$filters = ['all' => 'Todos', 'active' => 'Ativos', 'inactive' => 'Inativos', 'blocked' => 'Bloqueados', 'buyers' => 'Com pedidos'];
if (!isset($filters[$filter])) $filter = 'all';
$q = trim((string)($_GET['q'] ?? ''));
$where = []; $params = [];
if (in_array($filter, ['active', 'inactive', 'blocked'], true)) { $where[] = 'u.status=?'; $params[] = $filter; }
if ($filter === 'buyers') $where[] = 'EXISTS (SELECT 1 FROM orders o WHERE o.buyer_id=b.id)';
if ($q !== '') { $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)'; $like = '%' . $q . '%'; array_push($params, $like, $like, $like); }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$perPage = 20;
[$page, $offset] = page_offset($perPage);
$c = $pdo->prepare('SELECT COUNT(*) FROM buyers b JOIN users u ON u.id = b.user_id' . $whereSql);
$c->execute($params);
$totalFiltered = (int)$c->fetchColumn();

$s = $pdo->prepare('
    SELECT b.*, u.name, u.email, u.phone, u.status, u.created_at,
           (SELECT COUNT(*) FROM orders o WHERE o.buyer_id = b.id) AS orders_count,
           (SELECT COALESCE(SUM(o.total),0) FROM orders o
              WHERE o.buyer_id = b.id AND o.status <> "cancelled") AS total_spent
    FROM buyers b
    JOIN users u ON u.id = b.user_id' . $whereSql . '
    ORDER BY u.created_at DESC, b.id DESC
    LIMIT ' . $perPage . ' OFFSET ' . $offset);
$s->execute($params);
$buyers = $s->fetchAll();

/* ============================================================
   ESTATÍSTICAS (todos os compradores)
   ============================================================ */
$counts = $pdo->query('SELECT COUNT(*) total, COALESCE(SUM(u.status="active"),0) active, COALESCE(SUM(u.status="inactive"),0) inactive, COALESCE(SUM(u.status="blocked"),0) blocked,
    COALESCE(SUM(EXISTS(SELECT 1 FROM orders o WHERE o.buyer_id=b.id)),0) with_orders FROM buyers b JOIN users u ON u.id=b.user_id')->fetch();
$counts = array_map('intval', $counts);
$counts['revenue'] = (float)$pdo->query('SELECT COALESCE(SUM(total),0) FROM orders WHERE status <> "cancelled"')->fetchColumn();

$page_title = 'Compradores';
include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <!-- ================= HERO ================= -->
        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Utilizadores · Compradores
                </span>
                <h1 class="sa-hero-title">Gestão de <em>compradores</em></h1>
                <p class="sa-hero-sub">
                    <?php if ($counts['with_orders'] > 0): ?>
                        <strong><?= $counts['with_orders'] ?></strong> compradores já fizeram pelo menos uma compra.
                    <?php else: ?>
                        Ainda sem atividade de compra registada.
                    <?php endif; ?>
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('admin/dashboard.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <a href="<?= base_path('admin/pedidos.php') ?>" class="btn btn-primary">
                    <i class="bi bi-receipt"></i> Ver pedidos
                </a>
            </div>
        </header>

        <!-- ================= KPIs ================= -->
        <div class="sa-kpi-grid sa-kpi-grid-4">

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-people"></i></span>
                </header>
                <span class="sa-kpi-label">Total</span>
                <strong class="sa-kpi-value"><?= $counts['total'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-person-check"></i> compradores registados</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-check2-circle"></i></span>
                </header>
                <span class="sa-kpi-label">Ativos</span>
                <strong class="sa-kpi-value"><?= $counts['active'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-shield-check"></i> <?= $counts['blocked'] ?> bloqueados · <?= $counts['inactive'] ?> inativos</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-info"><i class="bi bi-bag-check"></i></span>
                </header>
                <span class="sa-kpi-label">Com pedidos</span>
                <strong class="sa-kpi-value"><?= $counts['with_orders'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-cart-check"></i> já compraram</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-cash-stack"></i></span>
                </header>
                <span class="sa-kpi-label">Receita gerada</span>
                <strong class="sa-kpi-value"><?= money($counts['revenue']) ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-graph-up-arrow"></i> pedidos não cancelados</span>
                </footer>
            </article>

        </div>

        <!-- ================= TABELA ================= -->
        <article class="content-card sa-card">

            <div class="card-heading sa-card-head">
                <div>
                    <h4>Lista de compradores</h4>
                    <p class="sa-card-sub"><?= $totalFiltered ?> registos</p>
                </div>

                <div class="sa-toolbar">
                    <form method="get" class="sa-search" role="search">
                        <i class="bi bi-search"></i>
                        <?php if ($filter !== 'all'): ?><input type="hidden" name="estado" value="<?= e($filter) ?>"><?php endif; ?>
                        <input type="text" name="q" value="<?= e($q) ?>" aria-label="Procurar comprador" placeholder="Procurar por nome, email ou telefone…">
                    </form>

                    <div class="sa-filters">
                        <?php foreach ($filters as $key => $label): ?>
                            <a class="sa-filter text-decoration-none<?= $filter === $key ? ' active' : '' ?>" href="?<?= e(http_build_query(array_filter(['estado' => $key !== 'all' ? $key : null, 'q' => $q !== '' ? $q : null]))) ?>"><?= $label ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php if (empty($buyers)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-people" style="font-size:2.5rem;"></i>
                    <p class="mt-3 mb-0"><?= ($q !== '' || $filter !== 'all') ? 'Nenhum resultado encontrado.' : 'Sem compradores registados.' ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive sa-table-wrap">
                    <table class="table align-middle sa-table" id="buyersTable">
                        <thead>
                            <tr>
                                <th>Comprador</th>
                                <th>Contacto</th>
                                <th class="text-center">Pedidos</th>
                                <th class="text-end">Total gasto</th>
                                <th>Estado</th>
                                <th class="text-end">Registado</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($buyers as $b):
                            $st = $b['status'] ?? 'active';
                            $ordersCount = (int)$b['orders_count'];
                        ?>
                            <tr data-status="<?= e($st) ?>">
                                <td>
                                    <div class="sa-user-cell">
                                        <span class="sa-avatar">
                                            <?= e(mb_strtoupper(mb_substr($b['name'] ?? '?', 0, 1))) ?>
                                        </span>
                                        <div class="sa-user-info">
                                            <strong><?= e($b['name']) ?></strong>
                                            <small><?= $ordersCount > 0 ? 'Comprador ativo' : 'Novo comprador' ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="sa-contact">
                                        <span><i class="bi bi-envelope"></i> <?= e($b['email']) ?></span>
                                        <?php if (!empty($b['phone'])): ?>
                                            <span><i class="bi bi-telephone"></i> <?= e($b['phone']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <?php if ($ordersCount > 0): ?>
                                        <a href="<?= base_path('admin/pedidos.php?buyer=' . (int)$b['id']) ?>" class="sa-count sa-count-primary text-decoration-none" title="Ver pedidos deste comprador"><?= $ordersCount ?></a>
                                    <?php else: ?>
                                        <span class="sa-count">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <strong class="sa-price"><?= money($b['total_spent']) ?></strong>
                                </td>
                                <td>
                                    <span class="sa-badge sa-badge-<?= admin_user_status_class($st) ?>">
                                        <i class="bi bi-<?= admin_user_status_icon($st) ?>"></i>
                                        <?= admin_user_status_label($st) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <span class="sa-date">
                                        <?= date('d/m/Y', strtotime($b['created_at'] ?? 'now')) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="sa-actions">
                                        <a href="<?= base_path('admin/pedidos.php?buyer=' . (int)$b['id']) ?>" class="sa-btn sa-btn-ghost" title="Ver pedidos">
                                            <i class="bi bi-receipt"></i> <span>Pedidos</span>
                                        </a>
                                        <?php if ($st === 'blocked'): ?>
                                            <?= post_button('', ['action' => 'unblock', 'id' => (int)$b['id']], '<i class="bi bi-unlock"></i> <span>Desbloquear</span>', 'sa-btn sa-btn-success', null, 'Desbloquear comprador') ?>
                                        <?php else: ?>
                                            <?= post_button('', ['action' => 'block', 'id' => (int)$b['id']], '<i class="bi bi-lock"></i> <span>Bloquear</span>', 'sa-btn sa-btn-danger', 'Bloquear ' . $b['name'] . '? Deixará de conseguir iniciar sessão.', 'Bloquear comprador') ?>
                                            <?php if ($st === 'inactive'): ?>
                                                <?= post_button('', ['action' => 'unblock', 'id' => (int)$b['id']], '<i class="bi bi-play-circle"></i> <span>Reativar</span>', 'sa-btn sa-btn-success', null, 'Reativar comprador') ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
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
