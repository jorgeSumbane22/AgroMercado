<?php
require_once __DIR__.'/../includes/permissions.php';
require_role('superadmin');
require_once __DIR__.'/_helpers.php';

$page_title = 'Emails enviados';
$pdo = db();
$statusLabels = [
    'queued' => ['Em fila',    'warning',   'hourglass-split'],
    'sent'   => ['Enviado',    'success',   'check-circle-fill'],
    'failed' => ['Falhado',    'danger',    'x-circle-fill'],
    'logged' => ['Só registo', 'secondary', 'journal-text'],
];

/* ============================================================
   AÇÕES (POST + CSRF): reenviar, processar fila
   ============================================================ */
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    $action = $_POST['action'] ?? '';
    if ($action === 'resend') {
        $id = safe_int($_POST['id'] ?? 0);
        $s = $pdo->prepare('SELECT id,status,to_email FROM email_outbox WHERE id=?'); $s->execute([$id]); $mail = $s->fetch();
        if (!$mail || !in_array($mail['status'], ['failed', 'logged', 'queued'], true)) {
            flash('danger', 'Email não encontrado ou já enviado.');
        } else {
            $pdo->prepare('UPDATE email_outbox SET status="queued", last_error=NULL WHERE id=?')->execute([$id]);
            deliver_email($id);
            $s = $pdo->prepare('SELECT status,last_error FROM email_outbox WHERE id=?'); $s->execute([$id]); $r = $s->fetch();
            audit(user()['id'], 'resend', 'email', 'Email #' . $id . ' para ' . $mail['to_email'] . ' reenviado (' . $r['status'] . ')');
            if ($r['status'] === 'sent') flash('success', 'Email #' . $id . ' enviado.');
            elseif ($r['status'] === 'logged') flash('warning', 'Email #' . $id . ' registado mas não enviado: ' . $r['last_error']);
            else flash('danger', 'Falha ao enviar o email #' . $id . ': ' . $r['last_error']);
        }
    } elseif ($action === 'process') {
        $stats = send_queued_emails(50);
        audit(user()['id'], 'process', 'email', 'Fila de email processada: ' . json_encode($stats));
        flash('success', sprintf('Fila processada: %d enviados, %d falhados, %d registados.', $stats['sent'], $stats['failed'], $stats['logged']));
    } else {
        flash('danger', 'Ação inválida.');
    }
    redirect(admin_self_url());
}

/* ============================================================
   FILTROS + PAGINAÇÃO
   ============================================================ */
$statusFilter = (string)($_GET['status'] ?? '');
if (!isset($statusLabels[$statusFilter])) $statusFilter = '';
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$where = []; $params = [];
if ($statusFilter !== '') { $where[] = 'm.status = ?'; $params[] = $statusFilter; }
if ($q !== '') { $where[] = '(m.to_email LIKE ? OR m.subject LIKE ?)'; $like = '%' . $q . '%'; array_push($params, $like, $like); }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$st = $pdo->prepare('SELECT COUNT(*) FROM email_outbox m' . $whereSql); $st->execute($params); $total = (int)$st->fetchColumn();
$perPage = 30;
[$page, $offset] = page_offset($perPage);
$st = $pdo->prepare('SELECT m.*, u.name user_name FROM email_outbox m LEFT JOIN users u ON u.id=m.user_id' . $whereSql . ' ORDER BY m.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$st->execute($params);
$mails = $st->fetchAll();

$counts = array_fill_keys(array_keys($statusLabels), 0);
foreach ($pdo->query('SELECT status, COUNT(*) n FROM email_outbox GROUP BY status') as $r) $counts[$r['status']] = (int)$r['n'];
$smtpReady = mail_configured();
$filterQuery = fn(array $over) => http_build_query(array_filter(array_merge(['status' => $statusFilter ?: null, 'q' => $q !== '' ? $q : null], $over), fn($v) => $v !== null && $v !== ''));

include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Sistema · Email
                </span>
                <h1 class="sa-hero-title">Emails <em>enviados</em></h1>
                <p class="sa-hero-sub">
                    <strong><?= array_sum($counts) ?></strong> emails ·
                    <?php if ($smtpReady): ?>
                        envio por SMTP <strong>ativo</strong>
                    <?php else: ?>
                        SMTP <strong>não configurado</strong>: os emails ficam apenas registados
                    <?php endif; ?>
                </p>
            </div>
            <div class="sa-hero-actions">
                <a href="<?= base_path('admin/configuracoes.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-gear"></i> Configurar SMTP
                </a>
                <?= post_button('', ['action' => 'process'], '<i class="bi bi-send"></i> Processar fila (' . ($counts['queued'] + $counts['failed']) . ')', 'btn btn-primary') ?>
            </div>
        </header>

        <div class="sa-cat-chips">
            <span class="sa-cat-label"><i class="bi bi-funnel"></i> Estado:</span>
            <a href="?<?= e($filterQuery(['status' => null, 'page' => null])) ?>" class="sa-cat-chip <?= $statusFilter === '' ? 'active' : '' ?>">Todos</a>
            <?php foreach ($statusLabels as $k => [$label, $cls, $icon]): ?>
                <a href="?<?= e($filterQuery(['status' => $k, 'page' => null])) ?>" class="sa-cat-chip <?= $statusFilter === $k ? 'active' : '' ?>">
                    <i class="bi bi-<?= $icon ?>"></i> <?= e($label) ?> <span class="ms-1"><?= $counts[$k] ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <article class="content-card sa-card">
            <div class="card-heading sa-card-head">
                <div>
                    <h4>Caixa de saída</h4>
                    <p class="sa-card-sub"><?= $total ?> registos<?= $statusFilter || $q !== '' ? ' no filtro atual' : '' ?></p>
                </div>
                <form method="get" class="d-flex gap-2">
                    <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
                    <label class="visually-hidden" for="emailSearch">Pesquisar</label>
                    <input id="emailSearch" name="q" class="form-control form-control-sm" placeholder="Destinatário ou assunto" value="<?= e($q) ?>">
                    <button class="btn btn-sm btn-outline-secondary" title="Pesquisar" aria-label="Pesquisar"><i class="bi bi-search"></i></button>
                </form>
            </div>

            <?php if (!$mails): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-envelope" style="font-size:2.5rem;"></i>
                    <p class="mt-3 mb-0">Nenhum email<?= $statusFilter || $q !== '' ? ' neste filtro' : '' ?>.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle sa-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Data</th>
                                <th>Destinatário</th>
                                <th>Assunto</th>
                                <th>Estado</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($mails as $m): [$label, $cls, $icon] = $statusLabels[$m['status']] ?? [$m['status'], 'secondary', 'circle']; ?>
                            <tr>
                                <td class="text-muted small"><?= (int)$m['id'] ?></td>
                                <td class="text-nowrap small">
                                    <?= e(date('d/m/Y H:i', strtotime($m['created_at']))) ?>
                                    <?php if ($m['sent_at']): ?><br><span class="text-muted">enviado <?= e(date('d/m H:i', strtotime($m['sent_at']))) ?></span><?php endif; ?>
                                </td>
                                <td>
                                    <?= e($m['to_email']) ?>
                                    <?php if ($m['user_name']): ?><br><small class="text-muted"><?= e($m['user_name']) ?></small><?php endif; ?>
                                </td>
                                <td><?= e($m['subject']) ?></td>
                                <td>
                                    <span class="sa-badge sa-badge-<?= $cls ?>"><i class="bi bi-<?= $icon ?>"></i> <?= e($label) ?></span>
                                    <?php if ((int)$m['attempts'] > 0): ?><br><small class="text-muted"><?= (int)$m['attempts'] ?> tentativa(s)</small><?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="sa-actions">
                                        <button type="button" class="sa-btn sa-btn-ghost js-email-view" title="Ver mensagem"
                                                data-bs-toggle="modal" data-bs-target="#emailModal"
                                                data-subject="<?= e($m['subject']) ?>" data-to="<?= e($m['to_email']) ?>"
                                                data-body="<?= e($m['body']) ?>" data-error="<?= e((string)$m['last_error']) ?>">
                                            <i class="bi bi-eye"></i> <span>Ver</span>
                                        </button>
                                        <?php if (in_array($m['status'], ['failed', 'logged', 'queued'], true)): ?>
                                            <?= post_button('', ['action' => 'resend', 'id' => (int)$m['id']], '<i class="bi bi-arrow-repeat"></i> <span>Reenviar</span>', 'sa-btn sa-btn-success', null, 'Reenviar') ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?= pagination($page, $perPage, $total) ?>
            <?php endif; ?>
        </article>

    </section>
</div>

<!-- Modal: conteúdo do email (texto preenchido via textContent, nunca como HTML) -->
<div class="modal fade" id="emailModal" tabindex="-1" aria-labelledby="emailModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="emailModalTitle">Email</h5>
                    <small class="text-muted" id="emailModalTo"></small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger small d-none" id="emailModalError"></div>
                <pre class="mb-0" id="emailModalBody" style="white-space:pre-wrap;font-family:inherit"></pre>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    document.querySelectorAll('.js-email-view').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('emailModalTitle').textContent = btn.dataset.subject || 'Email';
            document.getElementById('emailModalTo').textContent = 'Para: ' + (btn.dataset.to || '');
            document.getElementById('emailModalBody').textContent = btn.dataset.body || '';
            var err = document.getElementById('emailModalError');
            err.textContent = btn.dataset.error || '';
            err.classList.toggle('d-none', !btn.dataset.error);
        });
    });
})();
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>
