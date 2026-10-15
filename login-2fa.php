<?php
require_once __DIR__.'/includes/auth.php';
if (logged_in()) redirect(base_path('index.php'));
$page_title = 'Verificação em dois passos';

$pending = $_SESSION['pending_2fa'] ?? null;
// O passo da password vale 5 minutos.
if (!$pending || time() - (int)($pending['at'] ?? 0) > 300) {
    unset($_SESSION['pending_2fa']);
    flash('warning', 'Introduza novamente o email e a password.');
    redirect(base_path('login.php'));
}

if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    if (($_POST['action'] ?? '') === 'cancel') {
        unset($_SESSION['pending_2fa']);
        redirect(base_path('login.php'));
    }
    $uid = (int)$pending['user_id'];
    if (!rate_limit('2fa:user:' . $uid, 5, 600) || !rate_limit('2fa:ip:' . client_ip(), 20, 600)) {
        flash('danger', 'Demasiadas tentativas. Aguarde 10 minutos e tente novamente.');
        redirect(base_path('login-2fa.php'));
    }
    $s = db()->prepare('SELECT u.id,u.status,u.totp_secret,u.totp_enabled,r.name role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?');
    $s->execute([$uid]);
    $u = $s->fetch();
    if (!$u || $u['status'] !== 'active') {
        unset($_SESSION['pending_2fa']);
        flash('danger', 'Não foi possível iniciar sessão nesta conta.');
        redirect(base_path('login.php'));
    }
    if (!(int)$u['totp_enabled'] || totp_verify((string)$u['totp_secret'], (string)($_POST['code'] ?? ''))) {
        redirect(login_complete($uid, $u['role'], !empty($pending['remember']), '2fa'));
    }
    audit($uid, 'login_2fa_failed', 'auth', 'Código de verificação em dois passos inválido');
    flash('danger', 'Código inválido. Confirme a hora do telemóvel e tente novamente.');
    redirect(base_path('login-2fa.php'));
}

$authAlerts = [];
foreach (get_flashes() as $f) {
    if (in_array($f['type'] ?? '', ['danger', 'warning'], true)) $authAlerts[] = $f;
    else $_SESSION['flash'][] = $f;
}
include __DIR__.'/includes/header.php';
?>
<section class="auth-section">
    <div class="auth-card">
        <div class="text-center mb-4">
            <div class="auth-logo"><i class="bi bi-shield-lock"></i></div>
            <h1>Verificação em dois passos</h1>
            <p class="text-muted">Introduza o código de 6 dígitos da sua app autenticadora.</p>
        </div>

        <?php foreach ($authAlerts as $alert): ?>
            <div class="alert alert-<?= $alert['type'] === 'danger' ? 'danger' : 'warning' ?>" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($alert['message'] ?? '') ?>
            </div>
        <?php endforeach; ?>

        <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <label class="form-label" for="code">Código de verificação</label>
            <input id="code" name="code" class="form-control form-control-lg text-center mb-3"
                   inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="000000"
                   autocomplete="one-time-code" required autofocus>
            <button class="btn btn-primary btn-lg w-100" type="submit">
                Verificar <i class="bi bi-arrow-right ms-2"></i>
            </button>
        </form>

        <form method="post" class="text-center mt-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="btn btn-link">Cancelar e voltar ao login</button>
        </form>
        <p class="small text-muted text-center mb-0">Perdeu o acesso à app? Contacte o suporte do AgroMercado para desativar a verificação em dois passos.</p>
    </div>
</section>
<?php include __DIR__.'/includes/footer.php'; ?>
