<?php
/* Campos partilhados por produto-novo.php e produto-editar.php.
   Espera: $old, $categories, $farmers, $currentImgUrl (string, '' em novo produto); opcional $gallery (edição). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }
$statusOptions = ['active' => 'Ativo', 'inactive' => 'Inativo', 'out_of_stock' => 'Esgotado'];
?>
                <?= csrf_field() ?>
                <div class="row g-3">

                    <div class="col-md-8">
                        <label class="form-label" for="pf_name">Nome do produto *</label>
                        <input type="text" id="pf_name" name="name" class="form-control"
                               value="<?= e($old['name']) ?>" maxlength="150" required autofocus>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="pf_status">Estado</label>
                        <select id="pf_status" name="status" class="form-select">
                            <?php foreach ($statusOptions as $v => $l): ?>
                                <option value="<?= $v ?>" <?= $old['status'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Sem stock, um produto ativo passa a "Esgotado".</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="pf_category">Categoria *</label>
                        <select id="pf_category" name="category_id" class="form-select" required>
                            <option value="">— Selecionar —</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" <?= (int)$old['category_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= e($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="pf_farmer">Agricultor *</label>
                        <select id="pf_farmer" name="farmer_id" class="form-select" required>
                            <option value="">— Selecionar —</option>
                            <?php foreach ($farmers as $f): ?>
                                <option value="<?= (int)$f['id'] ?>" <?= (int)$old['farmer_id'] === (int)$f['id'] ? 'selected' : '' ?>>
                                    <?= e($f['farmer_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="pf_price">Preço (MZN) *</label>
                        <input type="number" id="pf_price" step="0.01" min="0" name="price"
                               class="form-control" value="<?= e($old['price']) ?>" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="pf_stock">Stock *</label>
                        <input type="number" id="pf_stock" step="0.01" min="0" name="stock"
                               class="form-control" value="<?= e($old['stock']) ?>" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="pf_unit">Unidade</label>
                        <select id="pf_unit" name="unit" class="form-select">
                            <?php foreach (product_units() as $u => $label): ?>
                                <option value="<?= e($u) ?>" <?= $old['unit'] === $u ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="pf_province">Província</label>
                        <select id="pf_province" name="province" class="form-select">
                            <option value="">— Do agricultor —</option>
                            <?php foreach (provinces() as $pv): ?>
                                <option value="<?= e($pv) ?>" <?= $old['province'] === $pv ? 'selected' : '' ?>><?= e($pv) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="pf_district">Distrito</label>
                        <input type="text" id="pf_district" name="district" class="form-control" maxlength="100"
                               value="<?= e($old['district']) ?>" placeholder="Opcional">
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="pf_description">Descrição</label>
                        <textarea id="pf_description" name="description" rows="4" class="form-control" maxlength="5000"><?= e($old['description']) ?></textarea>
                    </div>

                    <div class="col-md-8">
                        <?php if ($currentImgUrl !== ''): ?>
                            <label class="form-label">Imagem atual</label>
                        <?php endif; ?>
                        <div class="mb-2">
                            <img id="imgPreview" src="<?= e($currentImgUrl) ?>" alt=""
                                 style="<?= $currentImgUrl === '' ? 'display:none;' : '' ?>max-width:260px;border-radius:12px;border:1px solid #e5e7eb;">
                        </div>

                        <label class="form-label" for="imageInput"><?= $currentImgUrl !== '' ? 'Substituir imagem' : 'Imagem' ?></label>
                        <input type="file" name="image" id="imageInput" class="form-control"
                               accept="image/png,image/jpeg,image/webp">
                        <small class="text-muted">Máx. 3 MB · JPG, PNG ou WEBP.</small>

                        <?php if ($currentImgUrl !== ''): ?>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox"
                                       name="remove_image" value="1" id="remove_image">
                                <label class="form-check-label" for="remove_image">
                                    Remover imagem atual (ignorado se enviar uma nova)
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12" id="galeria">
                        <h5 class="mt-2 mb-2"><i class="bi bi-images me-1"></i> Galeria de imagens <small class="text-muted fw-normal">(<?= count($gallery ?? []) ?>/<?= PRODUCT_GALLERY_MAX ?>)</small></h5>
                        <?= product_gallery_fields($gallery ?? [], 'pf_gallery') ?>
                    </div>

                </div>

<script>
document.getElementById('imageInput').addEventListener('change', function (ev) {
    var file = ev.target.files && ev.target.files[0];
    var img  = document.getElementById('imgPreview');
    if (!file) return;
    img.src = URL.createObjectURL(file);
    img.style.display = 'block';
});
</script>
