<?php
require_once __DIR__.'/includes/auth.php';
require_login();
$page_title = 'Segurança da conta';
$me = user();
$uid = (int)$me['id'];
$self = base_path('seguranca.php');

/* ============================================================
   AÇÕES (POST + CSRF)
   ============================================================ */
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'totp_start' && !(int)$me['totp_enabled']) {
        // O segredo só é gravado na conta depois de confirmado com um código válido.
        $_SESSION['totp_setup'] = totp_generate_secret();
    } elseif ($action === 'totp_cancel') {
        unset($_SESSION['totp_setup']);
    } elseif ($action === 'totp_confirm' && !(int)$me['totp_enabled'] && !empty($_SESSION['totp_setup'])) {
        if (!rate_limit('2fa_setup:' . $uid, 5, 600)) {
            flash('danger', 'Demasiadas tentativas. Aguarde 10 minutos.');
        } elseif (totp_verify($_SESSION['totp_setup'], (string)($_POST['code'] ?? ''))) {
            db()->prepare('UPDATE users SET totp_secret=?, totp_enabled=1 WHERE id=?')->execute([$_SESSION['totp_setup'], $uid]);
            unset($_SESSION['totp_setup']);
            audit($uid, '2fa_enable', 'auth', 'Verificação em dois passos ativada');
            notify($uid, 'Verificação em dois passos ativada', 'A partir de agora, o início de sessão pede um código da sua app autenticadora.');
            flash('success', 'Verificação em dois passos ativada.');
        } else {
            flash('danger', 'Código inválido. Confirme que o relógio do telemóvel está certo e tente novamente.');
        }
    } elseif ($action === 'totp_disable' && (int)$me['totp_enabled']) {
        if (!rate_limit('2fa_disable:' . $uid, 5, 600)) {
            flash('danger', 'Demasiadas tentativas. Aguarde 10 minutos.');
        } elseif (!password_verify((string)($_POST['password'] ?? ''), $me['password']) || !totp_verify((string)$me['totp_secret'], (string)($_POST['code'] ?? ''))) {
            audit($uid, '2fa_disable_failed', 'auth', 'Tentativa falhada de desativar a verificação em dois passos');
            flash('danger', 'Password ou código incorretos.');
        } else {
            db()->prepare('UPDATE users SET totp_secret=NULL, totp_enabled=0 WHERE id=?')->execute([$uid]);
            audit($uid, '2fa_disable', 'auth', 'Verificação em dois passos desativada');
            notify($uid, 'Verificação em dois passos desativada', 'A verificação em dois passos foi desativada na sua conta. Se não foi você, altere a password imediatamente.');
            flash('success', 'Verificação em dois passos desativada.');
        }
    } elseif ($action === 'logout_all') {
        revoke_remember_tokens($uid);
        remember_forget_current();
        audit($uid, 'logout_all', 'auth', 'Sessões "manter sessão iniciada" revogadas em todos os dispositivos');
        flash('success', 'Os outros dispositivos deixam de ter a sessão memorizada.');
    } else {
        flash('danger', 'Ação inválida.');
    }
    redirect($self);
}

$setupSecret = (int)$me['totp_enabled'] ? null : ($_SESSION['totp_setup'] ?? null);
$rememberCount = (int)db()->query('SELECT COUNT(*) FROM user_tokens WHERE type="remember" AND used_at IS NULL AND expires_at>NOW() AND user_id=' . $uid)->fetchColumn();
$recent = db()->prepare("SELECT action,description,ip,created_at FROM audit_logs WHERE user_id=? AND module='auth' ORDER BY id DESC LIMIT 8");
$recent->execute([$uid]);
$recent = $recent->fetchAll();

include __DIR__.'/includes/header.php';
?>
<div class="dashboard-layout">
    <?php include __DIR__.'/includes/sidebar.php'; ?>
    <section class="dashboard-content">
        <div class="dashboard-top">
            <div>
                <span class="eyebrow">Conta</span>
                <h1>Segurança da conta</h1>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">

                <!-- VERIFICAÇÃO EM DOIS PASSOS -->
                <div class="content-card mb-4">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <h4 class="mb-0"><i class="bi bi-phone-vibrate me-2"></i>Verificação em dois passos</h4>
                        <?php if ((int)$me['totp_enabled']): ?>
                            <span class="badge text-bg-success"><i class="bi bi-shield-check me-1"></i>Ativa</span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary"><i class="bi bi-shield-slash me-1"></i>Inativa</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-muted">Além da password, o início de sessão pede um código de 6 dígitos gerado por uma app autenticadora (Google Authenticator, Microsoft Authenticator, Aegis, 2FAS...).</p>

                    <?php if ((int)$me['totp_enabled']): ?>
                        <form method="post" class="row g-3" autocomplete="off" data-confirm="Desativar a verificação em dois passos?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="totp_disable">
                            <div class="col-md-6">
                                <label class="form-label" for="dis-password">Password atual</label>
                                <input id="dis-password" type="password" name="password" class="form-control" required autocomplete="current-password">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="dis-code">Código da app</label>
                                <input id="dis-code" name="code" class="form-control" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autocomplete="one-time-code">
                            </div>
                            <div class="col-12 text-end">
                                <button class="btn btn-outline-danger"><i class="bi bi-shield-slash me-1"></i>Desativar</button>
                            </div>
                        </form>
                    <?php elseif ($setupSecret): ?>
                        <ol class="mb-3">
                            <li>Na app autenticadora, escolha <em>adicionar conta</em> → <em>introduzir chave manualmente</em>.</li>
                            <li>Conta: <strong><?= e($me['email']) ?></strong> · Tipo: <em>baseado no tempo</em>.</li>
                            <li>Chave secreta:</li>
                        </ol>
                        <div class="p-3 mb-3 rounded border bg-body-tertiary text-center">
                            <code class="fs-5 user-select-all" style="letter-spacing:.08em"><?= e(totp_format_secret($setupSecret)) ?></code>
                        </div>
                        <details class="mb-3 small">
                            <summary>URI otpauth (para apps que aceitam colar o link)</summary>
                            <code class="d-block text-break user-select-all mt-2"><?= e(totp_uri($setupSecret, $me['email'])) ?></code>
                        </details>
                        <form method="post" class="row g-2 align-items-end" autocomplete="off">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="totp_confirm">
                            <div class="col-sm-6">
                                <label class="form-label" for="setup-code">Código de 6 dígitos mostrado na app</label>
                                <input id="setup-code" name="code" class="form-control form-control-lg" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus autocomplete="one-time-code">
                            </div>
                            <div class="col-sm-6 d-flex gap-2">
                                <button class="btn btn-primary btn-lg flex-grow-1"><i class="bi bi-check2 me-1"></i>Confirmar e ativar</button>
                            </div>
                        </form>
                        <div class="mt-2"><?= post_button($self, ['action' => 'totp_cancel'], 'Cancelar', 'btn btn-link px-0') ?></div>
                        <p class="small text-muted mb-0 mt-2"><i class="bi bi-info-circle me-1"></i>Guarde a chave num local seguro: se perder o telemóvel, precisará dela (ou do suporte) para recuperar o acesso.</p>
                    <?php else: ?>
                        <?= post_button($self, ['action' => 'totp_start'], '<i class="bi bi-shield-plus me-1"></i>Ativar verificação em dois passos', 'btn btn-primary') ?>
                    <?php endif; ?>
                </div>

                <!-- ATIVIDADE RECENTE -->
                <div class="content-card">
                    <h4><i class="bi bi-clock-history me-2"></i>Atividade recente</h4>
                    <?php if (!$recent): ?>
                        <p class="text-muted mb-0">Sem registos.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Data</th><th>Evento</th><th>IP</th></tr></thead>
                                <tbody>
                                <?php foreach ($recent as $r): ?>
                                    <tr>
                                        <td class="text-nowrap small"><?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td>
                                        <td class="small"><?= e($r['description']) ?></td>
                                        <td class="small text-muted"><?= e($r['ip']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-5">
                <!-- EMAIL -->
                <div class="content-card mb-4">
                    <h4><i class="bi bi-envelope-check me-2"></i>Email</h4>
                    <p class="mb-2"><strong><?= e($me['email']) ?></strong></p>
                    <?php if (email_is_verified($me)): ?>
                        <span class="badge text-bg-success"><i class="bi bi-check-circle-fill me-1"></i>Confirmado em <?= e(date('d/m/Y', strtotime($me['email_verified_at']))) ?></span>
                    <?php else: ?>
                        <p class="text-muted small">O email ainda não foi confirmado. Abra o link que lhe enviámos.</p>
                        <?= post_button(base_path('verificar-email.php'), ['action' => 'resend', 'back' => $self], '<i class="bi bi-arrow-repeat me-1"></i>Reenviar link', 'btn btn-outline-primary') ?>
                    <?php endif; ?>
                </div>

                <!-- SESSÕES -->
                <div class="content-card mb-4">
                    <h4><i class="bi bi-laptop me-2"></i>Sessões memorizadas</h4>
                    <p class="text-muted small">"Manter sessão iniciada" guarda um token durante <?= REMEMBER_DAYS ?> dias em cada browser. Tokens ativos: <strong><?= $rememberCount ?></strong>.</p>
                    <?= post_button($self, ['action' => 'logout_all'], '<i class="bi bi-door-closed me-1"></i>Esquecer todos os dispositivos', 'btn btn-outline-danger', 'Terminar as sessões memorizadas em todos os dispositivos?') ?>
                </div>

                <!-- PASSWORD -->
                <div class="content-card">
                    <h4><i class="bi bi-key me-2"></i>Password</h4>
                    <p class="text-muted small mb-3">Use uma password longa e única. Alterar a password termina as sessões memorizadas.</p>
                    <a class="btn btn-outline-secondary" href="<?= base_path($me['role'] === 'farmer' ? 'agricultor/perfil.php' : ($me['role'] === 'buyer' ? 'comprador/perfil.php' : 'admin/perfil.php')) ?>"><i class="bi bi-pencil me-1"></i>Alterar no perfil</a>
                </div>
            </div>
        </div>
    </section>
</div>
<?php include __DIR__.'/includes/footer.php'; ?>
