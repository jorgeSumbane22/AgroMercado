<?php
/* Email: fila (email_outbox), envio SMTP e notificações por email.
   Incluído no fim de functions.php: aqui só se definem funções (nada corre ao incluir).
   Configuração: SMTP_HOST/PORT/USER/PASS/SECURE, MAIL_FROM, MAIL_ENABLED no .env têm prioridade sobre app_settings. */

/** Configuração SMTP efetiva. */
function mail_config(): array {
    return [
        'enabled' => config_value('MAIL_ENABLED', 'mail_enabled', '0') === '1',
        'host'    => trim((string)config_value('SMTP_HOST', 'smtp_host', '')),
        'port'    => (int)(config_value('SMTP_PORT', 'smtp_port', '587') ?: 587),
        'user'    => (string)config_value('SMTP_USER', 'smtp_user', ''),
        'pass'    => (string)config_value('SMTP_PASS', 'smtp_pass', ''),
        'secure'  => strtolower((string)config_value('SMTP_SECURE', 'smtp_secure', 'tls')),
        'from'    => (string)config_value('MAIL_FROM', 'mail_from', 'no-reply@agromercado.local'),
        'name'    => (string)setting('app_name', 'AgroMercado'),
    ];
}
/** true quando o envio real está ativo (MAIL_ENABLED=1 e servidor SMTP definido). */
function mail_configured(): bool { $c = mail_config(); return $c['enabled'] && $c['host'] !== ''; }

/** Coloca um email na fila e devolve o id (0 se inválido/erro). Nunca lança exceções. */
function queue_email(string $to, string $subject, string $body, ?int $userId = null): int {
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) return 0;
    $subject = mb_substr(trim(preg_replace('/[\r\n]+/', ' ', $subject)), 0, 200);
    try {
        db()->prepare('INSERT INTO email_outbox(user_id,to_email,subject,body,status) VALUES(?,?,?,?,"queued")')->execute([$userId ?: null, $to, $subject, $body]);
        return (int)db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('queue_email: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Entrega um email da fila: sem SMTP configurado fica 'logged' (visível em admin/emails.php);
 * com SMTP fica 'sent' ou 'failed' (com last_error). Devolve true se enviado ou registado. Nunca lança exceções.
 */
function deliver_email(int $id): bool {
    try {
        $s = db()->prepare('SELECT * FROM email_outbox WHERE id=?'); $s->execute([$id]); $row = $s->fetch();
        if (!$row || $row['status'] === 'sent') return (bool)$row;
        $cfg = mail_config();
        if (!$cfg['enabled'] || $cfg['host'] === '') {
            db()->prepare('UPDATE email_outbox SET status="logged", last_error=? WHERE id=?')
                ->execute([$cfg['enabled'] ? 'Servidor SMTP não configurado.' : 'Envio de email desativado (MAIL_ENABLED=0).', $id]);
            return true;
        }
        try {
            smtp_send($cfg, $row['to_email'], $row['subject'], $row['body']);
            db()->prepare('UPDATE email_outbox SET status="sent", attempts=attempts+1, last_error=NULL, sent_at=NOW() WHERE id=?')->execute([$id]);
            return true;
        } catch (Throwable $e) {
            db()->prepare('UPDATE email_outbox SET status="failed", attempts=attempts+1, last_error=? WHERE id=?')->execute([mb_substr($e->getMessage(), 0, 1000), $id]);
            error_log('deliver_email #' . $id . ': ' . $e->getMessage());
            return false;
        }
    } catch (Throwable $e) {
        error_log('deliver_email: ' . $e->getMessage());
        return false;
    }
}

/** Envia os emails em fila (e os falhados com menos de 3 tentativas). Devolve ['sent'=>n,'failed'=>n,'logged'=>n]. */
function send_queued_emails(int $limit = 20): array {
    $stats = ['sent' => 0, 'failed' => 0, 'logged' => 0];
    $s = db()->prepare('SELECT id FROM email_outbox WHERE status="queued" OR (status="failed" AND attempts < 3) ORDER BY id LIMIT ?');
    $s->bindValue(1, max(1, $limit), PDO::PARAM_INT); $s->execute();
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
        deliver_email((int)$id);
        $st = db()->prepare('SELECT status FROM email_outbox WHERE id=?'); $st->execute([$id]);
        $status = (string)$st->fetchColumn();
        if (isset($stats[$status])) $stats[$status]++;
    }
    return $stats;
}

/** Entrega no fim do pedido (depois de commits) os emails colocados na fila durante a página. */
function deliver_email_later(int $id): void {
    static $pending = null;
    if ($pending === null) {
        $pending = [];
        register_shutdown_function(function () use (&$pending) { foreach ($pending as $pid) deliver_email($pid); });
    }
    $pending[] = $id;
}

/** Notificação por email (chamada por notify()): respeita preferências e só envia a emails confirmados. */
function notify_by_email(int $userId, string $title, string $body): void {
    try {
        $s = db()->prepare('SELECT u.name,u.email,u.status,u.email_verified_at, COALESCE(np.email_enabled,1) email_enabled, COALESCE(np.order_updates,1) order_updates
                            FROM users u LEFT JOIN notification_preferences np ON np.user_id=u.id WHERE u.id=?');
        $s->execute([$userId]); $u = $s->fetch();
        if (!$u || $u['status'] !== 'active' || empty($u['email_verified_at']) || !(int)$u['email_enabled']) return;
        if (!(int)$u['order_updates'] && preg_match('/pedido|encomenda|entrega|pagamento/iu', $title)) return;
        $text = "Olá " . $u['name'] . ",\n\n" . $body . "\n\nAceda à sua conta: " . absolute_url('login.php')
              . "\n\nPode desativar estes emails nas preferências de notificação do seu perfil.\n— AgroMercado";
        if ($id = queue_email($u['email'], 'AgroMercado — ' . $title, $text, $userId)) deliver_email_later($id);
    } catch (Throwable $e) {
        error_log('notify_by_email: ' . $e->getMessage());
    }
}

/* ---------- Cliente SMTP mínimo ---------- */
function smtp_encode_header(string $text): string {
    if (!preg_match('/[^\x20-\x7E]/', $text)) return $text;
    $out = []; // pedaços de até 45 bytes sem partir caracteres UTF-8
    foreach (mb_str_split($text, 1, 'UTF-8') as $ch) {
        $last = count($out) - 1;
        if ($last < 0 || strlen($out[$last] . $ch) > 45) $out[] = $ch; else $out[$last] .= $ch;
    }
    return implode("\r\n ", array_map(fn($p) => '=?UTF-8?B?' . base64_encode($p) . '?=', $out));
}
/** Lê uma resposta SMTP (multi-linha) e valida o código esperado. */
function smtp_expect($fp, array $codes): string {
    $data = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $data .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') break;
    }
    $meta = stream_get_meta_data($fp);
    if (!empty($meta['timed_out'])) throw new RuntimeException('SMTP: tempo de espera esgotado.');
    $code = (int)substr($data, 0, 3);
    if (!in_array($code, $codes, true)) throw new RuntimeException('SMTP: resposta inesperada: ' . trim($data !== '' ? $data : 'ligação fechada'));
    return $data;
}
function smtp_cmd($fp, string $cmd, array $codes): string {
    fwrite($fp, $cmd . "\r\n");
    return smtp_expect($fp, $codes);
}
/** Envia um email de texto (UTF-8, base64). Lança RuntimeException em caso de erro. */
function smtp_send(array $cfg, string $to, string $subject, string $body): void {
    $from = $cfg['from'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Endereço de email inválido.');
    $secure = in_array($cfg['secure'], ['tls', 'ssl'], true) ? $cfg['secure'] : '';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $cfg['host'], 'SNI_enabled' => true]]);
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . (int)$cfg['port'];
    $fp = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp && $errstr !== '' && !mb_check_encoding($errstr, 'UTF-8')) $errstr = mb_convert_encoding($errstr, 'UTF-8', 'Windows-1252'); // mensagens do Windows
    if (!$fp) throw new RuntimeException('SMTP: não foi possível ligar a ' . $cfg['host'] . ':' . $cfg['port'] . ' (' . ($errstr ?: 'erro ' . $errno) . ').');
    stream_set_timeout($fp, 10);
    try {
        $domain = substr(strrchr($from, '@'), 1) ?: 'localhost';
        smtp_expect($fp, [220]);
        smtp_cmd($fp, 'EHLO ' . $domain, [250]);
        if ($secure === 'tls') {
            smtp_cmd($fp, 'STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('SMTP: falha ao iniciar TLS.');
            smtp_cmd($fp, 'EHLO ' . $domain, [250]);
        }
        if ($cfg['user'] !== '') {
            smtp_cmd($fp, 'AUTH LOGIN', [334]);
            smtp_cmd($fp, base64_encode($cfg['user']), [334]);
            smtp_cmd($fp, base64_encode($cfg['pass']), [235]);
        }
        smtp_cmd($fp, 'MAIL FROM:<' . $from . '>', [250]);
        smtp_cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtp_cmd($fp, 'DATA', [354]);
        $headers = [
            'Date: ' . date('r'),
            'From: ' . smtp_encode_header($cfg['name'] ?: 'AgroMercado') . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . smtp_encode_header(preg_replace('/[\r\n]+/', ' ', $subject)),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $message = implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $body))), 76, "\r\n"));
        $message = preg_replace('/^\./m', '..', $message); // dot-stuffing
        fwrite($fp, $message . "\r\n.\r\n");
        smtp_expect($fp, [250]);
        try { smtp_cmd($fp, 'QUIT', [221]); } catch (Throwable $e) {}
    } finally {
        fclose($fp);
    }
}
