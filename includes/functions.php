<?php
require_once __DIR__ . '/../config/database.php';

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function is_post(): bool { return $_SERVER['REQUEST_METHOD'] === 'POST'; }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function verify_csrf(?string $token): void { if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) { http_response_code(403); exit('Pedido inválido. Atualize a página e tente novamente.'); } }
function flash(string $type, string $message): void { $_SESSION['flash'][] = ['type'=>$type,'message'=>$message]; }
function get_flashes(): array { $items = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $items; }
function slugify(string $text): string { $text = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$text); $text = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $text)); return trim($text, '-'); }
function money($value): string { return number_format((float)$value, 2, ',', '.') . ' MT'; }
function safe_int($value, int $default = 0): int { return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int)$value : $default; }
function current_user_id(): int { return (int)($_SESSION['user_id'] ?? 0); }

/**
 * Resolve uma imagem de produto para URL pública.
 */
function get_product_image(?string $image): string {
    if (empty($image)) {
        return asset('img/product-placeholder.svg');
    }

    if (preg_match('#^https?://#i', $image)) {
        return $image;
    }

    $image = ltrim($image, '/');

    // 1) Formato atual: 'assets/img/tomate.jpg'
    if (str_starts_with($image, 'assets/')) {
        if (is_file(__DIR__ . '/../' . $image)) {
            return base_path($image);
        }
    }

    // 2) Formato 'uploads/...'
    if (str_starts_with($image, 'uploads/')) {
        return base_path($image);
    }

    // 3) Formato legado 'produtos/tomate.jpg' → tenta uploads primeiro
    if (is_file(__DIR__ . '/../uploads/' . $image)) {
        return upload_url($image);
    }

    // 4) Fallback: procura em assets/img/
    $filename = basename($image);
    if (is_file(__DIR__ . '/../assets/img/' . $filename)) {
        return asset('img/' . $filename);
    }

    // 5) Placeholder
    return asset('img/product-placeholder.svg');
}

function get_farmer_image(?string $image = null, ?int $farmerId = null): string { 
    $map=[1=>'farmer-joao.jpg',2=>'farmer-maria.jpg',3=>'farmer-antonio.jpg',4=>'farmer-celina.jpg',5=>'farmer-maria.jpg',6=>'farmer-antonio.jpg']; 
    if($image && is_file(__DIR__.'/../uploads/'.$image)) return upload_url($image); 
    $file=$map[(int)$farmerId]??'farmer-joao.jpg'; 
    return asset('img/farmers/'.$file); 
}

function get_category_image(int $categoryId): string { 
    $map=[1=>'arroz.jpg',2=>'manga.jpg',3=>'hortalicas.jpg',4=>'batata.jpg',5=>'feijao.jpg',6=>'amendoim.jpg',7=>'pimento.jpg',8=>'ovos.jpg',9=>'mel.jpg',10=>'couve-verde.jpg']; 
    return asset('img/'.($map[$categoryId]??'hortalicas.jpg')); 
}

function cart_count(): int { if (!current_user_id()) return 0; $s = db()->prepare('SELECT COALESCE(SUM(ci.quantity),0) FROM carts c JOIN cart_items ci ON ci.cart_id=c.id WHERE c.buyer_id=(SELECT id FROM buyers WHERE user_id=?)'); $s->execute([current_user_id()]); return (int)$s->fetchColumn(); }
function notify(int $userId, string $title, string $body): void { $s=db()->prepare('INSERT INTO notifications (user_id,title,body) VALUES (?,?,?)'); $s->execute([$userId,$title,$body]); }
function audit(?int $userId, string $action, string $module, string $description): void { $s=db()->prepare('INSERT INTO audit_logs (user_id,action,module,description,ip) VALUES (?,?,?,?,?)'); $s->execute([$userId ?: null,$action,$module,$description,$_SERVER['REMOTE_ADDR'] ?? 'local']); }
function order_status_label(string $status): string { return ['pending'=>'Pendente','confirmed'=>'Confirmado','preparing'=>'Em preparação','ready'=>'Pronto para entrega','shipping'=>'Em transporte','delivered'=>'Entregue','cancelled'=>'Cancelado'][$status] ?? ucfirst($status); }
function order_status_class(string $status): string { return ['pending'=>'warning','confirmed'=>'info','preparing'=>'primary','ready'=>'primary','shipping'=>'secondary','delivered'=>'success','cancelled'=>'danger'][$status] ?? 'secondary'; }
function order_status_icon(string $status): string { return ['pending'=>'clock','confirmed'=>'check2-circle','preparing'=>'gear-wide-connected','ready'=>'box-seam','shipping'=>'truck','delivered'=>'check-circle-fill','cancelled'=>'x-circle-fill'][$status] ?? 'circle'; }
function order_status_badge(string $status): string { return '<span class="badge text-bg-'.e(order_status_class($status)).' status-badge"><i class="bi bi-'.e(order_status_icon($status)).' me-1"></i>'.e(order_status_label($status)).'</span>'; }
function product_query_base(): string { return 'SELECT p.*, c.name category_name, c.icon category_icon, u.name farmer_name, f.verification_status FROM products p JOIN categories c ON c.id=p.category_id JOIN farmers f ON f.id=p.farmer_id JOIN users u ON u.id=f.user_id'; }
function valid_upload(array $file): array { $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp']; if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)return [false,'Selecione uma imagem válida.']; if(($file['size']??0)>3*1024*1024)return [false,'A imagem deve ter no máximo 3 MB.']; $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']); if(!isset($allowed[$mime]))return [false,'Formato inválido. Aceite JPG, PNG ou WEBP.']; return [true,$allowed[$mime]]; }
function save_upload(array $file): string { [$ok,$ext]=valid_upload($file); if(!$ok) throw new RuntimeException($ext); $name='produto_'.bin2hex(random_bytes(8)).'.'.$ext; $dir=__DIR__.'/../uploads/produtos/'; if(!is_dir($dir))mkdir($dir,0755,true); if(!move_uploaded_file($file['tmp_name'],$dir.$name))throw new RuntimeException('Não foi possível guardar a imagem.'); return 'produtos/'.$name; }
function save_farmer_photo(array $file): string { [$ok,$ext]=valid_upload($file); if(!$ok) throw new RuntimeException($ext); $name='agricultor_'.bin2hex(random_bytes(8)).'.'.$ext; $dir=__DIR__.'/../uploads/agricultores/'; if(!is_dir($dir))mkdir($dir,0755,true); if(!move_uploaded_file($file['tmp_name'],$dir.$name))throw new RuntimeException('Não foi possível guardar a fotografia.'); return 'agricultores/'.$name; }
function save_farmer_document(array $file): string { if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('Selecione um documento.'); if(($file['size']??0)>5*1024*1024) throw new RuntimeException('O documento deve ter no máximo 5 MB.'); $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']); $allowed=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png']; if(!isset($allowed[$mime])) throw new RuntimeException('Aceite apenas PDF, JPG ou PNG.'); $name='documento_'.bin2hex(random_bytes(8)).'.'.$allowed[$mime]; $dir=__DIR__.'/../uploads/agricultores/'; if(!is_dir($dir))mkdir($dir,0755,true); if(!move_uploaded_file($file['tmp_name'],$dir.$name))throw new RuntimeException('Não foi possível guardar o documento.'); return 'agricultores/'.$name; }
function pagination(int $page, int $perPage, int $total): string { $pages=max(1,(int)ceil($total/$perPage)); if($pages<=1)return ''; $html='<nav><ul class="pagination justify-content-center">'; for($i=1;$i<=$pages;$i++){ $active=$i===$page?' active':''; $query=$_GET; $query['page']=$i; $html.='<li class="page-item'.$active.'"><a class="page-link" href="?'.e(http_build_query($query)).'">'.$i.'</a></li>'; } return $html.'</ul></nav>'; }
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'local'),0,45); }
function login_blocked(string $email): bool { try { $s=db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE email=? AND ip=? AND success=0 AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)'); $s->execute([strtolower(trim($email)),client_ip()]); return (int)$s->fetchColumn() >= 5; } catch(Throwable $e) { return false; } }
function record_login_attempt(string $email, bool $success): void { try { $s=db()->prepare('INSERT INTO login_attempts(email,ip,success) VALUES(?,?,?)'); $s->execute([strtolower(trim($email)),client_ip(),$success?1:0]); } catch(Throwable $e) {} }
function setting(string $key, ?string $default=null): ?string { try { $s=db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?'); $s->execute([$key]); $value=$s->fetchColumn(); return $value===false?$default:(string)$value; } catch(Throwable $e) { return $default; } }
function delivery_zone(string $province, string $district): array { try { $s=db()->prepare('SELECT fee,eta_days FROM delivery_zones WHERE province=? AND district=? AND is_active=1 LIMIT 1'); $s->execute([trim($province),trim($district)]); $row=$s->fetch(); return $row?:['fee'=>0,'eta_days'=>3]; } catch(Throwable $e) { return ['fee'=>0,'eta_days'=>3]; } }

/* ---------- Ambiente ---------- */
function app_env(): string { $env = getenv('APP_ENV'); if ($env) return strtolower($env); return strtolower((string)setting('app_env', 'local')); }
function is_local_env(): bool { return app_env() === 'local'; }

/* ---------- Pedidos HTTP / API ---------- */
function require_post(): void { if (!is_post()) { http_response_code(405); header('Allow: POST'); exit('Método não permitido.'); } }
function json_out(array $data, int $code = 200): never { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
/** Valida sessão, papel, método POST e CSRF de um endpoint JSON e devolve o payload. */
function api_guard(string ...$roles): array {
    header('Content-Type: application/json; charset=utf-8');
    if (!is_post()) json_out(['success'=>false,'message'=>'Método não permitido.'], 405);
    if (!logged_in()) json_out(['success'=>false,'message'=>'Faça login para continuar.'], 401);
    if ($roles && !in_array(user()['role'], $roles, true)) json_out(['success'=>false,'message'=>'Sem permissão para esta ação.'], 403);
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($data['csrf'] ?? ''))) json_out(['success'=>false,'message'=>'Sessão expirada. Atualize a página.'], 403);
    return $data;
}
/** Formulário POST de um só botão (com CSRF) para ações que alteram dados. $label é HTML confiável. */
function post_button(string $action, array $fields, string $label, string $class = 'btn btn-sm btn-outline-primary', ?string $confirm = null, string $title = ''): string {
    $html = '<form method="post" action="'.e($action).'" class="d-inline"'.($confirm ? ' data-confirm="'.e($confirm).'"' : '').'>'.csrf_field();
    foreach ($fields as $k => $v) $html .= '<input type="hidden" name="'.e($k).'" value="'.e($v).'">';
    return $html.'<button type="submit" class="'.e($class).'"'.($title ? ' title="'.e($title).'" aria-label="'.e($title).'"' : '').'>'.$label.'</button></form>';
}

/* ---------- Catálogo ---------- */
function product_units(): array { return ['kg'=>'kg','tonelada'=>'Tonelada','saco'=>'Saco','caixa'=>'Caixa','litro'=>'Litro','unidade'=>'Unidade','dúzia'=>'Dúzia']; }
function provinces(): array { return ['Maputo','Maputo Cidade','Gaza','Inhambane','Sofala','Manica','Tete','Zambézia','Nampula','Cabo Delgado','Niassa']; }
function category_icon_html(?string $icon): string {
    $icon = trim((string)$icon);
    if ($icon === '') return '<i class="bi bi-tag"></i>';
    if (preg_match('/^bi-[a-z0-9-]+$/', $icon)) return '<i class="bi '.e($icon).'"></i>';
    return '<i class="bi bi-tag"></i>'; // ícones antigos em emoji caem no ícone genérico
}

/* ---------- Notificações ---------- */
function unread_notifications_count(): int { if (!current_user_id()) return 0; $s = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0'); $s->execute([current_user_id()]); return (int)$s->fetchColumn(); }
function notify_buyer_of_order(int $orderId, string $title, string $body): void { $s = db()->prepare('SELECT b.user_id FROM orders o JOIN buyers b ON b.id=o.buyer_id WHERE o.id=?'); $s->execute([$orderId]); if ($uid = $s->fetchColumn()) notify((int)$uid, $title, $body); }

/* ---------- Fluxo de estados de pedidos ---------- */
function order_status_rank(): array { return ['pending'=>0,'confirmed'=>1,'preparing'=>2,'ready'=>3,'shipping'=>4,'delivered'=>5]; }
function order_status_next(string $from): array { return ['pending'=>['confirmed','cancelled'],'confirmed'=>['preparing','cancelled'],'preparing'=>['ready','cancelled'],'ready'=>['shipping'],'shipping'=>['delivered'],'delivered'=>[],'cancelled'=>[]][$from] ?? []; }
function can_transition(string $from, string $to): bool { return in_array($to, order_status_next($from), true); }
/** Repõe o stock dos itens indicados (cancelamento). Cada item precisa de product_id e quantity. */
function restore_item_stock(PDO $pdo, array $items, string $note): void {
    foreach ($items as $i) {
        $pdo->prepare('UPDATE products SET stock=stock+?, sales_count=GREATEST(sales_count-?,0), status=IF(status="out_of_stock","active",status) WHERE id=?')->execute([$i['quantity'], $i['quantity'], $i['product_id']]);
        $pdo->prepare('INSERT INTO inventory(product_id,quantity,movement_type,notes,created_by) VALUES(?,?,"return",?,?)')->execute([$i['product_id'], $i['quantity'], $note, current_user_id() ?: null]);
    }
}
/** O estado do pedido é o do item menos avançado; fica cancelado só quando todos os itens estão cancelados. */
function recalc_order_status(PDO $pdo, int $orderId): string {
    $s = $pdo->prepare('SELECT status FROM order_items WHERE order_id=?'); $s->execute([$orderId]); $statuses = $s->fetchAll(PDO::FETCH_COLUMN);
    $active = array_values(array_filter($statuses, fn($x) => $x !== 'cancelled'));
    if (!$active) $new = 'cancelled';
    else { $rank = order_status_rank(); usort($active, fn($a, $b) => $rank[$a] <=> $rank[$b]); $new = $active[0]; }
    $pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$new, $orderId]);
    if ($new === 'cancelled') $pdo->prepare('UPDATE payments SET status=IF(status="paid","refunded",IF(status="pending","failed",status)) WHERE order_id=?')->execute([$orderId]);
    sync_order_payment_status($pdo, $orderId);
    return $new;
}
function sync_order_payment_status(PDO $pdo, int $orderId): void { $pdo->prepare('UPDATE orders o JOIN payments p ON p.order_id=o.id SET o.payment_status=p.status WHERE o.id=?')->execute([$orderId]); }
function payment_status_label(string $s): string { return ['pending'=>'Pendente','paid'=>'Pago','failed'=>'Falhado','refunded'=>'Reembolsado'][$s] ?? ucfirst($s); }
function payment_method_label(string $m): string { return ['cash'=>'Dinheiro','mpesa'=>'M-Pesa','emola'=>'e-Mola','bank'=>'Transferência','card'=>'Cartão'][$m] ?? ($m ?: '—'); }

/* ---------- Listas ---------- */
function page_offset(int $perPage): array { $page = max(1, safe_int($_GET['page'] ?? 1, 1)); return [$page, ($page - 1) * $perPage]; }
function csv_download(string $filename, array $header, iterable $rows): never {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename).'"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF"); fputcsv($out, $header, ';');
    foreach ($rows as $r) fputcsv($out, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, array_values((array)$r)), ';');
    fclose($out); exit;
}

/* ---------- Password ---------- */
function strong_password(string $p): bool { return strlen($p) >= 10 && preg_match('/[A-Z]/', $p) && preg_match('/[a-z]/', $p) && preg_match('/[0-9]/', $p); }
/* ---------- Favoritos ---------- */
function favorite_product_ids(): array { static $ids = null; if ($ids !== null) return $ids; $ids = []; if (!current_user_id()) return $ids; $s = db()->prepare('SELECT product_id FROM favorites WHERE user_id=? AND product_id IS NOT NULL'); $s->execute([current_user_id()]); return $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)); }
function is_favorite(int $productId): bool { return in_array($productId, favorite_product_ids(), true); }
