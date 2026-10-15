<?php
require_once __DIR__.'/../includes/permissions.php';
/* Mensagens comprador ↔ agricultor sobre um pedido. Um fio por (order_id, farmer_id). */
$data=api_guard('buyer','farmer');$u=user();$pdo=db();
$orderId=safe_int($data['order_id']??0);$body=trim(str_replace("\r\n","\n",(string)($data['body']??'')));
if($body==='')json_out(['success'=>false,'message'=>'Escreva uma mensagem.'],422);
if(mb_strlen($body)>2000)json_out(['success'=>false,'message'=>'A mensagem pode ter no máximo 2000 caracteres.'],422);
$s=$pdo->prepare('SELECT o.id,o.order_number,b.user_id buyer_user_id FROM orders o JOIN buyers b ON b.id=o.buyer_id WHERE o.id=?');$s->execute([$orderId]);$o=$s->fetch();
if(!$o)json_out(['success'=>false,'message'=>'Pedido não encontrado.'],404);
if($u['role']==='buyer'){
    $farmerId=safe_int($data['farmer_id']??0);
    if((int)$o['buyer_user_id']!==(int)$u['id'])json_out(['success'=>false,'message'=>'Sem permissão para este pedido.'],403);
}else{$farmerId=(int)$u['farmer_id'];}
$s=$pdo->prepare('SELECT f.user_id FROM farmers f WHERE f.id=? AND EXISTS(SELECT 1 FROM order_items oi WHERE oi.order_id=? AND oi.farmer_id=f.id)');$s->execute([$farmerId,$orderId]);$farmerUser=$s->fetchColumn();
if(!$farmerId||!$farmerUser)json_out(['success'=>false,'message'=>'Sem permissão para este pedido.'],403);
// Limite simples contra abuso: 20 mensagens por minuto por utilizador.
$s=$pdo->prepare('SELECT COUNT(*) FROM order_messages WHERE sender_id=? AND created_at>=NOW()-INTERVAL 1 MINUTE');$s->execute([(int)$u['id']]);if((int)$s->fetchColumn()>=20)json_out(['success'=>false,'message'=>'Demasiadas mensagens. Aguarde um momento.'],429);
try{
    $pdo->prepare('INSERT INTO order_messages(order_id,farmer_id,sender_id,body) VALUES(?,?,?,?)')->execute([$orderId,$farmerId,(int)$u['id'],$body]);
    $recipient=$u['role']==='buyer'?(int)$farmerUser:(int)$o['buyer_user_id'];
    notify($recipient,'Nova mensagem sobre o pedido '.$o['order_number'],$u['name'].': '.mb_strimwidth($body,0,160,'…'));
}catch(Throwable $e){error_log('api/mensagens: '.$e->getMessage());json_out(['success'=>false,'message'=>'Não foi possível enviar a mensagem.'],500);}
json_out(['success'=>true,'message'=>'Mensagem enviada.']);
