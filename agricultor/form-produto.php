<?php
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/_helpers.php';
require_role('farmer');
// Este ficheiro é um parcial: só pode ser incluído por adicionar-produto.php
if (!defined('AGRO_PRODUCT_FORM') || !isset($categories)) redirect(base_path('agricultor/adicionar-produto.php'));
$verified = farmer_verified();
// ─────────────────────────────────────────────────────────────
// Estado inicial do produto (novo ou edição)
// ─────────────────────────────────────────────────────────────
$product = $product ?? [
    'name'         => '',
    'description'  => '',
    'price'        => '',
    'unit'         => 'kg',
    'stock'        => '',
    'quantity_min' => '1',
    'category_id'  => '',
    'province'     => 'Maputo',
    'district'     => '',
    'locality'     => '',
    'harvest_date' => '',
    'expiry_date'  => '',
    'main_image'   => '',
];

$edit = $edit ?? false;

// Recupera inputs antigos em caso de erro de validação
$old = $_SESSION['old_input'] ?? [];
unset($_SESSION['old_input']);

// Helper: devolve o valor antigo (se existir) ou o do produto
$val = function (string $key, $default = '') use ($old, $product) {
    return $old[$key] ?? ($product[$key] ?? $default);
};

include __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <section class="dashboard-content">

        <!-- ─── Topo ─────────────────────────────────────── -->
        <div class="dashboard-top">
            <div>
                <span class="eyebrow">Catálogo</span>
                <h1><?= $edit ? 'Editar produto' : 'Novo produto' ?></h1>
                <p class="text-muted mb-0" style="font-size:.9rem;">
                    <?= $edit
                        ? 'Atualize os dados do produto e guarde as alterações.'
                        : ($verified ? 'Preencha os dados do produto. Ele ficará visível no marketplace assim que publicar.' : 'Preencha os dados do produto. O produto será publicado após a verificação da conta.') ?>
                </p>
            </div>
            <a href="<?= base_path('agricultor/produtos.php') ?>" class="btn btn-light">
                <i class="bi bi-arrow-left me-1"></i> Cancelar
            </a>
        </div>

        <?php if (!$verified): ?>
            <div class="alert alert-warning d-flex gap-2 align-items-start">
                <i class="bi bi-shield-exclamation fs-5"></i>
                <div><strong>Conta ainda não verificada.</strong> Pode registar o produto, mas ele ficará <strong>inativo</strong> — o produto será publicado após a verificação da conta.</div>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" id="productForm">
            <?= csrf_field() ?>
            <?php if ($edit): ?>
                <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
            <?php endif; ?>

            <div class="row g-4">

                <!-- ─── Coluna principal ──────────────────── -->
                <div class="col-lg-8">
                    <div class="content-card sa-card">

                        <!-- Cabeçalho do card -->
                        <div class="sa-card-head">
                            <div class="sa-card-head-icon">
                                <div class="sa-kpi-icon sa-icon-primary">
                                    <i class="bi bi-box-seam"></i>
                                </div>
                                <div>
                                    <h4>Informação do produto</h4>
                                    <p class="sa-card-sub">Dados básicos que aparecem na listagem</p>
                                </div>
                            </div>
                        </div>

                        <!-- Grelha de campos -->
                        <div class="sa-form-grid">

                            <!-- Nome -->
                            <div class="sa-field sa-field-full">
                                <label class="form-label" for="name">
                                    Nome do produto <span class="sa-req">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="name"
                                    name="name"
                                    class="form-control"
                                    placeholder="Ex: Tomate fresco de Chókwè"
                                    value="<?= e($val('name')) ?>"
                                    required>
                                <small class="sa-field-hint">Use um nome claro e específico. Ex: “Tomate fresco”, “Milho branco”.</small>
                            </div>

                            <!-- Categoria -->
                            <div class="sa-field">
                                <label class="form-label" for="category_id">
                                    Categoria <span class="sa-req">*</span>
                                </label>
                                <div class="sa-select-wrap">
                                    <select name="category_id" id="category_id" class="sa-select form-control" required>
                                        <option value="">Selecione uma categoria</option>
                                        <?php foreach ($categories as $c): ?>
                                            <option value="<?= (int) $c['id'] ?>"
                                                <?= (int) $val('category_id') === (int) $c['id'] ? 'selected' : '' ?>>
                                                <?= e($c['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <i class="bi bi-chevron-down sa-select-chevron"></i>
                                </div>
                            </div>

                            <!-- Unidade -->
                            <div class="sa-field">
                                <label class="form-label" for="unit">Unidade de venda</label>
                                <div class="sa-select-wrap">
                                    <select name="unit" id="unit" class="sa-select form-control">
                                        <?php foreach (product_units() as $u => $uLabel): ?>
                                            <option value="<?= e($u) ?>" <?= (string)$val('unit') === (string)$u ? 'selected' : '' ?>>
                                                <?= e($uLabel) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <i class="bi bi-chevron-down sa-select-chevron"></i>
                                </div>
                            </div>

                            <!-- Descrição -->
                            <div class="sa-field sa-field-full">
                                <label class="form-label" for="description">
                                    Descrição <span class="sa-req">*</span>
                                </label>
                                <textarea
                                    id="description"
                                    name="description"
                                    class="form-control"
                                    rows="4"
                                    maxlength="1000"
                                    placeholder="Descreva a qualidade, origem e características do produto..."
                                    required><?= e($val('description')) ?></textarea>
                                <div class="sa-char-counter">
                                    <span id="descCount">0</span> / 1000
                                </div>
                            </div>

                            <!-- Preço -->
                            <div class="sa-field">
                                <label class="form-label" for="price">
                                    Preço <span class="sa-req">*</span>
                                </label>
                                <div class="sa-input-group">
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        id="price"
                                        name="price"
                                        class="form-control"
                                        placeholder="0.00"
                                        value="<?= e($val('price')) ?>"
                                        required>
                                    <span class="sa-input-suffix">MT</span>
                                </div>
                            </div>

                            <!-- Stock -->
                            <div class="sa-field">
                                <label class="form-label" for="stock">
                                    Stock disponível <span class="sa-req">*</span>
                                </label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    id="stock"
                                    name="stock"
                                    class="form-control"
                                    placeholder="0"
                                    value="<?= e($val('stock')) ?>"
                                    required>
                                <small class="sa-field-hint">Quantidade disponível para venda imediata.</small>
                            </div>

                            <!-- Quantidade mínima -->
                            <div class="sa-field">
                                <label class="form-label" for="quantity_min">
                                    Quantidade mínima <span class="sa-req">*</span>
                                </label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    id="quantity_min"
                                    name="quantity_min"
                                    class="form-control"
                                    value="<?= e($val('quantity_min', '1')) ?>"
                                    required>
                                <small class="sa-field-hint">Mínimo que o comprador pode adquirir.</small>
                            </div>

                            <!-- Província -->
                            <div class="sa-field">
                                <label class="form-label" for="province">
                                    Província <span class="sa-req">*</span>
                                </label>
                                <div class="sa-select-wrap">
                                    <select id="province" name="province" class="sa-select form-control" required>
                                        <option value="">Escolha…</option>
                                        <?php foreach (provinces() as $pv): ?>
                                            <option value="<?= e($pv) ?>" <?= (string)$val('province') === $pv ? 'selected' : '' ?>><?= e($pv) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <i class="bi bi-chevron-down sa-select-chevron"></i>
                                </div>
                            </div>

                            <!-- Distrito -->
                            <div class="sa-field">
                                <label class="form-label" for="district">
                                    Distrito <span class="sa-req">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="district"
                                    name="district"
                                    class="form-control"
                                    value="<?= e($val('district')) ?>"
                                    required>
                            </div>

                            <!-- Localidade -->
                            <div class="sa-field">
                                <label class="form-label" for="locality">Localidade</label>
                                <input
                                    type="text"
                                    id="locality"
                                    name="locality"
                                    class="form-control"
                                    value="<?= e($val('locality')) ?>">
                            </div>

                            <!-- Data de colheita -->
                            <div class="sa-field">
                                <label class="form-label" for="harvest_date">Data de colheita</label>
                                <input
                                    type="date"
                                    id="harvest_date"
                                    name="harvest_date"
                                    class="form-control"
                                    value="<?= e($val('harvest_date')) ?>">
                            </div>

                            <!-- Data de validade -->
                            <div class="sa-field">
                                <label class="form-label" for="expiry_date">Data de validade</label>
                                <input
                                    type="date"
                                    id="expiry_date"
                                    name="expiry_date"
                                    class="form-control"
                                    value="<?= e($val('expiry_date')) ?>">
                            </div>

                        </div><!-- /.sa-form-grid -->
                    </div><!-- /.content-card -->

                    <!-- ─── Upload de imagem ──────────────────── -->
                    <div class="content-card sa-card mt-4">
                        <div class="sa-card-head">
                            <div class="sa-card-head-icon">
                                <div class="sa-kpi-icon sa-icon-info">
                                    <i class="bi bi-image"></i>
                                </div>
                                <div>
                                    <h4>Imagem principal</h4>
                                    <p class="sa-card-sub">JPG, PNG ou WEBP até 3 MB</p>
                                </div>
                            </div>
                        </div>

                        <div class="sa-upload" id="uploadZone">
                            <input
                                type="file"
                                name="image"
                                id="imageInput"
                                class="sa-upload-input"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">

                            <div class="sa-upload-empty" id="uploadEmpty">
                                <div class="sa-upload-icon">
                                    <i class="bi bi-cloud-arrow-up"></i>
                                </div>
                                <strong>Arraste a imagem para aqui</strong>
                                <small>ou clique para escolher do dispositivo</small>
                            </div>

                            <div class="sa-upload-preview" id="uploadPreview" style="display:none;">
                                <img id="previewImg" src="" alt="Pré-visualização">
                                <div class="sa-upload-actions">
                                    <button type="button" class="sa-btn sa-btn-ghost" id="changeImage">
                                        <i class="bi bi-arrow-repeat"></i> Trocar imagem
                                    </button>
                                    <button type="button" class="sa-btn sa-btn-danger" id="removeImage">
                                        <i class="bi bi-trash"></i> Remover
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ─── Coluna lateral ─────────────────────── -->
                <div class="col-lg-4">
                    <div class="sa-sticky">

                        <!-- Preview em tempo real -->
                        <div class="content-card sa-card">
                            <div class="sa-card-head">
                                <div class="sa-card-head-icon">
                                    <div class="sa-kpi-icon sa-icon-success">
                                        <i class="bi bi-eye"></i>
                                    </div>
                                    <div>
                                        <h4>Pré-visualização</h4>
                                        <p class="sa-card-sub">Como aparecerá no marketplace</p>
                                    </div>
                                </div>
                            </div>

                            <div class="sa-preview-card">
                                <div class="sa-preview-media">
                                    <img id="previewCardImg"
                                         src="<?= !empty($product['main_image']) ? e(get_product_image($product['main_image'])) : '' ?>"
                                         alt=""
                                         style="<?= !empty($product['main_image']) ? '' : 'display:none;' ?>">
                                    <div class="sa-preview-placeholder" id="previewPlaceholder"
                                         style="<?= !empty($product['main_image']) ? 'display:none;' : '' ?>">
                                        <i class="bi bi-image"></i>
                                    </div>
                                    <span class="sa-preview-chip" id="previewCategory">Categoria</span>
                                </div>
                                <div class="sa-preview-body">
                                    <h5 id="previewName">Nome do produto</h5>
                                    <p class="sa-preview-desc" id="previewDesc">A descrição aparecerá aqui…</p>
                                    <div class="sa-preview-foot">
                                        <span class="sa-preview-price" id="previewPrice">0,00</span>
                                        <span class="sa-preview-unit">MT / <span id="previewUnit">kg</span></span>
                                    </div>
                                    <div class="sa-preview-stock">
                                        <i class="bi bi-box-seam"></i>
                                        <span>Stock: <strong id="previewStock">0</strong></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Botão de submissão -->
                        <div class="content-card sa-card mt-4">
                            <button type="submit" class="sa-submit">
                                <i class="bi bi-check2-circle"></i>
                                <?= $edit ? 'Guardar alterações' : ($verified ? 'Publicar produto' : 'Guardar produto (inativo)') ?>
                            </button>
                            <p class="sa-submit-note">
                                <i class="bi bi-info-circle"></i>
                                Os campos marcados com <span class="sa-req">*</span> são obrigatórios.
                            </p>
                        </div>

                        <!-- Dicas -->
                        <div class="content-card sa-card mt-4">
                            <div class="sa-card-head">
                                <div class="sa-card-head-icon">
                                    <div class="sa-kpi-icon sa-icon-warning">
                                        <i class="bi bi-lightbulb"></i>
                                    </div>
                                    <div>
                                        <h4>Dicas</h4>
                                    </div>
                                </div>
                            </div>
                            <ul class="sa-tips">
                                <li>
                                    <i class="bi bi-camera"></i>
                                    <div>
                                        <strong>Boa iluminação</strong>
                                        <small>Fotos com luz natural vendem mais.</small>
                                    </div>
                                </li>
                                <li>
                                    <i class="bi bi-tag"></i>
                                    <div>
                                        <strong>Preço competitivo</strong>
                                        <small>Compare com outros agricultores da região.</small>
                                    </div>
                                </li>
                                <li>
                                    <i class="bi bi-box-seam"></i>
                                    <div>
                                        <strong>Stock atualizado</strong>
                                        <small>Mantenha o stock real para evitar cancelamentos.</small>
                                    </div>
                                </li>
                            </ul>
                        </div>

                    </div>
                </div>

            </div>
        </form>
    </section>
</div>

<!-- ─── JS: preview em tempo real + upload ─────────────── -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    // ── Referências ───────────────────────────────────
    const $ = (id) => document.getElementById(id);

    const form         = $('productForm');
    const nameInput    = $('name');
    const descInput    = $('description');
    const priceInput   = $('price');
    const unitInput    = $('unit');
    const stockInput   = $('stock');
    const catSelect    = $('category_id');
    const descCount    = $('descCount');

    const pName        = $('previewName');
    const pDesc        = $('previewDesc');
    const pPrice       = $('previewPrice');
    const pUnit        = $('previewUnit');
    const pStock       = $('previewStock');
    const pCategory    = $('previewCategory');
    const pPlaceholder = $('previewPlaceholder');
    const pCardImg     = $('previewCardImg');

    // ── Upload ─────────────────────────────────────────
    const uploadZone    = $('uploadZone');
    const imageInput    = $('imageInput');
    const uploadEmpty   = $('uploadEmpty');
    const uploadPreview = $('uploadPreview');
    const previewImg    = $('previewImg');
    const changeBtn     = $('changeImage');
    const removeBtn     = $('removeImage');

    // ── Preview em tempo real ─────────────────────────
    const updatePreview = () => {
        pName.textContent  = nameInput.value.trim() || 'Nome do produto';
        pDesc.textContent  = descInput.value.trim() || 'A descrição aparecerá aqui…';
        pPrice.textContent = priceInput.value ? parseFloat(priceInput.value).toFixed(2).replace('.', ',') : '0,00';
        pUnit.textContent  = unitInput.value || 'kg';
        pStock.textContent = stockInput.value || '0';

        const catText = catSelect.options[catSelect.selectedIndex]?.text || '';
        pCategory.textContent = (catSelect.value && catText) ? catText : 'Categoria';

        descCount.textContent = descInput.value.length;
    };

    [nameInput, descInput, priceInput, unitInput, stockInput, catSelect]
        .forEach(el => el && el.addEventListener('input', updatePreview));
    [unitInput, catSelect].forEach(el => el && el.addEventListener('change', updatePreview));

    updatePreview(); // estado inicial

    // ── Mostrar/ocultar imagem no cartão preview ─────
    const showImageInCard = (src) => {
        pCardImg.src = src;
        pCardImg.style.display = '';
        pPlaceholder.style.display = 'none';
    };
    const clearImageFromCard = () => {
        pCardImg.src = '';
        pCardImg.style.display = 'none';
        pPlaceholder.style.display = '';
    };

    // ── Handler do ficheiro ───────────────────────────
    const handleFile = (file) => {
        if (!file) return;
        if (!file.type.startsWith('image/')) {
            alert('Por favor selecione um ficheiro de imagem válido.');
            return;
        }
        if (file.size > 3 * 1024 * 1024) {
            alert('A imagem não pode exceder 3 MB.');
            return;
        }
        const reader = new FileReader();
        reader.onload = (e) => {
            previewImg.src = e.target.result;
            uploadEmpty.style.display   = 'none';
            uploadPreview.style.display = '';
            showImageInCard(e.target.result);
        };
        reader.readAsDataURL(file);
    };

    imageInput?.addEventListener('change', (e) => handleFile(e.target.files[0]));
    changeBtn?.addEventListener('click', () => imageInput.click());

    removeBtn?.addEventListener('click', () => {
        imageInput.value = '';
        previewImg.src = '';
        uploadPreview.style.display = 'none';
        uploadEmpty.style.display = '';
        clearImageFromCard();
    });

    // ── Drag & drop ────────────────────────────────────
    ['dragenter', 'dragover'].forEach(evt =>
        uploadZone.addEventListener(evt, (e) => {
            e.preventDefault();
            uploadZone.classList.add('is-dragover');
        })
    );
    ['dragleave', 'drop'].forEach(evt =>
        uploadZone.addEventListener(evt, (e) => {
            e.preventDefault();
            uploadZone.classList.remove('is-dragover');
        })
    );
    uploadZone.addEventListener('drop', (e) => {
        const file = e.dataTransfer.files[0];
        if (file) {
            imageInput.files = e.dataTransfer.files;
            handleFile(file);
        }
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>