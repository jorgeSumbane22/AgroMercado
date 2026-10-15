</main>

<footer class="site-footer">
    <div class="container">
        <div class="site-footer-top">
            <div class="site-footer-brand">
                <a class="brand-pro" href="<?= base_path('index.php') ?>">
                    <span class="brand-pro-icon"><i class="bi bi-basket2-fill" aria-hidden="true"></i></span>
                    <span class="brand-pro-text">Agro<em>Mercado</em></span>
                </a>
                <p>Uma plataforma que aproxima produtores e compradores, encurtando a cadeia alimentar em Moçambique.</p>
            </div>

            <nav class="site-footer-col" aria-label="Explorar">
                <h2 class="footer-heading">Explorar</h2>
                <ul class="footer-links">
                    <li><a href="<?= base_path('marketplace.php') ?>">Marketplace</a></li>
                    <li><a href="<?= base_path('index.php#categorias') ?>">Categorias</a></li>
                    <li><a href="<?= base_path('index.php#como-funciona') ?>">Como funciona</a></li>
                </ul>
            </nav>

            <nav class="site-footer-col" aria-label="Conta">
                <h2 class="footer-heading">Conta</h2>
                <ul class="footer-links">
                    <li><a href="<?= base_path('login.php') ?>">Entrar</a></li>
                    <li><a href="<?= base_path('cadastro.php') ?>">Criar conta</a></li>
                    <li><a href="<?= base_path('carrinho.php') ?>">Carrinho</a></li>
                </ul>
            </nav>

            <div class="site-footer-col">
                <h2 class="footer-heading">Contacto</h2>
                <ul class="footer-contact-list">
                    <li><i class="bi bi-geo-alt" aria-hidden="true"></i> <span>Maputo, Moçambique</span></li>
                    <li><i class="bi bi-envelope" aria-hidden="true"></i> <span>jsumbane22@gmail.com</span></li>
                    <li><i class="bi bi-clock" aria-hidden="true"></i> <span>Seg – Sex, 8h às 18h</span></li>
                </ul>
            </div>
        </div>

        <div class="site-footer-bottom">
            <span>© <?= date('Y') ?> AgroMercado — comércio agrícola em Moçambique</span>
            <nav aria-label="Legal">
                <a href="<?= base_path('termos.php') ?>">Termos</a>
                <a href="<?= base_path('privacidade.php') ?>">Privacidade</a>
                <a href="<?= base_path('cookies.php') ?>">Cookies</a>
            </nav>
        </div>
    </div>
</footer>

<div id="toast-container" class="toast-container position-fixed bottom-0 end-0 p-3"></div>

<script>
    window.BASE_URL = '<?= base_path() ?>';
    window.CSRF     = '<?= e(csrf_token()) ?>';
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="<?= asset('js/app.js') ?>"></script>
</body>
</html>
