<?php
require_once __DIR__.'/../includes/permissions.php';
$data=api_guard();
$subject=trim((string)($data['subject']??''));$description=trim((string)($data['description']??''));$productId=safe_int($data['product_id']??0);$orderId=safe_int($data['order_id']??0);$reportedId=safe_int($data['reported_user_id']??0);
if(mb_strlen($subject)<5||mb_strlen($subject)>160||mb_strlen($description)<15||mb_strlen($description)>5000)json_out(['success'=>false,'message'=>'Indique assunto (5–160 caracteres) e descrição detalhada (mínimo 15).'],422);
$pdo=db();$exists=function(string $sql,array $p)use($pdo):bool{$s=$pdo->prepare($sql);$s->execute($p);return (bool)$s->fetchColumn();};
if($productId&&!$exists('SELECT 1 FROM products WHERE id=?',[$productId]))json_out(['success'=>false,'message'=>'Produto não encontrado.'],422);
if($reportedId&&!$exists('SELECT 1 FROM users WHERE id=?',[$reportedId]))json_out(['success'=>false,'message'=>'Utilizador não encontrado.'],422);
if($orderId){$ok=user()['role']==='buyer'?$exists('SELECT 1 FROM orders WHERE id=? AND buyer_id=?',[$orderId,(int)user()['buyer_id']]):(user()['role']==='farmer'?$exists('SELECT 1 FROM order_items WHERE order_id=? AND farmer_id=?',[$orderId,(int)user()['farmer_id']]):$exists('SELECT 1 FROM orders WHERE id=?',[$orderId]));if(!$ok)json_out(['success'=>false,'message'=>'Pedido não encontrado.'],422);}
$stmt=$pdo->prepare('INSERT INTO complaints(reporter_id,reported_user_id,product_id,order_id,subject,description) VALUES(?,?,?,?,?,?)');$stmt->execute([current_user_id(),$reportedId?:null,$productId?:null,$orderId?:null,$subject,$description]);$id=(int)$pdo->lastInsertId();audit(current_user_id(),'create','complaints','Denúncia #'.$id.' criada');json_out(['success'=>true,'message'=>'Denúncia enviada para análise.','id'=>$id]);
