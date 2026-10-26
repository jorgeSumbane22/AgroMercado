<?php
/**
 * Configuração inicial do SuperAdmin num alojamento (uso único).
 *
 * Quando a base de dados é importada a partir de database/hosting.sql, todas as contas de
 * demonstração ficam sem palavra-passe utilizável. Esta página define o email e a palavra-passe
 * do SuperAdmin, e só funciona quando:
 *   1. AGRO_SETUP_KEY está definida no .env (com pelo menos 24 caracteres);
 *   2. o endereço inclui ?key=<a mesma chave>;
 *   3. o SuperAdmin ainda não tem palavra-passe (depois de configurado, a página deixa de funcionar).
 * No fim, apague AGRO_SETUP_KEY do .env.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';

const SETUP_DISABLED_PREFIX = '!'; // password_verify() nunca aceita um "hash" que comece por "!"

function not_found(): never {
    http_response_code(404);
    exit('Página não encontrada.');
}
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$setupKey = (string)(getenv('AGRO_SETUP_KEY') ?: '');
if (strlen($setupKey) < 24) not_found();

$given = (string)($_POST['key'] ?? $_GET['key'] ?? '');
if (!hash_equals($setupKey, $given)) not_found();

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$admin = db()->query('SELECT id, name, email, password FROM users WHERE role_id = 1 ORDER BY id LIMIT 1')->fetch();
if (!$admin) {
    $state = 'missing';
} elseif (!str_starts_with((string)$admin['password'], SETUP_DISABLED_PREFIX)) {
    $state = 'done';
} else {
    $state = 'form';
}

$errors = [];
$success = false;
$email = (string)($_POST['email'] ?? ($admin['email'] ?? ''));
$name = (string)($_POST['name'] ?? ($admin['name'] ?? ''));

if ($state === 'form' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');
    $name = trim($name);
    $email = trim($email);

    if ($name === '' || mb_strlen($name) > 120) $errors[] = 'Indique um nome (até 120 caracteres).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160) $errors[] = 'Indique um email válido.';
    if (mb_strlen($password) < 12) $errors[] = 'A palavra-passe deve ter pelo menos 12 caracteres.';
    if (!hash_equals($password, $confirm)) $errors[] = 'As palavras-passe não coincidem.';

    if (!$errors) {
        $taken = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $taken->execute([$email, $admin['id']]);
        if ($taken->fetch()) $errors[] = 'Esse email já está a ser usado por outra conta.';
    }

    if (!$errors) {
        $upd = db()->prepare('UPDATE users SET name = ?, email = ?, password = ?, status = "active" WHERE id = ?');
        $upd->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
        $success = true;
        $state = 'done';
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Configuração inicial · AgroMercado</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px;
               font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #f4f7f2; color: #1d2b1f; }
        main { width: min(440px, 100%); background: #fff; border: 1px solid #dfe7da; border-radius: 16px; padding: 28px; box-shadow: 0 10px 30px -18px rgba(0,0,0,.35); }
        h1 { font-size: 1.35rem; margin: 0 0 6px; }
        p { color: #4c5d4f; line-height: 1.5; }
        label { display: block; font-weight: 600; font-size: .9rem; margin: 14px 0 6px; }
        input { width: 100%; box-sizing: border-box; padding: 11px 12px; border: 1.5px solid #cfdac9; border-radius: 10px; font-size: 1rem; }
        input:focus { outline: none; border-color: #2e7d32; box-shadow: 0 0 0 3px rgba(46,125,50,.15); }
        button, .btn { display: inline-block; margin-top: 20px; width: 100%; padding: 12px; border: 0; border-radius: 10px;
                       background: #2e7d32; color: #fff; font-size: 1rem; font-weight: 600; text-align: center; text-decoration: none; cursor: pointer; }
        .error { background: #fdecea; color: #a12622; border-radius: 10px; padding: 10px 12px; margin-top: 14px; }
        .ok { background: #e8f5e9; color: #1b5e20; border-radius: 10px; padding: 12px; margin-top: 14px; }
        small { color: #6b7b6d; }
    </style>
</head>
<body>
<main>
<?php if ($state === 'missing'): ?>
    <h1>Sem conta SuperAdmin</h1>
    <p>A base de dados não tem nenhuma conta SuperAdmin. Importe <code>database/hosting.sql</code> e tente de novo.</p>
<?php elseif ($state === 'done'): ?>
    <h1><?= $success ? 'Configuração concluída' : 'Configuração já concluída' ?></h1>
    <div class="ok">
        <?php if ($success): ?>
            A conta SuperAdmin foi configurada com o email <strong><?= h($email) ?></strong>.
        <?php else: ?>
            O SuperAdmin já tem palavra-passe definida, por isso esta página está desativada.
        <?php endif; ?>
    </div>
    <p><strong>Importante:</strong> apague a linha <code>AGRO_SETUP_KEY</code> do ficheiro <code>.env</code> no servidor.</p>
    <a class="btn" href="<?= h(base_path('login.php')) ?>">Ir para o login</a>
<?php else: ?>
    <h1>Configuração inicial</h1>
    <p>Defina os dados de acesso da conta SuperAdmin. As contas de demonstração ficam sem acesso.</p>
    <?php foreach ($errors as $e): ?><div class="error"><?= h($e) ?></div><?php endforeach; ?>
    <form method="post" autocomplete="off">
        <input type="hidden" name="key" value="<?= h($given) ?>">
        <label for="name">Nome</label>
        <input id="name" name="name" value="<?= h($name) ?>" required maxlength="120">
        <label for="email">Email (usado no login e na recuperação da palavra-passe)</label>
        <input id="email" name="email" type="email" value="<?= h($email) ?>" required maxlength="160">
        <label for="password">Palavra-passe</label>
        <input id="password" name="password" type="password" required minlength="12" autocomplete="new-password">
        <small>Mínimo de 12 caracteres.</small>
        <label for="password_confirm">Confirmar palavra-passe</label>
        <input id="password_confirm" name="password_confirm" type="password" required minlength="12" autocomplete="new-password">
        <button type="submit">Guardar</button>
    </form>
<?php endif; ?>
</main>
</body>
</html>
