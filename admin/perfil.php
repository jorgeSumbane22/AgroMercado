<?php
require_once __DIR__.'/../includes/permissions.php';
require_role('superadmin');

$page_title = 'O meu perfil';
$pdo = db();

/* ============================================================
   AÇÕES (POST + CSRF): dados pessoais / password
   ============================================================ */
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'profile') {
            $name  = trim((string)($_POST['name'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('Indique o nome (máx. 120 caracteres).');
            if ($phone !== '' && !preg_match('/^\+?[0-9 ]{7,20}$/', $phone)) throw new RuntimeException('Telefone inválido. Use apenas números, espaços e o prefixo +.');
            $pdo->prepare('UPDATE users SET name=?, phone=?, updated_at=NOW() WHERE id=?')->execute([$name, $phone !== '' ? $phone : null, current_user_id()]);
            audit(current_user_id(), 'update', 'users', 'SuperAdmin atualizou os seus dados pessoais');
            flash('success', 'Perfil atualizado.');
        } elseif ($action === 'password') {
            $current = (string)($_POST['current_password'] ?? '');
            $new     = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');
            $s = $pdo->prepare('SELECT password FROM users WHERE id = ?');
            $s->execute([current_user_id()]);
            if (!password_verify($current, (string)$s->fetchColumn())) throw new RuntimeException('A password atual está incorreta.');
            if (!strong_password($new)) throw new RuntimeException('A nova password deve ter pelo menos 10 caracteres, com maiúsculas, minúsculas e números.');
            if (!hash_equals($new, $confirm)) throw new RuntimeException('A confirmação não coincide com a nova password.');
            if (hash_equals($current, $new)) throw new RuntimeException('A nova password deve ser diferente da atual.');
            $pdo->prepare('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), current_user_id()]);
            revoke_remember_tokens(current_user_id());
            $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([current_user_id()]);
            session_regenerate_id(true);
            audit(current_user_id(), 'password_change', 'users', 'SuperAdmin alterou a password');
            flash('success', 'Password alterada com sucesso.');
        } else {
            throw new RuntimeException('Ação inválida.');
        }
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect(base_path('admin/perfil.php'));
}

$me = user();

include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Conta · Perfil
                </span>
                <h1 class="sa-hero-title">O meu <em>perfil</em></h1>
                <p class="sa-hero-sub"><?= e($me['email']) ?> · SuperAdmin desde <?= date('d/m/Y', strtotime($me['created_at'])) ?></p>
            </div>
            <div class="sa-hero-actions">
                <a href="<?= base_path('admin/dashboard.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
            </div>
        </header>

        <div class="row g-4">
            <div class="col-lg-6">
                <article class="content-card sa-card">
                    <div class="card-heading">
                        <div>
                            <h4>Dados pessoais</h4>
                            <p class="sa-card-sub">O email de acesso não pode ser alterado aqui.</p>
                        </div>
                    </div>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="profile">
                        <div class="mb-3">
                            <label class="form-label" for="pName">Nome</label>
                            <input id="pName" name="name" class="form-control" required maxlength="120" value="<?= e($me['name']) ?>" autocomplete="name">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="pEmail">Email</label>
                            <input id="pEmail" class="form-control" value="<?= e($me['email']) ?>" disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="pPhone">Telefone</label>
                            <input id="pPhone" name="phone" class="form-control" maxlength="20" value="<?= e($me['phone'] ?? '') ?>" placeholder="+258 84 000 0000" autocomplete="tel">
                        </div>
                        <button class="btn btn-primary"><i class="bi bi-check2"></i> Guardar</button>
                    </form>
                </article>
            </div>

            <div class="col-lg-6">
                <article class="content-card sa-card">
                    <div class="card-heading">
                        <div>
                            <h4>Alterar password</h4>
                            <p class="sa-card-sub">Mínimo 10 caracteres, com maiúsculas, minúsculas e números.</p>
                        </div>
                    </div>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="password">
                        <div class="mb-3">
                            <label class="form-label" for="pwCurrent">Password atual</label>
                            <input id="pwCurrent" type="password" name="current_password" class="form-control" required autocomplete="current-password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="pwNew">Nova password</label>
                            <input id="pwNew" type="password" name="new_password" class="form-control" required minlength="10" autocomplete="new-password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="pwConfirm">Confirmar nova password</label>
                            <input id="pwConfirm" type="password" name="confirm_password" class="form-control" required minlength="10" autocomplete="new-password">
                        </div>
                        <button class="btn btn-primary"><i class="bi bi-key"></i> Alterar password</button>
                    </form>
                </article>
            </div>
        </div>

    </section>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
