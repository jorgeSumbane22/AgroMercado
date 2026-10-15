<?php require_once __DIR__.'/../includes/permissions.php'; ?>
<?php
require_role('superadmin');
require_once __DIR__.'/_helpers.php';

/* ============================================================
   SELECTS
   ============================================================ */
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
function make_slug(string $text): string {
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

function unique_slug(string $base): string {
    $slug = $base;
    $i = 1;
    while (true) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM products WHERE slug = ?');
        $stmt->execute([$slug]);
        if (!$stmt->fetchColumn()) return $slug;
        $slug = $base.'-'.(++$i);
    }
}

/* ============================================================
   SUBMISSÃO
   ============================================================ */
$errors = [];
$old = [
    'name'        => '',
    'description' => '',
    'price'       => '',
    'unit'        => 'kg',
    'stock'       => '',
    'category_id' => '',
    'farmer_id'   => '',
    'status'      => 'active',
    'province'    => '',
    'district'    => '',
];

if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    $errors = admin_product_from_post($old);

    /* ---------- Upload (só depois de validar o resto, para não deixar ficheiros órfãos) ---------- */
    $storedImage = null;
    if (empty($errors) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $storedImage = save_upload($_FILES['image']);
        } catch (RuntimeException $ex) {
            $errors['image'] = $ex->getMessage();
        }
    }

    /* ---------- INSERT ---------- */
    if (empty($errors)) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $slug = unique_slug(make_slug($old['name']));
            $stmt = $pdo->prepare("
                INSERT INTO products
                    (farmer_id, category_id, name, slug, description, price, unit, stock,
                     quantity_min, main_image, status, province, district, featured, sales_count, created_at, updated_at)
                VALUES
                    (:farmer_id, :category_id, :name, :slug, :description, :price, :unit, :stock,
                     1, :main_image, :status, :province, :district, 0, 0, NOW(), NOW())
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
                ':main_image'  => $storedImage,
                ':status'      => $old['status'],
                ':province'    => admin_product_province($old),
                ':district'    => $old['district'] !== '' ? $old['district'] : null,
            ]);
            $newId = (int)$pdo->lastInsertId();
            if ((float)$old['stock'] > 0) {
                $pdo->prepare('INSERT INTO inventory(product_id,quantity,movement_type,notes,created_by) VALUES(?,?,"initial",?,?)')
                    ->execute([$newId, (float)$old['stock'], 'Stock inicial (SuperAdmin)', user()['id']]);
            }
            $pdo->commit();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            admin_delete_product_image($storedImage);
            error_log('produto-novo: ' . $ex->getMessage());
            $errors['db'] = 'Não foi possível criar o produto. Verifique os dados e tente novamente.';
        }

        if (empty($errors)) {
            audit(user()['id'], 'create', 'products', 'Admin criou o produto #'.$newId.' ('.$old['name'].')');
            flash('success', 'Produto criado com sucesso.');
            redirect(base_path('admin/produtos.php'));
        }
    }
}

$currentImgUrl = '';
$page_title = 'Novo produto';
include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Catálogo · Novo produto
                </span>
                <h1 class="sa-hero-title">Adicionar <em>produto</em></h1>
                <p class="sa-hero-sub">Nome, imagem e dados essenciais.</p>
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
                        <i class="bi bi-check2"></i> Criar produto
                    </button>
                </div>
            </form>
        </article>

    </section>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
