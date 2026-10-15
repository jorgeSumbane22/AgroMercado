<?php
require_once __DIR__.'/../includes/permissions.php';
$data=api_guard('farmer','superadmin');$u=user();$pdo=db();
$id=safe_int($data['order_id']??0);$status=(string)($data['status']??'');$reason=mb_substr(trim((string)($data['reason']??'')),0,255);
$allowed=['confirmed','preparing','ready','shipping','delivered','cancelled'];
if(!in_array($status,$allowed,true))json_out(['success'=>false,'message'=>'Estado inválido.'],422);
$s=$pdo->prepare('SELECT id,order_number,status FROM orders WHERE id=?');$s->execute([$id]);$o=$s->fetch();
if(!$o)json_out(['success'=>false,'message'=>'Pedido não encontrado.'],404);
$isFarmer=$u['role']==='farmer';
if($isFarmer&&!$u['farmer_id'])json_out(['success'=>false,'message'=>'Sem permissão para este pedido.'],403);
$sql='SELECT id,product_id,quantity,status FROM order_items WHERE order_id=?'.($isFarmer?' AND farmer_id=?':'');
$s=$pdo->prepare($sql);$s->execute($isFarmer?[$id,(int)$u['farmer_id']]:[$id]);$items=$s->fetchAll();
if(!$items)json_out(['success'=>false,'message'=>'Sem permissão para este pedido.'],403);
$target=array_values(array_filter($items,fn($i)=>$i['status']!=='cancelled'));
if(!$target)json_out(['success'=>false,'message'=>'Os seus itens deste pedido já estão cancelados.'],422);
if(!$isFarmer){$ok=array_values(array_filter($target,fn($i)=>can_transition($i['status'],$status)));if($ok)$target=$ok;}// admin: aplica aos itens que podem transitar
foreach($target as $i){if(!can_transition($i['status'],$status))json_out(['success'=>false,'message'=>'Não é possível passar de "'.order_status_label($i['status']).'" para "'.order_status_label($status).'".'],422);}
if($status==='cancelled'&&$reason==='')$reason='Cancelado pelo '.($isFarmer?'agricultor':'administrador').'.';
try{
    $pdo->beginTransaction();
    $ids=array_map(fn($i)=>(int)$i['id'],$target);$in=implode(',',array_fill(0,count($ids),'?'));
    $pdo->prepare("UPDATE order_items SET status=? WHERE id IN ($in)")->execute(array_merge([$status],$ids));
    if($status==='cancelled'){restore_item_stock($pdo,$target,'Cancelamento do pedido '.$o['order_number']);$pdo->prepare('UPDATE orders SET cancel_reason=? WHERE id=?')->execute([$reason,$id]);}
    $new=recalc_order_status($pdo,$id);
    $body='O pedido '.$o['order_number'].($isFarmer?' (itens de '.$u['name'].')':'').' está agora: '.order_status_label($status).'.'.($status==='cancelled'?' Motivo: '.$reason:'');
    notify_buyer_of_order($id,'Pedido atualizado',$body);
    audit((int)$u['id'],'update','orders','Itens do pedido '.$o['order_number'].' → '.$status.' (estado do pedido: '.$new.')');
    $pdo->commit();
}catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();error_log($ex->getMessage());json_out(['success'=>false,'message'=>'Não foi possível atualizar o pedido.'],500);}
json_out(['success'=>true,'message'=>'Estado atualizado para '.order_status_label($status).'.','status'=>$new,'item_status'=>$status]);
