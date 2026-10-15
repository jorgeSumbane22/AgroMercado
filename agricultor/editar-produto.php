<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/_helpers.php';

require_role('farmer');

$page_title = 'Editar produto';
$fid = user()['farmer_id'];
$pdo = db();

/* ============================================================
   CARREGAR PRODUTO
   ============================================================ */
$id = safe_int($_GET['id'] ?? $_POST['id'] ?? 0);

$s = $pdo->prepare('SELECT * FROM products WHERE id = ? AND farmer_id = ?');
$s->execute([$id, $fid]);
$product = $s->fetch();

if (!$product) {
    flash('danger', 'Produto não encontrado.');
    redirect(base_path('agricultor/produtos.php'));
}

/* ============================================================
   CATEGORIAS
   ============================================================ */
$categories = [];
try {
    $categories = $pdo->query('SELECT * FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll() ?: [];
} catch (Throwable $e) {
    $categories = [];
}

/* ============================================================
   PROCESSAR POST
   ============================================================ */
$errors = [];
$verified = farmer_verified();

/* Ações da galeria (remover / usar como capa) — o produto já foi validado como sendo deste agricultor */
if (is_post() && isset($_POST['gallery_action'])) {
    verify_csrf($_POST['csrf'] ?? '');
    try {
        [$type, $msg] = handle_gallery_action($pdo, $id, $_POST) ?? ['danger', 'Ação inválida.'];
        if ($type === 'success') audit(user()['id'], 'update', 'products', 'Agricultor alterou a galeria do produto #' . $id . ' (' . $_POST['gallery_action'] . ')');
    } catch (Throwable $e) {
        error_log($e->getMessage());
        [$type, $msg] = ['danger', 'Não foi possível alterar a galeria.'];
    }
    flash($type, $msg);
    redirect(base_path('agricultor/editar-produto.php?id=' . $id . '#galeria'));
}

if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');

    $newImage = null;
    try {
        $d = product_form_input($pdo, $_POST);

        /* Imagem: mantém a atual ou substitui (erro de upload é mostrado) */
        $newImage = product_form_image($_FILES['image'] ?? null);
        $image = $newImage ?? ($product['main_image'] ?? null);

        /* Estado: nunca ativo sem verificação; sem stock → out_of_stock; reposto → ativo (se verificado) */
        $status = $product['status'];
        if (!$verified && $status === 'active') $status = 'inactive';
        if ($d['stock'] <= 0 && $status === 'active') $status = 'out_of_stock';
        if ($d['stock'] > 0 && $status === 'out_of_stock') $status = $verified ? 'active' : 'inactive';

        $pdo->beginTransaction();
        $s = $pdo->prepare('
            UPDATE products SET
                category_id  = ?,
                name         = ?,
                description  = ?,
                price        = ?,
                unit         = ?,
                stock        = ?,
                quantity_min = ?,
                main_image   = ?,
                status       = ?,
                province     = ?,
                district     = ?,
                locality     = ?,
                harvest_date = ?,
                expiry_date  = ?
            WHERE id = ? AND farmer_id = ?
        ');
        $s->execute([
            $d['category_id'],
            $d['name'],
            $d['description'],
            $d['price'],
            $d['unit'],
            $d['stock'],
            $d['quantity_min'],
            $image,
            $status,
            $d['province'],
            $d['district'],
            $d['locality'],
            $d['harvest_date'],
            $d['expiry_date'],
            $id,
            $fid,
        ]);

        /* Movimento de inventário: regista a diferença de stock */
        $delta = round($d['stock'] - (float)$product['stock'], 2);
        if ($delta != 0) {
            $pdo->prepare('INSERT INTO inventory (product_id, quantity, movement_type, notes, created_by) VALUES (?, ?, "adjustment", ?, ?)')
                ->execute([$id, $delta, 'Ajuste na edição do produto (' . (float)$product['stock'] . ' → ' . $d['stock'] . ')', user()['id']]);
        }

        audit(user()['id'], 'update', 'products', 'Agricultor atualizou o produto #' . $id . ' ' . $d['name'] . ($delta != 0 ? ' (stock ' . ($delta > 0 ? '+' : '') . $delta . ')' : ''));
        $pdo->commit();

        /* Imagem antiga só é apagada depois de gravar com sucesso (e só se estiver em uploads/) */
        if ($newImage && !empty($product['main_image']) && $product['main_image'] !== $newImage) delete_product_image_file($product['main_image']);

        /* Galeria: novas imagens (o produto já está gravado; erros de imagens individuais são avisos) */
        try { $galleryErrors = add_product_images($pdo, $id, $_FILES['gallery'] ?? null); }
        catch (Throwable $ge) { error_log($ge->getMessage()); $galleryErrors = ['erro inesperado.']; }
        if ($galleryErrors) flash('warning', 'Algumas imagens da galeria não foram guardadas: ' . implode(' ', $galleryErrors));

        flash('success', 'Produto atualizado com sucesso.' . (!$verified ? ' O produto será publicado após a verificação da conta.' : ''));
        redirect(base_path('agricultor/produtos.php'));

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($newImage) delete_product_image_file($newImage);
        if (!$e instanceof RuntimeException) error_log($e->getMessage());
        $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível guardar as alterações. Verifique os dados e tente novamente.';
    }
}

/* ============================================================
   VALORES ATUAIS (usa POST se houver erro, senão o produto)
   ============================================================ */
$v = function (string $key, $default = '') use ($product) {
    if (is_post() && isset($_POST[$key])) return $_POST[$key];
    return $product[$key] ?? $default;
};

/* Províncias */
$provincias = provinces();

/* Unidades */
$unidades = product_units();

/* Galeria */
$gallery = product_gallery($id);

/* Imagem atual */
$currentImage = '';
if (!empty($product['main_image']) && function_exists('get_product_image')) {
    $currentImage = get_product_image($product['main_image']);
}

include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <!-- ================= HERO ================= -->
        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Catálogo · Editar produto
                </span>
                <h1 class="sa-hero-title">Editar <em>produto</em></h1>
                <p class="sa-hero-sub">
                    Produto <strong>#<?= (int)$product['id'] ?></strong> ·
                    atualize os dados e guarde as alterações.
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('agricultor/produtos.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
                <button type="submit" form="productForm" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> Guardar alterações
                </button>
            </div>
        </header>

        <?php if (!$verified): ?>
            <div class="alert alert-warning d-flex gap-2 align-items-start mb-4">
                <i class="bi bi-shield-exclamation fs-5"></i>
                <div><strong>Conta ainda não verificada.</strong> O produto fica inativo — o produto será publicado após a verificação da conta.</div>
            </div>
        <?php endif; ?>

        <!-- ================= ERROS ================= -->
        <?php if (!empty($errors)): ?>
            <div class="sa-alert sa-alert-danger mb-4">
                <span class="sa-alert-icon"><i class="bi bi-exclamation-octagon-fill"></i></span>
                <span class="sa-alert-body">
                    <strong>Não foi possível guardar</strong>
                    <?php foreach ($errors as $err): ?>
                        <p class="mb-0"><?= e($err) ?></p>
                    <?php endforeach; ?>
                </span>
            </div>
        <?php endif; ?>

        <!-- ================= FORM ================= -->
        <form id="productForm"
              method="post"
              action="<?= base_path('agricultor/editar-produto.php?id=' . (int)$product['id']) ?>"
              enctype="multipart/form-data"
              novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$product['id'] ?>">

            <div class="row g-4">

                <!-- ===== COLUNA ESQUERDA ===== -->
                <div class="col-lg-8">

                    <!-- INFORMAÇÃO BÁSICA -->
                    <article class="content-card sa-card mb-4">
                        <div class="card-heading">
                            <div class="sa-card-head-icon">
                                <span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-info-circle"></i></span>
                                <div>
                                    <h4>Informação básica</h4>
                                    <p class="sa-card-sub">Nome, categoria e descrição do produto</p>
                                </div>
                            </div>
                        </div>

                        <div class="sa-form-grid">
                            <div class="sa-field sa-field-full">
                                <label class="form-label" for="pName">
                                    Nome do produto <span class="sa-req">*</span>
                                </label>
                                <input id="pName" type="text" name="name" class="form-control"
                                       value="<?= e($v('name')) ?>"
                                       required maxlength="120">
                            </div>

                            <div class="sa-field">
                                <label class="form-label" for="pCategory">
                                    Categoria <span class="sa-req">*</span>
                                </label>
                                <div class="sa-select-wrap">
                                    <select id="pCategory" name="category_id" class="sa-select" required>
                                        <option value="">Escolha uma categoria…</option>
                                        <?php foreach ($categories as $c): ?>
                                            <option value="<?= (int)$c['id'] ?>"
                                                <?= (int)$v('category_id') === (int)$c['id'] ? 'selected' : '' ?>>
                                                <?= e($c['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <i class="bi bi-chevron-down sa-select-chevron"></i>
                                </div>
                            </div>

                            <div class="sa-field">
                                <label class="form-label" for="pUnit">
                                    Unidade de venda <span class="sa-req">*</span>
                                </label>
                                <div class="sa-select-wrap">
                                    <select id="pUnit" name="unit" class="sa-select" required>
                                        <?php foreach ($unidades as $u => $uLabel): ?>
                                            <option value="<?= e($u) ?>"
                                                <?= (string)$v('unit', 'kg') === (string)$u ? 'selected' : '' ?>>
                                                <?= e($uLabel) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <i class="bi bi-chevron-down sa-select-chevron"></i>
                                </div>
                            </div>

                            <div class="sa-field sa-field-full">
                                <label class="form-label" for="pDesc">Descrição</label>
                                <textarea id="pDesc" name="description" class="form-control" rows="4"
                                          maxlength="600"><?= e($v('description')) ?></textarea>
                                <div class="sa-char-counter">
                                    <span id="descCount">0</span> / 600 caracteres
                                </div>
                            </div>
                        </div>
                    </article>

                    <!-- PREÇO E STOCK -->
                    <article class="content-card sa-card mb-4">
                        <div class="card-heading">
                            <div class="sa-card-head-icon">
                                <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-cash-coin"></i></span>
                                <div>
                                    <h4>Preço e stock</h4>
                                    <p class="sa-card-sub">Defina o preço unitário e a quantidade disponível</p>
                                </div>
                            </div>
                        </div>

                        <div class="sa-form-grid">
                            <div class="sa-field">
                                <label class="form-label" for="pPrice">
                                    Preço por unidade <span class="sa-req">*</span>
                                </label>
                                <div class="sa-input-group">
                                    <input id="pPrice" type="number" name="price" class="form-control"
                                           value="<?= e($v('price')) ?>"
                                           step="0.01" min="0" required>
                                    <span class="sa-input-suffix">MT</span>
                                </div>
                            </div>

                            <div class="sa-field">
                                <label class="form-label" for="pStock">
                                    Stock disponível <span class="sa-req">*</span>
                                </label>
                                <div class="sa-input-group">
                                    <input id="pStock" type="number" name="stock" class="form-control"
                                           value="<?= e($v('stock')) ?>"
                                           step="0.01" min="0" required>
                                    <span class="sa-input-suffix" id="stockUnit"><?= e($v('unit', 'kg')) ?></span>
                                </div>
                            </div>

                            <div class="sa-field sa-field-full">
                                <label class="form-label" for="pMin">
                                    Quantidade mínima por encomenda <span class="sa-req">*</span>
                                </label>
                                <div class="sa-input-group">
                                    <input id="pMin" type="number" name="quantity_min" class="form-control"
                                           value="<?= e($v('quantity_min', '1')) ?>"
                                           step="0.01" min="0.01" required>
                                    <span class="sa-input-suffix" id="minUnit"><?= e($v('unit', 'kg')) ?></span>
                                </div>
                            </div>
                        </div>
                    </article>

                    <!-- LOCALIZAÇÃO E DATAS -->
                    <article class="content-card sa-card mb-4">
                        <div class="card-heading">
                            <div class="sa-card-head-icon">
                                <span class="sa-kpi-icon sa-icon-info"><i class="bi bi-geo-alt"></i></span>
                                <div>
                                    <h4>Localização e datas</h4>
                                    <p class="sa-card-sub">Origem e validade do produto</p>
                                </div>
                            </div>
                        </div>

                        <div class="sa-form-grid">
                            <div class="sa-field">
                                <label class="form-label" for="pProvince">
                                    Província <span class="sa-req">*</span>
                                </label>
                                <div class="sa-select-wrap">
                                    <select id="pProvince" name="province" class="sa-select" required>
                                        <option value="">Escolha…</option>
                                        <?php foreach ($provincias as $p): ?>
                                            <option value="<?= e($p) ?>"
                                                <?= $v('province') === $p ? 'selected' : '' ?>>
                                                <?= e($p) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <i class="bi bi-chevron-down sa-select-chevron"></i>
                                </div>
                            </div>

                            <div class="sa-field">
                                <label class="form-label" for="pDistrict">Distrito</label>
                                <input id="pDistrict" type="text" name="district" class="form-control"
                                       value="<?= e($v('district')) ?>" maxlength="80">
                            </div>

                            <div class="sa-field sa-field-full">
                                <label class="form-label" for="pLocality">Localidade / referência</label>
                                <input id="pLocality" type="text" name="locality" class="form-control"
                                       value="<?= e($v('locality')) ?>" maxlength="120">
                            </div>

                            <div class="sa-field">
                                <label class="form-label" for="pHarvest">Data de colheita</label>
                                <input id="pHarvest" type="date" name="harvest_date" class="form-control"
                                       value="<?= e($v('harvest_date')) ?>">
                            </div>

                            <div class="sa-field">
                                <label class="form-label" for="pExpiry">Data de validade</label>
                                <input id="pExpiry" type="date" name="expiry_date" class="form-control"
                                       value="<?= e($v('expiry_date')) ?>">
                            </div>
                        </div>
                    </article>

                    <!-- IMAGEM -->
                    <article class="content-card sa-card">
                        <div class="card-heading">
                            <div class="sa-card-head-icon">
                                <span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-image"></i></span>
                                <div>
                                    <h4>Imagem do produto</h4>
                                    <p class="sa-card-sub">JPG, PNG ou WEBP · máx. 3 MB</p>
                                </div>
                            </div>
                        </div>

                        <div class="sa-upload" id="uploadZone">
                            <input type="file" id="pImage" name="image"
                                   accept="image/jpeg,image/png,image/webp"
                                   class="sa-upload-input">

                            <?php if ($currentImage): ?>
                                <div class="sa-upload-preview" id="uploadPreview">
                                    <img id="uploadImg" src="<?= e($currentImage) ?>" alt="Imagem atual">
                                    <div class="sa-upload-actions">
                                        <button type="button" class="sa-btn sa-btn-ghost" id="uploadChange">
                                            <i class="bi bi-arrow-repeat"></i> Trocar
                                        </button>
                                        <button type="button" class="sa-btn sa-btn-danger" id="uploadRemove">
                                            <i class="bi bi-trash"></i> Remover
                                        </button>
                                    </div>
                                </div>
                                <div class="sa-upload-empty" id="uploadEmpty" style="display:none;">
                                    <span class="sa-upload-icon"><i class="bi bi-cloud-arrow-up"></i></span>
                                    <strong>Arraste uma imagem ou clique para escolher</strong>
                                    <small>Recomendado: 800×800 px</small>
                                </div>
                            <?php else: ?>
                                <div class="sa-upload-empty" id="uploadEmpty">
                                    <span class="sa-upload-icon"><i class="bi bi-cloud-arrow-up"></i></span>
                                    <strong>Arraste uma imagem ou clique para escolher</strong>
                                    <small>Recomendado: 800×800 px</small>
                                </div>
                                <div class="sa-upload-preview" id="uploadPreview" style="display:none;">
                                    <img id="uploadImg" alt="Pré-visualização">
                                    <div class="sa-upload-actions">
                                        <button type="button" class="sa-btn sa-btn-ghost" id="uploadChange">
                                            <i class="bi bi-arrow-repeat"></i> Trocar
                                        </button>
                                        <button type="button" class="sa-btn sa-btn-danger" id="uploadRemove">
                                            <i class="bi bi-trash"></i> Remover
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>

                    <!-- GALERIA -->
                    <article class="content-card sa-card mt-4" id="galeria">
                        <div class="card-heading">
                            <div class="sa-card-head-icon">
                                <span class="sa-kpi-icon sa-icon-info"><i class="bi bi-images"></i></span>
                                <div>
                                    <h4>Galeria de imagens</h4>
                                    <p class="sa-card-sub"><?= count($gallery) ?> de <?= PRODUCT_GALLERY_MAX ?> imagens extra</p>
                                </div>
                            </div>
                        </div>
                        <?= product_gallery_fields($gallery) ?>
                    </article>

                </div>

                <!-- ===== COLUNA DIREITA ===== -->
                <aside class="col-lg-4">

                    <article class="content-card sa-card mb-4">
                        <div class="card-heading">
                            <div>
                                <h4>Pré-visualização</h4>
                                <p class="sa-card-sub">Como aparece no marketplace</p>
                            </div>
                        </div>

                        <div class="sa-preview-card">
                            <div class="sa-preview-media">
                                <img id="previewImage"
                                     src="<?= $currentImage ? e($currentImage) : '' ?>"
                                     alt=""
                                     style="<?= $currentImage ? 'display:block;' : 'display:none;' ?>">
                                <div class="sa-preview-placeholder" id="previewPlaceholder"
                                     style="<?= $currentImage ? 'display:none;' : '' ?>">
                                    <i class="bi bi-image"></i>
                                </div>
                                <span class="sa-preview-chip" id="previewCategory">—</span>
                            </div>
                            <div class="sa-preview-body">
                                <h5 id="previewName"><?= e($v('name', 'Nome do produto')) ?></h5>
                                <p class="sa-preview-desc" id="previewDesc">
                                    <?= e($v('description', 'Descrição breve do produto aparecerá aqui…')) ?>
                                </p>
                                <div class="sa-preview-foot">
                                    <strong class="sa-preview-price" id="previewPrice"><?= money($v('price', 0)) ?></strong>
                                    <span class="sa-preview-unit" id="previewUnit">/ <?= e($v('unit', 'kg')) ?></span>
                                </div>
                                <div class="sa-preview-stock">
                                    <i class="bi bi-boxes"></i>
                                    <span id="previewStock"><?= e($v('stock', 0)) ?> em stock</span>
                                </div>
                            </div>
                        </div>
                    </article>

                    <article class="content-card sa-card sa-sticky">
                        <div class="card-heading">
                            <div>
                                <h4>Guardar alterações</h4>
                                <p class="sa-card-sub">Verifique os campos obrigatórios</p>
                            </div>
                        </div>

                        <button type="submit" class="sa-submit">
                            <i class="bi bi-save2"></i> Guardar alterações
                        </button>

                        <p class="sa-submit-note">
                            <i class="bi bi-info-circle"></i>
                            As alterações são aplicadas imediatamente.
                        </p>
                    </article>

                </aside>
            </div>
        </form>
        <?= product_gallery_action_forms($gallery, (int)$product['id'], base_path('agricultor/editar-produto.php?id=' . (int)$product['id'])) ?>

    </section>
</div>

<script>
(function () {
    var nameInput  = document.getElementById('pName');
    var priceInput = document.getElementById('pPrice');
    var stockInput = document.getElementById('pStock');
    var descInput  = document.getElementById('pDesc');
    var catSelect  = document.getElementById('pCategory');
    var unitSelect = document.getElementById('pUnit');

    var previewName  = document.getElementById('previewName');
    var previewDesc  = document.getElementById('previewDesc');
    var previewPrice = document.getElementById('previewPrice');
    var previewUnit  = document.getElementById('previewUnit');
    var previewStock = document.getElementById('previewStock');
    var previewCat   = document.getElementById('previewCategory');

    var descCount = document.getElementById('descCount');
    var stockUnit = document.getElementById('stockUnit');
    var minUnit   = document.getElementById('minUnit');

    function fmtMT(v) {
        var n = parseFloat(v); if (!isFinite(n)) n = 0;
        return n.toLocaleString('pt-MZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' MT';
    }

    function updatePreview() {
        if (previewName && nameInput) previewName.textContent = nameInput.value.trim() || 'Nome do produto';
        if (previewDesc && descInput) {
            var d = descInput.value.trim();
            previewDesc.textContent = d ? (d.length > 90 ? d.substring(0, 90) + '…' : d) : 'Descrição breve do produto aparecerá aqui…';
        }
        if (previewPrice && priceInput) previewPrice.textContent = fmtMT(priceInput.value);
        if (previewUnit && unitSelect)  previewUnit.textContent  = '/ ' + (unitSelect.value || 'kg');
        if (previewStock && stockInput) {
            var s = parseFloat(stockInput.value) || 0;
            previewStock.textContent = s.toLocaleString('pt-MZ') + ' em stock';
        }
        if (previewCat && catSelect) {
            var opt = catSelect.options[catSelect.selectedIndex];
            previewCat.textContent = (opt && opt.value) ? opt.text : '—';
        }
        if (stockUnit && unitSelect) stockUnit.textContent = unitSelect.value || 'kg';
        if (minUnit && unitSelect)   minUnit.textContent   = unitSelect.value || 'kg';
        if (descCount && descInput)  descCount.textContent = descInput.value.length;
    }

    [nameInput, priceInput, stockInput, descInput, catSelect, unitSelect].forEach(function (el) {
        if (!el) return;
        el.addEventListener('input', updatePreview);
        el.addEventListener('change', updatePreview);
    });
    updatePreview();

    /* ============================================================
       Upload
       ============================================================ */
    var uploadZone    = document.getElementById('uploadZone');
    var fileInput     = document.getElementById('pImage');
    var uploadEmpty   = document.getElementById('uploadEmpty');
    var uploadPreview = document.getElementById('uploadPreview');
    var uploadImg     = document.getElementById('uploadImg');
    var uploadChange  = document.getElementById('uploadChange');
    var uploadRemove  = document.getElementById('uploadRemove');
    var previewImage  = document.getElementById('previewImage');
    var previewPlace  = document.getElementById('previewPlaceholder');

    function showFile(file) {
        if (!file) return;
        if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { alert('Formato inválido. Use JPG, PNG ou WEBP.'); return; }
        if (file.size > 3 * 1024 * 1024) { alert('Ficheiro demasiado grande. Máximo 3 MB.'); return; }
        var url = URL.createObjectURL(file);
        uploadImg.src = url;
        uploadEmpty.style.display = 'none';
        uploadPreview.style.display = '';
        if (previewImage) {
            previewImage.src = url;
            previewImage.style.display = 'block';
            if (previewPlace) previewPlace.style.display = 'none';
        }
    }

    if (fileInput) fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files[0]) showFile(fileInput.files[0]);
    });

    if (uploadZone) {
        ['dragenter','dragover'].forEach(function (ev) {
            uploadZone.addEventListener(ev, function (e) { e.preventDefault(); uploadZone.classList.add('is-dragover'); });
        });
        ['dragleave','drop'].forEach(function (ev) {
            uploadZone.addEventListener(ev, function (e) { e.preventDefault(); uploadZone.classList.remove('is-dragover'); });
        });
        uploadZone.addEventListener('drop', function (e) {
            var file = e.dataTransfer.files[0];
            if (file) { var dt = new DataTransfer(); dt.items.add(file); fileInput.files = dt.files; showFile(file); }
        });
    }

    if (uploadChange) uploadChange.addEventListener('click', function () { fileInput.click(); });
    if (uploadRemove) uploadRemove.addEventListener('click', function () {
        fileInput.value = '';
        if (uploadImg) uploadImg.src = '';
        if (uploadPreview) uploadPreview.style.display = 'none';
        if (uploadEmpty) uploadEmpty.style.display = '';
        if (previewImage) {
            previewImage.src = '';
            previewImage.style.display = 'none';
        }
        if (previewPlace) previewPlace.style.display = '';
    });
})();
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>