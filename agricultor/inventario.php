<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';

require_role('farmer');

$page_title = 'Histórico de inventário';
$fid = user()['farmer_id'];
$pdo = db();

/* ============================================================
   FILTROS
   ============================================================ */
$types = ['initial' => 'Stock inicial', 'sale' => 'Venda', 'adjustment' => 'Ajuste', 'return' => 'Devolução / cancelamento'];
$type = array_key_exists($_GET['type'] ?? '', $types) ? $_GET['type'] : '';
$productId = safe_int($_GET['product'] ?? 0);

$s = $pdo->prepare('SELECT id, name, unit FROM products WHERE farmer_id = ? ORDER BY name');
$s->execute([$fid]);
$myProducts = $s->fetchAll();

$where = 'p.farmer_id = ?';
$params = [$fid];
if ($type !== '') { $where .= ' AND i.movement_type = ?'; $params[] = $type; }
if ($productId > 0) { $where .= ' AND i.product_id = ?'; $params[] = $productId; }

/* ============================================================
   MOVIMENTOS (paginados)
   ============================================================ */
$s = $pdo->prepare("SELECT COUNT(*) FROM inventory i JOIN products p ON p.id = i.product_id WHERE $where");
$s->execute($params);
$total = (int)$s->fetchColumn();

$perPage = 25;
[$page, $offset] = page_offset($perPage);
$s = $pdo->prepare("
    SELECT i.*, p.name AS product_name, p.unit, u.name AS user_name
    FROM inventory i
    JOIN products p ON p.id = i.product_id
    LEFT JOIN users u ON u.id = i.created_by
    WHERE $where
    ORDER BY i.created_at DESC, i.id DESC
    LIMIT $perPage OFFSET $offset
");
$s->execute($params);
$moves = $s->fetchAll();

/* Totais por tipo (com os mesmos filtros de produto) */
$s = $pdo->prepare('
    SELECT i.movement_type, COUNT(*) AS n, COALESCE(SUM(i.quantity), 0) AS qty
    FROM inventory i JOIN products p ON p.id = i.product_id
    WHERE p.farmer_id = ?' . ($productId > 0 ? ' AND i.product_id = ?' : '') . '
    GROUP BY i.movement_type
');
$s->execute($productId > 0 ? [$fid, $productId] : [$fid]);
$byType = [];
foreach ($s->fetchAll() as $r) $byType[$r['movement_type']] = $r;

$typeMeta = [
    'initial'    => ['primary', 'box-arrow-in-down'],
    'sale'       => ['danger',  'cart-dash'],
    'adjustment' => ['warning', 'sliders'],
    'return'     => ['success', 'arrow-counterclockwise'],
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
                    <span class="sa-dot"></span> Inventário · Movimentos
                </span>
                <h1 class="sa-hero-title">Histórico de <em>stock</em></h1>
                <p class="sa-hero-sub">
                    Entradas, vendas, ajustes e devoluções dos seus produtos.
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('agricultor/stock.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Gestão de stock
                </a>
            </div>
        </header>

        <!-- ================= KPIs ================= -->
        <div class="sa-kpi-grid sa-kpi-grid-4">
            <?php foreach ($types as $tk => $tl): [$cls, $icon] = $typeMeta[$tk]; ?>
                <article class="sa-kpi">
                    <header class="sa-kpi-head">
                        <span class="sa-kpi-icon sa-icon-<?= $cls ?>"><i class="bi bi-<?= $icon ?>"></i></span>
                    </header>
                    <span class="sa-kpi-label"><?= e($tl) ?></span>
                    <strong class="sa-kpi-value"><?= (int)($byType[$tk]['n'] ?? 0) ?></strong>
                    <footer class="sa-kpi-foot">
                        <span class="sa-kpi-sub">
                            <?php $qty = (float)($byType[$tk]['qty'] ?? 0); ?>
                            <?= $qty > 0 ? '+' : '' ?><?= number_format($qty, 2, ',', '.') ?> unidades
                        </span>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>

        <!-- ================= TABELA ================= -->
        <article class="content-card sa-card">
            <div class="card-heading sa-card-head">
                <div>
                    <h4>Movimentos</h4>
                    <p class="sa-card-sub"><?= $total ?> registo<?= $total !== 1 ? 's' : '' ?></p>
                </div>

                <form method="get" class="d-flex flex-wrap gap-2">
                    <select name="product" class="form-select form-select-sm" style="width:auto">
                        <option value="0">Todos os produtos</option>
                        <?php foreach ($myProducts as $mp): ?>
                            <option value="<?= (int)$mp['id'] ?>" <?= $productId === (int)$mp['id'] ? 'selected' : '' ?>><?= e($mp['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="type" class="form-select form-select-sm" style="width:auto">
                        <option value="">Todos os tipos</option>
                        <?php foreach ($types as $tk => $tl): ?>
                            <option value="<?= e($tk) ?>" <?= $type === $tk ? 'selected' : '' ?>><?= e($tl) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Filtrar</button>
                    <?php if ($type !== '' || $productId > 0): ?>
                        <a href="<?= base_path('agricultor/inventario.php') ?>" class="btn btn-sm btn-outline-secondary">Limpar</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (empty($moves)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-clock-history" style="font-size:2.5rem;"></i>
                    <p class="mt-3 mb-0">Sem movimentos de inventário<?= ($type !== '' || $productId > 0) ? ' para este filtro' : '' ?>.</p>
                </div>
            <?php else: ?>
                <div class="sa-scroll-x">
                    <table class="table align-middle sa-table">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Produto</th>
                                <th>Tipo</th>
                                <th class="text-end">Quantidade</th>
                                <th>Notas</th>
                                <th>Por</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($moves as $m):
                            [$cls, $icon] = $typeMeta[$m['movement_type']] ?? ['secondary', 'circle'];
                            $q = (float)$m['quantity'];
                        ?>
                            <tr>
                                <td>
                                    <?= date('d/m/Y', strtotime($m['created_at'])) ?>
                                    <small class="text-muted d-block"><?= date('H:i', strtotime($m['created_at'])) ?></small>
                                </td>
                                <td><strong><?= e($m['product_name']) ?></strong></td>
                                <td>
                                    <span class="badge text-bg-<?= $cls ?>"><i class="bi bi-<?= $icon ?> me-1"></i><?= e($types[$m['movement_type']] ?? $m['movement_type']) ?></span>
                                </td>
                                <td class="text-end">
                                    <strong class="<?= $q < 0 ? 'text-danger' : 'text-success' ?>"><?= $q > 0 ? '+' : '' ?><?= number_format($q, 2, ',', '.') ?></strong>
                                    <small class="text-muted"><?= e($m['unit']) ?></small>
                                </td>
                                <td><?= e($m['notes'] ?? '—') ?></td>
                                <td><?= e($m['user_name'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3"><?= pagination($page, $perPage, $total) ?></div>
            <?php endif; ?>
        </article>

    </section>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
