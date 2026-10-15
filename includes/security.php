<?php
/* Segurança: tokens de sessão, verificação de email, 2FA (TOTP) e limite de pedidos.
   Incluído no fim de functions.php: aqui só se definem funções (nada corre ao incluir). */

/* ---------- Configuração (.env tem prioridade sobre app_settings) ---------- */
/** Valor de configuração: variável de ambiente/.env não vazia ganha; senão app_settings; senão $default. */
function config_value(string $envKey, string $settingKey, ?string $default = null): ?string {
    $env = getenv($envKey);
    if ($env !== false && $env !== '') return $env;
    return setting($settingKey, $default);
}
/** Indica se o valor vem do ambiente (.env) — o painel mostra-o só de leitura. */
function config_from_env(string $envKey): bool { $env = getenv($envKey); return $env !== false && $env !== ''; }

/* ---------- Limite de pedidos (janela fixa, tabela rate_limits) ---------- */
/** Conta um pedido no balde e devolve false quando ultrapassa $max dentro de $seconds. Em caso de erro de BD não bloqueia. */
function rate_limit(string $bucket, int $max, int $seconds): bool {
    if (strlen($bucket) > 160) $bucket = substr($bucket, 0, 110) . ':' . sha1($bucket);
    try {
        $pdo = db();
        // A ordem importa: hits é avaliado antes de window_start ser reposto.
        $pdo->prepare('INSERT INTO rate_limits(bucket,hits,window_start) VALUES(?,1,NOW())
            ON DUPLICATE KEY UPDATE hits=IF(window_start <= NOW() - INTERVAL ? SECOND, 1, hits+1),
                                    window_start=IF(window_start <= NOW() - INTERVAL ? SECOND, NOW(), window_start)')
            ->execute([$bucket, $seconds, $seconds]);
        $s = $pdo->prepare('SELECT hits FROM rate_limits WHERE bucket=?');
        $s->execute([$bucket]);
        if (random_int(1, 200) === 1) $pdo->exec('DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL 1 DAY');
        return (int)$s->fetchColumn() <= $max;
    } catch (Throwable $e) {
        error_log('rate_limit: ' . $e->getMessage());
        return true;
    }
}

/* ---------- Tokens selector:validator (user_tokens) ---------- */
/** Cria um token e devolve [selector(24 hex), validator(64 hex)]. Só o hash SHA-256 do validador é guardado. */
function user_token_create(int $userId, string $type, int $ttlSeconds): array {
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO user_tokens(user_id,type,selector,token_hash,expires_at) VALUES(?,?,?,?,DATE_ADD(NOW(), INTERVAL ? SECOND))')
        ->execute([$userId, $type, $selector, hash('sha256', $validator), $ttlSeconds]);
    return [$selector, $validator];
}
/** Procura um token válido (não expirado; não usado, salvo $graceSeconds após uso). Compara o hash em tempo constante. */
function user_token_find(string $selector, string $validator, string $type, int $graceSeconds = 0): ?array {
    if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) return null;
    $s = db()->prepare('SELECT t.*, (t.used_at IS NOT NULL) AS was_used FROM user_tokens t
                        WHERE t.selector=? AND t.type=? AND t.expires_at > NOW()
                          AND (t.used_at IS NULL OR t.used_at > NOW() - INTERVAL ? SECOND) LIMIT 1');
    $s->execute([$selector, $type, $graceSeconds]);
    $row = $s->fetch();
    if (!$row || !hash_equals($row['token_hash'], hash('sha256', $validator))) return null;
    return $row;
}

/* ---------- Manter sessão iniciada (cookie agro_remember) ---------- */
const REMEMBER_COOKIE = 'agro_remember';
const REMEMBER_DAYS = 30;
function remember_cookie_path(): string { return rtrim(base_path(), '/') . '/'; }
function remember_set_cookie(string $value, int $expires): void {
    if (headers_sent() || PHP_SAPI === 'cli') return;
    setcookie(REMEMBER_COOKIE, $value, ['expires' => $expires, 'path' => remember_cookie_path(), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    if ($expires > time()) $_COOKIE[REMEMBER_COOKIE] = $value; else unset($_COOKIE[REMEMBER_COOKIE]);
}
/** Emite um novo token "remember" (30 dias) e envia o cookie. */
function remember_issue(int $userId): void {
    try {
        db()->prepare('DELETE FROM user_tokens WHERE user_id=? AND type="remember" AND (expires_at < NOW() OR used_at < NOW() - INTERVAL 1 MINUTE)')->execute([$userId]);
        [$sel, $val] = user_token_create($userId, 'remember', REMEMBER_DAYS * 86400);
        remember_set_cookie($sel . ':' . $val, time() + REMEMBER_DAYS * 86400);
    } catch (Throwable $e) { error_log('remember_issue: ' . $e->getMessage()); }
}
/** Apaga o cookie e o token correspondente (logout). */
function remember_forget_current(): void {
    $raw = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if ($raw !== '' && str_contains($raw, ':')) {
        try { db()->prepare('DELETE FROM user_tokens WHERE selector=? AND type="remember"')->execute([explode(':', $raw, 2)[0]]); } catch (Throwable $e) {}
    }
    if ($raw !== '') remember_set_cookie('', time() - 3600);
}
/** Revoga todos os tokens "manter sessão" do utilizador (alteração de password, "terminar sessão em todos os dispositivos"). */
function revoke_remember_tokens(int $userId): void {
    try { db()->prepare('DELETE FROM user_tokens WHERE user_id=? AND type="remember"')->execute([$userId]); } catch (Throwable $e) { error_log('revoke_remember_tokens: ' . $e->getMessage()); }
}
/**
 * Sem sessão mas com cookie válido: inicia sessão, roda o token (o antigo fica marcado como usado e é aceite
 * só mais 30 s, para pedidos paralelos) e regista na auditoria. Devolve o id do utilizador ou 0.
 */
function remember_login_from_cookie(): int {
    $raw = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if ($raw === '' || current_user_id()) return current_user_id();
    [$sel, $val] = array_pad(explode(':', $raw, 2), 2, '');
    try {
        $row = user_token_find($sel, $val, 'remember', 30);
        if ($row) {
            $u = db()->prepare('SELECT status FROM users WHERE id=?'); $u->execute([(int)$row['user_id']]);
            if ($u->fetchColumn() !== 'active') $row = null;
        }
        if (!$row) { remember_set_cookie('', time() - 3600); return 0; }
        $uid = (int)$row['user_id'];
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $_SESSION['user_id'] = $uid;
        if (!(int)$row['was_used']) {
            db()->prepare('UPDATE user_tokens SET used_at=NOW() WHERE id=?')->execute([(int)$row['id']]);
            remember_issue($uid);
            audit($uid, 'login', 'auth', 'Sessão restaurada com "Manter sessão iniciada"');
        }
        return $uid;
    } catch (Throwable $e) {
        error_log('remember_login_from_cookie: ' . $e->getMessage());
        return 0;
    }
}

/* ---------- Início de sessão ---------- */
function role_home_path(string $role): string {
    return $role === 'superadmin' ? 'admin/dashboard.php' : ($role === 'farmer' ? 'agricultor/dashboard.php' : 'comprador/dashboard.php');
}
/** Conclui o login (depois da password e, se ativo, do código 2FA). Devolve o URL do painel. */
function login_complete(int $userId, string $role, bool $remember, string $via = 'password'): string {
    session_regenerate_id(true);
    unset($_SESSION['pending_2fa']);
    $_SESSION['user_id'] = $userId;
    if ($remember) remember_issue($userId);
    audit($userId, 'login', 'auth', 'Utilizador iniciou sessão' . ($via === '2fa' ? ' (com 2FA)' : ''));
    return base_path(role_home_path($role));
}

/* ---------- Verificação de email ---------- */
function email_is_verified(?array $u = null): bool {
    $u = $u ?? (function_exists('user') ? user() : null);
    return $u !== null && !empty($u['email_verified_at']);
}
/** URL absoluto para links enviados por email. */
function absolute_url(string $path): string {
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return (is_https() ? 'https' : 'http') . '://' . ($host ?: 'localhost') . base_path($path);
}
/** Cria um token de verificação (48 h), coloca o email na fila e devolve o link. Em ambiente local guarda o link para o mostrar. */
function send_verification_email(int $userId): ?string {
    try {
        $s = db()->prepare('SELECT id,name,email,email_verified_at FROM users WHERE id=?'); $s->execute([$userId]); $u = $s->fetch();
        if (!$u || $u['email_verified_at']) return null;
        db()->prepare('DELETE FROM user_tokens WHERE user_id=? AND type="verify_email"')->execute([$userId]);
        [$sel, $val] = user_token_create($userId, 'verify_email', 48 * 3600);
        $link = absolute_url('verificar-email.php?s=' . $sel . '&v=' . $val);
        $body = "Olá " . $u['name'] . ",\n\nConfirme o seu email no AgroMercado abrindo este link (válido por 48 horas):\n" . $link
              . "\n\nSe não criou esta conta, ignore esta mensagem.\n\n— Equipa AgroMercado";
        if (function_exists('queue_email') && ($id = queue_email($u['email'], 'AgroMercado — confirme o seu email', $body, $userId))) deliver_email($id);
        if (is_local_env()) $_SESSION['dev_verify_link'] = $link;
        return $link;
    } catch (Throwable $e) {
        error_log('send_verification_email: ' . $e->getMessage());
        return null;
    }
}
/** Para páginas que exigem email confirmado (checkout, publicar produtos...): redireciona com aviso. */
function require_verified_email(?string $back = null): void {
    if (function_exists('require_login')) require_login();
    if (email_is_verified()) return;
    flash('warning', 'Confirme o seu email antes de continuar. Use o botão "Reenviar" no aviso no topo da página.');
    redirect($back ?? base_path(role_home_path(user()['role'] ?? 'buyer')));
}

/* ---------- 2FA: TOTP (RFC 6238) ---------- */
function base32_encode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($data) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}
function base32_decode(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[\s=-]/', '', $b32)); $bits = ''; $out = '';
    for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
        $p = strpos($alphabet, $b32[$i]);
        if ($p === false) return '';
        $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
    }
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}
/** Segredo novo de 160 bits em base32 (32 caracteres). */
function totp_generate_secret(): string { return base32_encode(random_bytes(20)); }
/** Código TOTP de 6 dígitos (HMAC-SHA1, passo de 30 s) para o instante $time. */
function totp_code(string $secret, ?int $time = null): string {
    $key = base32_decode($secret);
    $counter = intdiv($time ?? time(), 30);
    $hash = hash_hmac('sha1', pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF), $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}
/** Valida um código com tolerância de ±1 passo (±30 s). */
function totp_verify(string $secret, string $code, int $window = 1): bool {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6 || $secret === '') return false;
    $now = time(); $ok = false;
    for ($i = -$window; $i <= $window; $i++) $ok = hash_equals(totp_code($secret, $now + $i * 30), $code) || $ok;
    return $ok;
}
/** URI otpauth:// para apps autenticadoras (Google Authenticator, Microsoft Authenticator, Aegis...). */
function totp_uri(string $secret, string $account, string $issuer = 'AgroMercado'): string {
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
         . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}
/** Segredo em grupos de 4 para leitura/escrita manual. */
function totp_format_secret(string $secret): string { return trim(chunk_split($secret, 4, ' ')); }
