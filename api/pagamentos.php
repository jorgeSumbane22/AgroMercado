<?php
require_once __DIR__.'/../includes/permissions.php';
header('Content-Type: application/json; charset=utf-8');
if(!is_post()){header('Allow: POST');json_out(['success'=>false,'message'=>'Método não permitido'],405);}
$statuses=['paid','failed','refunded','pending'];
/** Aplica um novo estado a um pagamento (linha já bloqueada) e propaga para o pedido. */
function apply_payment_status(PDO $pdo,array $pay,string $status): void {
    $pdo->prepare('UPDATE payments SET status=?,paid_at=CASE WHEN ?="paid" THEN COALESCE(paid_at,NOW()) ELSE paid_at END WHERE id=?')->execute([$status,$status,(int)$pay['id']]);
    sync_order_payment_status($pdo,(int)$pay['order_id']);
    if($status!==$pay['status']){$n=$pdo->prepare('SELECT order_number FROM orders WHERE id=?');$n->execute([(int)$pay['order_id']]);$num=(string)$n->fetchColumn();
        notify_buyer_of_order((int)$pay['order_id'],$status==='paid'?'Pagamento confirmado':'Pagamento atualizado','O pagamento do pedido '.$num.' está agora: '.payment_status_label($status).'.');}
}
if(isset($_GET['webhook'])) {
    $provider=substr(preg_replace('/[^a-z0-9_-]/i','',(string)($_GET['provider']??'unknown')),0,40);$raw=(string)file_get_contents('php://input');$signature=(string)($_SERVER['HTTP_X_PAYMENT_SIGNATURE']??'');$secret=(string)setting('payment_webhook_secret','');
    // Fail-closed: sem segredo configurado nenhum webhook é aceite.
    $valid=$secret!==''&&$signature!==''&&hash_equals(hash_hmac('sha256',$raw,$secret),$signature);
    if(!$valid)json_out(['success'=>false,'message'=>'Webhook não autorizado'],401);
    $payload=json_decode($raw,true);if(!is_array($payload))json_out(['success'=>false,'message'=>'Payload inválido'],422);
    $external=substr((string)($payload['id']??$payload['transaction_id']??''),0,160);$reference=substr((string)($payload['reference']??''),0,100);$status=(string)($payload['status']??'');
    if($external===''||$reference===''||!in_array($status,$statuses,true))json_out(['success'=>false,'message'=>'Campos obrigatórios em falta ou inválidos'],422);
    // Proteção contra replay: eventos com mais de 10 minutos são rejeitados.
    $ts=$payload['timestamp']??$payload['created_at']??$payload['time']??null;
    if($ts!==null){$t=is_numeric($ts)?(int)$ts:strtotime((string)$ts);if(!$t||$t<time()-600||$t>time()+300)json_out(['success'=>false,'message'=>'Evento expirado ou com data inválida'],422);}
    $pdo=db();
    try{$pdo->beginTransaction();
        $s=$pdo->prepare('SELECT id,order_id,amount,status FROM payments WHERE reference=? FOR UPDATE');$s->execute([$reference]);$pay=$s->fetch();
        if(!$pay){$pdo->rollBack();json_out(['success'=>false,'message'=>'Pagamento não encontrado'],404);}
        if($status==='paid'||isset($payload['amount'])){if(!isset($payload['amount'])||!is_numeric($payload['amount'])||abs((float)$payload['amount']-(float)$pay['amount'])>0.009){$pdo->rollBack();json_out(['success'=>false,'message'=>'Montante não corresponde ao pagamento'],422);}}
        $pdo->prepare('INSERT INTO payment_webhooks(provider,external_id,event_type,signature_valid,payload_json,processed_at) VALUES(?,?,?,?,?,NOW())')->execute([$provider,$external,substr((string)($payload['event']??'payment.updated'),0,80),1,$raw]);
        apply_payment_status($pdo,$pay,$status);
        $pdo->commit();json_out(['success'=>true]);
    }catch(PDOException $e){if($pdo->inTransaction())$pdo->rollBack();if($e->getCode()==='23000')json_out(['success'=>false,'message'=>'Evento já processado'],409);error_log('webhook: '.$e->getMessage());json_out(['success'=>false,'message'=>'Erro ao processar evento'],500);}
}
$data=api_guard('superadmin');$id=safe_int($data['payment_id']??0);$status=(string)($data['status']??'');if($id<=0||!in_array($status,$statuses,true))json_out(['success'=>false,'message'=>'Dados de pagamento inválidos'],422);
$pdo=db();try{$pdo->beginTransaction();$s=$pdo->prepare('SELECT id,order_id,amount,status FROM payments WHERE id=? FOR UPDATE');$s->execute([$id]);$pay=$s->fetch();if(!$pay){$pdo->rollBack();json_out(['success'=>false,'message'=>'Pagamento não encontrado'],404);}apply_payment_status($pdo,$pay,$status);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('api/pagamentos: '.$e->getMessage());json_out(['success'=>false,'message'=>'Não foi possível atualizar o pagamento'],500);}
audit(current_user_id(),'update','payments','Pagamento #'.$id.' atualizado para '.$status);json_out(['success'=>true,'message'=>'Pagamento atualizado']);
