<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/_helpers.php';

require_role('farmer');

$page_title = 'Os meus produtos';
$fid = user()['farmer_id'];
$pdo = db();

/* ============================================================
   AÇÕES (POST + CSRF): alternar estado / eliminar
   ============================================================ */
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? null);
    $id = safe_int($_POST['id'] ?? 0);
    $s = $pdo->prepare('SELECT * FROM products WHERE id=? AND farmer_id=?');
    $s->execute([$id, $fid]);
    $prod = $s->fetch();
    $back = base_path('agricultor/produtos.php') . (!empty($_POST['back']) && str_starts_with((string)$_POST['back'], '?') ? $_POST['back'] : '');
    if (!$prod) { flash('danger', 'Produto não encontrado.'); redirect($back); }

    if (($_POST['action'] ?? '') === 'toggle') {
        if ($prod['status'] === 'active') {
            $pdo->prepare('UPDATE products SET status="inactive" WHERE id=?')->execute([$id]);
            audit(user()['id'], 'update', 'products', 'Agricultor desativou o produto #'.$id);
            flash('success', 'Produto desativado.');
        } elseif (!farmer_verified()) {
            flash('warning', 'A sua conta ainda não foi verificada. Os produtos serão publicados após a verificação da conta.');
        } elseif ((float)$prod['stock'] <= 0) {
            flash('warning', 'Não é possível ativar um produto sem stock. Atualize o stock primeiro.');
        } else {
            $pdo->prepare('UPDATE products SET status="active" WHERE id=?')->execute([$id]);
            audit(user()['id'], 'update', 'products', 'Agricultor ativou o produto #'.$id);
            flash('success', 'Produto ativado.');
        }
    } elseif (($_POST['action'] ?? '') === 'delete') {
        $s = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE product_id=?');
        $s->execute([$id]);
        if ((int)$s->fetchColumn() > 0) {
            flash('warning', 'Este produto já tem pedidos associados e não pode ser eliminado. Pode desativá-lo para deixar de o vender.');
        } else {
            try {
                // Recolhe as imagens (capa + galeria) antes de apagar: as linhas de product_images caem em cascata.
                $imagePaths = product_image_paths($pdo, $id);
                $pdo->prepare('DELETE FROM products WHERE id=? AND farmer_id=?')->execute([$id, $fid]);
                delete_product_image_file($prod['main_image']);
                delete_product_files($imagePaths);
                audit(user()['id'], 'delete', 'products', 'Agricultor removeu o produto #'.$id.' ('.$prod['name'].')');
                flash('success', 'Produto removido.');
            } catch (PDOException $ex) {
                flash('warning', 'Não foi possível eliminar o produto porque tem registos associados. Pode desativá-lo.');
            }
        }
    }
    redirect($back);
}

/* ============================================================
   LISTA DE PRODUTOS
   ============================================================ */
$s = $pdo->prepare('
    SELECT p.*, c.name AS category_name
    FROM products p
    JOIN categories c ON c.id = p.category_id
    WHERE p.farmer_id = ?
    ORDER BY p.created_at DESC
');
$s->execute([$fid]);
$products = $s->fetchAll();

/* ============================================================
   ESTATÍSTICAS
   ============================================================ */
$counts = [
    'total'    => count($products),
    'active'   => 0,
    'inactive' => 0,
    'low'      => 0,
    'out'      => 0,
    'value'    => 0,
];
foreach ($products as $p) {
    $st = $p['status'] ?? 'active';
    if ($st === 'active') $counts['active']++; else $counts['inactive']++;

    $stock = (float)($p['stock'] ?? 0);
    $min   = (float)($p['quantity_min'] ?? 0);

    if ($stock <= 0) $counts['out']++;
    elseif ($stock <= $min) $counts['low']++;

    $counts['value'] += ((float)$p['price']) * $stock;
}

/* ============================================================
   FILTROS + PAGINAÇÃO (servidor)
   ============================================================ */
$filter = in_array($_GET['f'] ?? '', ['active', 'inactive', 'low', 'out'], true) ? $_GET['f'] : 'all';
$q = trim((string)($_GET['q'] ?? ''));
$filtered = array_values(array_filter($products, function ($p) use ($filter, $q) {
    $stock = (float)$p['stock']; $min = (float)$p['quantity_min'];
    if ($q !== '' && mb_stripos($p['name'].' '.$p['category_name'], $q) === false) return false;
    return match ($filter) {
        'active'   => $p['status'] === 'active',
        'inactive' => $p['status'] !== 'active',
        'low'      => $stock > 0 && $stock <= $min,
        'out'      => $stock <= 0,
        default    => true,
    };
}));
$filteredTotal = count($filtered);
$perPage = 20;
[$page, $offset] = page_offset($perPage);
$pageProducts = array_slice($filtered, $offset, $perPage);
$backQuery = $_GET ? '?' . http_build_query($_GET) : '';
function prod_filter_url(string $f, string $q): string { $p = []; if ($f !== 'all') $p['f'] = $f; if ($q !== '') $p['q'] = $q; return '?' . http_build_query($p); }

include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <!-- ================= HERO ================= -->
        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Catálogo · Produtos
                </span>
                <h1 class="sa-hero-title">Os meus <em>produtos</em></h1>
                <p class="sa-hero-sub">
                    <?php if ($counts['low'] + $counts['out'] > 0): ?>
                        <strong><?= $counts['out'] ?></strong> sem stock e
                        <strong><?= $counts['low'] ?></strong> com stock baixo.
                    <?php else: ?>
                        Catálogo em bom estado.
                    <?php endif; ?>
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('agricultor/dashboard.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <a href="<?= base_path('agricultor/adicionar-produto.php') ?>" class="btn btn-primary">
                    <i class="bi bi-plus-lg"></i> Novo produto
                </a>
            </div>
        </header>

        <!-- ================= KPIs ================= -->
        <div class="sa-kpi-grid sa-kpi-grid-4">

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-basket2"></i></span>
                </header>
                <span class="sa-kpi-label">Total</span>
                <strong class="sa-kpi-value"><?= $counts['total'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-check2-circle"></i> <?= $counts['active'] ?> ativos</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-info"><i class="bi bi-eye-slash"></i></span>
                </header>
                <span class="sa-kpi-label">Inativos</span>
                <strong class="sa-kpi-value"><?= $counts['inactive'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-slash-circle"></i> ocultos do catálogo</span>
                </footer>
            </article>

            <article class="sa-kpi <?= ($counts['low'] + $counts['out']) > 0 ? 'sa-kpi-warn' : '' ?>">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-warning"><i class="bi bi-exclamation-triangle"></i></span>
                    <?php if ($counts['out'] > 0): ?>
                        <span class="sa-kpi-badge sa-badge-warn"><?= $counts['out'] ?> sem stock</span>
                    <?php endif; ?>
                </header>
                <span class="sa-kpi-label">Stock baixo</span>
                <strong class="sa-kpi-value"><?= $counts['low'] + $counts['out'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-box"></i> a repor</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-cash-stack"></i></span>
                </header>
                <span class="sa-kpi-label">Valor em stock</span>
                <strong class="sa-kpi-value"><?= money($counts['value']) ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-graph-up"></i> preço × quantidade</span>
                </footer>
            </article>

        </div>

        <?php if (!farmer_verified()): ?>
        <div class="alert alert-warning d-flex gap-2 align-items-start">
            <i class="bi bi-shield-exclamation fs-5"></i>
            <div><strong>Conta por verificar.</strong> Pode registar produtos, mas ficarão inativos — o produto será publicado após a verificação da conta.</div>
        </div>
        <?php endif; ?>

        <!-- ================= TABELA ================= -->
        <article class="content-card sa-card">

            <div class="card-heading sa-card-head">
                <div>
                    <h4>Lista de produtos</h4>
                    <p class="sa-card-sub"><?= $filteredTotal ?> registo<?= $filteredTotal !== 1 ? 's' : '' ?></p>
                </div>

                <div class="sa-toolbar">
                    <form method="get" class="sa-search">
                        <i class="bi bi-search"></i>
                        <?php if ($filter !== 'all'): ?><input type="hidden" name="f" value="<?= e($filter) ?>"><?php endif; ?>
                        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Procurar por nome ou categoria…">
                    </form>

                    <div class="sa-filters">
                        <?php foreach (['all' => 'Todos', 'active' => 'Ativos', 'inactive' => 'Inativos', 'low' => 'Stock baixo', 'out' => 'Sem stock'] as $fk => $fl): ?>
                            <a class="sa-filter<?= $filter === $fk ? ' active' : '' ?>" href="<?= e(prod_filter_url($fk, $q)) ?>"><?= $fl ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php if (empty($products)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-basket2" style="font-size:2.5rem;"></i>
                    <p class="mt-3 mb-1">Ainda não tem produtos publicados.</p>
                    <a href="<?= base_path('agricultor/adicionar-produto.php') ?>" class="btn btn-primary mt-2">
                        <i class="bi bi-plus-lg"></i> Publicar primeiro produto
                    </a>
                </div>
            <?php elseif (empty($pageProducts)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-search" style="font-size:2rem;"></i>
                    <p class="mt-3 mb-0">Nenhum resultado encontrado.</p>
                </div>
            <?php else: ?>
                <div class="sa-scroll-x">
                    <table class="table align-middle sa-table sa-prod-table" id="prodTable">
                        <thead>
                            <tr>
                                <th>Produto</th>
                                <th>Categoria</th>
                                <th class="text-end">Preço</th>
                                <th class="text-center">Stock</th>
                                <th>Estado</th>
                                <th class="text-end sa-prod-col-action">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pageProducts as $p):
                            $st = $p['status'] ?? 'active';
                            $stock = (float)($p['stock'] ?? 0);
                            $min   = (float)($p['quantity_min'] ?? 0);
                            $isLow = $stock > 0 && $stock <= $min;
                            $isOut = $stock <= 0;

                            [$badgeClass, $badgeIcon, $badgeLabel] = [
                                'active'       => ['success', 'check-circle-fill', 'Ativo'],
                                'out_of_stock' => ['warning', 'exclamation-circle-fill', 'Sem stock'],
                            ][$st] ?? ['secondary', 'slash-circle-fill', 'Inativo'];

                            $stockClass = $isOut ? 'sa-stock-out' : ($isLow ? 'sa-stock-low' : 'sa-stock-ok');
                            $canActivate = farmer_verified() && !$isOut;
                        ?>
                            <tr>
                                <td>
                                    <div class="sa-product-cell">
                                        <img class="sa-product-thumb"
                                             src="<?= e(get_product_image($p['main_image'])) ?>"
                                             alt="<?= e($p['name']) ?>">
                                        <div class="sa-user-info">
                                            <strong><?= e($p['name']) ?></strong>
                                            <small>#<?= (int)$p['id'] ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="sa-cat-tag">
                                        <i class="bi bi-tag"></i>
                                        <?= e($p['category_name']) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <strong class="sa-price"><?= money($p['price']) ?></strong>
                                    <small class="sa-price-unit">/ <?= e($p['unit']) ?></small>
                                </td>
                                <td class="text-center">
                                    <span class="sa-stock <?= $stockClass ?>">
                                        <?php if ($isOut): ?>
                                            <i class="bi bi-x-circle"></i> 0
                                        <?php elseif ($isLow): ?>
                                            <i class="bi bi-exclamation-triangle"></i> <?= e($p['stock']) ?>
                                        <?php else: ?>
                                            <?= e($p['stock']) ?>
                                        <?php endif; ?>
                                        <small><?= e($p['unit']) ?></small>
                                    </span>
                                </td>
                                <td>
                                    <span class="sa-badge sa-badge-<?= $badgeClass ?>">
                                        <i class="bi bi-<?= $badgeIcon ?>"></i>
                                        <?= $badgeLabel ?>
                                    </span>
                                </td>
                                <td class="text-end sa-prod-col-action">
                                    <div class="sa-actions">
                                        <a href="<?= base_path('agricultor/editar-produto.php?id='.(int)$p['id']) ?>"
                                           class="sa-btn sa-btn-ghost" title="Editar">
                                            <i class="bi bi-pencil-square"></i>
                                            <span>Editar</span>
                                        </a>
                                        <?php if ($st === 'active'): ?>
                                            <?= post_button('', ['action' => 'toggle', 'id' => (int)$p['id'], 'back' => $backQuery], '<i class="bi bi-power"></i> <span>Desativar</span>', 'sa-btn sa-btn-danger', null, 'Desativar') ?>
                                        <?php elseif ($canActivate): ?>
                                            <?= post_button('', ['action' => 'toggle', 'id' => (int)$p['id'], 'back' => $backQuery], '<i class="bi bi-power"></i> <span>Ativar</span>', 'sa-btn sa-btn-success', null, 'Ativar') ?>
                                        <?php else: ?>
                                            <span class="sa-btn sa-btn-ghost disabled" title="<?= farmer_verified() ? 'Sem stock: atualize o stock para ativar' : 'Disponível após a verificação da conta' ?>">
                                                <i class="bi bi-power"></i> <span>Ativar</span>
                                            </span>
                                        <?php endif; ?>
                                        <?= post_button('', ['action' => 'delete', 'id' => (int)$p['id'], 'back' => $backQuery], '<i class="bi bi-trash"></i>', 'sa-btn sa-btn-icon-danger', 'Eliminar permanentemente «'.$p['name'].'»? Esta ação não pode ser revertida.', 'Eliminar') ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3"><?= pagination($page, $perPage, $filteredTotal) ?></div>
            <?php endif; ?>

        </article>

    </section>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
