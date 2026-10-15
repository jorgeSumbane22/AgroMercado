<?php
require_once __DIR__.'/../includes/permissions.php';
require_role('superadmin');
require_once __DIR__.'/_helpers.php';

$page_title = 'Cupões';
$pdo = db();
$blank = ['id' => 0, 'code' => '', 'description' => '', 'discount_type' => 'percent', 'value' => '', 'min_subtotal' => '0', 'max_uses' => '', 'starts_at' => '', 'ends_at' => '', 'is_active' => 1];
$old = $blank;
$errors = [];

/** Converte 'Y-m-d\TH:i' (datetime-local) em DATETIME ou null; false se inválido. */
function coupon_dt(string $v): string|null|false {
    $v = trim($v);
    if ($v === '') return null;
    $d = DateTime::createFromFormat('Y-m-d\TH:i', $v) ?: DateTime::createFromFormat('Y-m-d', $v);
    return $d ? $d->format(strlen($v) === 10 ? 'Y-m-d 00:00:00' : 'Y-m-d H:i:00') : false;
}

/* ============================================================
   AÇÕES (POST + CSRF)
   ============================================================ */
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    $me = (int)user()['id'];
    $id = safe_int($_POST['id'] ?? 0);
    try {
        if ($action === 'save') {
            foreach ($blank as $k => $v) if ($k !== 'is_active') $old[$k] = trim((string)($_POST[$k] ?? ''));
            $old['id'] = $id;
            $old['is_active'] = isset($_POST['is_active']) ? 1 : 0;
            $old['code'] = coupon_normalize($old['code']);
            if (!preg_match('/^[A-Z0-9_-]{3,30}$/', $old['code'])) $errors[] = 'Código: 3 a 30 caracteres (letras, números, _ ou -).';
            if (!in_array($old['discount_type'], ['percent', 'fixed'], true)) $errors[] = 'Tipo de desconto inválido.';
            $value = str_replace(',', '.', $old['value']);
            if (!is_numeric($value) || (float)$value <= 0) $errors[] = 'Valor do desconto inválido.';
            elseif ($old['discount_type'] === 'percent' && (float)$value > 100) $errors[] = 'A percentagem não pode exceder 100%.';
            $min = str_replace(',', '.', $old['min_subtotal'] === '' ? '0' : $old['min_subtotal']);
            if (!is_numeric($min) || (float)$min < 0) $errors[] = 'Subtotal mínimo inválido.';
            $maxUses = $old['max_uses'] === '' ? null : filter_var($old['max_uses'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($maxUses === false) $errors[] = 'Máximo de utilizações inválido.';
            $starts = coupon_dt($old['starts_at']); $ends = coupon_dt($old['ends_at']);
            if ($starts === false || $ends === false) $errors[] = 'Datas inválidas.';
            elseif ($starts && $ends && $ends <= $starts) $errors[] = 'A data de fim deve ser posterior à de início.';
            if (mb_strlen($old['description']) > 160) $errors[] = 'Descrição: máx. 160 caracteres.';
            $dup = $pdo->prepare('SELECT id FROM coupons WHERE code = ? AND id <> ?'); $dup->execute([$old['code'], $id]);
            if ($dup->fetchColumn()) $errors[] = 'Já existe um cupão com esse código.';
            if (!$errors) {
                $vals = [$old['code'], $old['description'] !== '' ? $old['description'] : null, $old['discount_type'], round((float)$value, 2), round((float)$min, 2), $maxUses, $starts, $ends, $old['is_active']];
                if ($id > 0) {
                    $pdo->prepare('UPDATE coupons SET code=?, description=?, discount_type=?, value=?, min_subtotal=?, max_uses=?, starts_at=?, ends_at=?, is_active=? WHERE id=?')->execute(array_merge($vals, [$id]));
                    audit($me, 'update', 'coupons', 'Cupão ' . $old['code'] . ' atualizado (' . $old['discount_type'] . ' ' . $vals[3] . ')');
                    flash('success', 'Cupão ' . $old['code'] . ' atualizado.');
                } else {
                    $pdo->prepare('INSERT INTO coupons(code, description, discount_type, value, min_subtotal, max_uses, starts_at, ends_at, is_active) VALUES(?,?,?,?,?,?,?,?,?)')->execute($vals);
                    audit($me, 'create', 'coupons', 'Cupão ' . $old['code'] . ' criado (' . $old['discount_type'] . ' ' . $vals[3] . ')');
                    flash('success', 'Cupão ' . $old['code'] . ' criado.');
                }
                redirect(base_path('admin/cupoes.php'));
            }
        } elseif ($action === 'toggle' || $action === 'delete') {
            $s = $pdo->prepare('SELECT * FROM coupons WHERE id = ?'); $s->execute([$id]); $c = $s->fetch();
            if (!$c) throw new DomainException('Cupão não encontrado.');
            if ($action === 'toggle') {
                $pdo->prepare('UPDATE coupons SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
                audit($me, 'update', 'coupons', 'Cupão ' . $c['code'] . ' ' . ($c['is_active'] ? 'desativado' : 'ativado'));
                flash('success', 'Cupão ' . $c['code'] . ' ' . ($c['is_active'] ? 'desativado' : 'ativado') . '.');
            } else {
                $u = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE coupon_id = ?'); $u->execute([$id]);
                if ((int)$u->fetchColumn() > 0 || (int)$c['used_count'] > 0) throw new DomainException('O cupão já foi usado; desative-o em vez de o eliminar.');
                $pdo->prepare('DELETE FROM coupons WHERE id = ?')->execute([$id]);
                audit($me, 'delete', 'coupons', 'Cupão ' . $c['code'] . ' eliminado');
                flash('success', 'Cupão ' . $c['code'] . ' eliminado.');
            }
            redirect(admin_self_url());
        } else {
            throw new DomainException('Ação inválida.');
        }
    } catch (DomainException $ex) {
        flash('danger', $ex->getMessage());
        redirect(admin_self_url());
    } catch (Throwable $ex) {
        error_log('admin/cupoes: ' . $ex->getMessage());
        flash('danger', 'Não foi possível guardar o cupão.');
        redirect(admin_self_url());
    }
}

/* Edição (?edit=id) */
$editId = safe_int($_GET['edit'] ?? 0);
if (!$errors && $editId > 0) {
    $s = $pdo->prepare('SELECT * FROM coupons WHERE id = ?'); $s->execute([$editId]);
    if ($row = $s->fetch()) {
        $old = $row;
        $old['value'] = rtrim(rtrim((string)$row['value'], '0'), '.');
        $old['min_subtotal'] = rtrim(rtrim((string)$row['min_subtotal'], '0'), '.') ?: '0';
        $old['max_uses'] = (string)($row['max_uses'] ?? '');
        $old['starts_at'] = $row['starts_at'] ? date('Y-m-d\TH:i', strtotime($row['starts_at'])) : '';
        $old['ends_at'] = $row['ends_at'] ? date('Y-m-d\TH:i', strtotime($row['ends_at'])) : '';
    }
}

/* Lista + filtros */
$fState = (string)($_GET['estado'] ?? '');
if (!in_array($fState, ['', 'active', 'inactive'], true)) $fState = '';
$q = trim((string)($_GET['q'] ?? ''));
$where = []; $params = [];
if ($fState !== '') $where[] = 'c.is_active = ' . ($fState === 'active' ? 1 : 0);
if ($q !== '') { $where[] = '(c.code LIKE ? OR c.description LIKE ?)'; $like = '%' . $q . '%'; array_push($params, $like, $like); }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$perPage = 20;
[$page, $offset] = page_offset($perPage);
$c = $pdo->prepare('SELECT COUNT(*) FROM coupons c' . $whereSql); $c->execute($params); $totalFiltered = (int)$c->fetchColumn();
$s = $pdo->prepare('SELECT c.*, (SELECT COALESCE(SUM(o.discount),0) FROM orders o WHERE o.coupon_id = c.id AND o.status <> "cancelled") AS total_discount FROM coupons c' . $whereSql . ' ORDER BY c.is_active DESC, c.created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$s->execute($params);
$coupons = $s->fetchAll();

include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow"><span class="sa-dot"></span> Comercial · Cupões</span>
                <h1 class="sa-hero-title">Cupões de <em>desconto</em></h1>
                <p class="sa-hero-sub">O desconto é aplicado ao subtotal dos produtos e é suportado pela plataforma (o valor do agricultor não muda). Cada comprador usa cada cupão uma vez.</p>
            </div>
        </header>

        <div class="row g-4">
            <div class="col-xl-4">
                <article class="content-card sa-card">
                    <div class="card-heading"><div><h4><?= (int)$old['id'] > 0 ? 'Editar cupão' : 'Novo cupão' ?></h4></div><?php if ((int)$old['id'] > 0): ?><a href="<?= base_path('admin/cupoes.php') ?>" class="small">Novo</a><?php endif; ?></div>
                    <?php if ($errors): ?><div class="alert alert-danger small"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
                    <form method="post" action="<?= base_path('admin/cupoes.php') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$old['id'] ?>">
                        <div class="mb-2"><label class="form-label" for="cp-code">Código *</label><input id="cp-code" name="code" class="form-control text-uppercase" maxlength="30" required value="<?= e($old['code']) ?>"></div>
                        <div class="mb-2"><label class="form-label" for="cp-desc">Descrição</label><input id="cp-desc" name="description" class="form-control" maxlength="160" value="<?= e($old['description']) ?>"></div>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label" for="cp-type">Tipo</label><select id="cp-type" name="discount_type" class="form-select"><option value="percent" <?= $old['discount_type'] === 'percent' ? 'selected' : '' ?>>Percentagem (%)</option><option value="fixed" <?= $old['discount_type'] === 'fixed' ? 'selected' : '' ?>>Valor fixo (MT)</option></select></div>
                            <div class="col-6"><label class="form-label" for="cp-value">Valor *</label><input id="cp-value" name="value" type="number" step="0.01" min="0.01" class="form-control" required value="<?= e($old['value']) ?>"></div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label" for="cp-min">Subtotal mínimo</label><input id="cp-min" name="min_subtotal" type="number" step="0.01" min="0" class="form-control" value="<?= e($old['min_subtotal']) ?>"></div>
                            <div class="col-6"><label class="form-label" for="cp-max">Máx. utilizações</label><input id="cp-max" name="max_uses" type="number" min="1" class="form-control" placeholder="Ilimitado" value="<?= e($old['max_uses']) ?>"></div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label" for="cp-start">Início</label><input id="cp-start" name="starts_at" type="datetime-local" class="form-control" value="<?= e($old['starts_at']) ?>"></div>
                            <div class="col-6"><label class="form-label" for="cp-end">Fim</label><input id="cp-end" name="ends_at" type="datetime-local" class="form-control" value="<?= e($old['ends_at']) ?>"></div>
                        </div>
                        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="cp-active" name="is_active" value="1" <?= (int)$old['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="cp-active">Ativo</label></div>
                        <button class="btn btn-primary w-100"><i class="bi bi-check2"></i> Guardar cupão</button>
                    </form>
                </article>
            </div>

            <div class="col-xl-8">
                <article class="content-card sa-card">
                    <div class="card-heading sa-card-head">
                        <div><h4>Cupões</h4><p class="sa-card-sub"><?= $totalFiltered ?> registo(s)</p></div>
                        <div class="sa-toolbar">
                            <form method="get" class="sa-search" role="search">
                                <i class="bi bi-search"></i>
                                <?php if ($fState !== ''): ?><input type="hidden" name="estado" value="<?= e($fState) ?>"><?php endif; ?>
                                <input type="text" name="q" value="<?= e($q) ?>" aria-label="Procurar cupão" placeholder="Código ou descrição…">
                            </form>
                            <div class="sa-filters">
                                <?php foreach (['' => 'Todos', 'active' => 'Ativos', 'inactive' => 'Inativos'] as $key => $label): ?>
                                    <a class="sa-filter text-decoration-none<?= $fState === $key ? ' active' : '' ?>" href="?<?= e(http_build_query(array_filter(['estado' => $key ?: null, 'q' => $q !== '' ? $q : null]))) ?>"><?= e($label) ?></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php if (!$coupons): ?>
                        <div class="empty-state py-5"><i class="bi bi-ticket-perforated" style="font-size:2.5rem;"></i><p class="mt-3 mb-0">Nenhum cupão encontrado.</p></div>
                    <?php else: ?>
                        <div class="sa-scroll-x">
                            <table class="table align-middle sa-table">
                                <thead><tr><th>Código</th><th>Desconto</th><th>Mínimo</th><th>Utilizações</th><th>Validade</th><th>Estado</th><th class="text-end">Ações</th></tr></thead>
                                <tbody>
                                <?php foreach ($coupons as $cp):
                                    $expired = $cp['ends_at'] && strtotime($cp['ends_at']) < time();
                                    $exhausted = $cp['max_uses'] !== null && (int)$cp['used_count'] >= (int)$cp['max_uses']; ?>
                                    <tr>
                                        <td><strong><?= e($cp['code']) ?></strong><?php if ($cp['description']): ?><br><small class="text-muted"><?= e($cp['description']) ?></small><?php endif; ?></td>
                                        <td><?= $cp['discount_type'] === 'percent' ? e(rtrim(rtrim(number_format((float)$cp['value'], 2, ',', ''), '0'), ',')) . '%' : money($cp['value']) ?></td>
                                        <td><?= (float)$cp['min_subtotal'] > 0 ? money($cp['min_subtotal']) : '—' ?></td>
                                        <td><?= (int)$cp['used_count'] ?><?= $cp['max_uses'] !== null ? ' / ' . (int)$cp['max_uses'] : '' ?><br><small class="text-muted">−<?= money($cp['total_discount']) ?></small></td>
                                        <td class="small"><?= $cp['starts_at'] ? date('d/m/Y H:i', strtotime($cp['starts_at'])) : 'Sempre' ?> → <?= $cp['ends_at'] ? date('d/m/Y H:i', strtotime($cp['ends_at'])) : 'sem fim' ?></td>
                                        <td><?php if (!(int)$cp['is_active']): ?><span class="badge text-bg-secondary">Inativo</span><?php elseif ($expired): ?><span class="badge text-bg-danger">Expirado</span><?php elseif ($exhausted): ?><span class="badge text-bg-warning">Esgotado</span><?php else: ?><span class="badge text-bg-success">Ativo</span><?php endif; ?></td>
                                        <td class="text-end text-nowrap">
                                            <a href="?<?= e(http_build_query(['edit' => (int)$cp['id']])) ?>" class="btn btn-sm btn-outline-primary" title="Editar" aria-label="Editar"><i class="bi bi-pencil"></i></a>
                                            <?= post_button(base_path('admin/cupoes.php'), ['action' => 'toggle', 'id' => (int)$cp['id']], '<i class="bi bi-' . ((int)$cp['is_active'] ? 'pause-circle' : 'play-circle') . '"></i>', 'btn btn-sm btn-outline-secondary', null, (int)$cp['is_active'] ? 'Desativar' : 'Ativar') ?>
                                            <?php if ((int)$cp['used_count'] === 0): ?><?= post_button(base_path('admin/cupoes.php'), ['action' => 'delete', 'id' => (int)$cp['id']], '<i class="bi bi-trash"></i>', 'btn btn-sm btn-outline-danger', 'Eliminar o cupão ' . $cp['code'] . '?', 'Eliminar') ?><?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3"><?= pagination($page, $perPage, $totalFiltered) ?></div>
                    <?php endif; ?>
                </article>
            </div>
        </div>

    </section>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
