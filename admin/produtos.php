<?php require_once __DIR__.'/../includes/permissions.php'; ?>
<?php
require_role('superadmin');
require_once __DIR__.'/_helpers.php';
$pdo = db();

/* ============================================================
   AÇÕES (POST + CSRF): alternar estado / apagar produto
   ============================================================ */
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    $action = $_POST['action'] ?? '';
    $id = safe_int($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT id, name, status, stock, main_image FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $prod = $stmt->fetch();

    if (!$prod) {
        flash('danger', 'Produto não encontrado.');
    } elseif ($action === 'toggle') {
        if ($prod['status'] === 'active') {
            $new = 'inactive';
        } elseif ((float)$prod['stock'] <= 0) {
            $new = null;
            flash('warning', 'O produto "' . $prod['name'] . '" não tem stock. Atualize o stock antes de o ativar.');
        } else {
            $new = 'active';
        }
        if ($new) {
            $pdo->prepare('UPDATE products SET status=?, updated_at=NOW() WHERE id=?')->execute([$new, $id]);
            audit(user()['id'], 'update', 'products', 'Produto #' . $id . ' (' . $prod['name'] . ') alterado para ' . $new);
            flash('success', 'Estado atualizado.');
        }
    } elseif ($action === 'delete') {
        $o = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE product_id=?');
        $o->execute([$id]);
        if ((int)$o->fetchColumn() > 0) {
            flash('warning', 'O produto "' . $prod['name'] . '" tem pedidos associados e não pode ser apagado. Desative-o em vez disso.');
        } else {
            try {
                // Recolhe as imagens (capa + galeria) antes de apagar: as linhas de product_images caem em cascata.
                $imagePaths = product_image_paths($pdo, $id);
                $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
                admin_delete_product_image($prod['main_image']);
                delete_product_files($imagePaths);
                audit(user()['id'], 'delete', 'products', 'Produto #' . $id . ' (' . $prod['name'] . ') apagado');
                flash('success', 'Produto apagado.');
            } catch (PDOException $e) {
                flash('danger', 'Não foi possível apagar o produto (existem registos associados). Desative-o em vez disso.');
            }
        }
    } else {
        flash('danger', 'Ação inválida.');
    }
    redirect(admin_self_url());
}

/* ============================================================
   FILTROS + PAGINAÇÃO (server-side)
   ============================================================ */
$filter = (string)($_GET['estado'] ?? 'all');
$filters = ['all' => 'Todos', 'active' => 'Ativos', 'inactive' => 'Inativos', 'lowstock' => 'Stock baixo', 'outstock' => 'Sem stock'];
if (!isset($filters[$filter])) $filter = 'all';
$q = trim((string)($_GET['q'] ?? ''));
$where = []; $params = [];
if ($filter === 'active')   $where[] = 'p.status="active"';
if ($filter === 'inactive') $where[] = 'p.status<>"active"';
if ($filter === 'lowstock') $where[] = 'p.stock>0 AND p.stock<=5';
if ($filter === 'outstock') $where[] = 'p.stock<=0';
if ($q !== '') { $where[] = '(p.name LIKE ? OR u.name LIKE ? OR c.name LIKE ?)'; $like = '%' . $q . '%'; array_push($params, $like, $like, $like); }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$fromSql = '
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN farmers f ON f.id = p.farmer_id
    LEFT JOIN users u ON u.id = f.user_id';

$perPage = 20;
[$page, $offset] = page_offset($perPage);
$c = $pdo->prepare('SELECT COUNT(*)' . $fromSql . $whereSql);
$c->execute($params);
$totalFiltered = (int)$c->fetchColumn();

$s = $pdo->prepare('SELECT p.*, c.name AS category_name, u.name AS farmer_name' . $fromSql . $whereSql . ' ORDER BY p.created_at DESC, p.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$s->execute($params);
$products = $s->fetchAll();

/* ============================================================
   ESTATÍSTICAS (sobre todo o catálogo)
   ============================================================ */
$counts = $pdo->query('SELECT COUNT(*) total, COALESCE(SUM(status="active"),0) active, COALESCE(SUM(status<>"active"),0) inactive,
    COALESCE(SUM(stock>0 AND stock<=5),0) lowstock, COALESCE(SUM(stock<=0),0) outstock, COALESCE(SUM(price*GREATEST(stock,0)),0) value FROM products')->fetch();
foreach (['total', 'active', 'inactive', 'lowstock', 'outstock'] as $k) $counts[$k] = (int)$counts[$k];
$categoryCount = [];
foreach ($pdo->query('SELECT COALESCE(c.name,"Sem categoria") name, COUNT(*) n FROM products p LEFT JOIN categories c ON c.id=p.category_id GROUP BY c.id, c.name ORDER BY n DESC LIMIT 6') as $row) {
    $categoryCount[$row['name']] = (int)$row['n'];
}

$page_title = 'Produtos';
include __DIR__.'/../includes/header.php';
?>

<style>
/* ============================================================
   SCROLL HORIZONTAL DA TABELA DE PRODUTOS
   ============================================================ */
.sa-table-wrap {
    overflow-x: auto !important;
    overflow-y: visible;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: #cbd5e1 #f1f5f9;
    padding-bottom: 8px;
    position: relative;
}

.sa-table-wrap::-webkit-scrollbar {
    height: 10px;
}
.sa-table-wrap::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 10px;
}
.sa-table-wrap::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
    border: 2px solid #f1f5f9;
}
.sa-table-wrap::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* força a tabela a ser mais larga que o contentor */
.sa-table-wrap .sa-table {
    min-width: 1000px;
    white-space: nowrap;
}

/* impede que o card corte o scroll */
.sa-card,
.content-card {
    overflow: visible;
}
</style>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <!-- ================= HERO ================= -->
        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Catálogo · Produtos
                </span>
                <h1 class="sa-hero-title">Todos os <em>produtos</em></h1>
                <p class="sa-hero-sub">
                    <?php if ($counts['lowstock'] > 0 || $counts['outstock'] > 0): ?>
                        <strong><?= $counts['outstock'] ?></strong> sem stock e
                        <strong><?= $counts['lowstock'] ?></strong> com stock baixo.
                    <?php else: ?>
                        Catálogo em bom estado.
                    <?php endif; ?>
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('admin/dashboard.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <a href="<?= base_path('admin/categorias.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-grid-3x3-gap"></i> Categorias
                </a>
                <a href="<?= base_path('admin/produto-novo.php') ?>" class="btn btn-primary">
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
                    <span class="sa-kpi-sub"><i class="bi bi-box-seam"></i> produtos no catálogo</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-check2-circle"></i></span>
                </header>
                <span class="sa-kpi-label">Ativos</span>
                <strong class="sa-kpi-value"><?= $counts['active'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-shop"></i> à venda agora</span>
                </footer>
            </article>

            <article class="sa-kpi <?= $counts['lowstock'] + $counts['outstock'] > 0 ? 'sa-kpi-warn' : '' ?>">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-warning"><i class="bi bi-exclamation-triangle"></i></span>
                    <?php if ($counts['outstock'] > 0): ?>
                        <span class="sa-kpi-badge sa-badge-warn"><?= $counts['outstock'] ?> sem stock</span>
                    <?php endif; ?>
                </header>
                <span class="sa-kpi-label">Stock baixo</span>
                <strong class="sa-kpi-value"><?= $counts['lowstock'] + $counts['outstock'] ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-box"></i> requerem atenção</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-info"><i class="bi bi-cash-stack"></i></span>
                </header>
                <span class="sa-kpi-label">Valor em stock</span>
                <strong class="sa-kpi-value"><?= money($counts['value']) ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-graph-up"></i> preço × quantidade</span>
                </footer>
            </article>

        </div>

        <!-- ================= CATEGORIAS (chips) ================= -->
        <?php if (!empty($categoryCount)): ?>
        <div class="sa-cat-chips">
            <span class="sa-cat-label"><i class="bi bi-tags"></i> Top categorias:</span>
            <?php $i = 0; foreach ($categoryCount as $cat => $n): if ($i++ >= 6) break; ?>
                <span class="sa-cat-chip">
                    <?= e($cat) ?>
                    <strong><?= (int)$n ?></strong>
                </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ================= TABELA ================= -->
        <article class="content-card sa-card">

            <div class="card-heading sa-card-head">
                <div>
                    <h4>Lista de produtos</h4>
                    <p class="sa-card-sub"><?= $totalFiltered ?> registos</p>
                </div>

                <div class="sa-toolbar">
                    <form method="get" class="sa-search" role="search">
                        <i class="bi bi-search"></i>
                        <?php if ($filter !== 'all'): ?><input type="hidden" name="estado" value="<?= e($filter) ?>"><?php endif; ?>
                        <input type="text" name="q" value="<?= e($q) ?>" aria-label="Procurar produto" placeholder="Procurar por produto, agricultor ou categoria…">
                    </form>

                    <div class="sa-filters">
                        <?php foreach ($filters as $key => $label): ?>
                            <a class="sa-filter text-decoration-none<?= $filter === $key ? ' active' : '' ?>" href="?<?= e(http_build_query(array_filter(['estado' => $key !== 'all' ? $key : null, 'q' => $q !== '' ? $q : null]))) ?>"><?= $label ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php if (empty($products)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-basket2" style="font-size:2.5rem;"></i>
                    <p class="mt-3 mb-0"><?= ($q !== '' || $filter !== 'all') ? 'Nenhum resultado encontrado.' : 'Sem produtos no catálogo.' ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive sa-table-wrap">
                    <table class="table align-middle sa-table" id="productsTable">
                        <thead>
                            <tr>
                                <th>Produto</th>
                                <th>Agricultor</th>
                                <th>Categoria</th>
                                <th class="text-end">Preço</th>
                                <th class="text-center">Stock</th>
                                <th>Estado</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($products as $p):
                            $st    = $p['status'] ?? 'active';
                            $stock = (float)($p['stock'] ?? 0);
                            $isLow = $stock > 0 && $stock <= 5;
                            $isOut = $stock <= 0;

                            $badgeClass = $st === 'active' ? 'success' : ($st === 'out_of_stock' ? 'warning' : 'danger');
                            $badgeIcon  = $st === 'active' ? 'check-circle-fill' : ($st === 'out_of_stock' ? 'box' : 'slash-circle-fill');
                            $badgeLabel = $st === 'active' ? 'Ativo' : ($st === 'out_of_stock' ? 'Esgotado' : 'Inativo');

                            $stockClass = $isOut ? 'sa-stock-out' : ($isLow ? 'sa-stock-low' : 'sa-stock-ok');

                            $imgUrl = !empty($p['main_image']) ? get_product_image($p['main_image']) : '';
                        ?>
                            <tr data-status="<?= e($st) ?>"
                                data-stock="<?= $isOut ? 'out' : ($isLow ? 'low' : 'ok') ?>">
                                <td>
                                    <div class="sa-product-cell">
                                        <?php if ($imgUrl): ?>
                                            <img class="sa-product-thumb"
                                                 src="<?= e($imgUrl) ?>"
                                                 alt="<?= e($p['name']) ?>">
                                        <?php else: ?>
                                            <span class="sa-product-thumb sa-thumb-placeholder">
                                                <i class="bi bi-basket2"></i>
                                            </span>
                                        <?php endif; ?>
                                        <div class="sa-user-info">
                                            <strong><?= e($p['name']) ?></strong>
                                            <small>#<?= (int)$p['id'] ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="sa-farmer-tag">
                                        <i class="bi bi-person-badge"></i>
                                        <?= e($p['farmer_name'] ?? '—') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="sa-cat-tag">
                                        <i class="bi bi-tag"></i>
                                        <?= e($p['category_name'] ?? '—') ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <strong class="sa-price"><?= money($p['price']) ?></strong>
                                </td>
                                <td class="text-center">
                                    <span class="sa-stock <?= $stockClass ?>">
                                        <?php if ($isOut): ?>
                                            <i class="bi bi-x-circle"></i> 0
                                        <?php elseif ($isLow): ?>
                                            <i class="bi bi-exclamation-triangle"></i> <?= rtrim(rtrim(number_format($stock, 2, '.', ''), '0'), '.') ?>
                                        <?php else: ?>
                                            <?= rtrim(rtrim(number_format($stock, 2, '.', ''), '0'), '.') ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="sa-badge sa-badge-<?= $badgeClass ?>">
                                        <i class="bi bi-<?= $badgeIcon ?>"></i>
                                        <?= $badgeLabel ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="sa-actions">
                                        <a href="<?= base_path('produto.php?id='.(int)$p['id']) ?>"
                                           class="sa-btn sa-btn-ghost"
                                           title="Ver no site"
                                           target="_blank" rel="noopener">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                            <span>Ver</span>
                                        </a>
                                        <a href="<?= base_path('admin/produto-editar.php?id='.(int)$p['id']) ?>"
                                           class="sa-btn sa-btn-ghost"
                                           title="Editar">
                                            <i class="bi bi-pencil"></i>
                                            <span>Editar</span>
                                        </a>
                                        <?= post_button('', ['action' => 'toggle', 'id' => (int)$p['id']], '<i class="bi bi-power"></i> <span>' . ($st === 'active' ? 'Desativar' : 'Ativar') . '</span>', 'sa-btn ' . ($st === 'active' ? 'sa-btn-danger' : 'sa-btn-success'), null, $st === 'active' ? 'Desativar' : 'Ativar') ?>
                                        <?= post_button('', ['action' => 'delete', 'id' => (int)$p['id']], '<i class="bi bi-trash"></i> <span>Apagar</span>', 'sa-btn sa-btn-danger', 'Apagar o produto "' . $p['name'] . '"? Esta ação é irreversível.', 'Apagar') ?>
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
