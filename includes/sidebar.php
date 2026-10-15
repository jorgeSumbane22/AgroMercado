<?php
/* ============================================================
   SIDEBAR — Dashboard por role
   ============================================================ */
$role = user()['role'] ?? '';
$base = $role === 'superadmin' ? 'admin' : ($role === 'farmer' ? 'agricultor' : 'comprador');

/* Deteção da página atual */
$currentFile = basename($_SERVER['PHP_SELF'] ?? '');

/* ============================================================
   DEFINIÇÃO DOS LINKS POR ROLE (agrupados)
   ============================================================ */
$groups = [];

if ($role === 'superadmin') {

    $groups = [
        [
            'label' => 'Visão geral',
            'items' => [
                ['dashboard.php', 'grid-1x2', 'Dashboard'],
            ],
        ],
        [
            'label' => 'Utilizadores',
            'items' => [
                ['utilizadores.php', 'person-lines-fill', 'Utilizadores'],
                ['agricultores.php', 'people-fill',    'Agricultores'],
                ['compradores.php',  'bag-check-fill', 'Compradores'],
            ],
        ],
        [
            'label' => 'Catálogo',
            'items' => [
                ['produtos.php',   'basket-fill',  'Produtos'],
                ['categorias.php', 'tags-fill',    'Categorias'],
                ['avaliacoes.php', 'star-fill',    'Avaliações'],
            ],
        ],
        [
            'label' => 'Operações',
            'items' => [
                ['pedidos.php',      'receipt',            'Pedidos'],
                ['pagamentos.php',   'credit-card-fill',   'Pagamentos'],
                ['reembolsos.php',   'arrow-counterclockwise', 'Reembolsos'],
                ['repasses.php',     'cash-stack',         'Repasses'],
                ['cupoes.php',       'ticket-perforated-fill', 'Cupões'],
                ['verificacoes.php', 'patch-check-fill',   'Verificações'],
                ['denuncias.php',    'flag-fill',          'Denúncias'],
                ['zonas.php',        'truck',              'Zonas de entrega'],
            ],
        ],
        [
            'label' => 'Sistema',
            'items' => [
                ['relatorios.php',    'file-earmark-bar-graph-fill', 'Relatórios'],
                ['auditoria.php',     'journal-text',                'Auditoria'],
                ['notificacoes.php',  'bell-fill',                   'Notificações'],
                ['emails.php',        'envelope-fill',               'Emails enviados'],
                ['configuracoes.php', 'gear-fill',                   'Configurações'],
                ['perfil.php',        'person-fill',                 'Perfil'],
            ],
        ],
    ];

} elseif ($role === 'farmer') {

    $groups = [
        [
            'label' => 'Visão geral',
            'items' => [
                ['dashboard.php', 'grid-1x2', 'Dashboard'],
            ],
        ],
        [
            'label' => 'Catálogo',
            'items' => [
                ['produtos.php',          'basket-fill',       'Produtos'],
                ['adicionar-produto.php', 'plus-circle-fill',  'Novo produto'],
                ['stock.php',             'boxes',             'Stock'],
            ],
        ],
        [
            'label' => 'Operações',
            'items' => [
                ['pedidos.php',  'receipt',               'Pedidos'],
                ['clientes.php', 'people-fill',           'Clientes'],
                ['vendas.php',   'graph-up-arrow',        'Vendas'],
                ['financeiro.php', 'wallet2',             'Financeiro'],
            ],
        ],
        [
            'label' => 'Conta',
            'items' => [
                ['relatorios.php',   'file-earmark-bar-graph-fill', 'Relatórios'],
                ['notificacoes.php', 'bell-fill',                   'Notificações'],
                ['perfil.php',       'person-fill',                 'Perfil'],
            ],
        ],
    ];

} else {

    $groups = [
        [
            'label' => 'Visão geral',
            'items' => [
                ['dashboard.php', 'grid-1x2', 'Dashboard'],
            ],
        ],
        [
            'label' => 'Compras',
            'items' => [
                ['pedidos.php',   'receipt',      'Os meus pedidos'],
                ['favoritos.php', 'heart-fill',   'Favoritos'],
                ['avaliacoes.php','star-fill',    'Avaliações'],
            ],
        ],
        [
            'label' => 'Conta',
            'items' => [
                ['notificacoes.php', 'bell-fill',   'Notificações'],
                ['perfil.php',       'person-fill', 'Perfil'],
            ],
        ],
    ];
}

/* Contagem de notificações (se a função existir) */
$notifCount = 0;
if (function_exists('unread_notifications_count')) {
    try { $notifCount = (int)unread_notifications_count(); } catch (Throwable $e) {}
}
?>

<aside class="dashboard-sidebar">

    <!-- ============================================================
         CABEÇALHO: MARCA
         ============================================================ -->
    <div class="sidebar-brand">
        <span class="brand-pro-icon"><i class="bi bi-basket2-fill"></i></span>
        <span class="brand-pro-text">Agro<em>Mercado</em></span>
    </div>

    <!-- ============================================================
         UTILIZADOR
         ============================================================ -->
    <div class="sidebar-user">
        <div class="sidebar-avatar">
            <span class="sidebar-avatar-initial"><?= e(strtoupper(substr(user()['name'] ?? '?', 0, 1))) ?></span>
            <span class="sidebar-avatar-ring"></span>
            <span class="sidebar-avatar-status" title="Online"></span>
        </div>
        <div class="sidebar-user-info">
            <strong><?= e(user()['name'] ?? 'Utilizador') ?></strong>
            <small>
                <i class="bi bi-<?= $role === 'superadmin' ? 'shield-fill-check' : ($role === 'farmer' ? 'person-badge-fill' : 'bag-check-fill') ?>"></i>
                <?= e(ucfirst($role ?: 'utilizador')) ?>
            </small>
        </div>
    </div>

    <!-- ============================================================
         NAVEGAÇÃO AGRUPADA
         ============================================================ -->
    <nav class="sidebar-nav">
        <?php foreach ($groups as $group): ?>
            <div class="sidebar-group">
                <span class="sidebar-group-label"><?= e($group['label']) ?></span>

                <?php foreach ($group['items'] as $item):
                    [$file, $icon, $label] = $item;
                    $isActive = ($currentFile === $file);
                    $isNotif  = ($file === 'notificacoes.php');
                ?>
                    <a href="<?= base_path($base . '/' . $file) ?>"
                       class="sidebar-link <?= $isActive ? 'active' : '' ?>"
                       <?= $isActive ? 'aria-current="page"' : '' ?>>
                        <i class="bi bi-<?= $icon ?>"></i>
                        <span><?= e($label) ?></span>

                        <?php if ($isNotif && $notifCount > 0): ?>
                            <span class="sidebar-badge"><?= $notifCount > 99 ? '99+' : $notifCount ?></span>
                        <?php endif; ?>

                        <?php if ($isActive): ?>
                            <span class="sidebar-active-indicator"></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>

    <!-- ============================================================
         RODAPÉ: VOLTAR AO SITE + SAIR
         ============================================================ -->
    <div class="sidebar-foot">
        <a class="sidebar-link sidebar-link-ghost" href="<?= base_path('index.php') ?>">
            <i class="bi bi-box-arrow-up-right"></i>
            <span>Voltar ao site</span>
        </a>
        <a class="sidebar-link sidebar-link-danger" href="<?= base_path('logout.php') ?>">
            <i class="bi bi-box-arrow-right"></i>
            <span>Sair</span>
        </a>
    </div>

    <!-- ============================================================
         TOGGLE MOBILE (fecha a sidebar)
         ============================================================ -->
    <button type="button"
            class="sidebar-close d-lg-none"
            aria-label="Fechar menu"
            onclick="document.body.classList.remove('sidebar-open')">
        <i class="bi bi-x-lg"></i>
    </button>

</aside>

<!-- ============================================================
     OVERLAY (mobile)
     ============================================================ -->
<div class="sidebar-overlay d-lg-none"
     onclick="document.body.classList.remove('sidebar-open')"></div>