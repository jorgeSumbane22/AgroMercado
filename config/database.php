<?php
require_once __DIR__ . '/config.php';
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $host = getenv('AGRO_DB_HOST') ?: '127.0.0.1';
    $name = getenv('AGRO_DB_NAME') ?: 'agro_marketplace';
    $user = getenv('AGRO_DB_USER') ?: 'root';
    $pass = getenv('AGRO_DB_PASS') ?: '';
    try {
        $pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            // Modo estrito: valores inválidos (ENUM, NOT NULL) geram erro em vez de serem gravados vazios.
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(503);
        exit('<div style="font-family:Arial;padding:40px;max-width:700px;margin:auto"><h1>Base de dados indisponível</h1><p>Importe <code>database/schema.sql</code> e depois <code>database/patch_2026_10.sql</code> no MySQL do XAMPP.</p></div>');
    }
}
?>
