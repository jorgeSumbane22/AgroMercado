<?php require_once __DIR__.'/../includes/permissions.php'; ?>
<?php
require_role('superadmin');
require_once __DIR__.'/_helpers.php';

$page_title = 'Auditoria';
$pdo = db();

/* ============================================================
   FILTROS (ação, módulo, utilizador, datas, texto)
   ============================================================ */
$actionFilter = mb_substr(trim((string)($_GET['action'] ?? '')), 0, 80);
$moduleFilter = mb_substr(trim((string)($_GET['module'] ?? '')), 0, 80);
$userFilter   = max(0, safe_int($_GET['user'] ?? 0));
$fFrom        = admin_valid_date($_GET['from'] ?? '');
$fTo          = admin_valid_date($_GET['to'] ?? '');
$q            = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);

$where = []; $params = [];
if ($actionFilter !== '') { $where[] = 'a.action = ?'; $params[] = $actionFilter; }
if ($moduleFilter !== '') { $where[] = 'a.module = ?'; $params[] = $moduleFilter; }
if ($userFilter)          { $where[] = 'a.user_id = ?'; $params[] = $userFilter; }
if ($fFrom !== '')        { $where[] = 'a.created_at >= ?'; $params[] = $fFrom . ' 00:00:00'; }
if ($fTo !== '')          { $where[] = 'a.created_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $fTo; }
if ($q !== '')            { $where[] = '(a.description LIKE ? OR u.name LIKE ? OR a.ip LIKE ?)'; $like = '%' . $q . '%'; array_push($params, $like, $like, $like); }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$fromSql = ' FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id';
$hasFilters = (bool)$where;

/* ---------- CSV ---------- */
if (($_GET['export'] ?? '') === 'csv') {
    $st = $pdo->prepare('SELECT a.id, a.created_at, COALESCE(u.name, "Sistema") name, a.user_id, a.action, a.module, a.description, a.ip' . $fromSql . $whereSql . ' ORDER BY a.created_at DESC, a.id DESC LIMIT 50000');
    $st->execute($params);
    $rows = [];
    foreach ($st as $r) $rows[] = [$r['id'], date('d/m/Y H:i:s', strtotime($r['created_at'])), $r['name'], $r['user_id'], $r['action'], $r['module'], $r['description'], $r['ip']];
    audit(user()['id'], 'export', 'audit', 'Exportou ' . count($rows) . ' eventos de auditoria (CSV)');
    csv_download('auditoria_' . date('Ymd_His') . '.csv', ['ID', 'Data', 'Utilizador', 'ID utilizador', 'Ação', 'Módulo', 'Descrição', 'IP'], $rows);
}

/* ---------- Paginação ---------- */
$perPage = 50;
[$page, $offset] = page_offset($perPage);
$st = $pdo->prepare('SELECT a.*, u.name' . $fromSql . $whereSql . ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$st->execute($params);
$logs = $st->fetchAll();

/* ============================================================
   ESTATÍSTICAS (sobre todo o filtro)
   ============================================================ */
$st = $pdo->prepare('SELECT COUNT(*) total, COALESCE(SUM(a.ip IS NOT NULL AND a.ip <> ""),0) with_ip, COALESCE(SUM(DATE(a.created_at)=CURDATE()),0) today, COUNT(DISTINCT a.module) modules' . $fromSql . $whereSql);
$st->execute($params);
$agg = $st->fetch();
$totalLogs  = (int)$agg['total'];
$withIp     = (int)$agg['with_ip'];
$todayCount = (int)$agg['today'];
$moduleCount = (int)$agg['modules'];
$today = date('Y-m-d');

$byAction = [];
$st = $pdo->prepare('SELECT a.action, COUNT(*) n' . $fromSql . $whereSql . ' GROUP BY a.action ORDER BY n DESC LIMIT 12');
$st->execute($params);
foreach ($st as $r) $byAction[$r['action']] = (int)$r['n'];
$topAction = array_key_first($byAction);
$topActionN = $topAction !== null ? $byAction[$topAction] : 0;

/* Listas para os selects de filtro */
$allActions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$allModules = $pdo->query('SELECT DISTINCT module FROM audit_logs ORDER BY module')->fetchAll(PDO::FETCH_COLUMN);
$allUsers   = $pdo->query('SELECT DISTINCT u.id, u.name FROM audit_logs a JOIN users u ON u.id = a.user_id ORDER BY u.name')->fetchAll();

$filterQuery = fn(array $over) => http_build_query(array_filter(array_merge(['action' => $actionFilter ?: null, 'module' => $moduleFilter ?: null, 'user' => $userFilter ?: null, 'from' => $fFrom ?: null, 'to' => $fTo ?: null, 'q' => $q !== '' ? $q : null], $over), fn($v) => $v !== null && $v !== ''));

/* Mapa de cores por tipo de ação */
function action_meta(string $a): array {
    $a = strtolower($a);
    return match (true) {
        str_contains($a, 'creat') || str_contains($a, 'create') => ['success',   'plus-circle',    'Criação'],
        str_contains($a, 'updat') || str_contains($a, 'update') => ['info',      'pencil-square',  'Alteração'],
        str_contains($a, 'delet') || str_contains($a, 'remove') => ['danger',    'trash',          'Eliminação'],
        str_contains($a, 'login')                               => ['primary',   'box-arrow-in-right', 'Sessão'],
        str_contains($a, 'logout')                              => ['secondary', 'box-arrow-right','Sessão'],
        str_contains($a, 'review')                              => ['warning',   'shield-check',   'Revisão'],
        default                                                 => ['secondary', 'activity',       ucfirst($a)],
    };
}

include __DIR__.'/../includes/header.php';
?>

<div class="dashboard-layout">
    <?php include __DIR__.'/../includes/sidebar.php'; ?>

    <section class="dashboard-content sa-dash">

        <!-- ================= HERO ================= -->
        <header class="sa-hero">
            <div>
                <span class="sa-hero-eyebrow">
                    <span class="sa-dot"></span> Segurança · Rastreabilidade
                </span>
                <h1 class="sa-hero-title">Registo de <em>auditoria</em></h1>
                <p class="sa-hero-sub">
                    <strong><?= $totalLogs ?></strong> eventos<?= $hasFilters ? ' no filtro atual' : '' ?> ·
                    <strong><?= $todayCount ?></strong> hoje
                    <?php if ($actionFilter !== ''): ?>
                        · ação <strong><?= e($actionFilter) ?></strong>
                    <?php endif; ?>
                    <?php if ($moduleFilter !== ''): ?>
                        · módulo <strong><?= e($moduleFilter) ?></strong>
                    <?php endif; ?>
                </p>
            </div>

            <div class="sa-hero-actions">
                <a href="<?= base_path('admin/dashboard.php') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <a href="?<?= e($filterQuery(['export' => 'csv'])) ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-filetype-csv"></i> Exportar CSV
                </a>
                <?php if ($hasFilters): ?>
                    <a href="<?= base_path('admin/auditoria.php') ?>" class="btn btn-primary">
                        <i class="bi bi-x-lg"></i> Limpar filtros
                    </a>
                <?php else: ?>
                    <a href="<?= base_path('admin/relatorios.php') ?>" class="btn btn-primary">
                        <i class="bi bi-bar-chart-line"></i> Relatórios
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <!-- ================= KPIs ================= -->
        <div class="sa-kpi-grid sa-kpi-grid-4">

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-primary"><i class="bi bi-journal-text"></i></span>
                    <?php if ($todayCount > 0): ?>
                        <span class="sa-kpi-badge sa-badge-up">
                            <i class="bi bi-arrow-up-right"></i> <?= $todayCount ?> hoje
                        </span>
                    <?php endif; ?>
                </header>
                <span class="sa-kpi-label">Eventos</span>
                <strong class="sa-kpi-value"><?= $totalLogs ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-clock-history"></i> <?= $hasFilters ? 'no filtro atual' : 'registos no total' ?></span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-info"><i class="bi bi-activity"></i></span>
                </header>
                <span class="sa-kpi-label">Ação mais frequente</span>
                <strong class="sa-kpi-value"><?= $topAction !== null ? e(ucfirst($topAction)) : '—' ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-hash"></i> <?= $topActionN ?> ocorrências</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-success"><i class="bi bi-diagram-3"></i></span>
                </header>
                <span class="sa-kpi-label">Módulos envolvidos</span>
                <strong class="sa-kpi-value"><?= $moduleCount ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-grid"></i> áreas da plataforma</span>
                </footer>
            </article>

            <article class="sa-kpi">
                <header class="sa-kpi-head">
                    <span class="sa-kpi-icon sa-icon-warning"><i class="bi bi-globe2"></i></span>
                </header>
                <span class="sa-kpi-label">Com IP registado</span>
                <strong class="sa-kpi-value"><?= $withIp ?></strong>
                <footer class="sa-kpi-foot">
                    <span class="sa-kpi-sub"><i class="bi bi-shield-check"></i> <?= $totalLogs > 0 ? round(($withIp / $totalLogs) * 100) : 0 ?>% do total</span>
                </footer>
            </article>

        </div>

        <!-- ================= CHIPS: TOP AÇÕES ================= -->
        <?php if (!empty($byAction)): ?>
        <div class="sa-cat-chips">
            <span class="sa-cat-label"><i class="bi bi-funnel"></i> Ações:</span>
            <a href="?<?= e($filterQuery(['action' => null])) ?>"
               class="sa-cat-chip <?= $actionFilter === '' ? 'active' : '' ?>">
                Todas
            </a>
            <?php $i = 0; foreach ($byAction as $a => $n): if ($i++ >= 8) break;
                [$cls, $icon, $label] = action_meta($a);
            ?>
                <a href="?<?= e($filterQuery(['action' => $a])) ?>"
                   class="sa-cat-chip <?= $actionFilter === $a ? 'active' : '' ?>">
                    <i class="bi bi-<?= $icon ?>"></i>
                    <?= e(ucfirst($a)) ?>
                    <strong><?= (int)$n ?></strong>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ================= TABELA ================= -->
        <article class="content-card sa-card">

            <div class="card-heading sa-card-head">
                <div>
                    <h4>Eventos registados</h4>
                    <p class="sa-card-sub"><?= $totalLogs ?> registos</p>
                </div>

            </div>

            <form method="get" class="row g-2 align-items-end mb-3" role="search">
                <div class="col-md-3">
                    <label class="form-label small" for="aq">Texto</label>
                    <input type="text" id="aq" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Descrição, utilizador ou IP…">
                </div>
                <div class="col-md-2">
                    <label class="form-label small" for="aaction">Ação</label>
                    <select id="aaction" name="action" class="form-select form-select-sm">
                        <option value="">Todas</option>
                        <?php foreach ($allActions as $a): ?><option value="<?= e($a) ?>" <?= $actionFilter === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small" for="amodule">Módulo</label>
                    <select id="amodule" name="module" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <?php foreach ($allModules as $m): ?><option value="<?= e($m) ?>" <?= $moduleFilter === $m ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small" for="auser">Utilizador</label>
                    <select id="auser" name="user" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <?php foreach ($allUsers as $uu): ?><option value="<?= (int)$uu['id'] ?>" <?= $userFilter === (int)$uu['id'] ? 'selected' : '' ?>><?= e($uu['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small" for="afrom">De</label>
                    <input type="date" id="afrom" name="from" value="<?= e($fFrom) ?>" class="form-control form-control-sm">
                </div>
                <div class="col-md-1">
                    <label class="form-label small" for="ato">Até</label>
                    <input type="date" id="ato" name="to" value="<?= e($fTo) ?>" class="form-control form-control-sm">
                </div>
                <div class="col-md-1">
                    <button class="btn btn-sm btn-primary w-100"><i class="bi bi-funnel"></i> Filtrar</button>
                </div>
            </form>

            <?php if (empty($logs)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-journal-x" style="font-size:2.5rem;"></i>
                    <p class="mt-3 mb-0"><?= $hasFilters ? 'Nenhum resultado encontrado.' : 'Sem registos de auditoria.' ?></p>
                </div>
            <?php else: ?>
                <div class="sa-scroll-x">
                    <table class="table align-middle sa-table sa-audit-table" id="logTable">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Utilizador</th>
                                <th>Ação</th>
                                <th>Módulo</th>
                                <th>Descrição</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($logs as $l):
                            $action = $l['action'] ?? '—';
                            [$cls, $icon, $label] = action_meta($action);

                            $dataTs = strtotime($l['created_at'] ?? 'now');
                            $isToday = date('Y-m-d', $dataTs) === $today;

                            $search = strtolower(
                                ($l['name'] ?? 'Sistema') . ' ' .
                                ($l['action'] ?? '') . ' ' .
                                ($l['module'] ?? '') . ' ' .
                                ($l['description'] ?? '')
                            );

                            // chave de categoria para os filtros
                            $akey = strtolower($action);
                            $cat = 'other';
                            if (str_contains($akey, 'creat'))  $cat = 'create';
                            if (str_contains($akey, 'updat'))  $cat = 'update';
                            if (str_contains($akey, 'delet') || str_contains($akey, 'remove')) $cat = 'delete';
                            if (str_contains($akey, 'login') || str_contains($akey, 'logout') || str_contains($akey, 'session')) $cat = 'login';
                            if (str_contains($akey, 'review')) $cat = 'review';
                        ?>
                            <tr data-cat="<?= $cat ?>" data-search="<?= e($search) ?>">
                                <td>
                                    <span class="sa-date">
                                        <?php if ($isToday): ?>
                                            <span class="sa-date-today">Hoje</span>
                                        <?php else: ?>
                                            <?= date('d M Y', $dataTs) ?>
                                        <?php endif; ?>
                                        <small><?= date('H:i:s', $dataTs) ?></small>
                                    </span>
                                </td>
                                <td>
                                    <div class="sa-user-cell">
                                        <span class="sa-avatar <?= ($l['name'] ?? null) ? '' : 'sa-avatar-system' ?>">
                                            <?php if ($l['name'] ?? null): ?>
                                                <?= e(mb_strtoupper(mb_substr($l['name'], 0, 1))) ?>
                                            <?php else: ?>
                                                <i class="bi bi-robot"></i>
                                            <?php endif; ?>
                                        </span>
                                        <div class="sa-user-info">
                                            <strong><?= e($l['name'] ?? 'Sistema') ?></strong>
                                            <?php if ($l['user_id'] ?? null): ?>
                                                <small>#<?= (int)$l['user_id'] ?></small>
                                            <?php else: ?>
                                                <small>automático</small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="sa-badge sa-badge-<?= $cls ?>">
                                        <i class="bi bi-<?= $icon ?>"></i>
                                        <?= e(ucfirst($action)) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="sa-module">
                                        <i class="bi bi-diagram-3"></i>
                                        <?= e($l['module'] ?? '—') ?>
                                    </span>
                                </td>
                                <td>
                                    <p class="sa-desc"><?= e($l['description'] ?? '—') ?></p>
                                </td>
                                <td>
                                    <?php if (!empty($l['ip'])): ?>
                                        <span class="sa-ip">
                                            <i class="bi bi-globe2"></i>
                                            <?= e($l['ip']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="sa-ip sa-ip-empty">
                                            <i class="bi bi-dash-circle"></i>
                                            —
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3"><?= pagination($page, $perPage, $totalLogs) ?></div>
            <?php endif; ?>

        </article>

    </section>
</div>

<?php include __DIR__.'/../includes/footer.php'; ?>
