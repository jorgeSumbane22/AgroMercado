<?php
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/_helpers.php';

require_role('farmer');

$page_title = 'Adicionar produto';
$error = '';
$pdo = db();
$verified = farmer_verified();

// ─────────────────────────────────────────────────────────────
// Dados auxiliares para o formulário
// ─────────────────────────────────────────────────────────────
try {
    $categories = $pdo
        ->query('SELECT * FROM categories WHERE is_active = 1 ORDER BY name')
        ->fetchAll();
} catch (Throwable $e) {
    $categories = [];
}

// ─────────────────────────────────────────────────────────────
// Processamento do formulário
// ─────────────────────────────────────────────────────────────
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');

    $imagePath = null;
    try {
        // ---------- 1. Validação de dados ----------
        $d = product_form_input($pdo, $_POST);

        // ---------- 2. Upload de imagem (erro de upload = erro visível) ----------
        $imagePath = product_form_image($_FILES['image'] ?? null);

        // ---------- 3. Estado: só agricultores verificados publicam ----------
        $status = !$verified ? 'inactive' : ($d['stock'] > 0 ? 'active' : 'out_of_stock');

        // ---------- 4. Inserção na base de dados ----------
        $slug = slugify($d['name']) . '-' . bin2hex(random_bytes(3));

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO products (
                    farmer_id, category_id, name, slug, description,
                    price, unit, stock, quantity_min, main_image,
                    status, province, district, locality,
                    harvest_date, expiry_date
                ) VALUES (
                    :farmer_id, :category_id, :name, :slug, :description,
                    :price, :unit, :stock, :quantity_min, :main_image,
                    :status, :province, :district, :locality,
                    :harvest_date, :expiry_date
                )');
        $stmt->execute([
            ':farmer_id'    => user()['farmer_id'],
            ':category_id'  => $d['category_id'],
            ':name'         => $d['name'],
            ':slug'         => $slug,
            ':description'  => $d['description'],
            ':price'        => $d['price'],
            ':unit'         => $d['unit'],
            ':stock'        => $d['stock'],
            ':quantity_min' => $d['quantity_min'],
            ':main_image'   => $imagePath,
            ':status'       => $status,
            ':province'     => $d['province'],
            ':district'     => $d['district'],
            ':locality'     => $d['locality'],
            ':harvest_date' => $d['harvest_date'],
            ':expiry_date'  => $d['expiry_date'],
        ]);

        $productId = (int) $pdo->lastInsertId();

        // ---------- 5. Registo de inventário inicial ----------
        $pdo->prepare(
            'INSERT INTO inventory (product_id, quantity, movement_type, notes, created_by)
             VALUES (?, ?, "initial", "Stock inicial", ?)'
        )->execute([$productId, $d['stock'], user()['id']]);

        // ---------- 6. Auditoria + feedback ----------
        audit(user()['id'], 'create', 'products', 'Agricultor criou o produto #' . $productId . ' ' . $d['name'] . ' (' . $status . ')');
        $pdo->commit();

        if (!$verified) {
            flash('warning', 'Produto guardado como inativo: o produto será publicado após a verificação da conta.');
        } elseif ($status === 'out_of_stock') {
            flash('warning', 'Produto guardado sem stock. Atualize o stock para que fique disponível.');
        } else {
            flash('success', 'Produto publicado com sucesso.');
        }
        redirect(base_path('agricultor/produtos.php'));

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($imagePath) delete_product_image_file($imagePath);
        // Guarda os dados submetidos para não obrigar o utilizador a reescrever tudo
        $_SESSION['old_input'] = $_POST;
        $msg = $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível guardar o produto. Verifique os dados e tente novamente.';
        if (!$e instanceof RuntimeException) error_log($e->getMessage());
        flash('danger', $msg);
        redirect(base_path('agricultor/adicionar-produto.php'));
    }
}

define('AGRO_PRODUCT_FORM', true);
include __DIR__ . '/form-produto.php';
