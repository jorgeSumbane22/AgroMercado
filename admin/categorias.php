<?php require_once __DIR__.'/../includes/permissions.php'; ?>
<?php
require_role('superadmin');
require_once __DIR__.'/_helpers.php';
$pdo = db();

/** Normaliza o ícone para uma classe Bootstrap Icons (bi-xxx). Devolve null se inválido. */
function category_icon_normalize(string $icon): ?string {
    $icon = trim($icon);
    if ($icon === '') return 'bi-tag';
    $icon = preg_replace('/^bi\s+/i', '', $icon);
    if (strpos($icon, 'bi-') !== 0) $icon = 'bi-' . ltrim($icon, '-');
    $icon = strtolower($icon);
    return (preg_match('/^bi-[a-z0-9-]+$/', $icon) && strlen($icon) <= 20) ? $icon : null;
}

/* ============================================================
   AÇÕES (POST + CSRF): criar, editar, ativar/desativar, apagar
   ============================================================ */
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    $action = $_POST['action'] ?? 'create';
    $id = safe_int($_POST['id'] ?? 0);
    $cat = null;
    if ($action !== 'create') {
        $s = $pdo->prepare('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id) products_count FROM categories c WHERE c.id=?');
        $s->execute([$id]);
        $cat = $s->fetch();
    }

    if ($action !== 'create' && !$cat) {
        flash('danger', 'Categoria não encontrada.');
    } elseif ($action === 'create' || $action === 'update') {
        $name = trim((string)($_POST['name'] ?? ''));
        $icon = category_icon_normalize((string)($_POST['icon'] ?? ''));
        $slug = slugify($name);
        if ($name === '' || mb_strlen($name) > 100 || $slug === '') {
            flash('danger', 'Indique um nome válido (até 100 caracteres).');
        } elseif ($icon === null) {
            flash('danger', 'Ícone inválido. Use uma classe Bootstrap Icons (ex.: bi-flower2).');
        } else {
            try {
                if ($action === 'create') {
                    $pdo->prepare('INSERT INTO categories(name,slug,icon) VALUES(?,?,?)')->execute([$name, $slug, $icon]);
                    audit(user()['id'], 'create', 'categories', 'Admin criou a categoria ' . $name);
                    flash('success', 'Categoria criada.');
                } else {
                    $pdo->prepare('UPDATE categories SET name=?, slug=?, icon=? WHERE id=?')->execute([$name, $slug, $icon, $id]);
                    audit(user()['id'], 'update', 'categories', 'Categoria #' . $id . ' renomeada/atualizada: ' . $cat['name'] . ' → ' . $name);
                    flash('success', 'Categoria atualizada.');
                    $saved = true;
                }
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) == 1062) flash('danger', 'Já existe uma categoria com o nome "' . $name . '" (ou slug equivalente).');
                else { error_log('categorias: ' . $e->getMessage()); flash('danger', 'Não foi possível guardar a categoria.'); }
            }
        }
        if ($action === 'update' && empty($saved)) redirect(base_path('admin/categorias.php?edit=' . $id));
    } elseif ($action === 'toggle') {
        $new = (int)$cat['is_active'] ? 0 : 1;
        $pdo->prepare('UPDATE categories SET is_active=? WHERE id=?')->execute([$new, $id]);
        audit(user()['id'], 'update', 'categories', 'Categoria #' . $id . ' (' . $cat['name'] . ') ' . ($new ? 'ativada' : 'desativada'));
        flash('success', 'Categoria ' . ($new ? 'ativada' : 'desativada') . '.');
    } elseif ($action === 'delete') {
        if ((int)$cat['products_count'] > 0) {
            flash('warning', 'A categoria "' . $cat['name'] . '" tem ' . (int)$cat['products_count'] . ' produto(s) e não pode ser apagada. Mova os produtos ou desative a categoria.');
        } else {
            try {
                $pdo->prepare('DELETE FROM categories WHERE id=?')->execute([$id]);
                audit(user()['id'], 'delete', 'categories', 'Categoria #' . $id . ' (' . $cat['name'] . ') apagada');
                flash('success', 'Categoria apagada.');
            } catch (PDOException $e) {
                flash('danger', 'Não foi possível apagar a categoria (existem registos associados).');
            }
        }
    } else {
        flash('danger', 'Ação inválida.');
    }
    redirect(base_path('admin/categorias.php'));
}

/* ============================================================
   LISTA DE CATEGORIAS
   ============================================================ */
$cats = $pdo->query('
    SELECT c.*, COUNT(p.id) AS products_count
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    GROUP BY c.id
    ORDER BY c.name
')->fetchAll();

$editing = null;
if (isset($_GET['edit'])) {
    foreach ($cats as $c) if ((int)$c['id'] === safe_int($_GET['edit'])) $editing = $c;
}
$formIcon = $editing ? ($editing['icon'] ?: 'bi-tag') : 'bi-tag';

/* ============================================================
   ESTATÍSTICAS
   ============================================================ */
$totalCats = count($cats);
$totalProducts = 0;
$emptyCats = 0;
foreach ($cats as $c) {
    $n = (int)$c['products_count'];
    $totalProducts += $n;
    if ($n === 0) $emptyCats++;
}
$avgPerCat = $totalCats > 0 ? round($totalProducts / $totalCats, 1) : 0;

/* Ícones sugeridos (Bootstrap Icons) */
$iconSuggestions = [
    'bi-sun',       'bi-flower1',    'bi-flower2',    'bi-flower3',    'bi-tree',
    'bi-basket2',   'bi-basket3',    'bi-egg',        'bi-egg-fried',  'bi-cup-hot',
    'bi-cup-straw', 'bi-droplet',    'bi-fire',      'bi-thermometer-sun',        'bi-cloud-sun',
    'bi-moisture',  'bi-bug',        'bi-heart',      'bi-star',       'bi-shop',
    'bi-box-seam',  'bi-bag',        'bi-bucket',     'bi-minecart',   'bi-truck',
];

$page_title = 'Categorias';
include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <!-- ================= HERO ================= -->
        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Catálogo · Categorias
                </span>
                <h1 class="sa-hero-title">Gestão de <em>categorias</em></h1>
                <p class="sa-hero-sub">
                    <?php if ($emptyCats > 0): ?>
                        <strong><?= $emptyCats ?></strong> categoria<?= $emptyCats > 1 ? 's' : '' ?> sem produtos associados.
                    <?php else: ?>
                        Todas as categorias têm produtos.
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
                    <span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-tags"></i></span>
                </header>
                <span class="sa-kpi-label">Total</span>
                <strong class="sa-kpi-value"><?= $totalCats ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-grid-3x3-gap"></i> categorias</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-basket2"></i></span>
                </header>
                <span class="sa-kpi-label">Produtos</span>
                <strong class="sa-kpi-value"><?= $totalProducts ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-box-seam"></i> em catálogo</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-info"><i class="bi bi-bar-chart-line"></i></span>
                </header>
                <span class="sa-kpi-label">Média por categoria</span>
                <strong class="sa-kpi-value"><?= $avgPerCat ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-calculator"></i> produtos / categoria</span>
                </footer>
            </article>

            <article class="sa-kpi <?= $emptyCats > 0 ? 'sa-kpi-warn' : '' ?>">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon <?= $emptyCats > 0 ? 'sa-icon-warning' : 'sa-icon-success' ?>">
                        <i class="bi bi-<?= $emptyCats > 0 ? 'exclamation-triangle' : 'check2-circle' ?>"></i>
                    </span>
                </header>
                <span class="sa-kpi-label">Vazias</span>
                <strong class="sa-kpi-value"><?= $emptyCats ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub">
                        <i class="bi bi-<?= $emptyCats > 0 ? 'hourglass-split' : 'shield-check' ?>"></i>
                        <?= $emptyCats > 0 ? 'sem produtos' : 'tudo em ordem' ?>
                    </span>
                </footer>
            </article>

        </div>

        <!-- ================= CONTEÚDO ================= -->
        <div class="row g-4">

            <!-- Lista -->
            <div class="col-lg-8">
                <article class="content-card sa-card">

                    <div class="card-heading sa-card-head">
                        <div>
                            <h4>Lista de categorias</h4>
                            <p class="sa-card-sub"><?= $totalCats ?> registos</p>
                        </div>

                        <div class="sa-toolbar">
                            <div class="sa-search">
                                <i class="bi bi-search"></i>
                                <input type="text" id="catSearch" placeholder="Procurar categoria…">
                            </div>
                        </div>
                    </div>

                    <?php if (empty($cats)): ?>
                        <div class="empty-state py-5">
                            <i class="bi bi-tags" style="font-size:2.5rem;"></i>
                            <p class="mt-3 mb-0">Sem categorias criadas.</p>
                        </div>
                    <?php else: ?>
                        <div class="sa-cat-grid" id="catGrid">
                            <?php foreach ($cats as $c):
                                $n = (int)$c['products_count'];
                                $isEmpty = $n === 0;
                                $isActive = (int)$c['is_active'] === 1;
                                $search = mb_strtolower(($c['name'] ?? '') . ' ' . ($c['slug'] ?? ''));
                            ?>
                                <article class="sa-cat-card <?= $isEmpty ? 'sa-cat-empty' : '' ?>"
                                         data-search="<?= e($search) ?>"<?= $isActive ? '' : ' style="opacity:.6"' ?>>
                                    <div class="sa-cat-top">
                                        <span class="sa-cat-icon">
                                            <?= category_icon_html($c['icon']) ?>
                                        </span>
                                        <span class="sa-cat-count <?= $isEmpty ? 'sa-cat-count-empty' : '' ?>">
                                            <?= $n ?> produto<?= $n !== 1 ? 's' : '' ?>
                                        </span>
                                    </div>
                                    <h5 class="sa-cat-name"><?= e($c['name']) ?><?php if (!$isActive): ?> <span class="badge text-bg-secondary">Inativa</span><?php endif; ?></h5>
                                    <code class="sa-cat-slug"><?= e($c['slug']) ?></code>

                                    <?php if (!$isEmpty): ?>
                                        <div class="sa-cat-bar">
                                            <?php $pct = $totalProducts > 0 ? max(4, round(($n / $totalProducts) * 100)) : 0; ?>
                                            <span style="width:<?= $pct ?>%"></span>
                                        </div>
                                    <?php endif; ?>

                                    <a href="<?= base_path('marketplace.php?categoria=' . (int)$c['id']) ?>"
                                       class="sa-cat-link"
                                       target="_blank" rel="noopener">
                                        Ver produtos <i class="bi bi-arrow-right"></i>
                                    </a>

                                    <div class="d-flex flex-wrap gap-1 mt-2">
                                        <a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int)$c['id'] ?>#catForm" title="Editar"><i class="bi bi-pencil"></i> Editar</a>
                                        <?= post_button('', ['action' => 'toggle', 'id' => (int)$c['id']], '<i class="bi bi-power"></i> ' . ($isActive ? 'Desativar' : 'Ativar'), 'btn btn-sm ' . ($isActive ? 'btn-outline-warning' : 'btn-outline-success'), $isActive ? 'Desativar a categoria "' . $c['name'] . '"? Deixa de aparecer no marketplace.' : null) ?>
                                        <?php if ($isEmpty): ?>
                                            <?= post_button('', ['action' => 'delete', 'id' => (int)$c['id']], '<i class="bi bi-trash"></i>', 'btn btn-sm btn-outline-danger', 'Apagar a categoria "' . $c['name'] . '"?', 'Apagar') ?>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" disabled title="Tem produtos associados"><i class="bi bi-trash"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>

                        <div id="noResults" class="empty-state py-5" style="display:none;">
                            <i class="bi bi-search" style="font-size:2rem;"></i>
                            <p class="mt-3 mb-0">Nenhum resultado encontrado.</p>
                        </div>
                    <?php endif; ?>

                </article>
            </div>

            <!-- Formulário -->
            <div class="col-lg-4">
                <article class="content-card sa-card sa-form-card" id="catForm">
                    <div class="card-heading">
                        <div>
                            <h4><?= $editing ? 'Editar categoria' : 'Nova categoria' ?></h4>
                            <p class="sa-card-sub"><?= $editing ? 'A editar: ' . e($editing['name']) . ' · <a href="' . base_path('admin/categorias.php') . '">cancelar</a>' : 'Adicione uma nova categoria ao catálogo' ?></p>
                        </div>
                    </div>

                    <form method="post" class="mt-2" action="<?= base_path('admin/categorias.php') ?>">
                        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
                        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
                        <?= csrf_field() ?>

                        <div class="sa-field">
                            <label class="form-label">Ícone</label>

                            <div class="sa-icon-picker">
                                <input type="hidden" id="catIcon" name="icon" value="<?= e($formIcon) ?>">

                                <div class="sa-icon-preview">
                                    <span class="sa-icon-preview-box" id="iconPreview">
                                        <?= category_icon_html($formIcon) ?>
                                    </span>
                                    <code id="iconValue"><?= e($formIcon) ?></code>
                                </div>

                                <div class="sa-icon-suggestions">
                                    <?php foreach ($iconSuggestions as $ic): ?>
                                        <button type="button"
                                                class="sa-icon-btn <?= $ic === $formIcon ? 'active' : '' ?>"
                                                data-icon="<?= $ic ?>"
                                                title="<?= $ic ?>">
                                            <i class="bi <?= $ic ?>"></i>
                                        </button>
                                    <?php endforeach; ?>
                                </div>

                                <label class="sa-field-hint" for="catIconManual">Ou escreva manualmente:</label>
                                <input type="text"
                                       id="catIconManual"
                                       class="form-control sa-icon-manual"
                                       placeholder="ex: bi-flower2"
                                       value="<?= e($formIcon) ?>" maxlength="20"
                                       autocomplete="off">
                            </div>
                        </div>

                        <div class="sa-field">
                            <label class="form-label" for="catName">Nome</label>
                            <input type="text" id="catName" name="name" maxlength="100" value="<?= e($editing['name'] ?? '') ?>"
                                   class="form-control"
                                   placeholder="Ex: Legumes frescos"
                                   required
                                   autocomplete="off">
                            <small class="sa-field-hint">O slug será gerado automaticamente.</small>
                        </div>

                        <div class="sa-slug-preview" id="slugPreview" style="display:none;">
                            <i class="bi bi-link-45deg"></i>
                            <code id="slugValue">—</code>
                        </div>

                        <button class="btn btn-primary w-100 sa-submit">
                            <i class="bi bi-<?= $editing ? 'check2' : 'plus-lg' ?>"></i> <?= $editing ? 'Guardar alterações' : 'Adicionar categoria' ?>
                        </button>
                    </form>
                </article>
            </div>

        </div>

    </section>
</div>

<script>
(function () {
    /* ---------- Pesquisa ---------- */
    var input = document.getElementById('catSearch');
    var grid  = document.getElementById('catGrid');
    var noRes = document.getElementById('noResults');
    if (grid) {
        var cards = Array.from(grid.querySelectorAll('.sa-cat-card'));
        function filterCards() {
            var term = (input.value || '').toLowerCase().trim();
            var visible = 0;
            cards.forEach(function (c) {
                var show = !term || (c.dataset.search || '').includes(term);
                c.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            if (noRes) noRes.style.display = visible === 0 ? '' : 'none';
        }
        if (input) input.addEventListener('input', filterCards);
    }

    /* ---------- Picker de ícone ---------- */
    var hidden  = document.getElementById('catIcon');
    var manual  = document.getElementById('catIconManual');
    var preview = document.getElementById('iconPreview');
    var iconVal = document.getElementById('iconValue');

    function setIcon(cls) {
        if (!cls) return;
        cls = cls.trim();
        cls = cls.replace(/^bi\s*/i, '');
        if (cls.indexOf('bi-') !== 0) cls = 'bi-' + cls.replace(/^-+/, '');
        if (!/^bi-[a-z0-9\-]+$/i.test(cls)) return;

        hidden.value = cls;
        iconVal.textContent = cls;
        preview.innerHTML = '<i class="bi ' + cls + '"></i>';
        if (manual && manual.value !== cls) manual.value = cls;
        document.querySelectorAll('.sa-icon-btn').forEach(function (b) {
            b.classList.toggle('active', b.dataset.icon === cls);
        });
    }

    document.querySelectorAll('.sa-icon-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            setIcon(b.dataset.icon);
        });
    });

    if (manual) {
        manual.addEventListener('input', function () {
            var v = manual.value.trim();
            if (v) setIcon(v);
        });
    }

    /* ---------- Preview de slug ---------- */
    var nameInput = document.getElementById('catName');
    var slugBox   = document.getElementById('slugPreview');
    var slugValue = document.getElementById('slugValue');

    function slugify(s) {
        return s.toString().toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    if (nameInput) {
        nameInput.addEventListener('input', function () {
            var s = slugify(nameInput.value);
            if (s) {
                slugBox.style.display = '';
                slugValue.textContent = s;
            } else {
                slugBox.style.display = 'none';
            }
        });
    }
})();
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>