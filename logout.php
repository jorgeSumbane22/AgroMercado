<?php
require_once __DIR__.'/includes/functions.php';

// ============================================
// REGISTRA AUDITORIA (SE USUÁRIO LOGADO)
// ============================================
if (current_user_id()) {
    audit(
        current_user_id(),
        'logout',
        'auth',
        'Utilizador terminou sessão'
    );
}

// ============================================
// LIMPA VARIÁVEIS DE SESSÃO
// ============================================
$_SESSION = [];

// ============================================
// REMOVE COOKIE DE SESSÃO
// ============================================
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// ============================================
// DESTROI A SESSÃO NO SERVIDOR
// ============================================
session_destroy();

// ============================================
// REDIRECIONA PARA PÁGINA INICIAL
// ============================================
header('Location: ' . base_path('index.php'));
exit;
?>