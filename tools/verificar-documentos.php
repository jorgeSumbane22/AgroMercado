<?php
/*
 * Verificação da validade dos documentos dos agricultores (apenas CLI).
 * Uso: php tools/verificar-documentos.php [--limit=200]
 * Agendar diariamente (cron / Agendador de Tarefas do Windows). É idempotente:
 *  - avisa uma única vez o agricultor 30 dias antes da validade;
 *  - marca como 'expired' os documentos aprovados com validade ultrapassada e avisa o agricultor;
 *  - se o único documento de identidade aprovado expirou, a conta volta a 'pending' e os produtos ativos ficam inativos;
 *  - envia um resumo aos SuperAdmins e regista tudo na auditoria.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../includes/functions.php';

$limit = 200;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) $limit = max(1, (int)$m[1]);
    elseif ($arg === '--help' || $arg === '-h') { echo "Uso: php tools/verificar-documentos.php [--limit=N]\n"; exit(0); }
}

$start = microtime(true);
try {
    $r = check_document_expiry($limit);
} catch (Throwable $e) {
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Verificação de documentos — ' . date('Y-m-d H:i:s') . PHP_EOL;
echo str_repeat('-', 48) . PHP_EOL;
printf("Avisos de validade (30 dias):   %d\n", $r['warned']);
printf("Documentos marcados expirados:  %d\n", $r['expired']);
printf("Agricultores de volta a pendente: %d\n", $r['farmers_suspended']);
printf("Produtos desativados:           %d\n", $r['products_deactivated']);
foreach ($r['details'] as $line) echo '  · ' . $line . PHP_EOL;
printf("Concluído em %.2f s.\n", microtime(true) - $start);
exit(0);
