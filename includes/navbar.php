<nav class="navbar navbar-pro navbar-expand-lg sticky-top">
    <div class="container">

        <!-- Brand -->
        <a class="navbar-brand brand-pro" href="<?= base_path('index.php') ?>">
            <span class="brand-pro-icon"><i class="bi bi-basket2-fill"></i></span>
            <span class="brand-pro-text">Agro<em>Mercado</em></span>
        </a>

        <!-- Ações à direita (sempre visíveis): tema + toggle mobile -->
        <div class="d-flex align-items-center gap-2 order-lg-3">
            <!-- Botão de tema -->
            <button id="themeToggle" class="theme-toggle" type="button" aria-label="Alternar tema claro/escuro">
                <i class="bi bi-moon-stars-fill icon-moon"></i>
                <i class="bi bi-sun-fill icon-sun"></i>
            </button>

            <!-- Toggle mobile -->
            <button class="navbar-toggler navbar-pro-toggler"
                    type="button"
                    data-bs-toggle="offcanvas"
                    data-bs-target="#mainMenu"
                    aria-label="Abrir menu">
                <i class="bi bi-list"></i>
            </button>
        </div>

        <!-- Menu -->
        <div class="offcanvas offcanvas-end offcanvas-pro" id="mainMenu">
            <div class="offcanvas-header">
                <span class="brand-pro">
                    <span class="brand-pro-icon"><i class="bi bi-basket2-fill"></i></span>
                    <span class="brand-pro-text">Agro<em>Mercado</em></span>
                </span>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
            </div>

            <div class="offcanvas-body">

                <!-- Links principais -->
                <ul class="navbar-nav navbar-pro-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?= base_path('index.php') ?>">
                            <i class="bi bi-house-door"></i> Início
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= base_path('marketplace.php') ?>">
                            <i class="bi bi-shop"></i> Marketplace
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= base_path('index.php#categorias') ?>">
                            <i class="bi bi-grid-3x3-gap"></i> Categorias
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= base_path('index.php#como-funciona') ?>">
                            <i class="bi bi-info-circle"></i> Como funciona
                        </a>
                    </li>
                </ul>

                <!-- Ações -->
                <div class="navbar-pro-actions">

                    <!-- Carrinho -->
                    <?php if (!logged_in() || user()['role'] === 'buyer'): $navCart = cart_count(); ?>
                    <a class="nav-pro-icon" href="<?= base_path('carrinho.php') ?>" title="Carrinho" aria-label="Carrinho">
                        <i class="bi bi-bag"></i>
                        <?php if ($navCart > 0): ?>
                            <span class="nav-pro-badge cart-badge"><?= $navCart ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>

                    <?php if (logged_in()): ?>

                        <!-- Painel -->
                        <a class="nav-pro-icon"
                           href="<?= base_path(user()['role'] === 'superadmin' ? 'admin/dashboard.php' : (user()['role'] === 'farmer' ? 'agricultor/dashboard.php' : 'comprador/dashboard.php')) ?>"
                           title="Painel">
                            <i class="bi bi-speedometer2"></i>
                        </a>

                        <!-- Notificações -->
                        <a class="nav-pro-icon" href="<?= base_path(
                            user()['role'] === 'farmer'
                                ? 'agricultor/notificacoes.php'
                                : (user()['role'] === 'buyer' ? 'comprador/notificacoes.php' : 'admin/dashboard.php')
                        ) ?>" title="Notificações" aria-label="Notificações">
                            <i class="bi bi-bell"></i>
                            <?php if (($navUnread = unread_notifications_count()) > 0): ?>
                                <span class="nav-pro-badge"><?= $navUnread > 99 ? '99+' : $navUnread ?></span>
                            <?php endif; ?>
                        </a>

                        <!-- Dropdown do utilizador -->
                        <div class="dropdown">
                            <button class="nav-pro-user dropdown-toggle"
                                    type="button"
                                    data-bs-toggle="dropdown"
                                    aria-expanded="false">
                                <span class="nav-pro-avatar"><?= e(strtoupper(substr(user()['name'], 0, 1))) ?></span>
                                <span class="nav-pro-name"><?= e(explode(' ', user()['name'])[0]) ?></span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end nav-pro-menu">
                                <li>
                                    <a class="dropdown-item" href="<?= base_path(
                                        user()['role'] === 'farmer'
                                            ? 'agricultor/perfil.php'
                                            : (user()['role'] === 'buyer' ? 'comprador/perfil.php' : 'admin/perfil.php')
                                    ) ?>">
                                        <i class="bi bi-person"></i> Meu perfil
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="<?= base_path(
                                        user()['role'] === 'farmer'
                                            ? 'agricultor/pedidos.php'
                                            : (user()['role'] === 'buyer' ? 'comprador/pedidos.php' : 'admin/pedidos.php')
                                    ) ?>">
                                        <i class="bi bi-receipt"></i> Meus pedidos
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="<?= base_path('seguranca.php') ?>">
                                        <i class="bi bi-shield-lock"></i> Segurança da conta
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <!-- Logout só por POST + CSRF -->
                                    <?= post_button(base_path('logout.php'), [], '<i class="bi bi-box-arrow-right"></i> Sair', 'dropdown-item nav-pro-logout') ?>
                                </li>
                            </ul>
                        </div>

                    <?php else: ?>

                        <a class="nav-pro-login" href="<?= base_path('login.php') ?>">
                            <i class="bi bi-box-arrow-in-right"></i> Entrar
                        </a>
                        <a class="btn btn-primary nav-pro-cta" href="<?= base_path('cadastro.php') ?>">
                            <i class="bi bi-person-plus"></i> Criar conta
                        </a>

                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</nav>

<?php
/* ============================================================
   AVISO: email por confirmar (+ link de teste em ambiente local)
   ============================================================ */
$navDevVerifyLink = is_local_env() ? ($_SESSION['dev_verify_link'] ?? null) : null;
unset($_SESSION['dev_verify_link']);
$navUnverified = logged_in() && empty(user()['email_verified_at']);
?>
<?php if ($navUnverified || $navDevVerifyLink): ?>
<div class="alert alert-warning rounded-0 border-0 border-bottom mb-0 py-2 small" role="status">
    <div class="container d-flex flex-wrap align-items-center gap-2">
        <?php if ($navUnverified): ?>
            <i class="bi bi-envelope-exclamation-fill"></i>
            <span><strong>Confirme o seu email.</strong> Enviámos um link para <?= e(user()['email']) ?>.</span>
            <?= post_button(base_path('verificar-email.php'), ['action' => 'resend', 'back' => (string)($_SERVER['REQUEST_URI'] ?? '')], '<i class="bi bi-arrow-repeat"></i> Reenviar', 'btn btn-sm btn-outline-dark py-0') ?>
        <?php endif; ?>
        <?php if ($navDevVerifyLink): ?>
            <span class="w-100 text-break"><strong>Ambiente local (apenas desenvolvimento):</strong>
                <a href="<?= e($navDevVerifyLink) ?>"><?= e($navDevVerifyLink) ?></a></span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     SCRIPT DO TEMA — funciona em qualquer página
     ============================================================ -->
<script>
(function () {
    var STORAGE_KEY = 'agromercado-theme';
    var html = document.documentElement;

    function getPreferredTheme() {
        var saved = null;
        try { saved = localStorage.getItem(STORAGE_KEY); } catch (e) {}
        if (saved === 'light' || saved === 'dark') return saved;
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function applyTheme(theme) {
        html.setAttribute('data-theme', theme);
        html.setAttribute('data-bs-theme', theme);
    }

    // Aplica imediatamente (evita flash)
    applyTheme(getPreferredTheme());

    function initToggle() {
        var btn = document.getElementById('themeToggle');
        if (!btn) return;

        // Remove listeners antigos ao re-render (seguro se chamado 2x)
        btn.onclick = function () {
            var current = html.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            var next = current === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            try { localStorage.setItem(STORAGE_KEY, next); } catch (e) {}
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initToggle);
    } else {
        initToggle();
    }

    // Segue a preferência do sistema se o utilizador nunca escolheu
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
            var saved = null;
            try { saved = localStorage.getItem(STORAGE_KEY); } catch (err) {}
            if (!saved) applyTheme(e.matches ? 'dark' : 'light');
        });
    }
})();
</script>