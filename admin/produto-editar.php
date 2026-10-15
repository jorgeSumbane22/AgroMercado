<?php require_once __DIR__.'/../includes/permissions.php'; ?>
<?php
require_role('superadmin');
require_once __DIR__.'/_helpers.php';

$id = safe_int($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM products WHERE id = ?');
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    flash('danger', 'Produto não encontrado.');
    redirect(base_path('admin/produtos.php'));
}

$categories = db()->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

$farmers = db()->query("
    SELECT f.id, u.name AS farmer_name
    FROM farmers f
    JOIN users u ON u.id = f.user_id
    ORDER BY u.name
")->fetchAll();

/* ============================================================
   HELPERS
   ============================================================ */
function make_slug_e(string $text): string {
    $text = mb_strtolower(trim($text), 'UTF-8');
    $map = [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n',
    ];
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');
    return $text !== '' ? $text : 'produto';
}

function unique_slug_e(string $base, int $ignoreId): string {
    $slug = $base;
    $i = 1;
    while (true) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM products WHERE slug = ? AND id <> ?');
        $stmt->execute([$slug, $ignoreId]);
        if (!$stmt->fetchColumn()) return $slug;
        $slug = $base.'-'.(++$i);
    }
}

/* ============================================================
   SUBMISSÃO
   ============================================================ */
$errors = [];
$old = [
    'name'        => $product['name'],
    'description' => $product['description'],
    'price'       => $product['price'],
    'unit'        => $product['unit'],
    'stock'       => $product['stock'],
    'category_id' => $product['category_id'],
    'farmer_id'   => $product['farmer_id'],
    'status'      => $product['status'],
    'province'    => (string)$product['province'],
    'district'    => (string)($product['district'] ?? ''),
];

if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    $errors = admin_product_from_post($old);

    /* ---------- Upload (só depois de validar o resto) ---------- */
    $newImage = null;
    if (empty($errors) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $newImage = save_upload($_FILES['image']);
        } catch (RuntimeException $ex) {
            $errors['image'] = $ex->getMessage();
        }
    }

    if (empty($errors)) {
        $pdo = db();
        $slug = unique_slug_e(make_slug_e($old['name']), $id);

        // Imagem final: uma nova imagem tem prioridade sobre "remover imagem".
        $finalImage = $product['main_image'];
        if ($newImage) $finalImage = $newImage;
        elseif (!empty($_POST['remove_image'])) $finalImage = null;

        $delta = round((float)$old['stock'] - (float)$product['stock'], 2);
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                UPDATE products SET
                    farmer_id   = :farmer_id,
                    category_id = :category_id,
                    name        = :name,
                    slug        = :slug,
                    description = :description,
                    price       = :price,
                    unit        = :unit,
                    stock       = :stock,
                    main_image  = :main_image,
                    status      = :status,
                    province    = :province,
                    district    = :district,
                    updated_at  = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':farmer_id'   => $old['farmer_id'],
                ':category_id' => $old['category_id'],
                ':name'        => $old['name'],
                ':slug'        => $slug,
                ':description' => $old['description'],
                ':price'       => (float)$old['price'],
                ':unit'        => $old['unit'],
                ':stock'       => (float)$old['stock'],
                ':main_image'  => $finalImage,
                ':status'      => $old['status'],
                ':province'    => admin_product_province($old),
                ':district'    => $old['district'] !== '' ? $old['district'] : null,
                ':id'          => $id,
            ]);
            if ($delta != 0) {
                $pdo->prepare('INSERT INTO inventory(product_id,quantity,movement_type,notes,created_by) VALUES(?,?,"adjustment",?,?)')
                    ->execute([$id, $delta, 'Ajuste de stock pelo SuperAdmin (' . rtrim(rtrim(number_format((float)$product['stock'], 2, '.', ''), '0'), '.') . ' → ' . rtrim(rtrim(number_format((float)$old['stock'], 2, '.', ''), '0'), '.') . ')', user()['id']]);
            }
            $pdo->commit();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            admin_delete_product_image($newImage);
            error_log('produto-editar: ' . $ex->getMessage());
            $errors['db'] = 'Não foi possível guardar o produto. Verifique os dados e tente novamente.';
        }

        if (empty($errors)) {
            // Só depois de gravar: apagar a imagem antiga se foi substituída ou removida.
            if ($finalImage !== $product['main_image']) admin_delete_product_image($product['main_image']);
            audit(user()['id'], 'update', 'products', 'Admin editou o produto #'.$id.' ('.$old['name'].')' . ($delta != 0 ? ' · stock ' . ($delta > 0 ? '+' : '') . $delta : ''));
            flash('success', 'Produto atualizado com sucesso.');
            redirect(base_path('admin/produtos.php'));
        }
    }
}

/* imagem atual para preview */
$currentImgUrl = !empty($product['main_image']) ? get_product_image($product['main_image']) : '';

$page_title = 'Editar produto';
include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Catálogo · Editar produto
                </span>
                <h1 class="sa-hero-title">Editar <em><?= e($product['name']) ?></em></h1>
                <p class="sa-hero-sub">Produto #<?= (int)$product['id'] ?></p>
            </div>
            <div class="sa-hero-actions">
                <a href="<?= base_path('admin/produtos.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>
        </header>

        <article class="content-card sa-card">
            <div class="card-heading sa-card-head">
                <div>
                    <h4>Dados do produto</h4>
                    <p class="sa-card-sub">Campos com * são obrigatórios.</p>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="sa-form">
                <?php include __DIR__.'/_produto_form.php'; ?>

                <div class="sa-form-actions mt-4">
                    <a href="<?= base_path('admin/produtos.php') ?>" class="btn btn-outline-secondary">Cancelar</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check2"></i> Guardar alterações
                    </button>
                </div>
            </form>
        </article>

    </section>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
