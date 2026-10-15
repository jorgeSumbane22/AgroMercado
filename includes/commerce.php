<?php
/* Comércio: comissão, repasses aos agricultores, reembolsos, cupões, faturas e mensagens.
 *
 * Regras de dinheiro (resumo):
 *  - Comissão: em cada item guarda-se commission_rate (% de app_settings.commission_percent, 0–50),
 *    commission_amount = round(subtotal*rate/100, 2) e farmer_amount = subtotal - commission_amount.
 *  - A taxa de entrega pertence à plataforma (não entra nos repasses).
 *  - Desconto de cupão: a comissão é calculada sobre os subtotais dos itens e o desconto é absorvido
 *    pela plataforma — primeiro pela comissão do pedido, e o eventual excedente também pela plataforma.
 *    farmer_amount nunca é reduzido pelo desconto. Receita líquida da plataforma por pedido =
 *    Σ commission_amount − discount + delivery_fee.
 *  - Repasse: só itens elegíveis (ver payout_eligible_sql()).
 *  - Reembolso: pedido/itens cancelados com pagamento pago criam um pedido de reembolso (requested);
 *    o pagamento só passa a "refunded" quando o reembolso total é processado pelo admin.
 */

/* ---------- Comissão ---------- */
function commission_percent(): float { $v = setting('commission_percent', '10'); $v = is_numeric($v) ? (float)$v : 10.0; return max(0.0, min(50.0, $v)); }
/** Divide o subtotal de um item: [rate, commission_amount, farmer_amount]. */
function commission_split(float $subtotal, ?float $rate = null): array { $rate = $rate ?? commission_percent(); $c = round($subtotal * $rate / 100, 2); return [$rate, $c, round($subtotal - $c, 2)]; }

/* ---------- Cupões ---------- */
function coupon_normalize(?string $code): string { return strtoupper(preg_replace('/\s+/', '', (string)$code)); }
function coupon_discount(array $coupon, float $subtotal): float {
    $d = $coupon['discount_type'] === 'percent' ? round($subtotal * min(100, (float)$coupon['value']) / 100, 2) : round((float)$coupon['value'], 2);
    return max(0.0, min($d, round($subtotal, 2)));
}
/**
 * Valida um cupão para um subtotal. Devolve [coupon, discount] ou uma string com o erro.
 * Com $lock=true lê a linha com FOR UPDATE (usar dentro da transação do checkout).
 * Regra extra: cada comprador só pode usar o mesmo cupão uma vez (pedidos não cancelados).
 */
function validate_coupon(string $code, float $subtotal, ?int $buyerUserId = null, ?PDO $pdo = null, bool $lock = false): array|string {
    $code = coupon_normalize($code); $pdo = $pdo ?? db();
    if ($code === '' || strlen($code) > 30) return 'Indique um código de cupão válido.';
    $s = $pdo->prepare('SELECT * FROM coupons WHERE code=?' . ($lock ? ' FOR UPDATE' : '')); $s->execute([$code]); $c = $s->fetch();
    if (!$c || !(int)$c['is_active']) return 'Cupão inválido ou inativo.';
    $now = time();
    if ($c['starts_at'] && strtotime($c['starts_at']) > $now) return 'Este cupão ainda não está em vigor.';
    if ($c['ends_at'] && strtotime($c['ends_at']) < $now) return 'Este cupão expirou.';
    if ($c['max_uses'] !== null && (int)$c['used_count'] >= (int)$c['max_uses']) return 'Este cupão já atingiu o limite de utilizações.';
    if ($subtotal < (float)$c['min_subtotal']) return 'Este cupão exige um subtotal mínimo de ' . money($c['min_subtotal']) . '.';
    if ($buyerUserId) {
        $u = $pdo->prepare('SELECT COUNT(*) FROM orders o JOIN buyers b ON b.id=o.buyer_id WHERE b.user_id=? AND o.coupon_id=? AND o.status<>"cancelled"'); $u->execute([$buyerUserId, $c['id']]);
        if ((int)$u->fetchColumn() > 0) return 'Já utilizou este cupão numa compra anterior.';
    }
    $d = coupon_discount($c, $subtotal);
    if ($d <= 0) return 'Este cupão não dá desconto neste pedido.';
    return [$c, $d];
}

/* ---------- Faturas ---------- */
function invoice_number_for(int $orderId, ?int $year = null): string { return 'FT ' . ($year ?? (int)date('Y')) . '/' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT); }
function assign_invoice_number(PDO $pdo, int $orderId): string { $n = invoice_number_for($orderId); $pdo->prepare('UPDATE orders SET invoice_number=? WHERE id=? AND invoice_number IS NULL')->execute([$n, $orderId]); return $n; }

/* ---------- Repasses (payouts) ---------- */
/**
 * FROM/WHERE dos itens elegíveis para repasse (alias oi, o, pay). Um item é elegível quando:
 *  - está entregue (oi.status='delivered') e ainda não está em nenhum repasse (payout_id NULL);
 *  - o pagamento do pedido está pago, OU o método é dinheiro (cobrado na entrega) e não está falhado/reembolsado;
 *  - o pedido não tem reembolsos pedidos/processados para além do valor dos itens cancelados
 *    (um reembolso que abranja itens entregues bloqueia o repasse; rejeitado não bloqueia).
 * $joins: JOINs extra (ex.: farmers/users). Acrescente " AND oi.farmer_id=?" para filtrar por agricultor.
 */
function payout_eligible_sql(string $joins = ''): string {
    return ' FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN payments pay ON pay.order_id=o.id ' . $joins
        . ' WHERE oi.status="delivered" AND oi.payout_id IS NULL'
        . ' AND (pay.status="paid" OR (pay.method="cash" AND pay.status NOT IN ("failed","refunded")))'
        . ' AND (SELECT COALESCE(SUM(r.amount),0) FROM refunds r WHERE r.order_id=oi.order_id AND r.status IN ("requested","processed"))'
        . ' <= (SELECT COALESCE(SUM(x.subtotal),0) FROM order_items x WHERE x.order_id=oi.order_id AND x.status="cancelled")';
}
function payout_status_label(string $s): string { return ['pending'=>'Em processamento','paid'=>'Pago','cancelled'=>'Cancelado'][$s] ?? ucfirst($s); }
function payout_status_class(string $s): string { return ['pending'=>'warning','paid'=>'success','cancelled'=>'secondary'][$s] ?? 'secondary'; }
/** Saldos de um agricultor: available (elegível), processing (repasses pendentes), paid. */
function farmer_balances(int $farmerId): array {
    $pdo = db();
    $s = $pdo->prepare('SELECT COALESCE(SUM(oi.farmer_amount),0), COUNT(*)' . payout_eligible_sql() . ' AND oi.farmer_id=?'); $s->execute([$farmerId]); [$avail, $n] = $s->fetch(PDO::FETCH_NUM);
    $s = $pdo->prepare('SELECT status, COALESCE(SUM(amount),0) a, COUNT(*) n FROM farmer_payouts WHERE farmer_id=? GROUP BY status'); $s->execute([$farmerId]);
    $out = ['available'=>(float)$avail, 'available_items'=>(int)$n, 'processing'=>0.0, 'processing_count'=>0, 'paid'=>0.0, 'paid_count'=>0];
    foreach ($s as $r) { if ($r['status'] === 'pending') { $out['processing'] = (float)$r['a']; $out['processing_count'] = (int)$r['n']; } elseif ($r['status'] === 'paid') { $out['paid'] = (float)$r['a']; $out['paid_count'] = (int)$r['n']; } }
    return $out;
}
/** Cria um repasse com todos os itens elegíveis do agricultor (transação própria). Devolve [payoutId, amount, count]. */
function create_farmer_payout(int $farmerId, ?int $createdBy, ?string $notes = null): array {
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $s = $pdo->prepare('SELECT oi.id, oi.farmer_amount' . payout_eligible_sql() . ' AND oi.farmer_id=? FOR UPDATE'); $s->execute([$farmerId]); $rows = $s->fetchAll();
        if (!$rows) throw new DomainException('Este agricultor não tem itens elegíveis para repasse.');
        $amount = round(array_sum(array_map(fn($r) => (float)$r['farmer_amount'], $rows)), 2);
        if ($amount <= 0) throw new DomainException('O saldo elegível é zero.');
        $pdo->prepare('INSERT INTO farmer_payouts(farmer_id,amount,items_count,status,notes,created_by) VALUES(?,?,?,"pending",?,?)')->execute([$farmerId, $amount, count($rows), $notes ?: null, $createdBy ?: null]);
        $pid = (int)$pdo->lastInsertId();
        $ids = array_map(fn($r) => (int)$r['id'], $rows); $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE order_items SET payout_id=? WHERE payout_id IS NULL AND id IN ($in)")->execute(array_merge([$pid], $ids));
        $pdo->commit();
        return [$pid, $amount, count($rows)];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/* ---------- Reembolsos ---------- */
function refund_status_label(string $s): string { return ['requested'=>'Pedido','processed'=>'Processado','rejected'=>'Rejeitado'][$s] ?? ucfirst($s); }
function refund_status_class(string $s): string { return ['requested'=>'warning','processed'=>'success','rejected'=>'secondary'][$s] ?? 'secondary'; }
/** Pagamento do pedido (bloqueado com FOR UPDATE — chamar dentro de uma transação). */
function order_payment_locked(PDO $pdo, int $orderId): ?array { $s = $pdo->prepare('SELECT * FROM payments WHERE order_id=? FOR UPDATE'); $s->execute([$orderId]); return $s->fetch() ?: null; }
/** Valor ainda reembolsável: valor pago menos reembolsos pedidos/processados. */
function order_refundable_amount(PDO $pdo, int $orderId): float {
    $s = $pdo->prepare('SELECT amount FROM payments WHERE order_id=?'); $s->execute([$orderId]); $paid = (float)($s->fetchColumn() ?: 0);
    $s = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM refunds WHERE order_id=? AND status IN ("requested","processed")'); $s->execute([$orderId]);
    return max(0.0, round($paid - (float)$s->fetchColumn(), 2));
}
/**
 * Cria um pedido de reembolso após um cancelamento. Chamar dentro da transação do cancelamento e apenas
 * quando o pagamento estava "paid" ANTES do cancelamento. O valor é limitado ao reembolsável.
 * recalc_order_status() marca o pagamento como "refunded" num cancelamento total; aqui volta a "paid"
 * porque o dinheiro só é devolvido quando o admin processa o reembolso (admin/reembolsos.php).
 * Devolve o id do reembolso ou null se não houver nada a reembolsar.
 */
function create_refund_for_cancel(PDO $pdo, int $orderId, float $amount, string $reason): ?int {
    $pay = order_payment_locked($pdo, $orderId);
    if (!$pay) return null;
    $amount = min(round($amount, 2), order_refundable_amount($pdo, $orderId));
    if ($pay['status'] === 'refunded') {
        $s = $pdo->prepare('SELECT COUNT(*) FROM refunds WHERE order_id=? AND status="processed"'); $s->execute([$orderId]);
        if ((int)$s->fetchColumn() === 0) { $pdo->prepare('UPDATE payments SET status="paid" WHERE id=?')->execute([$pay['id']]); sync_order_payment_status($pdo, $orderId); }
    }
    if ($amount <= 0) return null;
    $pdo->prepare('INSERT INTO refunds(order_id,payment_id,amount,reason,status) VALUES(?,?,?,?,"requested")')->execute([$orderId, $pay['id'], $amount, mb_substr($reason, 0, 255)]);
    return (int)$pdo->lastInsertId();
}
/** Valor a reembolsar num cancelamento: total reembolsável se o pedido ficou cancelado, senão a soma dos itens cancelados. */
function cancel_refund_amount(PDO $pdo, int $orderId, string $newOrderStatus, array $cancelledItemIds): float {
    if ($newOrderStatus === 'cancelled') return order_refundable_amount($pdo, $orderId);
    if (!$cancelledItemIds) return 0.0;
    $in = implode(',', array_fill(0, count($cancelledItemIds), '?'));
    $s = $pdo->prepare("SELECT COALESCE(SUM(subtotal),0) FROM order_items WHERE order_id=? AND id IN ($in)"); $s->execute(array_merge([$orderId], array_map('intval', $cancelledItemIds)));
    return (float)$s->fetchColumn();
}

/* ---------- Mensagens comprador ↔ agricultor (por pedido e agricultor) ---------- */
function order_thread(int $orderId, int $farmerId): array { $s = db()->prepare('SELECT m.*, u.name sender_name FROM order_messages m JOIN users u ON u.id=m.sender_id WHERE m.order_id=? AND m.farmer_id=? ORDER BY m.created_at, m.id'); $s->execute([$orderId, $farmerId]); return $s->fetchAll(); }
/** Marca como lidas as mensagens do fio recebidas pelo utilizador indicado. */
function mark_thread_read(int $orderId, int $farmerId, int $readerUserId): void { db()->prepare('UPDATE order_messages SET is_read=1 WHERE order_id=? AND farmer_id=? AND sender_id<>? AND is_read=0')->execute([$orderId, $farmerId, $readerUserId]); }
/** Mensagens por ler por pedido, para o utilizador (comprador: todos os fios dos seus pedidos; agricultor: os seus fios). */
function unread_messages_by_order(int $userId, ?int $farmerId = null): array {
    if ($farmerId) { $s = db()->prepare('SELECT order_id, COUNT(*) FROM order_messages WHERE farmer_id=? AND sender_id<>? AND is_read=0 GROUP BY order_id'); $s->execute([$farmerId, $userId]); }
    else { $s = db()->prepare('SELECT m.order_id, COUNT(*) FROM order_messages m JOIN orders o ON o.id=m.order_id JOIN buyers b ON b.id=o.buyer_id WHERE b.user_id=? AND m.sender_id<>? AND m.is_read=0 GROUP BY m.order_id'); $s->execute([$userId, $userId]); }
    return array_map('intval', $s->fetchAll(PDO::FETCH_KEY_PAIR));
}
/** HTML de um fio de mensagens com formulário (envio via api/mensagens.php). */
function order_thread_html(array $messages, int $orderId, int $farmerId, int $meId, string $title, bool $canSend = true): string {
    static $js = false;
    $h = '<div class="order-thread border rounded p-3 mb-3"><h5 class="h6 mb-3"><i class="bi bi-chat-dots me-1"></i>' . e($title) . '</h5><div class="d-flex flex-column gap-2 mb-3" style="max-height:320px;overflow:auto">';
    if (!$messages) $h .= '<p class="small text-muted mb-0">Ainda não há mensagens.</p>';
    foreach ($messages as $m) { $mine = (int)$m['sender_id'] === $meId;
        $h .= '<div class="p-2 rounded small ' . ($mine ? 'bg-primary-subtle align-self-end' : 'bg-body-secondary align-self-start') . '" style="max-width:85%"><div class="fw-semibold">' . e($mine ? 'Você' : $m['sender_name']) . ' <span class="text-muted fw-normal">· ' . date('d/m/Y H:i', strtotime($m['created_at'])) . '</span></div><div>' . nl2br(e($m['body'])) . '</div></div>'; }
    $h .= '</div>';
    if ($canSend) $h .= '<form data-message-form class="d-flex gap-2"><input type="hidden" name="order_id" value="' . $orderId . '"><input type="hidden" name="farmer_id" value="' . $farmerId . '"><label class="visually-hidden" for="msg-' . $orderId . '-' . $farmerId . '">Mensagem</label><textarea id="msg-' . $orderId . '-' . $farmerId . '" name="body" class="form-control form-control-sm" rows="2" maxlength="2000" required placeholder="Escreva uma mensagem…"></textarea><button class="btn btn-sm btn-primary align-self-end"><i class="bi bi-send me-1"></i>Enviar</button></form>';
    $h .= '</div>';
    if (!$js && $canSend) { $js = true;
        $h .= "<script>document.addEventListener('submit',async function(ev){var f=ev.target;if(!f.matches||!f.matches('[data-message-form]'))return;ev.preventDefault();var b=f.querySelector('button');b.disabled=true;var p={csrf:window.CSRF};new FormData(f).forEach(function(v,k){p[k]=v;});try{var r=await fetch(window.BASE_URL+'/api/mensagens.php',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify(p)});var d={};try{d=await r.json();}catch(e){}if(!r.ok||!d.success)throw new Error(d.message||'Ocorreu um erro.');location.reload();}catch(e){alert(e.message);b.disabled=false;}});</script>"; }
    return $h;
}
