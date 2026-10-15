<?php
require_once __DIR__.'/../includes/auth.php';
$data=api_guard('buyer');
$action=(string)($data['action']??'');$pdo=db();$buyerId=(int)user()['buyer_id'];
$pid=0;if($action!=='clear'){$raw=$data['product_id']??null;if(!is_numeric($raw)||filter_var($raw,FILTER_VALIDATE_INT)===false||(int)$raw<=0)json_out(['success'=>false,'message'=>'Produto inválido.'],422);$pid=(int)$raw;}
$c=$pdo->prepare('SELECT id FROM carts WHERE buyer_id=?');$c->execute([$buyerId]);$cartId=$c->fetchColumn();if(!$cartId){$pdo->prepare('INSERT INTO carts(buyer_id) VALUES(?)')->execute([$buyerId]);$cartId=$pdo->lastInsertId();}
$fmt=fn($v)=>rtrim(rtrim(number_format((float)$v,2,'.',''),'0'),'.');
try{
  if($action==='add'){$rawQ=$data['quantity']??1;if(!is_numeric($rawQ)||(float)$rawQ<=0)throw new RuntimeException('Indique uma quantidade maior que zero.');$q=round((float)$rawQ,2);
    $p=$pdo->prepare('SELECT stock,status,quantity_min,unit FROM products WHERE id=?');$p->execute([$pid]);$prod=$p->fetch();if(!$prod||$prod['status']!=='active')throw new RuntimeException('Produto indisponível.');
    $s=$pdo->prepare('SELECT quantity FROM cart_items WHERE cart_id=? AND product_id=?');$s->execute([$cartId,$pid]);$inCart=(float)$s->fetchColumn();$new=$inCart+$q;
    if($new<(float)$prod['quantity_min'])throw new RuntimeException('A quantidade mínima é '.$fmt($prod['quantity_min']).' '.$prod['unit'].'.');
    if($new>(float)$prod['stock'])throw new RuntimeException('Quantidade indisponível. Stock atual: '.$fmt($prod['stock']).' '.$prod['unit'].($inCart>0?' (já tem '.$fmt($inCart).' no carrinho).':'.'));
    $pdo->prepare('INSERT INTO cart_items(cart_id,product_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)')->execute([$cartId,$pid,$new]);$message='Produto adicionado ao carrinho';}
  elseif(in_array($action,['increase','decrease'],true)){$s=$pdo->prepare('SELECT ci.quantity,p.stock,p.quantity_min,p.unit,p.status FROM cart_items ci JOIN products p ON p.id=ci.product_id WHERE ci.cart_id=? AND ci.product_id=?');$s->execute([$cartId,$pid]);$row=$s->fetch();if(!$row)throw new RuntimeException('Produto não está no carrinho.');
    $old=(float)$row['quantity'];$min=max(0.01,(float)$row['quantity_min']);
    if($action==='increase'){if($row['status']!=='active')throw new RuntimeException('Produto indisponível.');$new=min($old+1,(float)$row['stock']);if($new<=$old)throw new RuntimeException('Stock máximo atingido ('.$fmt($row['stock']).' '.$row['unit'].').');$new=max($new,$min);}
    else{$new=$old-1;if($new<$min&&$old>$min)$new=$min;}
    if($action==='decrease'&&$new<$min)$pdo->prepare('DELETE FROM cart_items WHERE cart_id=? AND product_id=?')->execute([$cartId,$pid]);else $pdo->prepare('UPDATE cart_items SET quantity=? WHERE cart_id=? AND product_id=?')->execute([$new,$cartId,$pid]);$message='Carrinho atualizado';}
  elseif($action==='remove'){$pdo->prepare('DELETE FROM cart_items WHERE cart_id=? AND product_id=?')->execute([$cartId,$pid]);$message='Produto removido';}
  elseif($action==='clear'){$pdo->prepare('DELETE FROM cart_items WHERE cart_id=?')->execute([$cartId]);$message='Carrinho limpo';}
  else throw new RuntimeException('Ação inválida.');
  json_out(['success'=>true,'message'=>$message,'count'=>cart_count()]);
}catch(RuntimeException $e){json_out(['success'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){error_log('api/carrinho: '.$e->getMessage());json_out(['success'=>false,'message'=>'Não foi possível atualizar o carrinho.'],500);}
