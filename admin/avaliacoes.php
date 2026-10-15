<?php require_once __DIR__.'/../includes/permissions.php'; ?>
<?php
require_role('superadmin');
require_once __DIR__.'/_helpers.php';
$pdo = db();

/* ============================================================
   AÇÕES (POST + CSRF): ocultar / mostrar / apagar avaliação
   ============================================================ */
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    $action = $_POST['action'] ?? '';
    $id = safe_int($_POST['id'] ?? 0);
    $s = $pdo->prepare('SELECT r.id, r.status, r.rating, p.name product_name FROM reviews r JOIN products p ON p.id=r.product_id WHERE r.id=?');
    $s->execute([$id]);
    $rev = $s->fetch();
    if (!$rev) {
        flash('danger', 'Avaliação não encontrada.');
    } elseif ($action === 'hide' || $action === 'show') {
        $new = $action === 'hide' ? 'hidden' : 'visible';
        $pdo->prepare('UPDATE reviews SET status=? WHERE id=?')->execute([$new, $id]);
        audit(user()['id'], 'moderate', 'reviews', 'Avaliação #' . $id . ' (' . $rev['product_name'] . ') ' . ($new === 'hidden' ? 'ocultada' : 'tornada visível'));
        flash('success', $new === 'hidden' ? 'Avaliação ocultada.' : 'Avaliação visível novamente.');
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM reviews WHERE id=?')->execute([$id]);
        audit(user()['id'], 'delete', 'reviews', 'Avaliação #' . $id . ' (' . $rev['product_name'] . ', ' . (int)$rev['rating'] . ' estrelas) apagada');
        flash('success', 'Avaliação apagada.');
    } else {
        flash('danger', 'Ação inválida.');
    }
    redirect(admin_self_url());
}

/* ============================================================
   FILTROS + PAGINAÇÃO
   ============================================================ */
$nota = safe_int($_GET['nota'] ?? 0);
if ($nota < 1 || $nota > 5) $nota = 0;
$estado = (string)($_GET['estado'] ?? 'all');
if (!in_array($estado, ['all', 'visible', 'hidden'], true)) $estado = 'all';
$q = trim((string)($_GET['q'] ?? ''));
$where = []; $params = [];
if ($nota) { $where[] = 'r.rating=?'; $params[] = $nota; }
if ($estado !== 'all') { $where[] = 'r.status=?'; $params[] = $estado; }
if ($q !== '') { $where[] = '(p.name LIKE ? OR u.name LIKE ? OR r.comment LIKE ?)'; $like = '%' . $q . '%'; array_push($params, $like, $like, $like); }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$fromSql = ' FROM reviews r JOIN products p ON p.id = r.product_id JOIN users u ON u.id = r.user_id';

$perPage = 20;
[$page, $offset] = page_offset($perPage);
$c = $pdo->prepare('SELECT COUNT(*)' . $fromSql . $whereSql);
$c->execute($params);
$totalFiltered = (int)$c->fetchColumn();
$s = $pdo->prepare('SELECT r.*, p.name AS product_name, u.name AS user_name' . $fromSql . $whereSql . ' ORDER BY r.created_at DESC, r.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$s->execute($params);
$reviews = $s->fetchAll();

/* ============================================================
   ESTATÍSTICAS (todas as avaliações)
   ============================================================ */
$st = $pdo->query('SELECT COUNT(*) total, COALESCE(SUM(rating),0) sum, COALESCE(SUM(rating>=4),0) positive, COALESCE(SUM(rating<=2),0) negative,
    COALESCE(SUM(TRIM(COALESCE(comment,"")) <> ""),0) with_text, COALESCE(SUM(status="hidden"),0) hidden FROM reviews')->fetch();
$counts = array_map('intval', $st);
$distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
foreach ($pdo->query('SELECT LEAST(GREATEST(rating,1),5) r, COUNT(*) n FROM reviews GROUP BY 1') as $row) $distribution[(int)$row['r']] += (int)$row['n'];

$avgRating = $counts['total'] > 0 ? round($counts['sum'] / $counts['total'], 2) : 0;
$positivePct = $counts['total'] > 0 ? round(($counts['positive'] / $counts['total']) * 100) : 0;

$page_title = 'Avaliações';
include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <!-- ================= HERO ================= -->
        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Moderação · Avaliações
                </span>
                <h1 class="sa-hero-title">Moderação de <em>avaliações</em></h1>
                <p class="sa-hero-sub">
                    <?php if ($counts['total'] > 0): ?>
                        Nota média <strong><?= number_format($avgRating, 1) ?>/5</strong> · <strong><?= $positivePct ?>%</strong> positivas.
                    <?php else: ?>
                        Ainda não existem avaliações registadas.
                    <?php endif; ?>
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('admin/dashboard.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <a href="<?= base_path('admin/produtos.php') ?>" class="btn btn-primary">
                    <i class="bi bi-basket2"></i> Ver produtos
                </a>
            </div>
        </header>

        <!-- ================= KPIs ================= -->
        <div class="sa-kpi-grid sa-kpi-grid-4">

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-chat-square-text"></i></span>
                </header>
                <span class="sa-kpi-label">Total</span>
                <strong class="sa-kpi-value"><?= $counts['total'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-chat-left-text"></i> <?= $counts['with_text'] ?> com comentário</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-warning"><i class="bi bi-star-fill"></i></span>
                </header>
                <span class="sa-kpi-label">Nota média</span>
                <strong class="sa-kpi-value"><?= number_format($avgRating, 1) ?><span class="sa-kpi-unit">/5</span></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="bi <?= $i <= round($avgRating) ? 'bi-star-fill' : 'bi-star' ?>"></i>
                        <?php endfor; ?>
                    </span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-hand-thumbs-up"></i></span>
                </header>
                <span class="sa-kpi-label">Positivas</span>
                <strong class="sa-kpi-value"><?= $positivePct ?><span class="sa-kpi-unit">%</span></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-star-half"></i> <?= $counts['positive'] ?> de 4–5 estrelas</span>
                </footer>
            </article>

            <article class="sa-kpi <?= $counts['negative'] > 0 ? 'sa-kpi-warn' : '' ?>">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon <?= $counts['negative'] > 0 ? 'sa-icon-danger' : 'sa-icon-success' ?>">
                        <i class="bi bi-<?= $counts['negative'] > 0 ? 'hand-thumbs-down' : 'shield-check' ?>"></i>
                    </span>
                    <?php if ($counts['negative'] > 0): ?>
                        <span class="sa-kpi-badge sa-badge-warn">atenção</span>
                    <?php endif; ?>
                </header>
                <span class="sa-kpi-label">Negativas</span>
                <strong class="sa-kpi-value"><?= $counts['negative'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-exclamation-triangle"></i> 1–2 estrelas</span>
                </footer>
            </article>

        </div>

        <!-- ================= DISTRIBUIÇÃO ================= -->
        <?php if ($counts['total'] > 0): ?>
        <div class="sa-rating-dist">
            <div class="sa-rating-dist-head">
                <h4>Distribuição das notas</h4>
                <span class="sa-rating-dist-avg">
                    <i class="bi bi-star-fill"></i>
                    <strong><?= number_format($avgRating, 2) ?></strong>
                    <small>média</small>
                </span>
            </div>
            <div class="sa-rating-rows">
                <?php for ($star = 5; $star >= 1; $star--):
                    $n = $distribution[$star];
                    $pct = $counts['total'] > 0 ? round(($n / $counts['total']) * 100) : 0;
                ?>
                    <div class="sa-rating-row">
                        <span class="sa-rating-label">
                            <?= $star ?>
                            <i class="bi bi-star-fill"></i>
                        </span>
                        <div class="sa-rating-bar">
                            <span style="width:<?= $pct ?>%" class="sa-rating-bar-<?= $star >= 4 ? 'good' : ($star === 3 ? 'mid' : 'bad') ?>"></span>
                        </div>
                        <span class="sa-rating-count"><?= $n ?> <small>(<?= $pct ?>%)</small></span>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ================= TABELA ================= -->
        <article class="content-card sa-card">

            <div class="card-heading sa-card-head">
                <div>
                    <h4>Todas as avaliações</h4>
                    <p class="sa-card-sub"><?= $totalFiltered ?> registos · <?= $counts['hidden'] ?> ocultas</p>
                </div>

                <div class="sa-toolbar">
                    <form method="get" class="sa-search" role="search">
                        <i class="bi bi-search"></i>
                        <?php if ($nota): ?><input type="hidden" name="nota" value="<?= $nota ?>"><?php endif; ?>
                        <?php if ($estado !== 'all'): ?><input type="hidden" name="estado" value="<?= e($estado) ?>"><?php endif; ?>
                        <input type="text" name="q" value="<?= e($q) ?>" aria-label="Procurar avaliação" placeholder="Procurar por produto, utilizador ou comentário…">
                    </form>

                    <div class="sa-filters">
                        <?php foreach (['0' => 'Todas', '5' => '5 estrelas', '4' => '4 estrelas', '3' => '3 estrelas', '2' => '2 estrelas', '1' => '1 estrela'] as $k => $label): ?>
                            <a class="sa-filter text-decoration-none<?= $nota === (int)$k ? ' active' : '' ?>" href="?<?= e(http_build_query(array_filter(['nota' => (int)$k ?: null, 'estado' => $estado !== 'all' ? $estado : null, 'q' => $q !== '' ? $q : null]))) ?>"><?= $label ?></a>
                        <?php endforeach; ?>
                    </div>
                    <div class="sa-filters">
                        <?php foreach (['all' => 'Todos os estados', 'visible' => 'Visíveis', 'hidden' => 'Ocultas'] as $k => $label): ?>
                            <a class="sa-filter text-decoration-none<?= $estado === $k ? ' active' : '' ?>" href="?<?= e(http_build_query(array_filter(['nota' => $nota ?: null, 'estado' => $k !== 'all' ? $k : null, 'q' => $q !== '' ? $q : null]))) ?>"><?= $label ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php if (empty($reviews)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-chat-square-text" style="font-size:2.5rem;"></i>
                    <p class="mt-3 mb-0"><?= ($nota || $estado !== 'all' || $q !== '') ? 'Nenhum resultado encontrado.' : 'Ainda não existem avaliações registadas.' ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive sa-table-wrap">
                    <table class="table align-middle sa-table" id="revTable">
                        <thead>
                            <tr>
                                <th>Produto</th>
                                <th>Utilizador</th>
                                <th>Nota</th>
                                <th>Comentário</th>
                                <th>Estado</th>
                                <th class="text-end">Data</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($reviews as $r):
                            $rating = max(1, min(5, (int)($r['rating'] ?? 0)));
                            $ratingClass = $rating >= 4 ? 'good' : ($rating === 3 ? 'mid' : 'bad');
                            $hasComment = !empty(trim((string)($r['comment'] ?? '')));
                            $hidden = ($r['status'] ?? 'visible') === 'hidden';
                        ?>
                            <tr data-rating="<?= $rating ?>">
                                <td>
                                    <div class="sa-user-cell">
                                        <span class="sa-avatar sa-avatar-product">
                                            <i class="bi bi-basket2"></i>
                                        </span>
                                        <div class="sa-user-info">
                                            <strong><a href="<?= base_path('produto.php?id=' . (int)$r['product_id']) ?>" target="_blank" rel="noopener"><?= e($r['product_name']) ?></a></strong>
                                            <small>#<?= (int)$r['product_id'] ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="sa-user-cell">
                                        <span class="sa-avatar">
                                            <?= e(mb_strtoupper(mb_substr($r['user_name'] ?? '?', 0, 1))) ?>
                                        </span>
                                        <span><?= e($r['user_name']) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="sa-rating-stars sa-rating-<?= $ratingClass ?>">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="bi <?= $i <= $rating ? 'bi-star-fill' : 'bi-star' ?>"></i>
                                        <?php endfor; ?>
                                        <span class="sa-rating-num"><?= $rating ?>.0</span>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($hasComment): ?>
                                        <p class="sa-comment"><?= e($r['comment']) ?></p>
                                    <?php else: ?>
                                        <span class="sa-comment-empty">
                                            <i class="bi bi-dash-circle"></i> sem comentário
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="sa-badge sa-badge-<?= $hidden ? 'danger' : 'success' ?>">
                                        <i class="bi bi-<?= $hidden ? 'eye-slash' : 'eye' ?>"></i>
                                        <?= $hidden ? 'Oculta' : 'Visível' ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <span class="sa-date">
                                        <?= date('d/m/Y', strtotime($r['created_at'])) ?>
                                        <small><?= date('H:i', strtotime($r['created_at'])) ?></small>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="sa-actions">
                                        <?php if ($hidden): ?>
                                            <?= post_button('', ['action' => 'show', 'id' => (int)$r['id']], '<i class="bi bi-eye"></i> <span>Mostrar</span>', 'sa-btn sa-btn-success', null, 'Tornar visível') ?>
                                        <?php else: ?>
                                            <?= post_button('', ['action' => 'hide', 'id' => (int)$r['id']], '<i class="bi bi-eye-slash"></i> <span>Ocultar</span>', 'sa-btn sa-btn-ghost', null, 'Ocultar avaliação') ?>
                                        <?php endif; ?>
                                        <?= post_button('', ['action' => 'delete', 'id' => (int)$r['id']], '<i class="bi bi-trash"></i> <span>Apagar</span>', 'sa-btn sa-btn-danger', 'Apagar definitivamente esta avaliação de ' . $r['user_name'] . '?', 'Apagar') ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3"><?= pagination($page, $perPage, $totalFiltered) ?></div>
            <?php endif; ?>

        </article>

    </section>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
