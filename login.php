<?php
require_once __DIR__.'/includes/auth.php';
if (logged_in()) redirect(base_path('index.php'));
$page_title = 'Entrar';

if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (login_blocked($email)) {
        flash('warning', 'Demasiadas tentativas. Aguarde 15 minutos antes de tentar novamente.');
    } else {
        $s = db()->prepare('SELECT u.id,u.password,u.status,r.name role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=? LIMIT 1');
        $s->execute([$email]);
        $u = $s->fetch();
        $passwordOk = $u && password_verify($password, $u['password']);
        $valid = $passwordOk && $u['status'] === 'active';
        record_login_attempt($email, (bool)$valid);
        if ($valid) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$u['id'];
            if (!empty($_POST['remember'])) {
                // "Manter sessão iniciada": o cookie de sessão passa a durar 30 dias em vez de terminar ao fechar o browser.
                $_SESSION['remember_me'] = true;
                setcookie(session_name(), session_id(), [
                    'expires'  => time() + 30 * 86400,
                    'path'     => ini_get('session.cookie_path') ?: '/',
                    'domain'   => ini_get('session.cookie_domain') ?: '',
                    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            audit((int)$u['id'], 'login', 'auth', 'Utilizador iniciou sessão');
            redirect(base_path($u['role']==='superadmin'?'admin/dashboard.php':($u['role']==='farmer'?'agricultor/dashboard.php':'comprador/dashboard.php')));
        }
        // Só revelamos o estado da conta quando a password está correta.
        if ($passwordOk && $u['status'] === 'blocked') flash('danger', 'Conta bloqueada. Contacte o suporte do AgroMercado para mais informações.');
        elseif ($passwordOk) flash('danger', 'Conta inativa. Contacte o suporte do AgroMercado para reativar o acesso.');
        else flash('danger', 'Email ou password incorretos.');
    }
}
// Alertas de erro/aviso aparecem no próprio cartão de login; os restantes ficam para os toasts do cabeçalho.
$authAlerts = [];
foreach (get_flashes() as $f) {
    if (in_array($f['type'] ?? '', ['danger', 'warning'], true)) $authAlerts[] = $f;
    else $_SESSION['flash'][] = $f;
}
$loginStats = [
    'farmers'  => (int)db()->query("SELECT COUNT(*) FROM farmers WHERE verification_status='verified'")->fetchColumn(),
    'products' => (int)db()->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn(),
    'orders'   => (int)db()->query("SELECT COUNT(*) FROM orders WHERE status='delivered'")->fetchColumn(),
];
include __DIR__.'/includes/header.php';
?>

<section class="auth-section auth-section-pro">
    <div class="auth-shell">

        <!-- LADO ESQUERDO -->
        <aside class="auth-visual">
            <div class="auth-visual-glow"></div>

            <a href="<?= base_path('index.php') ?>" class="auth-visual-brand">
                <span class="brand-pro-icon"><i class="bi bi-basket2-fill"></i></span>
                <span class="brand-pro-text">Agro<em>Mercado</em></span>
            </a>

            <div class="auth-visual-content">
                <span class="eyebrow" style="color:#c6e5b1;">Bem-vindo de volta</span>
                <h2 class="auth-visual-title">
                    O campo<br>
                    <em>à distância de um clique.</em>
                </h2>
                <p class="auth-visual-text">
                    Compre diretamente de agricultores locais, com produtos frescos,
                    preços justos e entrega rápida em todo o país.
                </p>

                <ul class="auth-visual-list">
                    <li><i class="bi bi-check-circle-fill"></i> Produtos frescos do dia</li>
                    <li><i class="bi bi-check-circle-fill"></i> Agricultores verificados</li>
                    <li><i class="bi bi-check-circle-fill"></i> Pagamento seguro</li>
                </ul>
            </div>

            <div class="auth-visual-foot">
                <div class="auth-stamp">
                    <strong><?= number_format($loginStats['farmers'], 0, ',', ' ') ?></strong>
                    <small>agricultores verificados</small>
                </div>
                <div class="auth-stamp">
                    <strong><?= number_format($loginStats['products'], 0, ',', ' ') ?></strong>
                    <small>produtos ativos</small>
                </div>
                <div class="auth-stamp">
                    <strong><?= number_format($loginStats['orders'], 0, ',', ' ') ?></strong>
                    <small>pedidos entregues</small>
                </div>
            </div>
        </aside>

        <!-- LADO DIREITO -->
        <div class="auth-form-side">

            <div class="auth-card auth-card-pro">

                <div class="auth-head">
                    <div class="auth-logo">
                        <i class="bi bi-leaf-fill"></i>
                    </div>
                    <h1>Entrar na conta</h1>
                    <p class="text-muted">Introduza as suas credenciais para continuar.</p>
                </div>

                <!-- ALERTAS -->
                <?php foreach ($authAlerts as $alert): $isDanger = $alert['type'] === 'danger'; ?>
                    <div class="auth-alert auth-alert-<?= $isDanger ? 'danger' : 'warning' ?>" role="alert">
                        <i class="bi bi-<?= $isDanger ? 'exclamation-triangle-fill' : 'clock-history' ?>"></i>
                        <span><?= e($alert['message'] ?? '') ?></span>
                    </div>
                <?php endforeach; ?>

                <form method="post" novalidate>
                    <?= csrf_field() ?>

                    <!-- Email -->
                    <div class="auth-field">
                        <label class="form-label" for="email">Email</label>
                        <div class="auth-input-wrap">
                            <i class="bi bi-envelope auth-input-icon"></i>
                            <input id="email"
                                   type="email"
                                   name="email"
                                   class="form-control form-control-lg auth-input"
                                   placeholder="o.seu@email.com"
                                   required
                                   autocomplete="email"
                                   autofocus
                                   value="<?= e($_POST['email'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- Password COM OLHO -->
                    <div class="auth-field">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label" for="password">Password</label>
                            <a href="<?= base_path('recuperar-password.php') ?>" class="auth-forgot">
                                Esqueceu-se?
                            </a>
                        </div>
                        <div class="auth-input-wrap">
                            <i class="bi bi-lock auth-input-icon"></i>
                            <input id="password"
                                   type="password"
                                   name="password"
                                   class="form-control form-control-lg auth-input auth-input-password"
                                   placeholder="••••••••"
                                   required
                                   autocomplete="current-password">
                            <button type="button"
                                    class="auth-toggle-password"
                                    data-target="password"
                                    aria-label="Mostrar password"
                                    title="Mostrar password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Lembrar -->
                    <div class="form-check auth-remember">
                        <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
                        <label class="form-check-label" for="remember">
                            Manter sessão iniciada
                        </label>
                    </div>

                    <!-- Submit -->
                    <button class="btn btn-primary btn-lg w-100 auth-submit" type="submit">
                        Entrar
                        <i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </form>

                <div class="auth-divider"><span>ou</span></div>

                <p class="text-center text-muted mb-0 auth-foot">
                    Ainda não tem conta?
                    <a href="<?= base_path('cadastro.php') ?>" class="fw-bold">Criar conta</a>
                </p>
            </div>

            <p class="auth-legal">
                <i class="bi bi-shield-lock"></i>
                Os seus dados são tratados de forma segura e confidencial.
            </p>
        </div>
    </div>
</section>

<script>
(function () {
    /* ---------- MOSTRAR / OCULTAR PASSWORD ---------- */
    document.querySelectorAll('.auth-toggle-password').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var targetId = btn.getAttribute('data-target');
            var input = document.getElementById(targetId);
            if (!input) return;

            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';

            var icon = btn.querySelector('i');
            if (icon) {
                icon.classList.toggle('bi-eye', !show);
                icon.classList.toggle('bi-eye-slash', show);
            }

            btn.setAttribute('aria-label', show ? 'Ocultar password' : 'Mostrar password');
            btn.setAttribute('title', show ? 'Ocultar password' : 'Mostrar password');

            input.focus();
            var val = input.value;
            input.value = '';
            input.value = val;
        });
    });
})();
</script>

<?php include __DIR__.'/includes/footer.php'; ?>