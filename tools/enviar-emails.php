<?php
/**
 * Worker da fila de email (email_outbox). Uso: php tools/enviar-emails.php [limite]
 * Agende-o (Agendador de Tarefas / cron) a cada 1–5 minutos quando o SMTP estiver configurado.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/functions.php';

$limit = isset($argv[1]) ? max(1, (int)$argv[1]) : 50;
if (!mail_configured()) echo "Aviso: SMTP não configurado ou MAIL_ENABLED=0 — os emails em fila ficam marcados como 'logged'.\n";
$stats = send_queued_emails($limit);
printf("[%s] Enviados: %d · Falhados: %d · Só registo: %d\n", date('Y-m-d H:i:s'), $stats['sent'], $stats['failed'], $stats['logged']);
exit($stats['failed'] > 0 ? 1 : 0);
