<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';

require_role('farmer');

$page_title = 'Meu perfil';
$fid = user()['farmer_id'];
$pdo = db();

/* ============================================================
   CARREGAR PERFIL
   ============================================================ */
$s = $pdo->prepare('
    SELECT f.*, u.name, u.email, u.phone, u.avatar
    FROM farmers f
    JOIN users u ON u.id = f.user_id
    WHERE f.id = ?
');
$s->execute([$fid]);
$p = $s->fetch() ?: [];

/* ============================================================
   PROCESSAR POST
   ============================================================ */
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    $action = $_POST['action'] ?? 'profile';

    try {
        if ($action === 'document') {
            $type = in_array($_POST['document_type'] ?? '', ['identity','license','certificate','other'], true)
                ? $_POST['document_type']
                : 'other';

            // Validade: obrigatória para identificação e licença; tem de ser uma data futura.
            $expires = trim((string)($_POST['expires_at'] ?? ''));
            if ($expires === '' && document_type_requires_expiry($type)) throw new RuntimeException('Indique a data de validade do documento (' . mb_strtolower(document_type_label($type)) . ').');
            if ($expires !== '') {
                $dt = DateTime::createFromFormat('!Y-m-d', $expires);
                if (!$dt || $dt->format('Y-m-d') !== $expires) throw new RuntimeException('Data de validade inválida.');
                if ($dt < new DateTime('today')) throw new RuntimeException('O documento já expirou. Envie um documento dentro da validade.');
            }

            $path = save_farmer_document($_FILES['document'] ?? []);
            $pdo->prepare('INSERT INTO farmer_documents(farmer_id, document_type, file_path, expires_at) VALUES(?,?,?,?)')
                ->execute([$fid, $type, $path, $expires ?: null]);

            audit(current_user_id(), 'upload', 'farmer_documents', 'Agricultor enviou documento (' . $type . ($expires ? ', válido até ' . $expires : '') . ')');
            flash('success', 'Documento enviado para análise.');

        } elseif ($action === 'password') {
            $current = (string)($_POST['current_password'] ?? '');
            $new     = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');

            $s = $pdo->prepare('SELECT password FROM users WHERE id = ?');
            $s->execute([current_user_id()]);
            if (!password_verify($current, (string)$s->fetchColumn())) throw new RuntimeException('A palavra-passe atual está incorreta.');
            if (!strong_password($new)) throw new RuntimeException('A nova palavra-passe deve ter pelo menos 10 caracteres, com maiúsculas, minúsculas e números.');
            if ($new !== $confirm) throw new RuntimeException('A confirmação não corresponde à nova palavra-passe.');
            if ($new === $current) throw new RuntimeException('A nova palavra-passe deve ser diferente da atual.');

            $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), current_user_id()]);
            revoke_remember_tokens(current_user_id());
            session_regenerate_id(true);
            audit(current_user_id(), 'password_change', 'users', 'Agricultor alterou a palavra-passe');
            flash('success', 'Palavra-passe alterada com sucesso.');

        } else {
            $name     = trim((string)($_POST['name'] ?? ''));
            $phone    = trim((string)($_POST['phone'] ?? ''));
            $province = (string)($_POST['province'] ?? '');
            $district = trim((string)($_POST['district'] ?? ''));

            if (mb_strlen($name) < 3 || mb_strlen($name) > 120 || !preg_match('/^[\p{L}\p{M} .\'-]+$/u', $name)) throw new RuntimeException('Indique um nome válido (3 a 120 letras).');
            if (!preg_match('/^\+?[0-9 ]{9,20}$/', $phone)) throw new RuntimeException('Indique um número de telefone válido (ex.: +258 84 123 4567).');
            if (!in_array($province, provinces(), true)) throw new RuntimeException('Selecione uma província válida.');
            if ($district === '' || mb_strlen($district) > 100) throw new RuntimeException('Indique o distrito.');

            $avatar = $p['avatar'] ?? null;
            if (($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $avatar = save_farmer_photo($_FILES['avatar']);
            }

            $pdo->prepare('UPDATE users SET name = ?, phone = ?, avatar = ? WHERE id = ?')
                ->execute([$name, $phone, $avatar, user()['id']]);

            $pdo->prepare('UPDATE farmers SET province = ?, district = ?, locality = ?, specialty = ?, bio = ? WHERE id = ?')
                ->execute([
                    $province,
                    $district,
                    mb_substr(trim((string)($_POST['locality'] ?? '')), 0, 100),
                    mb_substr(trim((string)($_POST['specialty'] ?? '')), 0, 140),
                    mb_substr(trim((string)($_POST['bio'] ?? '')), 0, 2000),
                    $fid,
                ]);

            audit(current_user_id(), 'update', 'profile', 'Perfil de agricultor atualizado');
            flash('success', 'Perfil atualizado.');
        }
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
    } catch (Throwable $e) {
        error_log($e->getMessage());
        flash('danger', 'Não foi possível guardar as alterações.');
    }
    redirect(base_path('agricultor/perfil.php'));
}

/* ============================================================
   DOCUMENTOS
   ============================================================ */
maybe_check_document_expiry();
$docs = [];
try {
    $s = $pdo->prepare('SELECT * FROM farmer_documents WHERE farmer_id = ? ORDER BY created_at DESC');
    $s->execute([$fid]);
    $docs = $s->fetchAll() ?: [];
} catch (Throwable $e) {
    $docs = [];
}

/* ============================================================
   ESTADO DO PERFIL
   ============================================================ */
$verifyStatus = $p['verification_status'] ?? 'pending';
$verifyMeta = match ($verifyStatus) {
    'verified' => ['success', 'shield-fill-check',   'Verificado'],
    'rejected' => ['danger',  'shield-fill-x',       'Rejeitado'],
    default    => ['warning', 'shield-fill-exclamation','Pendente'],
};

$counts = [
    'docs_total'    => count($docs),
    'docs_approved' => 0,
    'docs_pending'  => 0,
    'docs_rejected' => 0,
    'docs_expired'  => 0,
    'docs_expiring' => 0,
];
foreach ($docs as $d) {
    $st = $d['status'] ?? 'pending';
    $left = document_days_left($d['expires_at'] ?? null);
    if ($st === 'expired' || ($st === 'approved' && $left !== null && $left < 0)) $counts['docs_expired']++;
    elseif ($st === 'approved') { $counts['docs_approved']++; if ($left !== null && $left <= 30) $counts['docs_expiring']++; }
    elseif ($st === 'rejected') $counts['docs_rejected']++;
    else $counts['docs_pending']++;
}

/* Províncias */
$provincias = provinces();

$currentProvince = $p['province'] ?? '';

include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <!-- ================= HERO ================= -->
        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Conta · Perfil
                </span>
                <h1 class="sa-hero-title">O meu <em>perfil</em></h1>
                <p class="sa-hero-sub">
                    Estado da conta:
                    <span class="sa-badge sa-badge-<?= $verifyMeta[0] ?> sa-badge-inline">
                        <i class="bi bi-<?= $verifyMeta[1] ?>"></i>
                        <?= $verifyMeta[2] ?>
                    </span>
                    · <strong><?= $counts['docs_total'] ?></strong> documento<?= $counts['docs_total'] !== 1 ? 's' : '' ?>.
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('agricultor/dashboard.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <a href="<?= base_path('agricultor/notificacoes.php') ?>" class="btn btn-primary">
                    <i class="bi bi-bell"></i> Ver notificações
                </a>
            </div>
        </header>

        <!-- ================= KPIs ================= -->
        <div class="sa-kpi-grid sa-kpi-grid-4">

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-person-badge"></i></span>
                </header>
                <span class="sa-kpi-label">Estado da conta</span>
                <strong class="sa-kpi-value" style="font-size:1.15rem;"><?= $verifyMeta[2] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub">
                        <i class="bi bi-<?= $verifyMeta[1] ?>"></i>
                        verificação
                    </span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-file-earmark-check"></i></span>
                </header>
                <span class="sa-kpi-label">Documentos aprovados</span>
                <strong class="sa-kpi-value"><?= $counts['docs_approved'] ?></strong>
                <footer class="sa-kpi-foot">
                    <?php if ($counts['docs_expired'] || $counts['docs_expiring']): ?>
                        <span class="sa-kpi-sub text-danger"><i class="bi bi-calendar-x"></i> <?= $counts['docs_expiring'] ?> a expirar · <?= $counts['docs_expired'] ?> expirado(s)</span>
                    <?php else: ?>
                        <span class="sa-kpi-sub"><i class="bi bi-check2-circle"></i> validados</span>
                    <?php endif; ?>
                </footer>
            </article>

            <article class="sa-kpi <?= $counts['docs_pending'] > 0 ? 'sa-kpi-warn' : '' ?>">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-warning"><i class="bi bi-hourglass-split"></i></span>
                    <?php if ($counts['docs_pending'] > 0): ?>
                        <span class="sa-kpi-badge sa-badge-warn">em análise</span>
                    <?php endif; ?>
                </header>
                <span class="sa-kpi-label">Pendentes</span>
                <strong class="sa-kpi-value"><?= $counts['docs_pending'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-clock-history"></i> a aguardar revisão</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-geo-alt"></i></span>
                </header>
                <span class="sa-kpi-label">Localização</span>
                <strong class="sa-kpi-value" style="font-size:1.1rem;">
                    <?= e($currentProvince ?: '—') ?>
                </strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub">
                        <i class="bi bi-pin-map"></i>
                        <?= e($p['district'] ?? '—') ?>
                    </span>
                </footer>
            </article>

        </div>

        <!-- ================= CONTEÚDO ================= -->
        <div class="row g-4">

            <!-- ===== COLUNA ESQUERDA: PERFIL ===== -->
            <div class="col-lg-8">

                <article class="content-card sa-card">
                    <div class="card-heading">
                        <div class="sa-card-head-icon">
                            <span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-person-vcard"></i></span>
                            <div>
                                <h4>Informação do perfil</h4>
                                <p class="sa-card-sub">Dados visíveis para os compradores</p>
                            </div>
                        </div>
                    </div>

                    <form method="post" enctype="multipart/form-data" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="profile">

                        <!-- Avatar -->
                        <div class="sa-avatar-row">
                            <div class="sa-avatar-preview">
                                <img id="avatarPreview"
                                     src="<?= e(function_exists('get_farmer_image') ? get_farmer_image($p['avatar'] ?? null, $fid) : '') ?>"
                                     alt="Fotografia de <?= e($p['name'] ?? 'agricultor') ?>">
                            </div>
                            <div class="sa-avatar-info">
                                <label class="form-label" for="pAvatar">Fotografia de perfil</label>
                                <input type="file"
                                       id="pAvatar"
                                       name="avatar"
                                       class="form-control"
                                       accept="image/jpeg,image/png,image/webp">
                                <small class="sa-field-hint">JPG, PNG ou WEBP · máx. 3 MB</small>
                            </div>
                        </div>

                        <div class="sa-form-grid">

                            <div class="sa-field">
                                <label class="form-label" for="pName">Nome <span class="sa-req">*</span></label>
                                <input id="pName" type="text" name="name" class="form-control"
                                       value="<?= e($p['name'] ?? '') ?>" required maxlength="100">
                            </div>

                            <div class="sa-field">
                                <label class="form-label" for="pPhone">Telefone <span class="sa-req">*</span></label>
                                <input id="pPhone" type="text" name="phone" class="form-control"
                                       value="<?= e($p['phone'] ?? '') ?>" required maxlength="30">
                            </div>

                            <div class="sa-field">
                                <label class="form-label" for="pEmail">Email</label>
                                <input id="pEmail" type="email" class="form-control"
                                       value="<?= e($p['email'] ?? '') ?>" disabled>
                                <small class="sa-field-hint">Não editável. Contacte o suporte para alterar.</small>
                            </div>

                            <div class="sa-field">
                                <label class="form-label" for="pSpecialty">Especialidade</label>
                                <input id="pSpecialty" type="text" name="specialty" class="form-control"
                                       value="<?= e($p['specialty'] ?? '') ?>"
                                       placeholder="Ex: Horticultura, avicultura…" maxlength="80">
                            </div>

                            <div class="sa-field">
                                <label class="form-label" for="pProvince">Província <span class="sa-req">*</span></label>
                                <div class="sa-select-wrap">
                                    <select id="pProvince" name="province" class="sa-select" required>
                                        <option value="">Escolha…</option>
                                        <?php foreach ($provincias as $prov): ?>
                                            <option value="<?= e($prov) ?>"
                                                <?= $currentProvince === $prov ? 'selected' : '' ?>>
                                                <?= e($prov) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <i class="bi bi-chevron-down sa-select-chevron"></i>
                                </div>
                            </div>

                            <div class="sa-field">
                                <label class="form-label" for="pDistrict">Distrito <span class="sa-req">*</span></label>
                                <input id="pDistrict" type="text" name="district" class="form-control"
                                       value="<?= e($p['district'] ?? '') ?>" required maxlength="80">
                            </div>

                            <div class="sa-field sa-field-full">
                                <label class="form-label" for="pLocality">Localidade</label>
                                <input id="pLocality" type="text" name="locality" class="form-control"
                                       value="<?= e($p['locality'] ?? '') ?>" maxlength="120">
                            </div>

                            <div class="sa-field sa-field-full">
                                <label class="form-label" for="pBio">Sobre o agricultor</label>
                                <textarea id="pBio" name="bio" class="form-control" rows="4"
                                          maxlength="500"
                                          placeholder="Fale sobre a sua quinta, métodos de produção, produtos típicos…"><?= e($p['bio'] ?? '') ?></textarea>
                                <div class="sa-char-counter">
                                    <span id="bioCount">0</span> / 500 caracteres
                                </div>
                            </div>

                        </div>

                        <div class="sa-form-actions">
                            <button type="submit" class="sa-submit">
                                <i class="bi bi-save2"></i> Guardar alterações
                            </button>
                        </div>
                    </form>
                </article>

                <!-- ALTERAR PALAVRA-PASSE -->
                <article class="content-card sa-card mt-4">
                    <div class="card-heading">
                        <div class="sa-card-head-icon">
                            <span class="sa-kpi-icon sa-icon-warning"><i class="bi bi-key"></i></span>
                            <div>
                                <h4>Alterar palavra-passe</h4>
                                <p class="sa-card-sub">Mínimo 10 caracteres, com maiúsculas, minúsculas e números</p>
                            </div>
                        </div>
                    </div>

                    <form method="post" autocomplete="off">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="password">
                        <div class="sa-form-grid">
                            <div class="sa-field sa-field-full">
                                <label class="form-label" for="pwCurrent">Palavra-passe atual <span class="sa-req">*</span></label>
                                <input id="pwCurrent" type="password" name="current_password" class="form-control" required autocomplete="current-password">
                            </div>
                            <div class="sa-field">
                                <label class="form-label" for="pwNew">Nova palavra-passe <span class="sa-req">*</span></label>
                                <input id="pwNew" type="password" name="new_password" class="form-control" required minlength="10" autocomplete="new-password">
                            </div>
                            <div class="sa-field">
                                <label class="form-label" for="pwConfirm">Confirmar nova palavra-passe <span class="sa-req">*</span></label>
                                <input id="pwConfirm" type="password" name="confirm_password" class="form-control" required minlength="10" autocomplete="new-password">
                            </div>
                        </div>
                        <div class="sa-form-actions">
                            <button type="submit" class="sa-submit">
                                <i class="bi bi-shield-lock"></i> Alterar palavra-passe
                            </button>
                        </div>
                    </form>
                </article>

            </div>

            <!-- ===== COLUNA DIREITA: DOCUMENTOS ===== -->
            <div class="col-lg-4">

                <!-- Upload de documento -->
                <article class="content-card sa-card mb-4">
                    <div class="card-heading">
                        <div class="sa-card-head-icon">
                            <span class="sa-kpi-icon sa-icon-info"><i class="bi bi-cloud-arrow-up"></i></span>
                            <div>
                                <h4>Enviar documento</h4>
                                <p class="sa-card-sub">Identidade, licença ou certificado</p>
                            </div>
                        </div>
                    </div>

                    <form method="post" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="document">

                        <div class="sa-field">
                            <label class="form-label" for="dType">Tipo</label>
                            <div class="sa-select-wrap">
                                <select id="dType" name="document_type" class="sa-select">
                                    <option value="identity" data-expiry-required="1">Identidade</option>
                                    <option value="license" data-expiry-required="1">Licença</option>
                                    <option value="certificate">Certificado</option>
                                    <option value="other">Outro</option>
                                </select>
                                <i class="bi bi-chevron-down sa-select-chevron"></i>
                            </div>
                        </div>

                        <div class="sa-field">
                            <label class="form-label" for="dFile">Ficheiro <span class="sa-req">*</span></label>
                            <input id="dFile" type="file" name="document" class="form-control"
                                   accept="application/pdf,image/jpeg,image/png" required>
                            <small class="sa-field-hint">PDF, JPG ou PNG · máx. 5 MB</small>
                        </div>

                        <div class="sa-field">
                            <label class="form-label" for="dExpires">Validade <span class="sa-req" id="dExpiresReq">*</span></label>
                            <input id="dExpires" type="date" name="expires_at" class="form-control" min="<?= date('Y-m-d') ?>" required aria-describedby="dExpiresHint">
                            <small class="sa-field-hint" id="dExpiresHint">Obrigatória para identidade e licença; opcional para os restantes.</small>
                        </div>

                        <button type="submit" class="sa-submit">
                            <i class="bi bi-upload"></i> Enviar documento
                        </button>
                    </form>
                </article>

                <!-- Lista de documentos -->
                <article class="content-card sa-card">
                    <div class="card-heading">
                        <div>
                            <h4>Documentos enviados</h4>
                            <p class="sa-card-sub"><?= $counts['docs_total'] ?> no total</p>
                        </div>
                    </div>

                    <?php if (empty($docs)): ?>
                        <div class="empty-state py-4">
                            <i class="bi bi-file-earmark" style="font-size:2rem;"></i>
                            <p class="mt-3 mb-0 text-muted">Ainda sem documentos.</p>
                        </div>
                    <?php else: ?>
                        <div class="sa-doc-list">
                            <?php foreach ($docs as $doc):
                                $st = $doc['status'] ?? 'pending';
                                $meta = match ($st) {
                                    'approved' => ['success', 'check-circle-fill', 'Aprovado'],
                                    'rejected' => ['danger',  'x-octagon-fill',    'Rejeitado'],
                                    'expired'  => ['danger',  'clock-history',     'Expirado'],
                                    default    => ['warning', 'hourglass-split',   'Pendente'],
                                };

                                $typeIcon = match ($doc['document_type'] ?? '') {
                                    'identity'    => 'person-vcard',
                                    'license'     => 'file-earmark-check',
                                    'certificate' => 'award',
                                    default       => 'file-earmark',
                                };

                                $hasFile = !empty($doc['file_path']);
                                $fileUrl = $hasFile && function_exists('upload_url')
                                    ? upload_url($doc['file_path'])
                                    : '#';
                            ?>
                                <div class="sa-doc">
                                    <span class="sa-doc-icon">
                                        <i class="bi bi-<?= $typeIcon ?>"></i>
                                    </span>
                                    <div class="sa-doc-info">
                                        <strong><?= e(document_type_label($doc['document_type'])) ?></strong>
                                        <small>
                                            Enviado a <?= date('d/m/Y', strtotime($doc['created_at'] ?? 'now')) ?>
                                            <?php if (!empty($doc['expires_at'])): ?>
                                                · válido até <?= date('d/m/Y', strtotime($doc['expires_at'])) ?>
                                            <?php endif; ?>
                                        </small>
                                        <?php if (!empty($doc['expires_at']) && $st !== 'rejected'): ?>
                                            <div class="mt-1"><?= document_expiry_badge($doc['expires_at'], $st) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="sa-doc-side">
                                        <span class="sa-badge sa-badge-<?= $meta[0] ?> sa-badge-sm">
                                            <i class="bi bi-<?= $meta[1] ?>"></i>
                                            <?= $meta[2] ?>
                                        </span>
                                        <?php if ($hasFile): ?>
                                            <a href="<?= e($fileUrl) ?>"
                                               target="_blank" rel="noopener"
                                               class="sa-doc-link"
                                               title="Ver documento">
                                                <i class="bi bi-box-arrow-up-right"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>

            </div>
        </div>

    </section>
</div>

<script>
(function () {
    /* ============================================================
       Preview do avatar
       ============================================================ */
    var input   = document.getElementById('pAvatar');
    var preview = document.getElementById('avatarPreview');

    if (input && preview) {
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) return;
            if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
                alert('Formato inválido. Use JPG, PNG ou WEBP.');
                input.value = '';
                return;
            }
            if (file.size > 3 * 1024 * 1024) {
                alert('Ficheiro demasiado grande. Máximo 3 MB.');
                input.value = '';
                return;
            }
            preview.src = URL.createObjectURL(file);
        });
    }

    /* ============================================================
       Validade obrigatória só para identidade e licença
       ============================================================ */
    var dType = document.getElementById('dType');
    var dExp  = document.getElementById('dExpires');
    var dReq  = document.getElementById('dExpiresReq');
    if (dType && dExp) {
        var syncExp = function () {
            var opt = dType.options[dType.selectedIndex];
            var req = !!(opt && opt.dataset.expiryRequired);
            dExp.required = req;
            if (dReq) dReq.style.display = req ? '' : 'none';
        };
        dType.addEventListener('change', syncExp);
        syncExp();
    }

    /* ============================================================
       Contador da bio
       ============================================================ */
    var bio = document.getElementById('pBio');
    var bioCount = document.getElementById('bioCount');
    if (bio && bioCount) {
        var upd = function () { bioCount.textContent = bio.value.length; };
        bio.addEventListener('input', upd);
        upd();
    }
})();
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>