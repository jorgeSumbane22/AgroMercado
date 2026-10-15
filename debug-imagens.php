<?php
require_once __DIR__ . '/includes/functions.php';

$products = db()->query('SELECT id, name, main_image FROM products ORDER BY id')->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Debug Imagens</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        h1 { color: #333; }
        table { width: 100%; background: #fff; border-collapse: collapse; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; vertical-align: middle; }
        th { background: #2c5f2d; color: #fff; }
        img { width: 80px; height: 80px; object-fit: cover; border-radius: 6px; background: #eee; }
        .url { font-family: monospace; font-size: 11px; color: #555; word-break: break-all; }
        .path { font-family: monospace; font-size: 12px; color: #0066cc; }
        .ok { color: green; font-weight: bold; }
        .erro { color: red; font-weight: bold; }
        tr:hover { background: #f9f9f9; }
    </style>
</head>
<body>
    <h1>Debug de Imagens — <?= count($products) ?> produtos</h1>
    <p>Se a imagem aparecer ao lado do nome mas NÃO corresponder, o problema está no <strong>arquivo da imagem</strong>.</p>
    <p>Se aparecer o <strong>placeholder cinzento</strong>, o caminho está errado.</p>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Produto</th>
                <th>Imagem</th>
                <th>Caminho no banco</th>
                <th>URL gerada</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($products as $p): ?>
            <?php
                $caminho = $p['main_image'];
                $url     = get_product_image($caminho);
                $existe  = is_file(__DIR__ . '/' . $caminho);
            ?>
            <tr>
                <td><?= $p['id'] ?></td>
                <td><strong><?= e($p['name']) ?></strong></td>
                <td>
                    <img src="<?= e($url) ?>"
                         onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2280%22 height=%2280%22><rect width=%2280%22 height=%2280%22 fill=%22%23f00%22/><text x=%2240%22 y=%2245%22 text-anchor=%22middle%22 fill=%22white%22 font-size=%2212%22>ERRO</text></svg>'">
                </td>
                <td class="path"><?= e($caminho) ?></td>
                <td class="url"><?= e($url) ?></td>
                <td class="<?= $existe ? 'ok' : 'erro' ?>">
                    <?= $existe ? 'Existe' : 'NÃO existe' ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>