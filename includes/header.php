<?php require_once __DIR__.'/permissions.php'; $page_title = $page_title ?? 'AgroMercado'; ?>
<!doctype html>
<html lang="pt" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title) ?> · AgroMercado</title>
    <meta name="description" content="Marketplace agrícola que conecta produtores e compradores em Moçambique">

    <!-- ============================================================
         ANTI-FLASH: define o tema ANTES de qualquer CSS renderizar
         ============================================================ -->
    <script>
    (function () {
        var saved = null;
        try { saved = localStorage.getItem('agromercado-theme'); } catch (e) {}
        var theme = saved || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', theme);
        document.documentElement.setAttribute('data-bs-theme', theme);
    })();
    </script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <!-- CSS do projeto -->
    <link rel="stylesheet" href="<?= asset('css/style.css') ?>">
</head>
<body>

<?php include __DIR__.'/navbar.php'; ?>

<main>
<?php
/* ============================================================
   FLASHES — renderizados como toast no topo direito
   ============================================================ */
$flashes = function_exists('get_flashes') ? get_flashes() : [];
if (!empty($flashes)):
?>
    <div class="sa-toasts" id="saToasts">
        <?php foreach ($flashes as $f):
            $type = $f['type'] ?? 'info';
            $icon = match ($type) {
                'success' => 'check-circle-fill',
                'danger', 'error' => 'exclamation-octagon-fill',
                'warning' => 'exclamation-triangle-fill',
                default   => 'info-circle-fill',
            };
        ?>
            <div class="sa-toast sa-toast-<?= e($type) ?>" role="alert">
                <span class="sa-toast-icon"><i class="bi bi-<?= $icon ?>"></i></span>
                <div class="sa-toast-body"><?= e($f['message']) ?></div>
                <button type="button" class="sa-toast-close" aria-label="Fechar" onclick="this.parentElement.remove()">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
    (function () {
        var wrap = document.getElementById('saToasts');
        if (!wrap) return;
        // Auto-fechar em 5 s
        wrap.querySelectorAll('.sa-toast').forEach(function (t, i) {
            setTimeout(function () {
                t.classList.add('leaving');
                setTimeout(function () { t.remove(); }, 250);
            }, 5000 + i * 300);
        });
    })();
    </script>
<?php endif; ?>