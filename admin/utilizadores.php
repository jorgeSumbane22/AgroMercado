<?php
require_once __DIR__.'/../includes/permissions.php';
require_role('superadmin');
require_once __DIR__.'/_helpers.php';

$page_title = 'Utilizadores';
$pdo = db();
$roleLabels = ['superadmin' => 'SuperAdmin', 'farmer' => 'Agricultor', 'buyer' => 'Comprador'];

/* ============================================================
   AÇÕES (POST + CSRF): bloquear / desbloquear / desativar / reset de password
   ============================================================ */
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    $action = $_POST['action'] ?? '';
    $uid = safe_int($_POST['id'] ?? 0);
    $map = ['block' => 'blocked', 'unblock' => 'active', 'activate' => 'active', 'deactivate' => 'inactive'];
    if (isset($map[$action])) {
        admin_set_user_status($uid, $map[$action]);
    } elseif ($action === 'reset') {
        $s = $pdo->prepare('SELECT id, name, email, status FROM users WHERE id=?');
        $s->execute([$uid]);
        $target = $s->fetch();
        if (!$target) {
            flash('danger', 'Utilizador não encontrado.');
        } else {
            // Mesmo mecanismo de recuperar-password.php: token aleatório, guardado como SHA-256, válido 60 minutos.
            $raw = bin2hex(random_bytes(32));
            $pdo->prepare('UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([(int)$target['id']]);
            $pdo->prepare('INSERT INTO password_resets(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 60 MINUTE))')->execute([(int)$target['id'], hash('sha256', $raw)]);
            $path = base_path('redefinir-password.php?token=' . $raw);
            $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
            $link = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $host . $path;
            if ((int)setting('mail_enabled', '0') === 1 && filter_var($target['email'], FILTER_VALIDATE_EMAIL)) {
                @mail($target['email'], 'AgroMercado — redefinição de password', 'Foi pedida a redefinição da sua password pela administração. Abra este link (válido 60 minutos): ' . $link, 'From: ' . setting('mail_from', 'no-reply@localhost'));
            }
            $_SESSION['admin_reset_link'] = ['name' => $target['name'], 'email' => $target['email'], 'link' => $link];
            audit(user()['id'], 'password_reset', 'users', 'Gerou link de redefinição de password para o utilizador #' . (int)$target['id'] . ' (' . $target['email'] . ')');
            flash('success', 'Link de redefinição gerado para ' . $target['name'] . ' (válido 60 minutos).' . ($target['status'] !== 'active' ? ' Atenção: a conta não está ativa.' : ''));
        }
    } else {
        flash('danger', 'Ação inválida.');
    }
    redirect(admin_self_url());
}
$resetInfo = $_SESSION['admin_reset_link'] ?? null;
unset($_SESSION['admin_reset_link']);

/* ============================================================
   FILTROS + PAGINAÇÃO
   ============================================================ */
$fRole = (string)($_GET['role'] ?? '');
if (!isset($roleLabels[$fRole])) $fRole = '';
$fStatus = (string)($_GET['status'] ?? '');
if (!in_array($fStatus, ['active', 'inactive', 'blocked'], true)) $fStatus = '';
$q = trim((string)($_GET['q'] ?? ''));
$where = []; $params = [];
if ($fRole !== '')   { $where[] = 'r.name=?'; $params[] = $fRole; }
if ($fStatus !== '') { $where[] = 'u.status=?'; $params[] = $fStatus; }
if ($q !== '')       { $where[] = '(u.name LIKE ? OR u.email LIKE ?)'; $like = '%' . $q . '%'; array_push($params, $like, $like); }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$fromSql = ' FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN farmers f ON f.user_id = u.id LEFT JOIN buyers b ON b.user_id = u.id';

$perPage = 20;
[$page, $offset] = page_offset($perPage);
$c = $pdo->prepare('SELECT COUNT(*)' . $fromSql . $whereSql);
$c->execute($params);
$totalFiltered = (int)$c->fetchColumn();
$s = $pdo->prepare('SELECT u.id, u.name, u.email, u.phone, u.status, u.created_at, r.name AS role, f.id AS farmer_id, b.id AS buyer_id' . $fromSql . $whereSql . ' ORDER BY u.created_at DESC, u.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$s->execute($params);
$users = $s->fetchAll();

$counts = $pdo->query('SELECT COUNT(*) total, COALESCE(SUM(status="active"),0) active, COALESCE(SUM(status="inactive"),0) inactive, COALESCE(SUM(status="blocked"),0) blocked FROM users')->fetch();
$counts = array_map('intval', $counts);
$hasFilters = $fRole !== '' || $fStatus !== '' || $q !== '';
$meId = (int)user()['id'];

include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Utilizadores · Contas
                </span>
                <h1 class="sa-hero-title">Todos os <em>utilizadores</em></h1>
                <p class="sa-hero-sub">
                    <strong><?= $counts['active'] ?></strong> ativos ·
                    <strong><?= $counts['inactive'] ?></strong> inativos ·
                    <strong><?= $counts['blocked'] ?></strong> bloqueados
                </p>
            </div>
            <div class="sa-hero-actions">
                <a href="<?= base_path('admin/dashboard.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <a href="<?= base_path('admin/agricultores.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-people"></i> Agricultores
                </a>
                <a href="<?= base_path('admin/compradores.php') ?>" class="btn btn-primary">
                    <i class="bi bi-bag-check"></i> Compradores
                </a>
            </div>
        </header>

        <?php if ($resetInfo): ?>
            <div class="alert alert-info" role="status">
                <i class="bi bi-key me-1"></i>
                <strong>Link de redefinição para <?= e($resetInfo['name']) ?> (<?= e($resetInfo['email']) ?>)</strong> — válido 60 minutos, mostrado apenas uma vez:
                <div class="input-group input-group-sm mt-2">
                    <input type="text" class="form-control" id="resetLink" value="<?= e($resetInfo['link']) ?>" readonly aria-label="Link de redefinição">
                    <button type="button" class="btn btn-outline-primary" data-copy-target="resetLink"><i class="bi bi-clipboard"></i> Copiar</button>
                </div>
            </div>
        <?php endif; ?>

        <div class="sa-kpi-grid sa-kpi-grid-4">
            <article class="sa-kpi">
                <header class="sa-kpi-head"><span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-person-lines-fill"></i></span></header>
                <span class="sa-kpi-label">Total</span>
                <strong class="sa-kpi-value"><?= $counts['total'] ?></strong>
                <footer class="sa-kpi-foot"><span class="sa-kpi-sub"><i class="bi bi-people"></i> contas registadas</span></footer>
            </article>
            <article class="sa-kpi">
                <header class="sa-kpi-head"><span class="sa-kpi-icon sa-icon-success"><i class="bi bi-check2-circle"></i></span></header>
                <span class="sa-kpi-label">Ativos</span>
                <strong class="sa-kpi-value"><?= $counts['active'] ?></strong>
                <footer class="sa-kpi-foot"><span class="sa-kpi-sub"><i class="bi bi-shield-check"></i> podem iniciar sessão</span></footer>
            </article>
            <article class="sa-kpi">
                <header class="sa-kpi-head"><span class="sa-kpi-icon sa-icon-warning"><i class="bi bi-pause-circle"></i></span></header>
                <span class="sa-kpi-label">Inativos</span>
                <strong class="sa-kpi-value"><?= $counts['inactive'] ?></strong>
                <footer class="sa-kpi-foot"><span class="sa-kpi-sub"><i class="bi bi-moon"></i> contas desativadas</span></footer>
            </article>
            <article class="sa-kpi <?= $counts['blocked'] > 0 ? 'sa-kpi-warn' : '' ?>">
                <header class="sa-kpi-head"><span class="sa-kpi-icon sa-icon-danger"><i class="bi bi-slash-circle"></i></span></header>
                <span class="sa-kpi-label">Bloqueados</span>
                <strong class="sa-kpi-value"><?= $counts['blocked'] ?></strong>
                <footer class="sa-kpi-foot"><span class="sa-kpi-sub"><i class="bi bi-lock"></i> acesso impedido</span></footer>
            </article>
        </div>

        <article class="content-card sa-card">
            <div class="card-heading sa-card-head">
                <div>
                    <h4>Lista de utilizadores</h4>
                    <p class="sa-card-sub"><?= $totalFiltered ?> registos</p>
                </div>
            </div>

            <form method="get" class="row g-2 align-items-end mb-3" role="search">
                <div class="col-md-5">
                    <label class="form-label small" for="uq">Nome ou email</label>
                    <input type="text" id="uq" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Procurar…">
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="urole">Perfil</label>
                    <select id="urole" name="role" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <?php foreach ($roleLabels as $k => $l): ?><option value="<?= $k ?>" <?= $fRole === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small" for="ustatus">Estado</label>
                    <select id="ustatus" name="status" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <?php foreach (['active', 'inactive', 'blocked'] as $k): ?><option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= admin_user_status_label($k) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-1">
                    <button class="btn btn-sm btn-primary flex-fill"><i class="bi bi-funnel"></i> Filtrar</button>
                    <?php if ($hasFilters): ?><a href="<?= base_path('admin/utilizadores.php') ?>" class="btn btn-sm btn-outline-secondary" title="Limpar filtros"><i class="bi bi-x-lg"></i></a><?php endif; ?>
                </div>
            </form>

            <?php if (empty($users)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-people" style="font-size:2.5rem;"></i>
                    <p class="mt-3 mb-0">Nenhum utilizador encontrado.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive sa-table-wrap">
                    <table class="table align-middle sa-table">
                        <thead>
                            <tr>
                                <th>Utilizador</th>
                                <th>Perfil</th>
                                <th>Contacto</th>
                                <th>Estado</th>
                                <th class="text-end">Registado</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $u):
                            $st = $u['status'];
                            $isMe = (int)$u['id'] === $meId;
                        ?>
                            <tr>
                                <td>
                                    <div class="sa-user-cell">
                                        <span class="sa-avatar"><?= e(mb_strtoupper(mb_substr($u['name'] ?? '?', 0, 1))) ?></span>
                                        <div class="sa-user-info">
                                            <strong><?= e($u['name']) ?><?= $isMe ? ' <span class="badge text-bg-secondary">você</span>' : '' ?></strong>
                                            <small>#<?= (int)$u['id'] ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="sa-badge sa-badge-<?= $u['role'] === 'superadmin' ? 'danger' : ($u['role'] === 'farmer' ? 'success' : 'info') ?>">
                                        <?= e($roleLabels[$u['role']] ?? $u['role']) ?>
                                    </span>
                                    <?php if ($u['farmer_id']): ?>
                                        <a href="<?= base_path('agricultor.php?id=' . (int)$u['farmer_id']) ?>" class="small d-block" target="_blank" rel="noopener">perfil público</a>
                                    <?php elseif ($u['buyer_id']): ?>
                                        <a href="<?= base_path('admin/pedidos.php?buyer=' . (int)$u['buyer_id']) ?>" class="small d-block">pedidos</a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="sa-contact">
                                        <span><i class="bi bi-envelope"></i> <?= e($u['email']) ?></span>
                                        <?php if (!empty($u['phone'])): ?><span><i class="bi bi-telephone"></i> <?= e($u['phone']) ?></span><?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="sa-badge sa-badge-<?= admin_user_status_class($st) ?>">
                                        <i class="bi bi-<?= admin_user_status_icon($st) ?>"></i>
                                        <?= admin_user_status_label($st) ?>
                                    </span>
                                </td>
                                <td class="text-end"><span class="sa-date"><?= date('d/m/Y', strtotime($u['created_at'])) ?></span></td>
                                <td class="text-end">
                                    <div class="sa-actions">
                                        <?php if ($isMe): ?>
                                            <a href="<?= base_path('admin/perfil.php') ?>" class="sa-btn sa-btn-ghost"><i class="bi bi-person-gear"></i> <span>O meu perfil</span></a>
                                        <?php else: ?>
                                            <?php if ($st === 'blocked'): ?>
                                                <?= post_button('', ['action' => 'unblock', 'id' => (int)$u['id']], '<i class="bi bi-unlock"></i> <span>Desbloquear</span>', 'sa-btn sa-btn-success', null, 'Desbloquear') ?>
                                            <?php else: ?>
                                                <?php if ($st === 'inactive'): ?>
                                                    <?= post_button('', ['action' => 'activate', 'id' => (int)$u['id']], '<i class="bi bi-play-circle"></i> <span>Ativar</span>', 'sa-btn sa-btn-success', null, 'Ativar conta') ?>
                                                <?php else: ?>
                                                    <?= post_button('', ['action' => 'deactivate', 'id' => (int)$u['id']], '<i class="bi bi-pause-circle"></i> <span>Desativar</span>', 'sa-btn sa-btn-ghost', 'Desativar a conta de ' . $u['name'] . '?', 'Desativar conta') ?>
                                                <?php endif; ?>
                                                <?= post_button('', ['action' => 'block', 'id' => (int)$u['id']], '<i class="bi bi-lock"></i> <span>Bloquear</span>', 'sa-btn sa-btn-danger', 'Bloquear ' . $u['name'] . '? Deixará de conseguir iniciar sessão.', 'Bloquear') ?>
                                            <?php endif; ?>
                                            <?= post_button('', ['action' => 'reset', 'id' => (int)$u['id']], '<i class="bi bi-key"></i> <span>Reset password</span>', 'sa-btn sa-btn-ghost', 'Gerar um link de redefinição de password para ' . $u['name'] . '? Links anteriores deixam de funcionar.', 'Enviar redefinição de password') ?>
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

<script>
document.querySelectorAll('[data-copy-target]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var el = document.getElementById(btn.dataset.copyTarget);
        if (!el) return;
        el.select();
        if (navigator.clipboard) navigator.clipboard.writeText(el.value); else document.execCommand('copy');
        btn.innerHTML = '<i class="bi bi-check2"></i> Copiado';
    });
});
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>
