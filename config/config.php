<?php
// Erros nunca são mostrados ao visitante; ficam no log do PHP. Defina APP_DEBUG=1 para os ver em desenvolvimento.
ini_set('display_errors', getenv('APP_DEBUG') === '1' ? '1' : '0');
ini_set('log_errors', '1');
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '2592000'); // 30 dias, para "Manter sessão iniciada"
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
    session_start();
}
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}
function base_path(string $path = ''): string {
    $base = '/agro-marketplace';
    return $base . ($path !== '' ? '/' . ltrim($path, '/') : '');
}
function asset(string $path): string { return base_path('assets/' . ltrim($path, '/')); }
function upload_url(string $path): string { return base_path('uploads/' . ltrim($path, '/')); }
?>
