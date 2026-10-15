<?php
require_once __DIR__.'/../includes/permissions.php';
require_role('superadmin');
$page_title = 'Configurações';
$pdo = db();

$keys = [
    'app_name',
    'app_env',
    'mail_enabled',
    'mail_from',
    'payment_mpesa_enabled',
    'payment_emola_enabled',
    'payment_webhook_secret',
];

/* ============================================================
   AÇÃO: guardar configurações
   ============================================================ */
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');

    $values = [];
    $errors = [];
    $values['app_name'] = trim((string)($_POST['app_name'] ?? ''));
    if ($values['app_name'] === '' || mb_strlen($values['app_name']) > 80) $errors[] = 'O nome da aplicação é obrigatório (máx. 80 caracteres).';
    $values['app_env'] = (string)($_POST['app_env'] ?? '');
    if (!in_array($values['app_env'], ['local', 'production'], true)) $errors[] = 'Ambiente inválido (local ou production).';
    $values['mail_from'] = trim((string)($_POST['mail_from'] ?? ''));
    if (!filter_var($values['mail_from'], FILTER_VALIDATE_EMAIL)) $errors[] = 'O email remetente não é válido.';
    foreach (['mail_enabled', 'payment_mpesa_enabled', 'payment_emola_enabled'] as $b) {
        $v = (string)($_POST[$b] ?? '0');
        if (!in_array($v, ['0', '1'], true)) $errors[] = 'Valor inválido para ' . $b . '.';
        $values[$b] = $v;
    }
    $secret = trim((string)($_POST['payment_webhook_secret'] ?? ''));
    $clearSecret = !empty($_POST['clear_webhook_secret']);
    if ($secret !== '' && (strlen($secret) < 16 || strlen($secret) > 255)) $errors[] = 'O segredo de webhook deve ter entre 16 e 255 caracteres.';
    if ($secret !== '' && $clearSecret) $errors[] = 'Escolha entre definir um novo segredo ou apagar o atual.';

    if ($errors) {
        foreach ($errors as $err) flash('danger', $err);
        redirect(base_path('admin/configuracoes.php'));
    }
    if ($clearSecret) $values['payment_webhook_secret'] = '';
    elseif ($secret !== '') $values['payment_webhook_secret'] = $secret; // vazio = manter o atual

    $stmt = $pdo->prepare('INSERT INTO app_settings(setting_key,setting_value,is_secret) VALUES(?,?,?)
                   ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_secret=VALUES(is_secret)');
    foreach ($values as $key => $value) {
        if (!in_array($key, $keys, true)) continue;
        $stmt->execute([$key, $value, $key === 'payment_webhook_secret' ? 1 : 0]);
    }

    audit(current_user_id(), 'update', 'settings', 'Configurações da plataforma atualizadas' . ($clearSecret ? ' (segredo de webhook apagado)' : ($secret !== '' ? ' (novo segredo de webhook)' : '')));
    flash('success', 'Configurações guardadas.');
    redirect(base_path('admin/configuracoes.php'));
}

/* ============================================================
   LER ESTADO ATUAL (para mostrar resumo lateral)
   ============================================================ */
$current = [
    'app_name'                 => setting('app_name', 'AgroMercado'),
    'app_env'                  => setting('app_env', 'local'),
    'mail_enabled'             => setting('mail_enabled', '0') === '1',
    'mail_from'                => setting('mail_from', 'no-reply@agromercado.co.mz'),
    'payment_mpesa_enabled'    => setting('payment_mpesa_enabled', '0') === '1',
    'payment_emola_enabled'    => setting('payment_emola_enabled', '0') === '1',
    'payment_webhook_secret'   => setting('payment_webhook_secret', '') !== '',
];

include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <!-- ================= HERO ================= -->
        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Plataforma · Configurações
                </span>
                <h1 class="sa-hero-title">Parâmetros da <em>plataforma</em></h1>
                <p class="sa-hero-sub">
                    Modo <strong><?= $current['app_env'] === 'production' ? 'Produção' : 'Local' ?></strong> ·
                    Email <?= $current['mail_enabled'] ? '<strong>ativo</strong>' : 'inativo' ?> ·
                    Pagamentos: <strong><?= ($current['payment_mpesa_enabled'] ? 'M-Pesa' : '') . ($current['payment_emola_enabled'] ? ($current['payment_mpesa_enabled'] ? ' + ' : '') . 'e-Mola' : '') ?: 'nenhum ativo' ?></strong>
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('admin/dashboard.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <a href="<?= base_path('admin/auditoria.php') ?>" class="btn btn-primary">
                    <i class="bi bi-journal-text"></i> Ver auditoria
                </a>
            </div>
        </header>

        <form method="post">
            <?= csrf_field() ?>

            <div class="row g-4">

                <!-- ================= COLUNA ESQUERDA ================= -->
                <div class="col-lg-8">

                    <!-- APLICAÇÃO -->
                    <article class="content-card sa-card mb-4">
                        <div class="card-heading">
                            <div class="sa-card-head-icon">
                                <span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-sliders2"></i></span>
                                <div>
                                    <h4>Aplicação</h4>
                                    <p class="sa-card-sub">Identidade e ambiente da instalação</p>
                                </div>
                            </div>
                        </div>

                        <div class="sa-form-grid">
                            <div class="sa-field">
                                <label class="form-label" for="app_name">Nome da aplicação</label>
                                <input id="app_name" name="app_name" class="form-control" required maxlength="80"
                                       value="<?= e(setting('app_name', 'AgroMercado')) ?>"
                                       placeholder="Ex: AgroMercado"
                                       autocomplete="off">
                                <small class="sa-field-hint">Aparece no título das páginas e no rodapé.</small>
                            </div>

                            <div class="sa-field">
                                <label class="form-label" for="app_env">Ambiente</label>
                                <div class="sa-select-wrap">
                                    <select id="app_env" name="app_env" class="sa-select">
                                        <option value="local"      <?= setting('app_env','local') === 'local'      ? 'selected' : '' ?>>Local (desenvolvimento)</option>
                                        <option value="production" <?= setting('app_env','local') === 'production' ? 'selected' : '' ?>>Produção</option>
                                    </select>
                                    <i class="bi bi-chevron-down sa-select-chevron"></i>
                                </div>
                                <small class="sa-field-hint">Altera o comportamento de erros e logs.</small>
                            </div>
                        </div>
                    </article>

                    <!-- EMAIL -->
                    <article class="content-card sa-card mb-4">
                        <div class="card-heading">
                            <div class="sa-card-head-icon">
                                <span class="sa-kpi-icon sa-icon-info"><i class="bi bi-envelope-at"></i></span>
                                <div>
                                    <h4>Email</h4>
                                    <p class="sa-card-sub">Notificações automáticas</p>
                                </div>
                            </div>
                        </div>

                        <div class="sa-form-grid">
                            <div class="sa-field">
                                <label class="form-label" for="mail_enabled">Email habilitado</label>
                                <div class="sa-select-wrap">
                                    <select id="mail_enabled" name="mail_enabled" class="sa-select">
                                        <option value="0" <?= setting('mail_enabled','0') === '0' ? 'selected' : '' ?>>Desativado</option>
                                        <option value="1" <?= setting('mail_enabled','0') === '1' ? 'selected' : '' ?>>Ativo</option>
                                    </select>
                                    <i class="bi bi-chevron-down sa-select-chevron"></i>
                                </div>
                                <small class="sa-field-hint">Se desativado, os emails não são enviados.</small>
                            </div>

                            <div class="sa-field">
                                <label class="form-label" for="mail_from">Email remetente</label>
                                <input id="mail_from" type="email" name="mail_from" class="form-control" required maxlength="160"
                                       value="<?= e(setting('mail_from', 'no-reply@agromercado.co.mz')) ?>"
                                       placeholder="no-reply@dominio.co.mz"
                                       autocomplete="off">
                                <small class="sa-field-hint">Usado no "De:" dos emails automáticos.</small>
                            </div>
                        </div>
                    </article>

                    <!-- PAGAMENTOS -->
                    <article class="content-card sa-card mb-4">
                        <div class="card-heading">
                            <div class="sa-card-head-icon">
                                <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-credit-card-2-front"></i></span>
                                <div>
                                    <h4>Pagamentos</h4>
                                    <p class="sa-card-sub">Métodos móveis e segurança de webhooks</p>
                                </div>
                            </div>
                        </div>

                        <div class="sa-notice sa-notice-inline">
                            <span class="sa-notice-icon"><i class="bi bi-shield-lock"></i></span>
                            <div class="sa-notice-body">
                                <strong>Ative apenas com credenciais oficiais</strong>
                                <p>Não ative métodos externos sem configurar as credenciais do provedor e testar os webhooks em produção.</p>
                            </div>
                        </div>

                        <div class="sa-form-grid">
                            <div class="sa-field">
                                <label class="form-label" for="payment_mpesa_enabled">M-Pesa</label>
                                <div class="sa-select-wrap">
                                    <select id="payment_mpesa_enabled" name="payment_mpesa_enabled" class="sa-select">
                                        <option value="0" <?= setting('payment_mpesa_enabled','0') === '0' ? 'selected' : '' ?>>Desativado</option>
                                        <option value="1" <?= setting('payment_mpesa_enabled','0') === '1' ? 'selected' : '' ?>>Ativo</option>
                                    </select>
                                    <i class="bi bi-chevron-down sa-select-chevron"></i>
                                </div>
                                <small class="sa-field-hint">Requer credenciais M-Pesa configuradas no servidor.</small>
                            </div>

                            <div class="sa-field">
                                <label class="form-label" for="payment_emola_enabled">e-Mola</label>
                                <div class="sa-select-wrap">
                                    <select id="payment_emola_enabled" name="payment_emola_enabled" class="sa-select">
                                        <option value="0" <?= setting('payment_emola_enabled','0') === '0' ? 'selected' : '' ?>>Desativado</option>
                                        <option value="1" <?= setting('payment_emola_enabled','0') === '1' ? 'selected' : '' ?>>Ativo</option>
                                    </select>
                                    <i class="bi bi-chevron-down sa-select-chevron"></i>
                                </div>
                                <small class="sa-field-hint">Requer credenciais e-Mola configuradas no servidor.</small>
                            </div>

                            <div class="sa-field sa-field-full">
                                <label class="form-label" for="payment_webhook_secret">
                                    Segredo de webhook
                                    <?php if ($current['payment_webhook_secret']): ?>
                                        <span class="sa-secret-chip">
                                            <i class="bi bi-check-circle-fill"></i> definido
                                        </span>
                                    <?php else: ?>
                                        <span class="sa-secret-chip sa-secret-chip-empty">
                                            <i class="bi bi-dash-circle"></i> não definido
                                        </span>
                                    <?php endif; ?>
                                </label>

                                <div class="sa-secret-input">
                                    <input id="payment_webhook_secret"
                                           type="password"
                                           name="payment_webhook_secret"
                                           class="form-control"
                                           placeholder="Deixe vazio para manter o atual"
                                           autocomplete="new-password">
                                    <button type="button"
                                            class="sa-secret-toggle"
                                            data-target="payment_webhook_secret"
                                            aria-label="Mostrar segredo">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <small class="sa-field-hint">O valor nunca é mostrado depois de guardado. Mínimo 16 caracteres.</small>
                                <?php if ($current['payment_webhook_secret']): ?>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" name="clear_webhook_secret" value="1" id="clear_webhook_secret">
                                        <label class="form-check-label" for="clear_webhook_secret">Apagar o segredo atual (os webhooks deixam de ser aceites)</label>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>

                </div>

                <!-- ================= COLUNA DIREITA ================= -->
                <div class="col-lg-4">

                    <!-- RESUMO ATUAL -->
                    <article class="content-card sa-card mb-4">
                        <div class="card-heading">
                            <div>
                                <h4>Resumo atual</h4>
                                <p class="sa-card-sub">Configuração em vigor</p>
                            </div>
                        </div>

                        <div class="sa-summary">
                            <div class="sa-summary-row">
                                <span><i class="bi bi-tag"></i> Nome</span>
                                <strong><?= e($current['app_name']) ?></strong>
                            </div>
                            <div class="sa-summary-row">
                                <span><i class="bi bi-hdd-network"></i> Ambiente</span>
                                <span class="sa-badge <?= $current['app_env'] === 'production' ? 'sa-badge-success' : 'sa-badge-warning' ?>">
                                    <i class="bi bi-<?= $current['app_env'] === 'production' ? 'check-circle-fill' : 'tools' ?>"></i>
                                    <?= $current['app_env'] === 'production' ? 'Produção' : 'Local' ?>
                                </span>
                            </div>
                            <div class="sa-summary-row">
                                <span><i class="bi bi-envelope-at"></i> Email</span>
                                <span class="sa-badge <?= $current['mail_enabled'] ? 'sa-badge-success' : 'sa-badge-secondary' ?>">
                                    <i class="bi bi-<?= $current['mail_enabled'] ? 'check-circle-fill' : 'slash-circle-fill' ?>"></i>
                                    <?= $current['mail_enabled'] ? 'Ativo' : 'Inativo' ?>
                                </span>
                            </div>
                            <div class="sa-summary-row">
                                <span><i class="bi bi-phone"></i> M-Pesa</span>
                                <span class="sa-badge <?= $current['payment_mpesa_enabled'] ? 'sa-badge-success' : 'sa-badge-secondary' ?>">
                                    <i class="bi bi-<?= $current['payment_mpesa_enabled'] ? 'check-circle-fill' : 'slash-circle-fill' ?>"></i>
                                    <?= $current['payment_mpesa_enabled'] ? 'Ativo' : 'Desativado' ?>
                                </span>
                            </div>
                            <div class="sa-summary-row">
                                <span><i class="bi bi-phone"></i> e-Mola</span>
                                <span class="sa-badge <?= $current['payment_emola_enabled'] ? 'sa-badge-success' : 'sa-badge-secondary' ?>">
                                    <i class="bi bi-<?= $current['payment_emola_enabled'] ? 'check-circle-fill' : 'slash-circle-fill' ?>"></i>
                                    <?= $current['payment_emola_enabled'] ? 'Ativo' : 'Desativado' ?>
                                </span>
                            </div>
                            <div class="sa-summary-row">
                                <span><i class="bi bi-key"></i> Webhook</span>
                                <span class="sa-badge <?= $current['payment_webhook_secret'] ? 'sa-badge-success' : 'sa-badge-secondary' ?>">
                                    <i class="bi bi-<?= $current['payment_webhook_secret'] ? 'shield-check' : 'shield-slash' ?>"></i>
                                    <?= $current['payment_webhook_secret'] ? 'Definido' : 'Ausente' ?>
                                </span>
                            </div>
                        </div>
                    </article>

                    <!-- ESTADO DA INSTALAÇÃO -->
                    <article class="content-card sa-card mb-4">
                        <div class="card-heading">
                            <div>
                                <h4>Estado da instalação</h4>
                                <p class="sa-card-sub">Infraestrutura e segurança</p>
                            </div>
                        </div>

                        <div class="sa-install">
                            <div class="sa-install-row">
                                <span class="sa-install-icon"><i class="bi bi-database-check"></i></span>
                                <div>
                                    <strong>Base de dados</strong>
                                    <small>MySQL via PDO</small>
                                </div>
                                <span class="sa-badge sa-badge-success">
                                    <i class="bi bi-check-circle-fill"></i> OK
                                </span>
                            </div>
                            <div class="sa-install-row">
                                <span class="sa-install-icon"><i class="bi bi-cloud-arrow-up"></i></span>
                                <div>
                                    <strong>Uploads</strong>
                                    <small>JPG · PNG · WEBP · 3 MB</small>
                                </div>
                                <span class="sa-badge sa-badge-success">
                                    <i class="bi bi-check-circle-fill"></i> OK
                                </span>
                            </div>
                            <div class="sa-install-row">
                                <span class="sa-install-icon"><i class="bi bi-shield-lock"></i></span>
                                <div>
                                    <strong>Segurança</strong>
                                    <small>CSRF · bcrypt · rate limit</small>
                                </div>
                                <span class="sa-badge sa-badge-success">
                                    <i class="bi bi-check-circle-fill"></i> OK
                                </span>
                            </div>
                            <div class="sa-install-row">
                                <span class="sa-install-icon"><i class="bi bi-arrow-counterclockwise"></i></span>
                                <div>
                                    <strong>Recuperação</strong>
                                    <small>Token de 60 minutos</small>
                                </div>
                                <span class="sa-badge sa-badge-success">
                                    <i class="bi bi-check-circle-fill"></i> OK
                                </span>
                            </div>
                        </div>
                    </article>

                    <!-- AÇÃO -->
                    <article class="content-card sa-card sa-sticky">
                        <div class="card-heading">
                            <div>
                                <h4>Aplicação das alterações</h4>
                                <p class="sa-card-sub">Guarde para aplicar</p>
                            </div>
                        </div>

                        <button type="submit" class="sa-submit">
                            <i class="bi bi-save2"></i> Guardar configurações
                        </button>

                        <p class="sa-submit-note">
                            <i class="bi bi-info-circle"></i>
                            As alterações são aplicadas imediatamente após guardar.
                        </p>
                    </article>

                </div>
            </div>
        </form>

    </section>
</div>

<script>
(function () {
    /* Mostrar/ocultar segredo do webhook */
    document.querySelectorAll('.sa-secret-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.dataset.target);
            if (!input) return;
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            var icon = btn.querySelector('i');
            if (icon) {
                icon.classList.toggle('bi-eye', !show);
                icon.classList.toggle('bi-eye-slash', show);
            }
            btn.setAttribute('aria-label', show ? 'Ocultar segredo' : 'Mostrar segredo');
        });
    });
})();
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>