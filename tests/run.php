<?php
/**
 * Testes automáticos do AgroMercado (unitários + integração HTTP).
 * Uso: php tests/run.php [--base=http://localhost/agro-marketplace]
 * Os testes de integração criam um comprador temporário e apagam tudo o que criaram no fim.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/functions.php';

$base = rtrim(getenv('BASE_URL') ?: 'http://localhost/agro-marketplace', '/');
foreach ($argv as $a) if (str_starts_with($a, '--base=')) $base = rtrim(substr($a, 7), '/');

$passed = 0; $failed = 0;
function ok(bool $cond, string $name, string $detail = ''): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  ok   $name\n"; }
    else { $failed++; echo "  FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

/* ---------------- Cliente HTTP com cookies ---------------- */
final class Http {
    private string $jar;
    public int $status = 0; public string $body = ''; public string $location = '';
    public function __construct(private string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'agro'); }
    public function __destruct() { @unlink($this->jar); }
    public function req(string $method, string $path, $data = null, bool $json = false): self {
        $ch = curl_init($this->base . '/' . ltrim($path, '/'));
        $headers = [];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30]);
        if ($data !== null) {
            if ($json) { $headers[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); }
            else curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw = (string)curl_exec($ch);
        $this->status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $head = substr($raw, 0, $hsize); $this->body = substr($raw, $hsize);
        $this->location = preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : '';
        curl_close($ch);
        return $this;
    }
    public function get(string $p): self { return $this->req('GET', $p); }
    public function post(string $p, array $d): self { return $this->req('POST', $p, $d); }
    public function api(string $p, array $d): array { $this->req('POST', $p, $d + ['csrf' => $this->jsCsrf()], true); return json_decode($this->body, true) ?: []; }
    public function formCsrf(string $p): string { $this->get($p); return preg_match('/name="csrf" value="([^"]+)"/', $this->body, $m) ? html_entity_decode($m[1]) : ''; }
    public function jsCsrf(string $p = 'marketplace.php'): string { $this->req('GET', $p); return preg_match("/window\.CSRF\s*=\s*'([^']+)'/", $this->body, $m) ? $m[1] : ''; }
    public function login(string $email, string $password): string { $t = $this->formCsrf('login.php'); $this->post('login.php', ['csrf' => $t, 'email' => $email, 'password' => $password]); return $this->location; }
}

echo "Testes unitários\n";
ok(strong_password('Senha12345') && !strong_password('fraca') && !strong_password('semnumeroAA'), 'strong_password');
ok(can_transition('pending', 'confirmed') && !can_transition('delivered', 'pending') && !can_transition('ready', 'cancelled'), 'fluxo de estados dos pedidos');
ok(base32_decode(base32_encode('AgroMercado!')) === 'AgroMercado!', 'base32 ida e volta');
// RFC 6238, apêndice B (SHA-1, T=59 s): 94287082 → 6 dígitos 287082
ok(totp_code(base32_encode('12345678901234567890'), 59) === '287082', 'TOTP segundo o RFC 6238');
$split = commission_split(240.0, 10.0);
[, $commission, $farmerShare] = $split; // [taxa, comissão, valor do agricultor]
ok(abs($commission - 24.0) < 0.001 && abs($farmerShare - 216.0) < 0.001, 'comissão de 10% sobre 240 MT', json_encode($split));
ok(invoice_number_for(7, 2026) === 'FT 2026/000007', 'numeração de faturas');
$v = validate_coupon('BEMVINDO10', 100.0);
ok(is_string($v), 'cupão recusado abaixo do mínimo');
$v = validate_coupon('BEMVINDO10', 300.0);
ok(is_array($v) && abs((float)$v[1] - 30.0) < 0.001, 'cupão BEMVINDO10 dá 10% de desconto');
ok(is_string(validate_coupon('NAOEXISTE', 500.0)), 'cupão inexistente recusado');

echo "Integração HTTP ($base)\n";
$pdo = db();
$email = 'teste.auto.' . bin2hex(random_bytes(4)) . '@agromercado.local';
$pdo->prepare('INSERT INTO users(role_id,name,email,password,phone,email_verified_at) VALUES((SELECT id FROM roles WHERE name="buyer"),"Teste Automático",?,?,"+258 84 000 0001",NOW())')->execute([$email, password_hash('Teste12345A', PASSWORD_DEFAULT)]);
$uid = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO buyers(user_id) VALUES(?)')->execute([$uid]);
$buyerId = (int)$pdo->lastInsertId();
$stock0 = (float)$pdo->query('SELECT stock FROM products WHERE id=1')->fetchColumn();
$coupon0 = (int)$pdo->query("SELECT used_count FROM coupons WHERE code='BEMVINDO10'")->fetchColumn();

try {
    $b = new Http($base);
    ok(str_ends_with($b->login($email, 'Teste12345A'), 'comprador/dashboard.php'), 'login do comprador');
    ok($b->get('admin/dashboard.php')->status === 403, 'comprador não entra no admin');
    ok($b->get('api/pedidos.php')->status === 405, 'API só aceita POST');

    $r = $b->api('api/carrinho.php', ['action' => 'clear']);
    $r = $b->api('api/carrinho.php', ['action' => 'add', 'product_id' => 1, 'quantity' => -5]);
    ok(($r['success'] ?? true) === false, 'quantidade negativa recusada');
    $r = $b->api('api/carrinho.php', ['action' => 'add', 'product_id' => 1, 'quantity' => 3]);
    ok(($r['success'] ?? false) === true, 'produto adicionado ao carrinho', $b->body);
    $r = $b->api('api/cupao.php', ['action' => 'apply', 'code' => 'BEMVINDO10']);
    ok(($r['success'] ?? false) === true, 'cupão aplicado', $b->body);

    $t = $b->formCsrf('checkout.php');
    $b->post('checkout.php', ['csrf' => $t, 'name' => 'Teste Automático', 'phone' => '+258 84 000 0001', 'province' => 'Maputo', 'city' => 'Maputo', 'district' => 'Boane', 'address' => 'Rua dos Testes, 1', 'payment_method' => 'hack']);
    ok($b->status !== 302 || !str_contains($b->location, 'pedido-confirmado'), 'método de pagamento inválido recusado');
    $t = $b->formCsrf('checkout.php');
    $b->post('checkout.php', ['csrf' => $t, 'name' => 'Teste Automático', 'phone' => '+258 84 000 0001', 'province' => 'Maputo', 'city' => 'Maputo', 'district' => 'Boane', 'address' => 'Rua dos Testes, 1', 'payment_method' => 'cash', 'coupon_code' => 'BEMVINDO10']);
    ok(str_contains($b->location, 'pedido-confirmado.php'), 'checkout cria o pedido', $b->status . ' ' . $b->location);
    $order = $pdo->query("SELECT * FROM orders WHERE buyer_id=$buyerId ORDER BY id DESC LIMIT 1")->fetch();
    ok($order && (float)$order['subtotal'] === 240.0 && (float)$order['discount'] === 24.0 && (float)$order['delivery_fee'] === 80.0 && (float)$order['total'] === 296.0, 'subtotal 240, desconto 24, entrega 80, total 296', json_encode($order ? array_intersect_key($order, array_flip(['subtotal','discount','delivery_fee','total'])) : null));
    ok($order && preg_match('/^FT \d{4}\/\d{6}$/', (string)$order['invoice_number']), 'fatura numerada');
    $oid = (int)($order['id'] ?? 0);
    $item = $pdo->query("SELECT * FROM order_items WHERE order_id=$oid")->fetch();
    ok($item && (float)$item['commission_amount'] === 24.0 && (float)$item['farmer_amount'] === 216.0, 'comissão e valor do agricultor no item');
    ok((float)$pdo->query('SELECT stock FROM products WHERE id=1')->fetchColumn() === $stock0 - 3, 'stock baixou 3 kg');
    ok($b->get("recibo.php?id=$oid")->status === 200, 'comprador abre o recibo');

    $other = new Http($base);
    $other->login('beatriz_nunes@agromercado.local', 'Buyer@123');
    ok($other->get("recibo.php?id=$oid")->status === 403, 'outro comprador não abre o recibo');

    $f = new Http($base);
    ok(str_ends_with($f->login('joao@agromercado.local', 'Farmer@123'), 'agricultor/dashboard.php'), 'login do agricultor');
    $r = $f->api('api/pedidos.php', ['order_id' => $oid, 'status' => 'delivered']);
    ok(($r['success'] ?? true) === false, 'transição inválida recusada');
    $r = $f->api('api/pedidos.php', ['order_id' => $oid, 'status' => 'confirmed']);
    ok(($r['success'] ?? false) === true, 'agricultor confirma o pedido', $f->body);
    $r = $f->api('api/mensagens.php', ['order_id' => $oid, 'body' => 'Olá, o tomate segue amanhã.']);
    ok(($r['success'] ?? false) === true, 'agricultor envia mensagem ao comprador', $f->body);

    $t = $b->formCsrf("comprador/pedido-detalhes.php?id=$oid");
    $b->post("comprador/pedido-detalhes.php?id=$oid", ['csrf' => $t, 'action' => 'cancel', 'reason' => 'Teste automático']);
    $st = $pdo->query("SELECT status FROM orders WHERE id=$oid")->fetchColumn();
    ok($st === 'cancelled', 'comprador cancela pedido confirmado', (string)$st);
    ok((float)$pdo->query('SELECT stock FROM products WHERE id=1')->fetchColumn() === $stock0, 'stock reposto após cancelamento');

    $b->get('logout.php');
    ok($b->get('comprador/dashboard.php')->status === 200, 'logout por GET não termina a sessão');
    $t = $b->jsCsrf('comprador/dashboard.php');
    $b->post('logout.php', ['csrf' => $t]);
    ok($b->get('comprador/dashboard.php')->status === 302, 'logout por POST termina a sessão');
} finally {
    // Limpeza de tudo o que o teste criou.
    $orders = $pdo->query("SELECT id, order_number FROM orders WHERE buyer_id=$buyerId")->fetchAll();
    foreach ($orders as $o) {
        foreach ($pdo->query('SELECT product_id, quantity, status FROM order_items WHERE order_id=' . (int)$o['id']) as $i)
            if ($i['status'] !== 'cancelled') $pdo->prepare('UPDATE products SET stock=stock+?, sales_count=GREATEST(sales_count-?,0) WHERE id=?')->execute([$i['quantity'], $i['quantity'], $i['product_id']]);
        $pdo->prepare('DELETE FROM inventory WHERE notes LIKE ?')->execute(['%' . $o['order_number'] . '%']);
        foreach (['refunds', 'order_messages', 'reviews', 'complaints', 'payments', 'order_items'] as $tb) $pdo->prepare("DELETE FROM $tb WHERE order_id=?")->execute([$o['id']]);
        $pdo->prepare('DELETE FROM orders WHERE id=?')->execute([$o['id']]);
        $pdo->prepare('DELETE FROM notifications WHERE body LIKE ?')->execute(['%' . $o['order_number'] . '%']);
        $pdo->prepare('DELETE FROM email_outbox WHERE body LIKE ?')->execute(['%' . $o['order_number'] . '%']);
    }
    $pdo->prepare("UPDATE coupons SET used_count=? WHERE code='BEMVINDO10'")->execute([$coupon0]);
    $pdo->prepare('UPDATE products SET status="active" WHERE id=1 AND status="out_of_stock" AND stock>0')->execute();
    foreach (['cart_items ci JOIN carts c ON c.id=ci.cart_id' => 'c.buyer_id'] as $t => $col) $pdo->prepare("DELETE ci FROM $t WHERE $col=?")->execute([$buyerId]);
    $pdo->prepare('DELETE FROM carts WHERE buyer_id=?')->execute([$buyerId]);
    $pdo->prepare('UPDATE audit_logs SET user_id=NULL WHERE user_id=?')->execute([$uid]);
    foreach (['notifications', 'notification_preferences', 'favorites', 'user_tokens', 'email_outbox', 'legal_acceptances', 'password_resets'] as $tb) $pdo->prepare("DELETE FROM $tb WHERE user_id=?")->execute([$uid]);
    $pdo->prepare('DELETE FROM buyers WHERE id=?')->execute([$buyerId]);
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
}

echo "\n$passed aprovados, $failed falhados\n";
exit($failed ? 1 : 0);
