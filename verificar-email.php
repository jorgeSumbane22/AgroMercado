<?php
require_once __DIR__.'/includes/auth.php';
$page_title = 'Confirmar email';

// ============================================
// POST: reenviar o link (utilizador com sessão; máx. 3 por hora)
// ============================================
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    require_login();
    $back = (string)($_POST['back'] ?? '');
    // Só redireciona para caminhos internos.
    if (!str_starts_with($back, base_path() . '/') || str_starts_with($back, '//') || preg_match('/[\r\n\\\\]/', $back)) $back = base_path(role_home_path(user()['role']));
    if (email_is_verified()) {
        flash('info', 'O seu email já está confirmado.');
    } elseif (!rate_limit('verify_resend:' . current_user_id(), 3, 3600)) {
        flash('warning', 'Já pediu vários links na última hora. Aguarde antes de pedir outro.');
    } else {
        send_verification_email(current_user_id());
        audit(current_user_id(), 'verify_email_resend', 'auth', 'Pedido de novo link de confirmação de email');
        flash('success', 'Enviámos um novo link de confirmação para ' . user()['email'] . '.');
    }
    redirect($back);
}

// ============================================
// GET: validar selector + validador
// ============================================
$ok = false;
$sel = (string)($_GET['s'] ?? '');
$val = (string)($_GET['v'] ?? '');
if ($sel !== '' && $val !== '') {
    $row = user_token_find($sel, $val, 'verify_email');
    if ($row) {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE user_tokens SET used_at=NOW() WHERE id=?')->execute([(int)$row['id']]);
        $pdo->prepare('UPDATE users SET email_verified_at=COALESCE(email_verified_at,NOW()) WHERE id=?')->execute([(int)$row['user_id']]);
        $pdo->commit();
        audit((int)$row['user_id'], 'verify_email', 'auth', 'Email confirmado');
        flash('success', 'Email confirmado com sucesso. Obrigado!');
        redirect(base_path(logged_in() ? role_home_path(user()['role']) : 'login.php'));
    }
}
include __DIR__.'/includes/header.php';
?>
<section class="auth-section">
    <div class="auth-card text-center">
        <div class="auth-logo"><i class="bi bi-envelope-x"></i></div>
        <h1>Link inválido</h1>
        <p class="text-muted">Este link de confirmação não é válido, expirou (48 horas) ou já foi utilizado.</p>
        <?php if (logged_in() && !email_is_verified()): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="resend">
                <input type="hidden" name="back" value="<?= e(base_path(role_home_path(user()['role']))) ?>">
                <button class="btn btn-primary w-100"><i class="bi bi-arrow-repeat me-2"></i>Enviar novo link</button>
            </form>
        <?php elseif (!logged_in()): ?>
            <a class="btn btn-primary w-100" href="<?= base_path('login.php') ?>">Iniciar sessão para pedir novo link</a>
        <?php else: ?>
            <a class="btn btn-primary w-100" href="<?= base_path(role_home_path(user()['role'])) ?>">O seu email já está confirmado — ir para o painel</a>
        <?php endif; ?>
    </div>
</section>
<?php include __DIR__.'/includes/footer.php'; ?>
