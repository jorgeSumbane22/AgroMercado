</main>

<footer class="footer-premium">
    <div class="footer-glow" aria-hidden="true"></div>

    <div class="container position-relative">

        <!-- ============ TOPO: Marca grande + Newsletter ============ -->
        <div class="footer-hero">
            <div class="row align-items-end g-4">
                <div class="col-lg-7">
                    <span class="footer-eyebrow">Do campo para o mercado</span>
                    <h2 class="footer-title">
                        Agro<em>Mercado</em>
                    </h2>
                    <p class="footer-tagline">
                        Uma plataforma que aproxima produtores e compradores,
                        encurtando a cadeia alimentar em Moçambique.
                    </p>
                </div>
                <div class="col-lg-5">
                    <form class="footer-newsletter" action="<?= base_path('newsletter.php') ?>" method="post">
                        <label class="footer-newsletter-label">
                            <i class="bi bi-envelope-paper-fill"></i>
                            Receba novidades e produtos frescos
                        </label>
                        <div class="footer-newsletter-row">
                            <input type="email" name="email" placeholder="O seu email" required>
                            <button type="submit">
                                Subscrever <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ============ MEIO: Links ============ -->
        <div class="footer-links-grid">
            <div class="row g-5">

                <!-- Explorar -->
                <div class="col-6 col-md-3">
                    <h6 class="footer-heading">Explorar</h6>
                    <ul class="footer-links">
                        <li>
                            <a href="<?= base_path('marketplace.php') ?>">
                                <i class="bi bi-shop"></i> Marketplace
                            </a>
                        </li>
                        <li>
                            <a href="<?= base_path('index.php#categorias') ?>">
                                <i class="bi bi-grid"></i> Categorias
                            </a>
                        </li>
                        <li>
                            <a href="<?= base_path('index.php#como-funciona') ?>">
                                <i class="bi bi-info-circle"></i> Como funciona
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Conta -->
                <div class="col-6 col-md-3">
                    <h6 class="footer-heading">Conta</h6>
                    <ul class="footer-links">
                        <li>
                            <a href="<?= base_path('login.php') ?>">
                                <i class="bi bi-box-arrow-in-right"></i> Entrar
                            </a>
                        </li>
                        <li>
                            <a href="<?= base_path('cadastro.php') ?>">
                                <i class="bi bi-person-plus"></i> Criar conta
                            </a>
                        </li>
                        <li>
                            <a href="<?= base_path('carrinho.php') ?>">
                                <i class="bi bi-bag"></i> Carrinho
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Legal -->
                <div class="col-6 col-md-3">
                    <h6 class="footer-heading">Legal</h6>
                    <ul class="footer-links">
                        <li>
                            <a href="<?= base_path('termos.php') ?>">
                                <i class="bi bi-file-text"></i> Termos
                            </a>
                        </li>
                        <li>
                            <a href="<?= base_path('privacidade.php') ?>">
                                <i class="bi bi-shield-check"></i> Privacidade
                            </a>
                        </li>
                        <li>
                            <a href="<?= base_path('cookies.php') ?>">
                                <i class="bi bi-cookie"></i> Cookies
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Contacto -->
                <div class="col-6 col-md-3">
                    <h6 class="footer-heading">Contacto</h6>
                    <ul class="footer-contact-list">
                        <li>
                            <i class="bi bi-geo-alt-fill"></i>
                            <span>Maputo, Moçambique</span>
                        </li>
                        <li>
                            <i class="bi bi-envelope-fill"></i>
                            <span>jsumbane22@gmail.com</span>
                        </li>
                        <li>
                            <i class="bi bi-clock-fill"></i>
                            <span>Seg – Sex, 8h às 18h</span>
                        </li>
                    </ul>
                </div>

            </div>
        </div>

        <!-- ============ BOTTOM ============ -->
        <div class="footer-bottom">
            <p class="footer-copy">
                <i class="bi bi-c-circle"></i>
                <?= date('Y') ?> AgroMercado — Plataforma demonstrativa para comércio agrícola.
            </p>
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