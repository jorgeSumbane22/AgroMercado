<?php
/* Helpers locais da área do agricultor (incluídos pelas páginas de agricultor/). */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit; }

/** O agricultor atual está verificado (só assim pode ter produtos ativos)? */
if (!function_exists('farmer_verified')) {
    function farmer_verified(): bool { return (user()['verification_status'] ?? '') === 'verified'; }
}

/** Valida os campos do formulário de produto. Devolve os dados normalizados ou lança RuntimeException. */
if (!function_exists('product_form_input')) {
    function product_form_input(PDO $pdo, array $in): array {
        $num = fn($k) => is_numeric(str_replace(',', '.', (string)($in[$k] ?? ''))) ? (float)str_replace(',', '.', (string)$in[$k]) : null;
        $d = [
            'name'         => trim((string)($in['name'] ?? '')),
            'description'  => trim((string)($in['description'] ?? '')),
            'category_id'  => safe_int($in['category_id'] ?? 0),
            'price'        => $num('price'),
            'stock'        => $num('stock'),
            'quantity_min' => $num('quantity_min'),
            'unit'         => (string)($in['unit'] ?? ''),
            'province'     => (string)($in['province'] ?? ''),
            'district'     => mb_substr(trim((string)($in['district'] ?? '')), 0, 100),
            'locality'     => mb_substr(trim((string)($in['locality'] ?? '')), 0, 100),
            'harvest_date' => null,
            'expiry_date'  => null,
        ];
        if (mb_strlen($d['name']) < 2 || mb_strlen($d['name']) > 160) throw new RuntimeException('Indique o nome do produto (2 a 160 caracteres).');
        $s = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = ? AND is_active = 1'); $s->execute([$d['category_id']]);
        if ($d['category_id'] <= 0 || !$s->fetchColumn()) throw new RuntimeException('Selecione uma categoria válida.');
        if ($d['price'] === null || $d['price'] <= 0) throw new RuntimeException('Indique um preço válido (maior que zero).');
        if ($d['stock'] === null || $d['stock'] < 0) throw new RuntimeException('Indique um stock válido (zero ou mais).');
        if ($d['quantity_min'] === null || $d['quantity_min'] <= 0) throw new RuntimeException('A quantidade mínima deve ser maior que zero.');
        if (!array_key_exists($d['unit'], product_units())) throw new RuntimeException('Selecione uma unidade de venda válida.');
        if (!in_array($d['province'], provinces(), true)) throw new RuntimeException('Selecione uma província válida.');
        foreach (['harvest_date', 'expiry_date'] as $k) {
            $raw = trim((string)($in[$k] ?? ''));
            if ($raw === '') continue;
            $dt = DateTime::createFromFormat('!Y-m-d', $raw);
            if (!$dt || $dt->format('Y-m-d') !== $raw) throw new RuntimeException('Data inválida.');
            $d[$k] = $raw;
        }
        if ($d['harvest_date'] && $d['expiry_date'] && $d['expiry_date'] < $d['harvest_date']) throw new RuntimeException('A data de validade não pode ser anterior à data de colheita.');
        return $d;
    }
}

/** Processa o upload opcional da imagem: null se nenhum ficheiro; lança erro se o upload falhou. */
if (!function_exists('product_form_image')) {
    function product_form_image(?array $file): ?string {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) return null;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('A imagem é demasiado grande (máx. 3 MB).');
        if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('O envio da imagem falhou. Tente novamente.');
        return save_upload($file);
    }
}

/** Remove um ficheiro de imagem de produto, apenas se estiver dentro de uploads/ (nunca assets/). */
if (!function_exists('delete_product_image_file')) {
    function delete_product_image_file(?string $image): void {
        $image = ltrim((string)$image, '/');
        if ($image === '' || preg_match('#^https?://#i', $image) || str_starts_with($image, 'assets/') || str_contains($image, '..')) return;
        if (str_starts_with($image, 'uploads/')) $image = substr($image, 8);
        $root = realpath(__DIR__ . '/../uploads');
        $file = realpath(__DIR__ . '/../uploads/' . $image);
        if ($root && $file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) @unlink($file);
    }
}
