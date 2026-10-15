<?php require_once __DIR__.'/../includes/permissions.php'; ?>
<?php
require_role('superadmin');
$pdo = db();

/* ============================================================
   AÇÃO (POST + CSRF): atualizar estado de verificação
   ============================================================ */
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    $status = $_POST['status'] ?? '';
    $id = safe_int($_POST['id'] ?? 0);
    $labels = ['verified' => 'Verificado', 'rejected' => 'Rejeitado', 'pending' => 'Pendente'];
    $u = $pdo->prepare('SELECT f.user_id, u.name FROM farmers f JOIN users u ON u.id=f.user_id WHERE f.id=?');
    $u->execute([$id]);
    $farmer = $u->fetch();
    if (!$farmer || !isset($labels[$status])) {
        flash('danger', 'Agricultor ou estado inválido.');
    } else {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE farmers SET verification_status=?, verified_at=IF(?="verified",NOW(),NULL) WHERE id=?')->execute([$status, $status, $id]);
        $pdo->prepare('INSERT INTO farmer_verifications(farmer_id,status,notes,reviewed_by) VALUES(?,?,?,?)')->execute([$id, $status, 'Atualizado pelo SuperAdmin', user()['id']]);
        $pdo->commit();
        notify((int)$farmer['user_id'], 'Estado de verificação atualizado', 'O estado do seu perfil é agora: ' . $labels[$status] . '.');
        audit(user()['id'], 'update', 'verification', 'Agricultor #' . $id . ' (' . $farmer['name'] . ') marcado como ' . $status);
        flash('success', 'Estado do agricultor atualizado.');
    }
    $back = (string)($_POST['back'] ?? '');
    redirect(base_path('admin/agricultores.php') . ($back !== '' && preg_match('/^[A-Za-z0-9_=&%.+-]+$/', $back) ? '?' . $back : ''));
}

/* ============================================================
   FILTROS + PAGINAÇÃO (server-side)
   ============================================================ */
$statusLabels = ['verified' => 'Verificado', 'pending' => 'Pendente', 'unverified' => 'Não verificado', 'rejected' => 'Rejeitado'];
$filter = (string)($_GET['estado'] ?? 'all');
if ($filter !== 'all' && !isset($statusLabels[$filter])) $filter = 'all';
$q = trim((string)($_GET['q'] ?? ''));
$where = []; $params = [];
if ($filter !== 'all') { $where[] = 'f.verification_status=?'; $params[] = $filter; }
if ($q !== '') { $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)'; $like = '%' . $q . '%'; array_push($params, $like, $like, $like); }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$perPage = 20;
[$page, $offset] = page_offset($perPage);
$c = $pdo->prepare('SELECT COUNT(*) FROM farmers f JOIN users u ON u.id=f.user_id' . $whereSql);
$c->execute($params);
$totalFiltered = (int)$c->fetchColumn();

$s = $pdo->prepare('
    SELECT f.*, u.name, u.email, u.phone,
           (SELECT COUNT(*) FROM products p WHERE p.farmer_id=f.id) products_count
    FROM farmers f
    JOIN users u ON u.id = f.user_id' . $whereSql . '
    ORDER BY f.created_at DESC
    LIMIT ' . $perPage . ' OFFSET ' . $offset);
$s->execute($params);
$farmers = $s->fetchAll();

/* ============================================================
   ESTATÍSTICAS (sobre todos os agricultores)
   ============================================================ */
$counts = ['total' => 0, 'verified' => 0, 'pending' => 0, 'unverified' => 0, 'rejected' => 0];
foreach ($pdo->query('SELECT verification_status, COUNT(*) n FROM farmers GROUP BY verification_status') as $row) {
    if (isset($counts[$row['verification_status']])) $counts[$row['verification_status']] = (int)$row['n'];
    $counts['total'] += (int)$row['n'];
}
$backQuery = http_build_query(array_filter(['estado' => $filter !== 'all' ? $filter : null, 'q' => $q !== '' ? $q : null, 'page' => $page > 1 ? $page : null]));

$page_title = 'Agricultores';
include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <!-- ================= HERO ================= -->
        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Utilizadores · Agricultores
                </span>
                <h1 class="sa-hero-title">Gestão de <em>agricultores</em></h1>
                <p class="sa-hero-sub">
                    <?php if ($counts['pending'] > 0): ?>
                        Existem <strong><?= $counts['pending'] ?></strong> contas a aguardar verificação.
                    <?php else: ?>
                        Todas as contas estão processadas.
                    <?php endif; ?>
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('admin/dashboard.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <a href="<?= base_path('admin/verificacoes.php') ?>" class="btn btn-primary">
                    <i class="bi bi-patch-check"></i> Verificações
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
                    <span class="sa-kpi-sub"><i class="bi bi-shop"></i> agricultores registados</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-patch-check"></i></span>
                </header>
                <span class="sa-kpi-label">Verificados</span>
                <strong class="sa-kpi-value"><?= $counts['verified'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-check2-circle"></i> aprovados</span>
                </footer>
            </article>

            <article class="sa-kpi sa-kpi-warn">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-warning"><i class="bi bi-clock-history"></i></span>
                    <?php if ($counts['pending'] > 0): ?>
                        <span class="sa-kpi-badge sa-badge-warn">ação necessária</span>
                    <?php endif; ?>
                </header>
                <span class="sa-kpi-label">Pendentes</span>
                <strong class="sa-kpi-value"><?= $counts['pending'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-hourglass-split"></i> a aguardar revisão · <?= $counts['unverified'] ?> não verificados</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-danger"><i class="bi bi-x-octagon"></i></span>
                </header>
                <span class="sa-kpi-label">Rejeitados</span>
                <strong class="sa-kpi-value"><?= $counts['rejected'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-slash-circle"></i> contas rejeitadas</span>
                </footer>
            </article>

        </div>

        <!-- ================= TABELA ================= -->
        <article class="content-card sa-card">

            <div class="card-heading sa-card-head">
                <div>
                    <h4>Lista de agricultores</h4>
                    <p class="sa-card-sub"><?= $totalFiltered ?> registos</p>
                </div>

                <div class="sa-toolbar">
                    <form method="get" class="sa-search" role="search">
                        <i class="bi bi-search"></i>
                        <?php if ($filter !== 'all'): ?><input type="hidden" name="estado" value="<?= e($filter) ?>"><?php endif; ?>
                        <input type="text" name="q" value="<?= e($q) ?>" aria-label="Procurar agricultor" placeholder="Procurar por nome, email ou telefone…">
                    </form>

                    <div class="sa-filters">
                        <?php foreach (['all' => 'Todos', 'verified' => 'Verificados', 'pending' => 'Pendentes', 'unverified' => 'Não verificados', 'rejected' => 'Rejeitados'] as $key => $label): ?>
                            <a class="sa-filter text-decoration-none<?= $filter === $key ? ' active' : '' ?>" href="?<?= e(http_build_query(array_filter(['estado' => $key !== 'all' ? $key : null, 'q' => $q !== '' ? $q : null]))) ?>"><?= $label ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php if (empty($farmers)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-people" style="font-size:2.5rem;"></i>
                    <p class="mt-3 mb-0"><?= ($q !== '' || $filter !== 'all') ? 'Nenhum resultado encontrado.' : 'Sem agricultores registados.' ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive sa-table-wrap">
                    <table class="table align-middle sa-table" id="farmersTable">
                        <thead>
                            <tr>
                                <th>Agricultor</th>
                                <th>Contacto</th>
                                <th>Localização</th>
                                <th class="text-center">Produtos</th>
                                <th>Estado</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($farmers as $f):
                            $st = $f['verification_status'] ?? 'pending';
                            $badgeClass = ['verified' => 'success', 'pending' => 'warning', 'unverified' => 'secondary', 'rejected' => 'danger'][$st] ?? 'secondary';
                            $badgeIcon  = ['verified' => 'patch-check-fill', 'pending' => 'hourglass-split', 'unverified' => 'question-circle', 'rejected' => 'x-octagon-fill'][$st] ?? 'circle';
                            $badgeLabel = $statusLabels[$st] ?? ucfirst($st);
                        ?>
                            <tr data-status="<?= e($st) ?>">
                                <td>
                                    <div class="sa-user-cell">
                                        <span class="sa-avatar">
                                            <?= e(mb_strtoupper(mb_substr($f['name'] ?? '?', 0, 1))) ?>
                                        </span>
                                        <div class="sa-user-info">
                                            <strong><a href="<?= base_path('agricultor.php?id=' . (int)$f['id']) ?>" target="_blank" rel="noopener" title="Ver perfil público"><?= e($f['name']) ?> <i class="bi bi-box-arrow-up-right small"></i></a></strong>
                                            <small>Desde <?= date('m/Y', strtotime($f['created_at'] ?? 'now')) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="sa-contact">
                                        <span><i class="bi bi-envelope"></i> <?= e($f['email']) ?></span>
                                        <?php if (!empty($f['phone'])): ?>
                                            <span><i class="bi bi-telephone"></i> <?= e($f['phone']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="sa-location">
                                        <i class="bi bi-geo-alt"></i>
                                        <?= e($f['district'] ?: ($f['province'] ?: '—')) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="sa-count"><?= (int)$f['products_count'] ?></span>
                                </td>
                                <td>
                                    <span class="sa-badge sa-badge-<?= $badgeClass ?>">
                                        <i class="bi bi-<?= $badgeIcon ?>"></i>
                                        <?= e($badgeLabel) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="sa-actions">
                                        <?php if ($st !== 'verified'): ?>
                                            <?= post_button('', ['id' => (int)$f['id'], 'status' => 'verified', 'back' => $backQuery], '<i class="bi bi-check-lg"></i> <span>Aprovar</span>', 'sa-btn sa-btn-success', null, 'Aprovar agricultor') ?>
                                        <?php endif; ?>

                                        <?php if ($st !== 'rejected'): ?>
                                            <?= post_button('', ['id' => (int)$f['id'], 'status' => 'rejected', 'back' => $backQuery], '<i class="bi bi-x-lg"></i> <span>Rejeitar</span>', 'sa-btn sa-btn-danger', 'Rejeitar ' . $f['name'] . '? Esta ação pode ser revertida.', 'Rejeitar agricultor') ?>
                                        <?php endif; ?>

                                        <?php if ($st === 'rejected'): ?>
                                            <?= post_button('', ['id' => (int)$f['id'], 'status' => 'pending', 'back' => $backQuery], '<i class="bi bi-arrow-counterclockwise"></i> <span>Reativar</span>', 'sa-btn sa-btn-ghost', null, 'Reativar como pendente') ?>
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
