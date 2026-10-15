<?php
require_once __DIR__.'/includes/functions.php';
$page_title='Recuperar password';
$message=null; $reset_link=null;
unset($_SESSION['dev_reset_link']);
if(is_post()) {
    verify_csrf($_POST['csrf']??'');
    $email=strtolower(trim((string)($_POST['email']??'')));
    // Limite por IP (10 pedidos/hora), além do limite por conta.
    if(filter_var($email,FILTER_VALIDATE_EMAIL) && rate_limit('pwreset:'.client_ip(),10,3600)) {
        $s=db()->prepare('SELECT id FROM users WHERE email=? AND status="active" LIMIT 1'); $s->execute([$email]); $userId=$s->fetchColumn();
        if($userId) {
            // Limite: no máximo 3 pedidos de recuperação por conta por hora.
            $c=db()->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id=? AND created_at >= (NOW() - INTERVAL 1 HOUR)'); $c->execute([(int)$userId]);
            if((int)$c->fetchColumn() < 3) {
                $raw=bin2hex(random_bytes(32)); $hash=hash('sha256',$raw);
                db()->prepare('UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([(int)$userId]);
                db()->prepare('INSERT INTO password_resets(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 60 MINUTE))')->execute([(int)$userId,$hash]);
                $link=absolute_url('redefinir-password.php?token='.$raw);
                $subject='AgroMercado — recuperação de password';
                $body="Abra este link para redefinir a sua password (válido por 60 minutos): ".$link;
                // Fila de email: enviado por SMTP quando configurado; caso contrário fica registado em admin/emails.php.
                if($mailId=queue_email($email,$subject,$body,(int)$userId)) deliver_email_later($mailId);
                audit((int)$userId,'password_reset_request','auth','Pedido de recuperação de password');
                // Ajuda de desenvolvimento: o link só é mostrado em ambiente local, nunca em produção.
                if(is_local_env()) $reset_link=$link;
            }
        }
    }
    // Mensagem genérica: não revela se o email existe nem se o limite foi atingido.
    $message='Se o email estiver registado, receberá instruções para redefinir a password.';
}
include __DIR__.'/includes/header.php';
?><section class="auth-section"><div class="auth-card"><div class="text-center mb-4"><div class="auth-logo"><i class="bi bi-key"></i></div><h1>Recuperar password</h1><p class="text-muted">Indique o email da sua conta.</p></div><?php if($message): ?><div class="alert alert-success" role="status"><i class="bi bi-check-circle me-2"></i><?=e($message)?></div><?php if($reset_link && is_local_env()): ?><div class="alert alert-warning small"><strong>Ambiente local (apenas desenvolvimento):</strong><br><a href="<?=e($reset_link)?>"><?=e($reset_link)?></a></div><?php endif; ?><?php endif; ?><form method="post"><?=csrf_field()?><label class="visually-hidden" for="email">Email</label><input id="email" type="email" name="email" class="form-control form-control-lg mb-3" placeholder="seu@email.com" required autocomplete="email"><button class="btn btn-primary btn-lg w-100">Enviar instruções <i class="bi bi-send ms-2"></i></button></form><p class="text-center mt-4 mb-0"><a href="<?=base_path('login.php')?>">Voltar ao login</a></p></div></section><?php include __DIR__.'/includes/footer.php'; ?>
