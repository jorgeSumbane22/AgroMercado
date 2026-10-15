<?php
require_once __DIR__.'/../includes/permissions.php';
require_role('superadmin');
require_once __DIR__.'/_helpers.php';

$page_title = 'Zonas de entrega';
$pdo = db();

/* ============================================================
   AÇÕES (POST + CSRF): criar, editar, ativar/desativar, apagar
   ============================================================ */
if (is_post()) {
    verify_csrf($_POST['csrf'] ?? '');
    $action = $_POST['action'] ?? '';
    $id = safe_int($_POST['id'] ?? 0);
    $zone = null;
    if ($action !== 'create') {
        $s = $pdo->prepare('SELECT * FROM delivery_zones WHERE id=?');
        $s->execute([$id]);
        $zone = $s->fetch();
    }
    $back = base_path('admin/zonas.php');

    if ($action !== 'create' && !$zone) {
        flash('danger', 'Zona não encontrada.');
    } elseif ($action === 'create' || $action === 'update') {
        $province = trim((string)($_POST['province'] ?? ''));
        $district = trim((string)($_POST['district'] ?? ''));
        $feeRaw   = str_replace(',', '.', trim((string)($_POST['fee'] ?? '')));
        $etaRaw   = trim((string)($_POST['eta_days'] ?? ''));
        $active   = !empty($_POST['is_active']) ? 1 : 0;
        $errors = [];
        if (!in_array($province, provinces(), true)) $errors[] = 'Escolha uma província válida.';
        if ($district === '' || mb_strlen($district) > 100) $errors[] = 'Indique o distrito (máx. 100 caracteres).';
        if ($feeRaw === '' || !is_numeric($feeRaw) || (float)$feeRaw < 0 || (float)$feeRaw > 1000000) $errors[] = 'A taxa deve ser um valor maior ou igual a 0.';
        if ($etaRaw === '' || filter_var($etaRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 30]]) === false) $errors[] = 'O prazo deve ser um número inteiro entre 0 e 30 dias.';
        if ($errors) {
            foreach ($errors as $err) flash('danger', $err);
            if ($action === 'update') $back .= '?edit=' . $id;
        } else {
            try {
                if ($action === 'create') {
                    $pdo->prepare('INSERT INTO delivery_zones(province,district,fee,eta_days,is_active) VALUES(?,?,?,?,?)')->execute([$province, $district, round((float)$feeRaw, 2), (int)$etaRaw, $active]);
                    audit(user()['id'], 'create', 'delivery_zones', 'Zona criada: ' . $province . ' / ' . $district . ' (' . $feeRaw . ' MT, ' . (int)$etaRaw . ' dias)');
                    flash('success', 'Zona de entrega criada.');
                } else {
                    $pdo->prepare('UPDATE delivery_zones SET province=?, district=?, fee=?, eta_days=?, is_active=? WHERE id=?')->execute([$province, $district, round((float)$feeRaw, 2), (int)$etaRaw, $active, $id]);
                    audit(user()['id'], 'update', 'delivery_zones', 'Zona #' . $id . ' atualizada: ' . $province . ' / ' . $district . ' (' . $feeRaw . ' MT, ' . (int)$etaRaw . ' dias, ' . ($active ? 'ativa' : 'inativa') . ')');
                    flash('success', 'Zona de entrega atualizada.');
                }
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) == 1062) flash('danger', 'Já existe uma zona para ' . $province . ' / ' . $district . '.');
                else { error_log('zonas: ' . $e->getMessage()); flash('danger', 'Não foi possível guardar a zona.'); }
                if ($action === 'update') $back .= '?edit=' . $id;
            }
        }
    } elseif ($action === 'toggle') {
        $new = (int)$zone['is_active'] ? 0 : 1;
        $pdo->prepare('UPDATE delivery_zones SET is_active=? WHERE id=?')->execute([$new, $id]);
        audit(user()['id'], 'update', 'delivery_zones', 'Zona #' . $id . ' (' . $zone['province'] . ' / ' . $zone['district'] . ') ' . ($new ? 'ativada' : 'desativada'));
        flash('success', 'Zona ' . ($new ? 'ativada' : 'desativada') . '.');
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM delivery_zones WHERE id=?')->execute([$id]);
        audit(user()['id'], 'delete', 'delivery_zones', 'Zona #' . $id . ' (' . $zone['province'] . ' / ' . $zone['district'] . ') apagada');
        flash('success', 'Zona apagada.');
    } else {
        flash('danger', 'Ação inválida.');
    }
    redirect($back);
}

$zones = $pdo->query('SELECT * FROM delivery_zones ORDER BY province, district')->fetchAll();
$editing = null;
if (isset($_GET['edit'])) foreach ($zones as $z) if ((int)$z['id'] === safe_int($_GET['edit'])) $editing = $z;
$form = $editing ?: ['province' => '', 'district' => '', 'fee' => '', 'eta_days' => 2, 'is_active' => 1];
$activeZones = count(array_filter($zones, fn($z) => (int)$z['is_active'] === 1));

include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Operações · Entregas
                </span>
                <h1 class="sa-hero-title">Zonas de <em>entrega</em></h1>
                <p class="sa-hero-sub">
                    <strong><?= $activeZones ?></strong> zonas ativas de <?= count($zones) ?>. Zonas sem configuração usam taxa 0 e prazo de 3 dias.
                </p>
            </div>
            <div class="sa-hero-actions">
                <a href="<?= base_path('admin/dashboard.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
            </div>
        </header>

        <div class="row g-4">
            <div class="col-lg-8">
                <article class="content-card sa-card">
                    <div class="card-heading sa-card-head">
                        <div>
                            <h4>Zonas configuradas</h4>
                            <p class="sa-card-sub"><?= count($zones) ?> registos</p>
                        </div>
                    </div>
                    <?php if (!$zones): ?>
                        <div class="empty-state py-5">
                            <i class="bi bi-truck" style="font-size:2.5rem;"></i>
                            <p class="mt-3 mb-0">Ainda não existem zonas de entrega.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle sa-table">
                                <thead>
                                    <tr>
                                        <th>Província</th>
                                        <th>Distrito</th>
                                        <th class="text-end">Taxa</th>
                                        <th class="text-center">Prazo</th>
                                        <th>Estado</th>
                                        <th class="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($zones as $z): $on = (int)$z['is_active'] === 1; ?>
                                    <tr>
                                        <td><?= e($z['province']) ?></td>
                                        <td><?= e($z['district']) ?></td>
                                        <td class="text-end"><strong><?= money($z['fee']) ?></strong></td>
                                        <td class="text-center"><?= (int)$z['eta_days'] ?> dia(s)</td>
                                        <td>
                                            <span class="sa-badge sa-badge-<?= $on ? 'success' : 'secondary' ?>">
                                                <i class="bi bi-<?= $on ? 'check-circle-fill' : 'pause-circle-fill' ?>"></i>
                                                <?= $on ? 'Ativa' : 'Inativa' ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <div class="sa-actions">
                                                <a href="?edit=<?= (int)$z['id'] ?>#zoneForm" class="sa-btn sa-btn-ghost" title="Editar"><i class="bi bi-pencil"></i> <span>Editar</span></a>
                                                <?= post_button('', ['action' => 'toggle', 'id' => (int)$z['id']], '<i class="bi bi-power"></i> <span>' . ($on ? 'Desativar' : 'Ativar') . '</span>', 'sa-btn ' . ($on ? 'sa-btn-ghost' : 'sa-btn-success'), null, $on ? 'Desativar' : 'Ativar') ?>
                                                <?= post_button('', ['action' => 'delete', 'id' => (int)$z['id']], '<i class="bi bi-trash"></i>', 'sa-btn sa-btn-danger', 'Apagar a zona ' . $z['province'] . ' / ' . $z['district'] . '?', 'Apagar') ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>
            </div>

            <div class="col-lg-4">
                <article class="content-card sa-card sa-form-card" id="zoneForm">
                    <div class="card-heading">
                        <div>
                            <h4><?= $editing ? 'Editar zona' : 'Nova zona' ?></h4>
                            <p class="sa-card-sub"><?= $editing ? '<a href="' . base_path('admin/zonas.php') . '">Cancelar edição</a>' : 'Taxa e prazo por distrito' ?></p>
                        </div>
                    </div>
                    <form method="post" action="<?= base_path('admin/zonas.php') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
                        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label" for="zProvince">Província</label>
                            <select id="zProvince" name="province" class="form-select" required>
                                <option value="">— Selecionar —</option>
                                <?php foreach (provinces() as $pv): ?>
                                    <option value="<?= e($pv) ?>" <?= $form['province'] === $pv ? 'selected' : '' ?>><?= e($pv) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="zDistrict">Distrito</label>
                            <input id="zDistrict" name="district" class="form-control" required maxlength="100" value="<?= e($form['district']) ?>" placeholder="Ex.: KaMpfumo">
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label" for="zFee">Taxa (MT)</label>
                                <input id="zFee" name="fee" type="number" step="0.01" min="0" class="form-control" required value="<?= e($form['fee']) ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="zEta">Prazo (dias)</label>
                                <input id="zEta" name="eta_days" type="number" step="1" min="0" max="30" class="form-control" required value="<?= e($form['eta_days']) ?>">
                            </div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="zActive" <?= (int)$form['is_active'] === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="zActive">Zona ativa</label>
                        </div>
                        <button class="btn btn-primary w-100">
                            <i class="bi bi-<?= $editing ? 'check2' : 'plus-lg' ?>"></i> <?= $editing ? 'Guardar alterações' : 'Adicionar zona' ?>
                        </button>
                    </form>
                </article>
            </div>
        </div>

    </section>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
