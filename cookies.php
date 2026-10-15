<?php require_once __DIR__.'/includes/functions.php'; $page_title='Política de cookies'; include __DIR__.'/includes/header.php'; ?>
<style>
.legal-page h2{font-size:1.35rem;margin-top:2.2rem;scroll-margin-top:100px}
.legal-page li{margin-bottom:.35rem}
.legal-toc{border:1px solid var(--line,#e5e7eb);border-radius:12px;padding:1rem 1.25rem;margin:1.5rem 0}
.legal-toc ol{margin:0;padding-left:1.2rem}
.legal-draft{border-left:4px solid #f0ad4e}
.legal-page table{font-size:.92rem}
</style>
<section class="container py-5">
<div class="content-card legal-page">
    <span class="eyebrow">Informação legal</span>
    <h1 class="mt-2">Política de cookies</h1>
    <p class="text-muted mb-2">Versão 1.0 — outubro de 2026</p>

    <div class="alert alert-warning legal-draft" role="note">
        <strong><i class="bi bi-exclamation-triangle me-1"></i>Rascunho — deve ser revisto por um jurista antes da publicação.</strong>
        Este texto é uma proposta de trabalho e não constitui aconselhamento jurídico.
    </div>

    <nav class="legal-toc" aria-label="Índice">
        <strong>Índice</strong>
        <ol class="mt-2">
            <li><a href="#o-que-sao">O que são cookies</a></li>
            <li><a href="#utilizados">Cookies e armazenamento que utilizamos</a></li>
            <li><a href="#terceiros">Serviços de terceiros</a></li>
            <li><a href="#controlo">Como controlar os cookies</a></li>
            <li><a href="#alteracoes">Alterações e contacto</a></li>
        </ol>
    </nav>

    <h2 id="o-que-sao">1. O que são cookies</h2>
    <p>Cookies são pequenos ficheiros de texto que um site guarda no seu navegador para o reconhecer entre páginas ou visitas. O armazenamento local (<em>localStorage</em>) é uma tecnologia semelhante, que guarda informação apenas no seu dispositivo e não é enviada automaticamente para o servidor.</p>

    <h2 id="utilizados">2. Cookies e armazenamento que utilizamos</h2>
    <p>O AgroMercado utiliza apenas o mínimo necessário para funcionar com segurança e para lembrar a sua preferência de tema. <strong>Não utilizamos cookies de publicidade, de rastreio ou de análise de terceiros.</strong></p>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr><th scope="col">Nome</th><th scope="col">Tipo</th><th scope="col">Finalidade</th><th scope="col">Duração</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>PHPSESSID</code></td>
                    <td>Cookie essencial (próprio)</td>
                    <td>Identifica a sua sessão: mantém o login, o carrinho, as mensagens de confirmação e o token de segurança contra pedidos forjados (CSRF). Marcado como HttpOnly e SameSite=Lax.</td>
                    <td>Sessão — termina quando fecha o navegador ou termina a sessão.</td>
                </tr>
                <tr>
                    <td><code>agro_remember</code></td>
                    <td>Cookie essencial/funcional (próprio)</td>
                    <td>Só é criado se escolher "Manter sessão iniciada" no login. Contém um identificador aleatório (não a palavra-passe) que permite restaurar a sessão de forma segura; é renovado a cada utilização e apagado ao terminar a sessão. Marcado como HttpOnly.</td>
                    <td>30 dias.</td>
                </tr>
                <tr>
                    <td><code>agromercado-theme</code></td>
                    <td>Armazenamento local (localStorage)</td>
                    <td>Guarda a preferência de tema claro ou escuro. Fica apenas no seu dispositivo e não é enviado para o servidor.</td>
                    <td>Até o apagar nas definições do navegador.</td>
                </tr>
            </tbody>
        </table>
    </div>
    <p>Como estes elementos são estritamente necessários para o serviço solicitado (ou apenas guardam uma preferência sua no próprio dispositivo), não é pedido consentimento prévio. Se no futuro forem introduzidos cookies não essenciais (por exemplo, de estatística), só serão ativados com o seu consentimento e esta política será atualizada.</p>

    <h2 id="terceiros">3. Serviços de terceiros</h2>
    <p>As páginas carregam tipos de letra do <strong>Google Fonts</strong> e bibliotecas de interface (Bootstrap e ícones) do <strong>jsDelivr</strong>. Estes serviços não definem cookies através do AgroMercado, mas, ao fornecer os ficheiros, podem receber o seu endereço IP e informação técnica do navegador (por exemplo, o tipo de navegador e a página de origem), de acordo com as respetivas políticas de privacidade. Algumas páginas internas de relatórios carregam também gráficos (Chart.js) a partir do jsDelivr.</p>

    <h2 id="controlo">4. Como controlar os cookies</h2>
    <ul>
        <li>Pode consultar e apagar cookies e dados de sites nas definições do seu navegador (normalmente em "Privacidade" ou "Definições do site").</li>
        <li>Se bloquear o cookie <code>PHPSESSID</code>, não será possível iniciar sessão nem fazer pedidos.</li>
        <li>Para remover o cookie <code>agro_remember</code>, basta terminar sessão ("Sair") ou não marcar "Manter sessão iniciada".</li>
        <li>Apagar o armazenamento local repõe o tema predefinido.</li>
    </ul>

    <h2 id="alteracoes">5. Alterações e contacto</h2>
    <p>Esta política pode ser atualizada quando mudarem os cookies utilizados. A versão e a data em vigor são indicadas no início da página. Para questões, contacte [EMAIL DE CONTACTO] ou [ENDEREÇO]. Mais informação sobre o tratamento de dados na <a href="<?= base_path('privacidade.php') ?>">Política de privacidade</a> e nos <a href="<?= base_path('termos.php') ?>">Termos de utilização</a>.</p>
    <p class="text-muted small mt-4 mb-0">Versão 1.0 — outubro de 2026.</p>
</div>
</section>
<?php include __DIR__.'/includes/footer.php'; ?>
