<?php
require_once __DIR__.'/../includes/auth.php';
$data=api_guard('buyer');
$productId=safe_int($data['product_id']??0);$orderId=safe_int($data['order_id']??0);$rating=filter_var($data['rating']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>5]]);$comment=trim((string)($data['comment']??''));
if($productId<=0||$orderId<=0)json_out(['success'=>false,'message'=>'Pedido ou produto inválido.'],422);
if($rating===false)json_out(['success'=>false,'message'=>'Escolha uma nota entre 1 e 5.'],422);
if(mb_strlen($comment)>1000)json_out(['success'=>false,'message'=>'O comentário pode ter no máximo 1000 caracteres.'],422);
// O pedido tem de pertencer ao comprador, conter o produto e estar entregue (o pedido ou o próprio item).
$s=db()->prepare('SELECT o.status order_status,oi.status item_status FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE o.id=? AND o.buyer_id=? AND oi.product_id=? LIMIT 1');$s->execute([$orderId,(int)user()['buyer_id'],$productId]);$row=$s->fetch();
if(!$row)json_out(['success'=>false,'message'=>'Este produto não pertence a um dos seus pedidos.'],422);
if($row['order_status']!=='delivered'&&$row['item_status']!=='delivered')json_out(['success'=>false,'message'=>'Só pode avaliar produtos de pedidos entregues.'],422);
try{db()->prepare('INSERT INTO reviews(user_id,product_id,order_id,rating,comment) VALUES(?,?,?,?,?)')->execute([(int)user()['id'],$productId,$orderId,$rating,$comment!==''?$comment:null]);audit((int)user()['id'],'create','reviews','Avaliação do produto #'.$productId.' no pedido #'.$orderId);json_out(['success'=>true,'message'=>'Avaliação guardada. Obrigado!']);}
catch(PDOException $e){if($e->getCode()==='23000')json_out(['success'=>false,'message'=>'Já avaliou este produto neste pedido.'],422);error_log('api/avaliacoes: '.$e->getMessage());json_out(['success'=>false,'message'=>'Não foi possível guardar a avaliação.'],500);}
