<?php
/* Helpers locais da área SuperAdmin (incluídos pelas páginas admin/*.php). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

/** URL da página atual (admin) preservando a query string — para redirecionar após um POST. */
function admin_self_url(): string {
    $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
    return base_path('admin/' . basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php')) . ($qs !== '' ? '?' . $qs : '');
}

/** Apaga do disco a imagem de um produto guardada em uploads/ (aceita 'uploads/...' e 'produtos/...'); nunca toca em assets/. */
function admin_delete_product_image(?string $image): void {
    $image = ltrim(trim((string)$image), '/');
    if ($image === '' || preg_match('#^(https?:|assets/)#i', $image)) return;
    $rel = str_starts_with($image, 'uploads/') ? substr($image, 8) : $image;
    $root = realpath(__DIR__ . '/../uploads');
    $file = realpath(__DIR__ . '/../uploads/' . $rel);
    if ($root && $file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) @unlink($file);
}

/** Valida uma data Y-m-d vinda de GET; devolve '' se inválida. */
function admin_valid_date(?string $d): string {
    $d = trim((string)$d);
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : '';
}

/** Lê e valida os campos do formulário de produto (admin). Preenche $old e devolve os erros. */
function admin_product_from_post(array &$old): array {
    $errors = [];
    $old['name']        = trim((string)($_POST['name'] ?? ''));
    $old['description'] = trim((string)($_POST['description'] ?? ''));
    $old['price']       = trim((string)($_POST['price'] ?? ''));
    $old['unit']        = (string)($_POST['unit'] ?? 'kg');
    $old['stock']       = trim((string)($_POST['stock'] ?? ''));
    $old['category_id'] = safe_int($_POST['category_id'] ?? 0);
    $old['farmer_id']   = safe_int($_POST['farmer_id'] ?? 0);
    $old['status']      = (string)($_POST['status'] ?? 'active');
    $old['province']    = trim((string)($_POST['province'] ?? ''));
    $old['district']    = trim((string)($_POST['district'] ?? ''));

    if ($old['name'] === '') $errors['name'] = 'O nome é obrigatório.';
    elseif (mb_strlen($old['name']) > 150) $errors['name'] = 'Nome: máx. 150 caracteres.';
    if ($old['price'] === '' || !is_numeric($old['price']) || (float)$old['price'] < 0 || (float)$old['price'] > 9999999999) $errors['price'] = 'Preço inválido.';
    if ($old['stock'] === '' || !is_numeric($old['stock']) || (float)$old['stock'] < 0 || (float)$old['stock'] > 9999999999) $errors['stock'] = 'Stock inválido.';
    if (!array_key_exists($old['unit'], product_units())) $errors['unit'] = 'Unidade inválida.';
    if (!in_array($old['status'], ['active', 'inactive', 'out_of_stock'], true)) $errors['status'] = 'Estado inválido.';
    if ($old['province'] !== '' && !in_array($old['province'], provinces(), true)) $errors['province'] = 'Província inválida.';
    if (mb_strlen($old['district']) > 100) $errors['district'] = 'Distrito: máx. 100 caracteres.';
    if (mb_strlen($old['description']) > 5000) $errors['description'] = 'Descrição: máx. 5000 caracteres.';

    $c = db()->prepare('SELECT COUNT(*) FROM categories WHERE id=?'); $c->execute([$old['category_id']]);
    if (!$old['category_id'] || !$c->fetchColumn()) $errors['category_id'] = 'Escolha a categoria.';
    $f = db()->prepare('SELECT COUNT(*) FROM farmers WHERE id=?'); $f->execute([$old['farmer_id']]);
    if (!$old['farmer_id'] || !$f->fetchColumn()) $errors['farmer_id'] = 'Escolha o agricultor.';

    // Produto ativo sem stock fica esgotado.
    if (!$errors && $old['status'] === 'active' && (float)$old['stock'] <= 0) $old['status'] = 'out_of_stock';
    return $errors;
}

/** Província efetiva do produto: a escolhida ou a do agricultor. */
function admin_product_province(array $old): string {
    if ($old['province'] !== '') return $old['province'];
    $s = db()->prepare('SELECT province FROM farmers WHERE id=?'); $s->execute([$old['farmer_id']]);
    return (string)($s->fetchColumn() ?: 'Maputo');
}

/* ---------- Utilizadores ---------- */
function admin_user_status_label(string $s): string { return ['active' => 'Ativo', 'inactive' => 'Inativo', 'blocked' => 'Bloqueado'][$s] ?? ucfirst($s); }
function admin_user_status_class(string $s): string { return ['active' => 'success', 'inactive' => 'secondary', 'blocked' => 'danger'][$s] ?? 'secondary'; }
function admin_user_status_icon(string $s): string { return ['active' => 'check-circle-fill', 'inactive' => 'pause-circle-fill', 'blocked' => 'slash-circle-fill'][$s] ?? 'circle'; }

/** Altera users.status com validação + auditoria + notificação. Nunca permite que o admin altere a própria conta. */
function admin_set_user_status(int $userId, string $status): void {
    if (!in_array($status, ['active', 'inactive', 'blocked'], true)) { flash('danger', 'Estado inválido.'); return; }
    if ($userId === (int)user()['id']) { flash('danger', 'Não pode alterar o estado da sua própria conta.'); return; }
    $s = db()->prepare('SELECT id, name, status FROM users WHERE id=?'); $s->execute([$userId]); $u = $s->fetch();
    if (!$u) { flash('danger', 'Utilizador não encontrado.'); return; }
    if ($u['status'] === $status) { flash('info', 'O utilizador já está ' . mb_strtolower(admin_user_status_label($status)) . '.'); return; }
    db()->prepare('UPDATE users SET status=?, updated_at=NOW() WHERE id=?')->execute([$status, $userId]);
    audit(user()['id'], 'update', 'users', 'Utilizador #' . $userId . ' (' . $u['name'] . '): ' . $u['status'] . ' → ' . $status);
    if ($status === 'active') notify($userId, 'Conta reativada', 'A sua conta foi reativada pela administração.');
    flash('success', 'Utilizador ' . $u['name'] . ' agora está ' . mb_strtolower(admin_user_status_label($status)) . '.');
}
