<?php
require_once __DIR__.'/../includes/permissions.php';
require_role('superadmin');

$page_title = 'Verificações';
$pdo = db();
$docTypeLabels = ['identity' => 'Identificação', 'license' => 'Licença', 'certificate' => 'Certificado', 'other' => 'Outro'];
$docStatusLabels = ['approved' => 'aprovado', 'rejected' => 'rejeitado', 'expired' => 'expirado'];
$farmerStatusLabels = ['verified' => 'Verificado', 'rejected' => 'Rejeitado', 'pending' => 'Pendente', 'unverified' => 'Não verificado'];

/* ============================================================
   AÇÕES (POST)
   ============================================================ */
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    $action  = $_POST['action'] ?? '';
    $fid     = safe_int($_POST['farmer_id'] ?? 0);
    $docId   = safe_int($_POST['document_id'] ?? 0);
    $status  = (string)($_POST['status'] ?? '');
    $notes   = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 1000);

    if ($action === 'document') {
        $s = $pdo->prepare('SELECT fd.id, fd.document_type, fd.expires_at, f.user_id FROM farmer_documents fd JOIN farmers f ON f.id=fd.farmer_id WHERE fd.id=?');
        $s->execute([$docId]);
        $doc = $s->fetch();
        if (!$doc || !isset($docStatusLabels[$status])) {
            flash('danger', 'Documento ou estado inválido.');
        } elseif ($status === 'approved' && !empty($doc['expires_at']) && document_days_left($doc['expires_at']) < 0) {
            flash('danger', 'Não é possível aprovar um documento cuja validade já terminou. Marque-o como expirado.');
        } else {
            // Ao rever de novo, o aviso de validade pode voltar a ser enviado.
            if ($status === 'approved') $pdo->prepare('UPDATE farmer_documents SET expiry_notified_at=NULL WHERE id=?')->execute([$docId]);
            // Mantém as notas existentes quando o campo vem vazio.
            $pdo->prepare('UPDATE farmer_documents SET status=?, notes=COALESCE(NULLIF(?,""),notes), reviewed_by=? WHERE id=?')
                ->execute([$status, $notes, current_user_id(), $docId]);
            if ($status !== 'approved') {
                notify((int)$doc['user_id'], 'Documento ' . $docStatusLabels[$status], 'O seu documento (' . ($docTypeLabels[$doc['document_type']] ?? $doc['document_type']) . ') foi ' . $docStatusLabels[$status] . '.' . ($notes !== '' ? ' Motivo: ' . $notes : ' Envie um documento válido para continuar a verificação.'));
            } else {
                notify((int)$doc['user_id'], 'Documento aprovado', 'O seu documento (' . ($docTypeLabels[$doc['document_type']] ?? $doc['document_type']) . ') foi aprovado.');
            }
            audit(current_user_id(), 'review', 'farmer_documents', 'Documento #' . $docId . ' revisto como ' . $status . ($notes !== '' ? ' — ' . $notes : ''));
            flash('success', 'Documento atualizado.');
        }
    } elseif ($action === 'farmer') {
        $s = $pdo->prepare('SELECT user_id FROM farmers WHERE id=?');
        $s->execute([$fid]);
        $uid = (int)$s->fetchColumn();
        if (!$uid || !in_array($status, ['verified', 'rejected'], true)) {
            flash('danger', 'Agricultor ou estado inválido.');
        } else {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE farmers SET verification_status=?, verified_at=CASE WHEN ?="verified" THEN NOW() ELSE NULL END WHERE id=?')
                ->execute([$status, $status, $fid]);
            $pdo->prepare('INSERT INTO farmer_verifications(farmer_id,status,notes,reviewed_by) VALUES(?,?,?,?)')
                ->execute([$fid, $status, $notes !== '' ? $notes : null, current_user_id()]);
            $pdo->commit();
            notify($uid, 'Verificação de perfil', 'O seu perfil foi atualizado para: ' . $farmerStatusLabels[$status] . '.' . ($notes !== '' ? ' Nota: ' . $notes : ''));
            audit(current_user_id(), 'review', 'farmers', 'Agricultor #' . $fid . ' revisto como ' . $status . ($notes !== '' ? ' — ' . $notes : ''));
            flash('success', 'Perfil atualizado.');
        }
    } else {
        flash('danger', 'Ação inválida.');
    }

    redirect(base_path('admin/verificacoes.php' . (($_GET['docs'] ?? '') !== '' ? '?docs=' . urlencode((string)$_GET['docs']) : '')));
}

// Verificação preguiçosa da validade dos documentos (no máx. 1x/hora por sessão).
maybe_check_document_expiry();

/* Filtro da lista de documentos */
$docFilters = ['pending' => 'Pendentes', 'expiring' => 'A expirar em 30 dias', 'expired' => 'Expirados', 'all' => 'Todos'];
$docFilter = (string)($_GET['docs'] ?? 'pending');
if (!isset($docFilters[$docFilter])) $docFilter = 'pending';
$docWhere = [
    'pending'  => "fd.status = 'pending'",
    'expiring' => "fd.status = 'approved' AND fd.expires_at IS NOT NULL AND fd.expires_at BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY",
    'expired'  => "(fd.status = 'expired' OR (fd.status IN ('approved','pending') AND fd.expires_at IS NOT NULL AND fd.expires_at < CURDATE()))",
    'all'      => '1=1',
][$docFilter];
$docOrder = $docFilter === 'pending' ? 'fd.created_at ASC' : 'fd.expires_at IS NULL, fd.expires_at ASC, fd.created_at DESC';
$docCounts = $pdo->query("SELECT
    SUM(status='pending') pending,
    SUM(status='approved' AND expires_at IS NOT NULL AND expires_at BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY) expiring,
    SUM(status='expired' OR (status IN ('approved','pending') AND expires_at IS NOT NULL AND expires_at < CURDATE())) expired,
    COUNT(*) `all`
    FROM farmer_documents")->fetch();

/* ============================================================
   LISTAS PENDENTES (perfis pendentes e não verificados)
   ============================================================ */
$farmers = $pdo->query("
    SELECT f.*, u.name, u.email, COUNT(fd.id) AS documents_count
    FROM farmers f
    JOIN users u ON u.id = f.user_id
    LEFT JOIN farmer_documents fd ON fd.farmer_id = f.id AND fd.status = 'pending'
    WHERE f.verification_status IN ('pending','unverified')
    GROUP BY f.id
    ORDER BY f.verification_status = 'pending' DESC, f.created_at ASC
")->fetchAll();

$docs = $pdo->query("
    SELECT fd.*, f.id AS farmer_id, u.name AS farmer_name
    FROM farmer_documents fd
    JOIN farmers f ON f.id = fd.farmer_id
    JOIN users u ON u.id = f.user_id
    WHERE $docWhere
    ORDER BY $docOrder
    LIMIT 300
")->fetchAll();

$pendingDocs = (int)($docCounts['pending'] ?? 0);
$totalPending = count($farmers) + $pendingDocs;

include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <!-- ================= HERO ================= -->
        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Revisão de perfis · Verificações
                </span>
                <h1 class="sa-hero-title">Verificações <em>pendentes</em></h1>
                <p class="sa-hero-sub">
                    <?php if ($totalPending > 0): ?>
                        Existem <strong><?= count($farmers) ?></strong> perfis e
                        <strong><?= $pendingDocs ?></strong> documentos a aguardar revisão.
                    <?php else: ?>
                        Não há nada pendente. Tudo em ordem.
                    <?php endif; ?>
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('admin/dashboard.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <a href="<?= base_path('admin/agricultores.php') ?>" class="btn btn-primary">
                    <i class="bi bi-people"></i> Ver agricultores
                </a>
            </div>
        </header>

        <!-- ================= KPIs ================= -->
        <div class="sa-kpi-grid sa-kpi-grid-4">

            <article class="sa-kpi <?= count($farmers) > 0 ? 'sa-kpi-warn' : '' ?>">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-warning"><i class="bi bi-person-exclamation"></i></span>
                    <?php if (count($farmers) > 0): ?>
                        <span class="sa-kpi-badge sa-badge-warn">ação necessária</span>
                    <?php endif; ?>
                </header>
                <span class="sa-kpi-label">Perfis pendentes</span>
                <strong class="sa-kpi-value"><?= count($farmers) ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-person-check"></i> aguardam verificação</span>
                </footer>
            </article>

            <article class="sa-kpi <?= $pendingDocs > 0 ? 'sa-kpi-warn' : '' ?>">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-warning"><i class="bi bi-file-earmark-text"></i></span>
                    <?php if ($pendingDocs > 0): ?>
                        <span class="sa-kpi-badge sa-badge-warn">revisão</span>
                    <?php endif; ?>
                </header>
                <span class="sa-kpi-label">Documentos pendentes</span>
                <strong class="sa-kpi-value"><?= $pendingDocs ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-file-earmark-check"></i> a aguardar</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-info"><i class="bi bi-diagram-3"></i></span>
                </header>
                <span class="sa-kpi-label">Total em fila</span>
                <strong class="sa-kpi-value"><?= $totalPending ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-list-task"></i> itens para revisão</span>
                </footer>
            </article>

            <?php $expN = (int)($docCounts['expiring'] ?? 0) + (int)($docCounts['expired'] ?? 0); ?>
            <article class="sa-kpi <?= $expN > 0 ? 'sa-kpi-warn' : '' ?>">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-<?= $expN > 0 ? 'warning' : 'success' ?>"><i class="bi bi-calendar-x"></i></span>
                    <?php if ($expN > 0): ?>
                        <span class="sa-kpi-badge sa-badge-warn">validade</span>
                    <?php endif; ?>
                </header>
                <span class="sa-kpi-label">Validade de documentos</span>
                <strong class="sa-kpi-value"><?= $expN ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub">
                        <i class="bi bi-hourglass-split"></i>
                        <a href="?docs=expiring#documentos"><?= (int)($docCounts['expiring'] ?? 0) ?> a expirar</a> ·
                        <a href="?docs=expired#documentos"><?= (int)($docCounts['expired'] ?? 0) ?> expirados</a>
                    </span>
                </footer>
            </article>

        </div>

        <!-- ================= PERFIS PENDENTES ================= -->
        <article class="content-card sa-card mb-4">

            <div class="card-heading sa-card-head">
                <div>
                    <h4>Perfis por verificar</h4>
                    <p class="sa-card-sub"><?= count($farmers) ?> registos pendentes ou não verificados</p>
                </div>

                <?php if (count($farmers) > 0): ?>
                    <div class="sa-toolbar">
                        <div class="sa-search">
                            <i class="bi bi-search"></i>
                            <input type="text" id="farmSearch" placeholder="Procurar agricultor…">
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (empty($farmers)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-check2-circle" style="font-size:2.5rem;"></i>
                    <p class="mt-3 mb-0">Não há perfis pendentes.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive sa-table-wrap">
                    <table class="table align-middle sa-table" id="farmTable">
                        <thead>
                            <tr>
                                <th>Agricultor</th>
                                <th>Email</th>
                                <th>Localização</th>
                                <th class="text-center">Documentos</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($farmers as $f):
                            $search = strtolower(($f['name'] ?? '') . ' ' . ($f['email'] ?? ''));
                            $docsN = (int)$f['documents_count'];
                        ?>
                            <tr data-search="<?= e($search) ?>">
                                <td>
                                    <div class="sa-user-cell">
                                        <span class="sa-avatar">
                                            <?= e(mb_strtoupper(mb_substr($f['name'] ?? '?', 0, 1))) ?>
                                        </span>
                                        <div class="sa-user-info">
                                            <strong><?= e($f['name']) ?></strong>
                                            <small>#<?= (int)$f['id'] ?> · <span class="badge text-bg-<?= $f['verification_status'] === 'pending' ? 'warning' : 'secondary' ?>"><?= e($farmerStatusLabels[$f['verification_status']] ?? $f['verification_status']) ?></span></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="sa-contact-line">
                                        <i class="bi bi-envelope"></i>
                                        <?= e($f['email']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="sa-location">
                                        <i class="bi bi-geo-alt"></i>
                                        <?= e($f['district'] ?: ($f['province'] ?: '—')) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($docsN > 0): ?>
                                        <span class="sa-count sa-count-primary"><?= $docsN ?></span>
                                    <?php else: ?>
                                        <span class="sa-count">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <form method="post" class="sa-verify-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="farmer">
                                        <input type="hidden" name="farmer_id" value="<?= (int)$f['id'] ?>">
                                        <label class="visually-hidden" for="fnotes<?= (int)$f['id'] ?>">Notas / motivo</label>
                                        <textarea id="fnotes<?= (int)$f['id'] ?>" name="notes" rows="1" maxlength="1000" class="form-control form-control-sm mb-1" placeholder="Notas / motivo (enviado ao agricultor)"></textarea>
                                        <div class="sa-actions">
                                            <button class="sa-btn sa-btn-success" type="submit" name="status" value="verified" title="Aprovar perfil">
                                                <i class="bi bi-check-lg"></i>
                                                <span>Aprovar</span>
                                            </button>
                                            <button class="sa-btn sa-btn-danger" type="submit" name="status" value="rejected" title="Rejeitar perfil">
                                                <i class="bi bi-x-lg"></i>
                                                <span>Rejeitar</span>
                                            </button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div id="farmNoResults" class="empty-state py-5" style="display:none;">
                    <i class="bi bi-search" style="font-size:2rem;"></i>
                    <p class="mt-3 mb-0">Nenhum resultado encontrado.</p>
                </div>
            <?php endif; ?>

        </article>

        <!-- ================= DOCUMENTOS ================= -->
        <article class="content-card sa-card" id="documentos">

            <div class="card-heading sa-card-head">
                <div>
                    <h4>Documentos — <?= e(mb_strtolower($docFilters[$docFilter])) ?></h4>
                    <p class="sa-card-sub"><?= count($docs) ?> documento(s)<?= $docFilter === 'pending' ? ' a aguardar revisão' : '' ?></p>
                </div>

                <?php if (count($docs) > 0): ?>
                    <div class="sa-toolbar">
                        <div class="sa-search">
                            <i class="bi bi-search"></i>
                            <input type="text" id="docSearch" placeholder="Procurar por agricultor ou tipo…" aria-label="Procurar documentos">
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <ul class="nav nav-pills gap-1 mb-3" aria-label="Filtrar documentos">
                <?php foreach ($docFilters as $k => $label): ?>
                    <li class="nav-item">
                        <a class="nav-link py-1 px-3<?= $k === $docFilter ? ' active' : '' ?>" <?= $k === $docFilter ? 'aria-current="page"' : '' ?> href="?docs=<?= e($k) ?>#documentos">
                            <?= e($label) ?> <span class="badge text-bg-light ms-1"><?= (int)($docCounts[$k] ?? 0) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if (empty($docs)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-file-earmark-check" style="font-size:2.5rem;"></i>
                    <p class="mt-3 mb-0"><?= $docFilter === 'pending' ? 'Não há documentos pendentes.' : 'Nenhum documento neste filtro.' ?></p>
                </div>
            <?php else: ?>
                <div class="sa-scroll-x">
                    <table class="table align-middle sa-table sa-doc-table" id="docTable">
                        <thead>
                            <tr>
                                <th>Agricultor</th>
                                <th>Tipo</th>
                                <th>Ficheiro</th>
                                <th>Validade</th>
                                <th class="text-end sa-doc-col-action">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($docs as $d):
                            $search = strtolower(($d['farmer_name'] ?? '') . ' ' . ($d['document_type'] ?? ''));
                            $expires = $d['expires_at'] ?? '';
                            $expiresTs = $expires ? strtotime($expires) : null;
                            $daysLeft = document_days_left($expires ?: null);
                            $isExpired = $daysLeft !== null && $daysLeft < 0;
                            $isSoon = $daysLeft !== null && !$isExpired && $daysLeft <= 30;
                        ?>
                            <tr data-search="<?= e($search) ?>">
                                <td>
                                    <div class="sa-user-cell">
                                        <span class="sa-avatar">
                                            <?= e(mb_strtoupper(mb_substr($d['farmer_name'] ?? '?', 0, 1))) ?>
                                        </span>
                                        <div class="sa-user-info">
                                            <strong><?= e($d['farmer_name']) ?></strong>
                                            <small>#<?= (int)$d['farmer_id'] ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="sa-doc-type">
                                        <i class="bi bi-file-earmark-text"></i>
                                        <?= e($docTypeLabels[$d['document_type']] ?? ucfirst($d['document_type'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?= base_path('admin/documento.php?id=' . (int)$d['id']) ?>"
                                       target="_blank" rel="noopener"
                                       class="sa-doc-link">
                                        <i class="bi bi-box-arrow-up-right"></i>
                                        Abrir
                                    </a>
                                </td>
                                <td>
                                    <?php if ($expiresTs): ?>
                                        <span class="sa-date-badge sa-date-<?= $isExpired ? 'danger' : ($isSoon ? 'warn' : 'ok') ?>">
                                            <i class="bi bi-calendar3"></i>
                                            <?= date('d/m/Y', $expiresTs) ?>
                                        </span>
                                        <div class="mt-1"><?= document_expiry_badge($expires, $d['status']) ?></div>
                                    <?php else: ?>
                                        <span class="sa-date-badge sa-date-muted">
                                            <i class="bi bi-dash-circle"></i>
                                            sem validade
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($docFilter !== 'pending'): ?>
                                        <div class="mt-1"><span class="badge text-bg-<?= e(document_status_class($d['status'])) ?>"><?= e(document_status_label($d['status'])) ?></span></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end sa-doc-col-action">
                                    <form method="post" class="sa-doc-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="document">
                                        <input type="hidden" name="document_id" value="<?= (int)$d['id'] ?>">

                                        <div class="sa-pay-control">
                                            <select name="status" class="sa-pay-select" aria-label="Alterar estado do documento">
                                                <?php if (!$isExpired): ?><option value="approved"<?= $d['status'] === 'approved' ? ' selected' : '' ?>>Aprovar</option><?php endif; ?>
                                                <option value="rejected"<?= $d['status'] === 'rejected' ? ' selected' : '' ?>>Rejeitar</option>
                                                <option value="expired"<?= ($d['status'] === 'expired' || $isExpired) ? ' selected' : '' ?>>Expirado</option>
                                            </select>
                                            <i class="bi bi-chevron-down sa-pay-chevron"></i>
                                        </div>

                                        <button type="submit" class="sa-pay-save" title="Guardar alteração">
                                            <i class="bi bi-check-lg"></i>
                                            <span>Guardar</span>
                                        </button>
                                        <label class="visually-hidden" for="dnotes<?= (int)$d['id'] ?>">Notas / motivo</label>
                                        <textarea id="dnotes<?= (int)$d['id'] ?>" name="notes" rows="1" maxlength="1000" class="form-control form-control-sm mt-1" placeholder="Notas / motivo (enviado ao agricultor)"><?= e($d['notes'] ?? '') ?></textarea>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div id="docNoResults" class="empty-state py-5" style="display:none;">
                    <i class="bi bi-search" style="font-size:2rem;"></i>
                    <p class="mt-3 mb-0">Nenhum resultado encontrado.</p>
                </div>
            <?php endif; ?>

        </article>

    </section>
</div>

<script>
(function () {
    /* ---------- Pesquisa: perfis ---------- */
    function bindSearch(inputId, tableId, noResId) {
        var input = document.getElementById(inputId);
        var table = document.getElementById(tableId);
        var noRes = document.getElementById(noResId);
        if (!table) return;

        var rows = Array.from(table.querySelectorAll('tbody tr'));

        function apply() {
            var term = (input ? input.value : '').toLowerCase().trim();
            var visible = 0;
            rows.forEach(function (r) {
                var show = !term || (r.dataset.search || '').includes(term);
                r.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            if (noRes) noRes.style.display = visible === 0 ? '' : 'none';
        }

        if (input) input.addEventListener('input', apply);
    }

    bindSearch('farmSearch', 'farmTable', 'farmNoResults');
    bindSearch('docSearch', 'docTable', 'docNoResults');

    /* ---------- Marca form como alterado ---------- */
    document.querySelectorAll('.sa-pay-select').forEach(function (sel) {
        var form = sel.closest('form');
        var original = sel.value;
        sel.addEventListener('change', function () {
            var changed = sel.value !== original;
            form.classList.toggle('is-dirty', changed);
            var btn = form.querySelector('.sa-pay-save');
            if (btn) btn.classList.toggle('pulse', changed);
        });
    });
})();
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>