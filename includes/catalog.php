<?php
/* Catálogo: galeria de imagens dos produtos e validade de documentos. */

/* ---------- Galeria de imagens ---------- */
/** Número máximo de imagens extra por produto (além da imagem de capa, products.main_image). */
const PRODUCT_GALLERY_MAX = 5;

/** Imagens extra do produto (exclui a linha is_primary, que espelha a capa). */
function product_gallery(int $productId): array {
    try {
        $s = db()->prepare('SELECT id, product_id, image_path, sort_order, created_at FROM product_images WHERE product_id=? AND is_primary=0 ORDER BY sort_order, id');
        $s->execute([$productId]);
        return $s->fetchAll();
    } catch (Throwable $e) { error_log($e->getMessage()); return []; }
}

/** Remove do disco um ficheiro de imagem, apenas se estiver dentro de uploads/ (nunca assets/ nem URLs). */
function catalog_delete_upload_file(?string $path): void {
    $path = ltrim(trim((string)$path), '/');
    if ($path === '' || preg_match('#^(https?:|assets/)#i', $path) || str_contains($path, '..')) return;
    if (str_starts_with($path, 'uploads/')) $path = substr($path, 8);
    $root = realpath(__DIR__ . '/../uploads');
    $file = realpath(__DIR__ . '/../uploads/' . $path);
    if ($root && $file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) @unlink($file);
}

/** Normaliza um input multi-ficheiro ($_FILES['gallery']) numa lista de ficheiros individuais. */
function catalog_normalize_files(?array $files): array {
    if (!$files || !isset($files['name'])) return [];
    if (!is_array($files['name'])) return [$files];
    $out = [];
    foreach (array_keys($files['name']) as $i) {
        $out[] = ['name' => $files['name'][$i] ?? '', 'type' => $files['type'][$i] ?? '', 'tmp_name' => $files['tmp_name'][$i] ?? '', 'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $files['size'][$i] ?? 0];
    }
    return $out;
}

/**
 * Guarda as imagens de um input name="gallery[]" na galeria do produto, respeitando o limite.
 * Devolve a lista de erros (vazia se tudo correu bem). Ficheiros vazios são ignorados.
 */
function add_product_images(PDO $pdo, int $productId, ?array $filesArray): array {
    $errors = [];
    $files = array_values(array_filter(catalog_normalize_files($filesArray), fn($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    if (!$files) return $errors;
    $s = $pdo->prepare('SELECT COUNT(*), COALESCE(MAX(sort_order),0) FROM product_images WHERE product_id=? AND is_primary=0');
    $s->execute([$productId]);
    [$count, $order] = array_map('intval', $s->fetch(PDO::FETCH_NUM));
    $ins = $pdo->prepare('INSERT INTO product_images (product_id, image_path, is_primary, sort_order) VALUES (?, ?, 0, ?)');
    foreach ($files as $f) {
        $label = mb_substr(basename((string)$f['name']), 0, 60) ?: 'imagem';
        if ($count >= PRODUCT_GALLERY_MAX) { $errors[] = $label . ': limite de ' . PRODUCT_GALLERY_MAX . ' imagens na galeria atingido.'; continue; }
        $err = $f['error'];
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { $errors[] = $label . ': a imagem é demasiado grande (máx. 3 MB).'; continue; }
        if ($err !== UPLOAD_ERR_OK) { $errors[] = $label . ': o envio falhou.'; continue; }
        $path = null;
        try {
            $path = save_upload($f);
            $ins->execute([$productId, $path, min(255, ++$order)]);
            $count++;
        } catch (RuntimeException $e) {
            $errors[] = $label . ': ' . $e->getMessage();
        } catch (Throwable $e) {
            if ($path) catalog_delete_upload_file($path);
            error_log($e->getMessage());
            $errors[] = $label . ': não foi possível guardar a imagem.';
        }
    }
    return $errors;
}

/** Remove uma imagem da galeria (verifica que pertence ao produto) e apaga o ficheiro se estiver em uploads/. */
function delete_product_image(PDO $pdo, int $imageId, int $productId): bool {
    $s = $pdo->prepare('SELECT image_path FROM product_images WHERE id=? AND product_id=? AND is_primary=0');
    $s->execute([$imageId, $productId]);
    $path = $s->fetchColumn();
    if ($path === false) return false;
    $pdo->prepare('DELETE FROM product_images WHERE id=? AND product_id=?')->execute([$imageId, $productId]);
    // Só apaga o ficheiro se nenhuma outra referência o usar (capa ou outra linha).
    $u = $pdo->prepare('SELECT (SELECT COUNT(*) FROM products WHERE main_image=?) + (SELECT COUNT(*) FROM product_images WHERE image_path=?)');
    $u->execute([$path, $path]);
    if (!(int)$u->fetchColumn()) catalog_delete_upload_file($path);
    return true;
}

/** Troca a capa: a imagem da galeria passa a main_image e a capa antiga ocupa o lugar dela na galeria. */
function set_cover_image(PDO $pdo, int $imageId, int $productId): bool {
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT pi.image_path, p.main_image FROM product_images pi JOIN products p ON p.id=pi.product_id WHERE pi.id=? AND pi.product_id=? AND pi.is_primary=0 FOR UPDATE');
        $s->execute([$imageId, $productId]);
        $row = $s->fetch();
        if (!$row) { if ($own) $pdo->rollBack(); return false; }
        $pdo->prepare('UPDATE products SET main_image=? WHERE id=?')->execute([$row['image_path'], $productId]);
        if (!empty($row['main_image'])) $pdo->prepare('UPDATE product_images SET image_path=? WHERE id=?')->execute([$row['main_image'], $imageId]);
        else $pdo->prepare('DELETE FROM product_images WHERE id=?')->execute([$imageId]);
        // Mantém a linha is_primary (legado) alinhada com a capa.
        $pdo->prepare('UPDATE product_images SET image_path=? WHERE product_id=? AND is_primary=1')->execute([$row['image_path'], $productId]);
        if ($own) $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Caminhos de todas as imagens (galeria + capa) de um produto — para apagar os ficheiros antes de apagar o produto. */
function product_image_paths(PDO $pdo, int $productId): array {
    $s = $pdo->prepare('SELECT image_path FROM product_images WHERE product_id=? UNION SELECT main_image FROM products WHERE id=? AND main_image IS NOT NULL');
    $s->execute([$productId, $productId]);
    return array_values(array_filter(array_unique($s->fetchAll(PDO::FETCH_COLUMN))));
}

/** Apaga do disco os ficheiros (só em uploads/) das imagens do produto. Chamar depois de apagar o produto com sucesso. */
function delete_product_files(array $paths): void { foreach ($paths as $p) catalog_delete_upload_file($p); }

/**
 * Formulários (escondidos) das ações de cada imagem da galeria. Têm de ficar FORA do formulário principal
 * (não se podem aninhar formulários); os botões das miniaturas apontam para eles com o atributo form="…".
 */
function product_gallery_action_forms(array $gallery, int $productId, string $action = ''): string {
    $html = '';
    foreach ($gallery as $g) {
        $gid = (int)$g['id'];
        foreach (['delete' => 'Remover esta imagem da galeria?', 'cover' => null] as $act => $confirm) {
            $html .= '<form method="post" action="' . e($action) . '" id="gal-' . $act . '-' . $gid . '" hidden' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
                . csrf_field()
                . '<input type="hidden" name="id" value="' . $productId . '">'
                . '<input type="hidden" name="gallery_action" value="' . $act . '">'
                . '<input type="hidden" name="image_id" value="' . $gid . '">'
                . '</form>';
        }
    }
    return $html;
}

/** Miniaturas da galeria atual (com "Usar como capa" / "Remover") + input multi-ficheiro name="gallery[]". */
function product_gallery_fields(array $gallery, string $inputId = 'galleryInput'): string {
    static $styled = false;
    $remaining = max(0, PRODUCT_GALLERY_MAX - count($gallery));
    $html = '';
    if (!$styled) {
        $styled = true;
        $html .= '<style>
.pg-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:.75rem;margin-bottom:1rem}
.pg-thumb{border:1px solid var(--bs-border-color,#e5e7eb);border-radius:12px;overflow:hidden;background:var(--bs-body-bg,#fff);display:flex;flex-direction:column}
.pg-thumb img{width:100%;aspect-ratio:1/1;object-fit:cover;display:block}
.pg-thumb-actions{display:flex;gap:.25rem;padding:.35rem;justify-content:space-between}
.pg-thumb-actions .btn{padding:.15rem .45rem;font-size:.78rem;line-height:1.4}
.pg-hint{font-size:.82rem;color:var(--bs-secondary-color,#6b7280)}
</style>';
    }
    if ($gallery) {
        $html .= '<div class="pg-gallery" role="list" aria-label="Imagens da galeria">';
        foreach ($gallery as $i => $g) {
            $gid = (int)$g['id'];
            $n = $i + 1;
            $html .= '<div class="pg-thumb" role="listitem">'
                . '<img src="' . e(get_product_image($g['image_path'])) . '" alt="Imagem ' . $n . ' da galeria" loading="lazy">'
                . '<div class="pg-thumb-actions">'
                . '<button type="submit" form="gal-cover-' . $gid . '" class="btn btn-outline-primary" title="Usar como capa" aria-label="Usar a imagem ' . $n . ' como capa"><i class="bi bi-star"></i> Capa</button>'
                . '<button type="submit" form="gal-delete-' . $gid . '" class="btn btn-outline-danger" title="Remover" aria-label="Remover a imagem ' . $n . ' da galeria"><i class="bi bi-trash"></i></button>'
                . '</div></div>';
        }
        $html .= '</div><p class="pg-hint mb-2"><i class="bi bi-info-circle"></i> "Capa" e "Remover" aplicam-se de imediato — guarde antes as outras alterações do formulário.</p>';
    }
    $html .= '<label class="form-label" for="' . e($inputId) . '">Adicionar imagens à galeria</label>'
        . '<input type="file" name="gallery[]" id="' . e($inputId) . '" class="form-control" multiple accept="image/jpeg,image/png,image/webp" data-gallery-max="' . $remaining . '"' . ($remaining === 0 ? ' disabled' : '') . ' aria-describedby="' . e($inputId) . 'Hint">'
        . '<small class="pg-hint d-block mt-1" id="' . e($inputId) . 'Hint">Até ' . PRODUCT_GALLERY_MAX . ' imagens extra além da capa (JPG, PNG ou WEBP, máx. 3 MB cada). '
        . ($remaining === 0 ? 'Limite atingido — remova uma imagem para adicionar outra.' : 'Pode adicionar mais ' . $remaining . '.') . '</small>'
        . '<script>(function(){var i=document.getElementById(' . json_encode($inputId) . ');if(!i)return;i.addEventListener("change",function(){var m=+i.dataset.galleryMax;if(i.files.length>m){alert("Pode adicionar no máximo "+m+" imagem(ns) à galeria.");i.value="";}});})();</script>';
    return $html;
}

/** Lida com as ações de galeria (remover / usar como capa) vindas de um POST. Devolve [tipo, mensagem] ou null. */
function handle_gallery_action(PDO $pdo, int $productId, array $post): ?array {
    $action = (string)($post['gallery_action'] ?? '');
    $imageId = safe_int($post['image_id'] ?? 0);
    if ($action === 'delete') {
        return delete_product_image($pdo, $imageId, $productId) ? ['success', 'Imagem removida da galeria.'] : ['danger', 'Imagem não encontrada.'];
    }
    if ($action === 'cover') {
        return set_cover_image($pdo, $imageId, $productId) ? ['success', 'Imagem definida como capa do produto.'] : ['danger', 'Imagem não encontrada.'];
    }
    return null;
}

/* ---------- Documentos dos agricultores ---------- */
function document_type_label(string $t): string { return ['identity' => 'Documento de identificação', 'license' => 'Licença / alvará', 'certificate' => 'Certificado', 'other' => 'Outro'][$t] ?? ucfirst($t); }
function document_type_requires_expiry(string $t): bool { return in_array($t, ['identity', 'license'], true); }
function document_status_label(string $s): string { return ['pending' => 'Pendente', 'approved' => 'Aprovado', 'rejected' => 'Rejeitado', 'expired' => 'Expirado'][$s] ?? ucfirst($s); }
function document_status_class(string $s): string { return ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'expired' => 'secondary'][$s] ?? 'secondary'; }

/** Dias até à validade (negativo = expirado); null se sem data. */
function document_days_left(?string $expiresAt): ?int {
    if (!$expiresAt) return null;
    $exp = DateTime::createFromFormat('!Y-m-d', substr($expiresAt, 0, 10));
    if (!$exp) return null;
    $today = new DateTime('today');
    return (int)$today->diff($exp)->format('%r%a');
}

/** Badge HTML com o estado da validade do documento ("expira em N dias", "expirado"…). */
function document_expiry_badge(?string $expiresAt, string $status = 'approved'): string {
    $days = document_days_left($expiresAt);
    if ($days === null) return '<span class="badge text-bg-light border">Sem validade</span>';
    $date = date('d/m/Y', strtotime($expiresAt));
    if ($status === 'expired' || $days < 0) return '<span class="badge text-bg-danger" title="Expirou a ' . e($date) . '"><i class="bi bi-x-octagon me-1"></i>Expirado</span>';
    if ($days === 0) return '<span class="badge text-bg-danger" title="' . e($date) . '"><i class="bi bi-hourglass-bottom me-1"></i>Expira hoje</span>';
    if ($days <= 30) return '<span class="badge text-bg-warning" title="' . e($date) . '"><i class="bi bi-hourglass-split me-1"></i>Expira em ' . $days . ' dia' . ($days === 1 ? '' : 's') . '</span>';
    return '<span class="badge text-bg-success" title="Válido até ' . e($date) . '"><i class="bi bi-calendar-check me-1"></i>Válido até ' . e($date) . '</span>';
}

/** IDs de utilizador dos SuperAdmins ativos. */
function superadmin_user_ids(): array {
    $s = db()->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='superadmin' AND u.status='active'");
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Verifica a validade dos documentos aprovados (idempotente):
 *  - aviso único ao agricultor 30 dias antes (expiry_notified_at);
 *  - documentos aprovados com expires_at < hoje passam a 'expired' e o agricultor é notificado;
 *  - se o único documento de identidade aprovado expirou, a conta volta a 'pending' e os produtos ativos ficam inativos;
 *  - os SuperAdmins recebem um resumo.
 * Devolve um resumo com contagens.
 */
function check_document_expiry(int $limit = 50): array {
    $pdo = db();
    $limit = max(1, min(1000, $limit));
    $sum = ['warned' => 0, 'expired' => 0, 'farmers_suspended' => 0, 'products_deactivated' => 0, 'details' => []];

    // 1) Avisos: expira nos próximos 30 dias e ainda não avisado.
    $s = $pdo->prepare("SELECT fd.id, fd.document_type, fd.expires_at, f.id farmer_id, f.user_id, u.name FROM farmer_documents fd JOIN farmers f ON f.id=fd.farmer_id JOIN users u ON u.id=f.user_id
        WHERE fd.status='approved' AND fd.expires_at IS NOT NULL AND fd.expires_at >= CURDATE() AND fd.expires_at <= CURDATE() + INTERVAL 30 DAY AND fd.expiry_notified_at IS NULL ORDER BY fd.expires_at LIMIT $limit");
    $s->execute();
    $warnNames = [];
    foreach ($s->fetchAll() as $d) {
        $upd = $pdo->prepare('UPDATE farmer_documents SET expiry_notified_at=NOW() WHERE id=? AND expiry_notified_at IS NULL');
        $upd->execute([$d['id']]);
        if (!$upd->rowCount()) continue; // outro processo já tratou
        $days = document_days_left($d['expires_at']);
        notify((int)$d['user_id'], 'Documento a expirar', document_type_label($d['document_type']) . ' expira a ' . date('d/m/Y', strtotime($d['expires_at'])) . ' (' . $days . ' dia' . ($days === 1 ? '' : 's') . '). Envie um documento atualizado no seu perfil para manter a conta verificada.');
        $sum['warned']++;
        $warnNames[] = $d['name'] . ' (' . document_type_label($d['document_type']) . ')';
        $sum['details'][] = 'Aviso: documento #' . $d['id'] . ' de ' . $d['name'] . ' expira a ' . $d['expires_at'];
    }

    // 2) Expirados: aprovados com validade ultrapassada.
    $s = $pdo->prepare("SELECT fd.id, fd.document_type, fd.expires_at, f.id farmer_id, f.user_id, f.verification_status, u.name FROM farmer_documents fd JOIN farmers f ON f.id=fd.farmer_id JOIN users u ON u.id=f.user_id
        WHERE fd.status='approved' AND fd.expires_at IS NOT NULL AND fd.expires_at < CURDATE() ORDER BY fd.expires_at LIMIT $limit");
    $s->execute();
    $expNames = [];
    foreach ($s->fetchAll() as $d) {
        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("UPDATE farmer_documents SET status='expired', expiry_notified_at=NOW() WHERE id=? AND status='approved'");
            $upd->execute([$d['id']]);
            if (!$upd->rowCount()) { $pdo->rollBack(); continue; }
            audit(null, 'expire', 'farmer_documents', 'Documento #' . $d['id'] . ' (' . $d['document_type'] . ') do agricultor #' . $d['farmer_id'] . ' expirou a ' . $d['expires_at']);
            $msg = document_type_label($d['document_type']) . ' expirou a ' . date('d/m/Y', strtotime($d['expires_at'])) . '. Envie um documento válido no seu perfil.';
            $suspended = false;
            if ($d['document_type'] === 'identity') {
                $c = $pdo->prepare("SELECT COUNT(*) FROM farmer_documents WHERE farmer_id=? AND document_type='identity' AND status='approved' AND (expires_at IS NULL OR expires_at >= CURDATE())");
                $c->execute([$d['farmer_id']]);
                if (!(int)$c->fetchColumn()) {
                    $f = $pdo->prepare("UPDATE farmers SET verification_status='pending' WHERE id=? AND verification_status='verified'");
                    $f->execute([$d['farmer_id']]);
                    if ($f->rowCount()) {
                        $p = $pdo->prepare("UPDATE products SET status='inactive' WHERE farmer_id=? AND status='active'");
                        $p->execute([$d['farmer_id']]);
                        $n = $p->rowCount();
                        $suspended = true;
                        $sum['farmers_suspended']++;
                        $sum['products_deactivated'] += $n;
                        audit(null, 'update', 'farmers', 'Agricultor #' . $d['farmer_id'] . ' voltou a pendente (identificação expirada); ' . $n . ' produto(s) desativado(s)');
                        $msg .= ' A sua conta voltou ao estado "pendente" e os produtos ativos foram desativados até à nova verificação.';
                    }
                }
            }
            notify((int)$d['user_id'], 'Documento expirado', $msg);
            $pdo->commit();
            $sum['expired']++;
            $expNames[] = $d['name'] . ' (' . document_type_label($d['document_type']) . ($suspended ? ', conta suspensa' : '') . ')';
            $sum['details'][] = 'Expirado: documento #' . $d['id'] . ' de ' . $d['name'] . ($suspended ? ' — conta voltou a pendente' : '');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('check_document_expiry: ' . $e->getMessage());
            $sum['details'][] = 'Erro no documento #' . $d['id'] . ': ' . $e->getMessage();
        }
    }

    // 3) Resumo para os SuperAdmins.
    if ($warnNames || $expNames) {
        $body = [];
        if ($expNames) $body[] = count($expNames) . ' documento(s) expirado(s): ' . implode('; ', $expNames) . '.';
        if ($warnNames) $body[] = count($warnNames) . ' documento(s) a expirar em 30 dias: ' . implode('; ', $warnNames) . '.';
        foreach (superadmin_user_ids() as $adminId) notify($adminId, 'Validade de documentos', mb_substr(implode(' ', $body), 0, 2000));
    }
    return $sum;
}

/** Execução preguiçosa (no máximo 1x por hora por sessão) a partir das páginas web. */
function maybe_check_document_expiry(): void {
    if (PHP_SAPI === 'cli') return;
    if (time() - (int)($_SESSION['doc_expiry_checked_at'] ?? 0) < 3600) return;
    $_SESSION['doc_expiry_checked_at'] = time();
    try { check_document_expiry(50); } catch (Throwable $e) { error_log('check_document_expiry: ' . $e->getMessage()); }
}
