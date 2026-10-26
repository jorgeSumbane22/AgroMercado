<?php
/**
 * Carrega <projeto>/.env (linhas KEY=VALUE, comentários com #, aspas opcionais) para getenv()/$_ENV.
 * Variáveis já definidas no ambiente real (Apache SetEnv, sistema) nunca são substituídas.
 */
function load_env_file(string $file): void {
    if (!is_file($file) || !is_readable($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (str_starts_with($line, 'export ')) $line = trim(substr($line, 7));
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) continue;
        $value = trim(substr($line, $pos + 1));
        $q = $value[0] ?? '';
        if (($q === '"' || $q === "'") && strlen($value) >= 2 && substr($value, -1) === $q) {
            $value = substr($value, 1, -1);
            if ($q === '"') $value = strtr($value, ['\\n' => "\n", '\\"' => '"', '\\\\' => '\\']);
        } else {
            $value = trim(preg_replace('/\s+#.*$/', '', $value)); // comentário no fim da linha (sem aspas)
        }
        if (getenv($key) !== false) continue; // o ambiente real tem prioridade
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}
load_env_file(dirname(__DIR__) . '/.env');

function is_https(): bool {
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    // Alojamentos partilhados (ex.: InfinityFree) terminam o HTTPS num proxy e avisam por estes cabeçalhos
    return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on';
}

// Erros nunca são mostrados ao visitante; ficam no log do PHP. Defina APP_DEBUG=1 para os ver em desenvolvimento.
ini_set('display_errors', getenv('APP_DEBUG') === '1' ? '1' : '0');
ini_set('log_errors', '1');
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_lifetime', '0');      // cookie de sessão termina ao fechar o browser
    ini_set('session.gc_maxlifetime', '28800');   // 8 h de inatividade; "Manter sessão iniciada" usa token próprio (user_tokens)
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', is_https() ? '1' : '0');
    session_start();
} elseif (PHP_SAPI === 'cli' && !isset($_SESSION)) {
    $_SESSION = []; // ferramentas de linha de comando (tools/*.php) não usam sessão
}
if (!headers_sent() && PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}
/** Caminho base da aplicação: AGRO_BASE_PATH (por omissão /agro-marketplace; vazio = raiz do domínio). */
function base_path(string $path = ''): string {
    static $base = null;
    if ($base === null) {
        $env = getenv('AGRO_BASE_PATH');
        $base = $env === false ? '/agro-marketplace' : rtrim(trim($env), '/');
        if ($base !== '' && $base[0] !== '/') $base = '/' . $base;
    }
    return $base . ($path !== '' ? '/' . ltrim($path, '/') : '');
}
function asset(string $path): string { return base_path('assets/' . ltrim($path, '/')); }
function upload_url(string $path): string { return base_path('uploads/' . ltrim($path, '/')); }
?>
