<?php
require_once __DIR__.'/includes/permissions.php';
/* Recibo / fatura imprimível. Acesso: comprador do pedido, agricultor com itens no pedido (só os seus itens) ou SuperAdmin. */
require_login();$u=user();$id=safe_int($_GET['id']??0);$pdo=db();
$s=$pdo->prepare('SELECT o.*, bu.id buyer_user_id, bu.name buyer_name, bu.email buyer_email, c.code coupon_code, pay.method pay_method, pay.status pay_status, pay.reference pay_reference, pay.paid_at pay_paid_at FROM orders o JOIN buyers b ON b.id=o.buyer_id JOIN users bu ON bu.id=b.user_id LEFT JOIN coupons c ON c.id=o.coupon_id LEFT JOIN payments pay ON pay.order_id=o.id WHERE o.id=?');$s->execute([$id]);$o=$s->fetch();
$role=$u['role'];$farmerView=false;
if($o&&$role==='buyer'&&(int)$o['buyer_user_id']===(int)$u['id'])$allowed=true;
elseif($o&&$role==='superadmin')$allowed=true;
elseif($o&&$role==='farmer'&&$u['farmer_id']){$c=$pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id=? AND farmer_id=?');$c->execute([$id,(int)$u['farmer_id']]);$allowed=$farmerView=(int)$c->fetchColumn()>0;}
else $allowed=false;
if(!$allowed){http_response_code($o?403:404);include __DIR__.'/403.php';exit;}
$s=$pdo->prepare('SELECT oi.*, fu.name farmer_name FROM order_items oi JOIN farmers f ON f.id=oi.farmer_id JOIN users fu ON fu.id=f.user_id WHERE oi.order_id=?'.($farmerView?' AND oi.farmer_id=?':'').' ORDER BY oi.id');$s->execute($farmerView?[$id,(int)$u['farmer_id']]:[$id]);$items=$s->fetchAll();
$active=array_filter($items,fn($i)=>$i['status']!=='cancelled');
$fSub=array_sum(array_map(fn($i)=>(float)$i['subtotal'],$active));$fComm=array_sum(array_map(fn($i)=>(float)$i['commission_amount'],$active));$fNet=array_sum(array_map(fn($i)=>(float)$i['farmer_amount'],$active));
$appName=setting('app_name','AgroMercado');$qty=fn($q)=>rtrim(rtrim(number_format((float)$q,2,',',''),'0'),',');
$invoice=$o['invoice_number']?:invoice_number_for((int)$o['id'],(int)date('Y',strtotime($o['created_at'])));
?><!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Recibo <?=e($invoice)?> · <?=e($appName)?></title>
<style>
  :root{--ink:#1d2a1f;--muted:#5f6b62;--line:#d9e0da;--accent:#2e7d32}
  *{box-sizing:border-box}
  body{margin:0;background:#eef2ee;color:var(--ink);font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}
  .sheet{max-width:820px;margin:24px auto;background:#fff;padding:36px 40px;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.08)}
  .toolbar{max-width:820px;margin:16px auto 0;display:flex;gap:8px;justify-content:flex-end;padding:0 16px}
  .toolbar button,.toolbar a{font:inherit;border:1px solid var(--accent);background:var(--accent);color:#fff;padding:8px 14px;border-radius:6px;cursor:pointer;text-decoration:none}
  .toolbar a{background:#fff;color:var(--accent)}
  header{display:flex;justify-content:space-between;gap:24px;border-bottom:2px solid var(--accent);padding-bottom:16px;margin-bottom:20px}
  h1{margin:0;font-size:24px;color:var(--accent)} h2{margin:0 0 4px;font-size:18px}
  .muted{color:var(--muted)} .right{text-align:right}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}
  .box{border:1px solid var(--line);border-radius:6px;padding:12px 14px} .box h3{margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
  table{width:100%;border-collapse:collapse;margin-bottom:16px} th,td{padding:8px 6px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top} th{font-size:12px;text-transform:uppercase;color:var(--muted)}
  td.num,th.num{text-align:right;white-space:nowrap} tr.cancel td{color:var(--muted);text-decoration:line-through}
  .totals{margin-left:auto;width:min(340px,100%)} .totals div{display:flex;justify-content:space-between;padding:4px 0} .totals .grand{border-top:2px solid var(--ink);margin-top:4px;padding-top:8px;font-weight:700;font-size:16px}
  .note{font-size:12px;color:var(--muted);margin-top:24px;border-top:1px solid var(--line);padding-top:12px}
  @media (max-width:600px){.sheet{padding:20px 16px;margin:12px}.grid{grid-template-columns:1fr}header{flex-direction:column}}
  @media print{body{background:#fff}.toolbar{display:none}.sheet{box-shadow:none;margin:0;max-width:none;padding:0;border-radius:0}a{color:inherit;text-decoration:none}@page{margin:15mm}}
</style>
</head>
<body>
<div class="toolbar"><a href="javascript:history.back()">Voltar</a><button type="button" onclick="window.print()">Imprimir / Guardar PDF</button></div>
<main class="sheet">
  <header>
    <div><h1><?=e($appName)?></h1><div class="muted">Marketplace agrícola · Moçambique</div></div>
    <div class="right"><h2><?=$farmerView?'Nota de venda':'Fatura-recibo'?></h2><div><strong><?=e($invoice)?></strong></div><div class="muted">Pedido <?=e($o['order_number'])?></div><div class="muted">Emitida em <?=date('d/m/Y H:i',strtotime($o['created_at']))?></div><div class="muted">Impressa em <?=date('d/m/Y H:i')?></div></div>
  </header>
  <div class="grid">
    <div class="box"><h3>Cliente / Entrega</h3><strong><?=e($o['delivery_name'])?></strong><br><?=e($o['delivery_phone'])?><?php if(!$farmerView): ?> · <?=e($o['delivery_email'])?><?php endif; ?><br><?=e($o['address'])?>, <?=e($o['district'])?>, <?=e($o['city'])?> — <?=e($o['province'])?></div>
    <div class="box"><h3>Pagamento</h3>Método: <strong><?=e(payment_method_label((string)($o['pay_method']??'')))?></strong><br>Estado: <strong><?=e(payment_status_label((string)($o['pay_status']??$o['payment_status'])))?></strong><?php if($o['pay_paid_at']): ?><br>Pago em: <?=date('d/m/Y H:i',strtotime($o['pay_paid_at']))?><?php endif; ?><?php if($o['pay_reference']): ?><br>Referência: <?=e($o['pay_reference'])?><?php endif; ?><br>Estado do pedido: <?=e(order_status_label($o['status']))?></div>
  </div>
  <table>
    <thead><tr><th>Produto</th><?php if(!$farmerView): ?><th>Agricultor</th><?php endif; ?><th class="num">Qtd.</th><th class="num">Preço unit.</th><th class="num">Subtotal</th><?php if($farmerView): ?><th class="num">Comissão</th><th class="num">Líquido</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach($items as $i): ?><tr class="<?=$i['status']==='cancelled'?'cancel':''?>"><td><?=e($i['product_name'])?><?=$i['status']==='cancelled'?' <small>(cancelado)</small>':''?></td><?php if(!$farmerView): ?><td><?=e($i['farmer_name'])?></td><?php endif; ?><td class="num"><?=e($qty($i['quantity']))?> <?=e($i['unit'])?></td><td class="num"><?=money($i['unit_price'])?></td><td class="num"><?=money($i['subtotal'])?></td><?php if($farmerView): ?><td class="num"><?=money($i['commission_amount'])?> <small class="muted">(<?=e(rtrim(rtrim(number_format((float)$i['commission_rate'],2,',',''),'0'),','))?>%)</small></td><td class="num"><?=money($i['farmer_amount'])?></td><?php endif; ?></tr><?php endforeach; ?>
    </tbody>
  </table>
  <div class="totals">
  <?php if($farmerView): ?>
    <div><span>Subtotal dos seus itens</span><strong><?=money($fSub)?></strong></div>
    <div><span>Comissão da plataforma</span><strong>−<?=money($fComm)?></strong></div>
    <div class="grand"><span>Valor líquido a receber</span><span><?=money($fNet)?></span></div>
  <?php else: ?>
    <div><span>Subtotal</span><strong><?=money($o['subtotal'])?></strong></div>
    <?php if((float)$o['discount']>0): ?><div><span>Desconto<?=$o['coupon_code']?' ('.e($o['coupon_code']).')':''?></span><strong>−<?=money($o['discount'])?></strong></div><?php endif; ?>
    <div><span>Taxa de entrega</span><strong><?=(float)$o['delivery_fee']>0?money($o['delivery_fee']):'A combinar'?></strong></div>
    <div class="grand"><span>Total</span><span><?=money($o['total'])?></span></div>
  <?php endif; ?>
  </div>
  <p class="note"><?=$farmerView?'Documento interno para o agricultor: mostra apenas os seus itens. O valor líquido é pago através de repasse após a entrega e confirmação do pagamento.':'Valores em Meticais (MT). Itens cancelados não são cobrados; eventuais reembolsos são tratados pela plataforma.'?> <?=e($appName)?> — documento gerado eletronicamente.</p>
</main>
</body>
</html>
