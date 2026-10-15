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
    'smtp_host',
    'smtp_port',
    'smtp_user',
    'smtp_pass',
    'smtp_secure',
];
// Chaves de email que podem vir do .env (o .env tem prioridade e o campo fica só de leitura).
$envKeys = [
    'mail_enabled' => 'MAIL_ENABLED',
    'mail_from'    => 'MAIL_FROM',
    'smtp_host'    => 'SMTP_HOST',
    'smtp_port'    => 'SMTP_PORT',
    'smtp_user'    => 'SMTP_USER',
    'smtp_pass'    => 'SMTP_PASS',
    'smtp_secure'  => 'SMTP_SECURE',
];
$fromEnv = array_map(fn($envKey) => config_from_env($envKey), $envKeys);

/* ============================================================
   AÇÃO: enviar email de teste para o administrador
   ============================================================ */
if (is_post() && ($_POST['action'] ?? '') === 'test_email') {
    verify_csrf($_POST['csrf'] ?? '');
    $to = (string)user()['email'];
    $id = queue_email($to, 'AgroMercado — email de teste', "Olá " . user()['name'] . ",\n\nEste é um email de teste enviado a partir das configurações do AgroMercado em " . date('d/m/Y H:i') . ".\n\nSe o recebeu, o envio por SMTP está a funcionar.", current_user_id());
    if (!$id) {
        flash('danger', 'Não foi possível colocar o email de teste na fila.');
    } else {
        deliver_email($id);
        $s = $pdo->prepare('SELECT status,last_error FROM email_outbox WHERE id=?'); $s->execute([$id]); $r = $s->fetch();
        if ($r['status'] === 'sent') flash('success', 'Email de teste enviado para ' . $to . '.');
        elseif ($r['status'] === 'logged') flash('warning', 'Email de teste registado mas não enviado: ' . $r['last_error'] . ' Veja-o em Emails enviados.');
        else flash('danger', 'Falha no envio: ' . $r['last_error']);
    }
    audit(current_user_id(), 'test_email', 'settings', 'Email de teste #' . $id . ' para ' . $to);
    redirect(base_path('admin/configuracoes.php'));
}

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

    // SMTP
    $values['smtp_host'] = trim((string)($_POST['smtp_host'] ?? ''));
    if ($values['smtp_host'] !== '' && !preg_match('/^[A-Za-z0-9.\-]{1,190}$/', $values['smtp_host'])) $errors[] = 'Servidor SMTP inválido (ex.: smtp.dominio.co.mz).';
    $values['smtp_port'] = trim((string)($_POST['smtp_port'] ?? '587'));
    if (filter_var($values['smtp_port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) $errors[] = 'Porta SMTP inválida.';
    $values['smtp_user'] = trim((string)($_POST['smtp_user'] ?? ''));
    if (mb_strlen($values['smtp_user']) > 190) $errors[] = 'Utilizador SMTP demasiado longo.';
    $values['smtp_secure'] = (string)($_POST['smtp_secure'] ?? 'tls');
    if (!in_array($values['smtp_secure'], ['tls', 'ssl', ''], true)) $errors[] = 'Segurança SMTP inválida.';
    $smtpPass = (string)($_POST['smtp_pass'] ?? '');
    $clearSmtpPass = !empty($_POST['clear_smtp_pass']);
    if ($smtpPass !== '' && strlen($smtpPass) > 255) $errors[] = 'Password SMTP demasiado longa.';
    if ($clearSmtpPass) $values['smtp_pass'] = '';
    elseif ($smtpPass !== '') $values['smtp_pass'] = $smtpPass; // vazio = manter a atual

    // Valores definidos no .env não são editáveis aqui.
    foreach ($fromEnv as $key => $isEnv) {
        if (!$isEnv) continue;
        unset($values[$key]);
        $errors = array_values(array_filter($errors, fn($err) => !($key === 'mail_from' && str_contains($err, 'remetente'))));
    }

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
        $stmt->execute([$key, $value, in_array($key, ['payment_webhook_secret', 'smtp_pass'], true) ? 1 : 0]);
    }

    audit(current_user_id(), 'update', 'settings', 'Configurações da plataforma atualizadas' . ($clearSecret ? ' (segredo de webhook apagado)' : ($secret !== '' ? ' (novo segredo de webhook)' : '')) . (isset($values['smtp_pass']) ? ' (password SMTP ' . ($values['smtp_pass'] === '' ? 'apagada' : 'alterada') . ')' : ''));
    flash('success', 'Configurações guardadas.');
    redirect(base_path('admin/configuracoes.php'));
}

/* ============================================================
   LER ESTADO ATUAL (para mostrar resumo lateral)
   ============================================================ */
$current = [
    'app_name'                 => setting('app_name', 'AgroMercado'),
    'app_env'                  => setting('app_env', 'local'),
    'mail_enabled'             => config_value('MAIL_ENABLED', 'mail_enabled', '0') === '1',
    'mail_from'                => config_value('MAIL_FROM', 'mail_from', 'no-reply@agromercado.co.mz'),
    'smtp_ready'               => mail_configured(),
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

                        <?php if (array_filter($fromEnv)): ?>
                            <div class="sa-notice sa-notice-inline">
                                <span class="sa-notice-icon"><i class="bi bi-file-earmark-lock"></i></span>
                                <div class="sa-notice-body">
                                    <strong>Valores definidos no ficheiro .env</strong>
                                    <p>Os campos marcados com <em>.env</em> vêm do ambiente do servidor e têm prioridade; só podem ser alterados no .env.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php $envChip = fn(string $k) => $fromEnv[$k] ? ' <span class="sa-secret-chip"><i class="bi bi-file-earmark-lock"></i> .env</span>' : ''; ?>

                        <div class="sa-form-grid">
                            <div class="sa-field">
                                <label class="form-label" for="mail_enabled">Email habilitado<?= $envChip('mail_enabled') ?></label>
                                <div class="sa-select-wrap">
                                    <select id="mail_enabled" name="mail_enabled" class="sa-select" <?= $fromEnv['mail_enabled'] ? 'disabled' : '' ?>>
                                        <option value="0" <?= !$current['mail_enabled'] ? 'selected' : '' ?>>Desativado</option>
                                        <option value="1" <?= $current['mail_enabled'] ? 'selected' : '' ?>>Ativo</option>
                                    </select>
                                    <i class="bi bi-chevron-down sa-select-chevron"></i>
                                </div>
                                <small class="sa-field-hint">Se desativado, os emails ficam apenas registados em <a href="<?= base_path('admin/emails.php') ?>">Emails enviados</a>.</small>
                            </div>

                            <div class="sa-field">
                                <label class="form-label" for="mail_from">Email remetente<?= $envChip('mail_from') ?></label>
                                <input id="mail_from" type="email" name="mail_from" class="form-control" required maxlength="160"
                                       value="<?= e($current['mail_from']) ?>"
                                       placeholder="no-reply@dominio.co.mz"
                                       autocomplete="off" <?= $fromEnv['mail_from'] ? 'disabled' : '' ?>>
                                <small class="sa-field-hint">Usado no "De:" dos emails automáticos.</small>
                            </div>

                            <div class="sa-field">
                                <label class="form-label" for="smtp_host">Servidor SMTP<?= $envChip('smtp_host') ?></label>
                                <input id="smtp_host" name="smtp_host" class="form-control" maxlength="190"
                                       value="<?= e(config_value('SMTP_HOST', 'smtp_host', '')) ?>"
                                       placeholder="smtp.dominio.co.mz" autocomplete="off" <?= $fromEnv['smtp_host'] ? 'disabled' : '' ?>>
                                <small class="sa-field-hint">Vazio = os emails ficam só registados.</small>
                            </div>

                            <div class="sa-field">
                                <label class="form-label" for="smtp_port">Porta<?= $envChip('smtp_port') ?></label>
                                <input id="smtp_port" name="smtp_port" type="number" min="1" max="65535" class="form-control"
                                       value="<?= e(config_value('SMTP_PORT', 'smtp_port', '587')) ?>" <?= $fromEnv['smtp_port'] ? 'disabled' : '' ?>>
                                <small class="sa-field-hint">587 (TLS/STARTTLS) ou 465 (SSL).</small>
                            </div>

                            <div class="sa-field">
                                <label class="form-label" for="smtp_secure">Segurança<?= $envChip('smtp_secure') ?></label>
                                <div class="sa-select-wrap">
                                    <?php $sec = config_value('SMTP_SECURE', 'smtp_secure', 'tls'); ?>
                                    <select id="smtp_secure" name="smtp_secure" class="sa-select" <?= $fromEnv['smtp_secure'] ? 'disabled' : '' ?>>
                                        <option value="tls" <?= $sec === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS)</option>
                                        <option value="ssl" <?= $sec === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                        <option value=""    <?= $sec === ''    ? 'selected' : '' ?>>Nenhuma (não recomendado)</option>
                                    </select>
                                    <i class="bi bi-chevron-down sa-select-chevron"></i>
                                </div>
                            </div>

                            <div class="sa-field">
                                <label class="form-label" for="smtp_user">Utilizador SMTP<?= $envChip('smtp_user') ?></label>
                                <input id="smtp_user" name="smtp_user" class="form-control" maxlength="190"
                                       value="<?= e(config_value('SMTP_USER', 'smtp_user', '')) ?>"
                                       autocomplete="off" <?= $fromEnv['smtp_user'] ? 'disabled' : '' ?>>
                                <small class="sa-field-hint">Vazio = sem autenticação.</small>
                            </div>

                            <div class="sa-field sa-field-full">
                                <?php $hasSmtpPass = config_value('SMTP_PASS', 'smtp_pass', '') !== ''; ?>
                                <label class="form-label" for="smtp_pass">
                                    Password SMTP<?= $envChip('smtp_pass') ?>
                                    <span class="sa-secret-chip <?= $hasSmtpPass ? '' : 'sa-secret-chip-empty' ?>">
                                        <i class="bi bi-<?= $hasSmtpPass ? 'check-circle-fill' : 'dash-circle' ?>"></i> <?= $hasSmtpPass ? 'definida' : 'não definida' ?>
                                    </span>
                                </label>
                                <div class="sa-secret-input">
                                    <input id="smtp_pass" type="password" name="smtp_pass" class="form-control"
                                           placeholder="Deixe vazio para manter a atual" autocomplete="new-password" <?= $fromEnv['smtp_pass'] ? 'disabled' : '' ?>>
                                    <button type="button" class="sa-secret-toggle" data-target="smtp_pass" aria-label="Mostrar password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <small class="sa-field-hint">Nunca é mostrada depois de guardada.</small>
                                <?php if ($hasSmtpPass && !$fromEnv['smtp_pass']): ?>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" name="clear_smtp_pass" value="1" id="clear_smtp_pass">
                                        <label class="form-check-label" for="clear_smtp_pass">Apagar a password SMTP atual</label>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                            <button type="submit" form="testEmailForm" class="btn btn-outline-primary">
                                <i class="bi bi-send"></i> Enviar email de teste
                            </button>
                            <small class="text-muted">Para <?= e(user()['email']) ?>, com a configuração <strong>guardada</strong>.
                                <a href="<?= base_path('admin/emails.php') ?>">Ver emails</a></small>
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
                                <span><i class="bi bi-hdd-rack"></i> SMTP</span>
                                <span class="sa-badge <?= $current['smtp_ready'] ? 'sa-badge-success' : 'sa-badge-secondary' ?>">
                                    <i class="bi bi-<?= $current['smtp_ready'] ? 'check-circle-fill' : 'journal-text' ?>"></i>
                                    <?= $current['smtp_ready'] ? 'A enviar' : 'Só registo' ?>
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

        <!-- Formulário do email de teste (fora do formulário principal; o botão usa form="testEmailForm") -->
        <form method="post" id="testEmailForm" class="d-none">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="test_email">
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