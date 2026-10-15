<?php
require_once __DIR__.'/../includes/permissions.php';
/* Pré-visualização de cupão no carrinho/checkout. O desconto é sempre recalculado no checkout (dentro da transação). */
$data=api_guard('buyer');$u=user();$action=(string)($data['action']??'apply');
if($action==='remove'){unset($_SESSION['coupon_code']);json_out(['success'=>true,'message'=>'Cupão removido.','discount'=>0,'code'=>'']);}
if($action!=='apply')json_out(['success'=>false,'message'=>'Ação inválida.'],422);
$code=coupon_normalize((string)($data['code']??''));
$s=db()->prepare('SELECT COALESCE(SUM(ci.quantity*p.price),0) FROM cart_items ci JOIN carts c ON c.id=ci.cart_id JOIN products p ON p.id=ci.product_id WHERE c.buyer_id=?');$s->execute([(int)$u['buyer_id']]);$subtotal=round((float)$s->fetchColumn(),2);
if($subtotal<=0)json_out(['success'=>false,'message'=>'O carrinho está vazio.'],422);
$v=validate_coupon($code,$subtotal,(int)$u['id']);
if(is_string($v)){unset($_SESSION['coupon_code']);json_out(['success'=>false,'message'=>$v],422);}
[$coupon,$discount]=$v;$_SESSION['coupon_code']=$coupon['code'];
json_out(['success'=>true,'message'=>'Cupão '.$coupon['code'].' aplicado: −'.money($discount).'.','code'=>$coupon['code'],'discount'=>$discount,'subtotal'=>$subtotal,'total_before_delivery'=>round($subtotal-$discount,2)]);
