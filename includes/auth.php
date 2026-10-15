<?php
require_once __DIR__ . '/functions.php';
function user(): ?array {
    static $loaded = false; static $value = null;
    if ($loaded) return $value; $loaded = true;
    if (!current_user_id()) return null;
    $s=db()->prepare('SELECT u.*,r.name role,f.id farmer_id,f.verification_status,f.province,b.id buyer_id FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN farmers f ON f.user_id=u.id LEFT JOIN buyers b ON b.user_id=u.id WHERE u.id=?');
    $s->execute([current_user_id()]); $value=$s->fetch() ?: null;
    // Conta bloqueada ou desativada perde a sessão no pedido seguinte.
    if ($value && $value['status'] !== 'active') { unset($_SESSION['user_id']); session_regenerate_id(true); $value = null; }
    return $value;
}
function logged_in(): bool { return user() !== null; }
function require_login(): void { if (!logged_in()) { flash('warning','Faça login para continuar.'); redirect(base_path('login.php')); } }
function require_role(string ...$roles): void { require_login(); if (!in_array(user()['role'],$roles,true)) { http_response_code(403); include __DIR__.'/../403.php'; exit; } }
?>
