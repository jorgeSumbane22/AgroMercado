<?php
require_once __DIR__.'/includes/auth.php';

// ============================================
// GET: não termina a sessão (evita logout por links/imagens de terceiros);
// mostra uma confirmação com botão POST + CSRF.
// ============================================
if (!is_post()) {
    if (!logged_in()) redirect(base_path('index.php'));
    $page_title = 'Terminar sessão';
    include __DIR__.'/includes/header.php';
    ?>
    <section class="auth-section">
        <div class="auth-card text-center">
            <div class="auth-logo"><i class="bi bi-box-arrow-right"></i></div>
            <h1>Terminar sessão?</h1>
            <p class="text-muted">Sessão iniciada como <strong><?= e(user()['email']) ?></strong>.</p>
            <form method="post" action="<?= base_path('logout.php') ?>">
                <?= csrf_field() ?>
                <button class="btn btn-primary btn-lg w-100" type="submit">
                    <i class="bi bi-box-arrow-right me-2"></i>Terminar sessão
                </button>
            </form>
            <a class="btn btn-link mt-2" href="<?= base_path(role_home_path(user()['role'])) ?>">Cancelar</a>
        </div>
    </section>
    <?php
    include __DIR__.'/includes/footer.php';
    exit;
}

verify_csrf($_POST['csrf'] ?? '');

// ============================================
// REGISTA AUDITORIA (SE UTILIZADOR COM SESSÃO)
// ============================================
if (current_user_id()) {
    audit(current_user_id(), 'logout', 'auth', 'Utilizador terminou sessão');
}

// ============================================
// REVOGA O TOKEN "MANTER SESSÃO" DESTE BROWSER
// ============================================
remember_forget_current();

// ============================================
// LIMPA A SESSÃO E O COOKIE DE SESSÃO
// ============================================
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

header('Location: ' . base_path('index.php'));
exit;
?>
