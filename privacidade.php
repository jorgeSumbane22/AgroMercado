<?php require_once __DIR__.'/includes/functions.php'; $page_title='Política de privacidade'; include __DIR__.'/includes/header.php'; ?>
<style>
.legal-page h2{font-size:1.35rem;margin-top:2.2rem;scroll-margin-top:100px}
.legal-page h3{font-size:1.05rem;margin-top:1.2rem}
.legal-page li{margin-bottom:.35rem}
.legal-toc{border:1px solid var(--line,#e5e7eb);border-radius:12px;padding:1rem 1.25rem;margin:1.5rem 0}
.legal-toc ol{margin:0;padding-left:1.2rem;columns:2;column-gap:2rem}
@media (max-width:767px){.legal-toc ol{columns:1}}
.legal-draft{border-left:4px solid #f0ad4e}
</style>
<section class="container py-5">
<div class="content-card legal-page">
    <span class="eyebrow">Informação legal</span>
    <h1 class="mt-2">Política de privacidade</h1>
    <p class="text-muted mb-2">Versão 1.0 — outubro de 2026</p>

    <div class="alert alert-warning legal-draft" role="note">
        <strong><i class="bi bi-exclamation-triangle me-1"></i>Rascunho — deve ser revisto por um jurista antes da publicação.</strong>
        Este texto é uma proposta de trabalho e não constitui aconselhamento jurídico. Os elementos entre parênteses retos devem ser preenchidos pelo operador da plataforma.
    </div>

    <nav class="legal-toc" aria-label="Índice">
        <strong>Índice</strong>
        <ol class="mt-2">
            <li><a href="#responsavel">Responsável pelo tratamento</a></li>
            <li><a href="#dados-recolhidos">Dados que recolhemos</a></li>
            <li><a href="#finalidades">Finalidades</a></li>
            <li><a href="#fundamentos">Fundamentos para o tratamento</a></li>
            <li><a href="#partilha">Partilha de dados</a></li>
            <li><a href="#transferencias">Serviços externos e transferências</a></li>
            <li><a href="#conservacao">Prazos de conservação</a></li>
            <li><a href="#seguranca">Segurança</a></li>
            <li><a href="#direitos">Os seus direitos</a></li>
            <li><a href="#menores">Menores</a></li>
            <li><a href="#cookies">Cookies</a></li>
            <li><a href="#alteracoes">Alterações</a></li>
            <li><a href="#contacto">Contacto</a></li>
        </ol>
    </nav>

    <p>Esta Política explica que dados pessoais o AgroMercado trata, para que fins, com quem os partilha, durante quanto tempo os conserva e que direitos tem o titular dos dados. Aplica-se a visitantes, compradores, agricultores e administradores da plataforma.</p>

    <h2 id="responsavel">1. Responsável pelo tratamento</h2>
    <p>O responsável pelo tratamento é [NOME DA ENTIDADE OPERADORA], com sede em [ENDEREÇO], contactável através de [EMAIL DE CONTACTO]. Questões sobre privacidade podem ser enviadas para [EMAIL DE PRIVACIDADE].</p>

    <h2 id="dados-recolhidos">2. Dados que recolhemos</h2>
    <h3>2.1 Dados de conta (todos os utilizadores)</h3>
    <ul>
        <li>Nome, endereço de email, número de telefone e palavra-passe (guardada apenas de forma cifrada, através de função de hash, nunca em texto legível).</li>
        <li>Tipo de perfil (comprador ou agricultor), estado da conta, data de criação, confirmação de email e, se ativada, configuração de autenticação em dois passos.</li>
        <li>Fotografia de perfil, se a carregar.</li>
    </ul>
    <h3>2.2 Dados de compra e entrega (compradores)</h3>
    <ul>
        <li>Endereço de entrega (província, distrito, cidade/localidade, morada e referências), telefone de contacto e notas de entrega.</li>
        <li>Histórico de pedidos, carrinho, favoritos, avaliações e denúncias feitas.</li>
        <li>Dados do pagamento necessários à sua confirmação: método escolhido, montante, estado e referência da transação. Não guardamos dados completos de cartões; esses dados são tratados diretamente pelos prestadores de pagamento.</li>
    </ul>
    <h3>2.3 Dados de agricultores</h3>
    <ul>
        <li>Localização da exploração (província, distrito, localidade), especialidade e descrição pública.</li>
        <li><strong>Documentos de verificação</strong>: cópia do documento de identificação (por exemplo, BI, passaporte ou DIRE), licenças/alvarás e certificados, com as respetivas datas de validade e o resultado da análise. Estes documentos são guardados numa área protegida e só são acessíveis à Administração.</li>
        <li>Produtos publicados, stock, vendas, valores de comissão e dados necessários para os repasses (por exemplo, conta M-Pesa/e-Mola ou bancária indicada).</li>
    </ul>
    <h3>2.4 Comunicações</h3>
    <ul>
        <li>Mensagens trocadas entre comprador e agricultor no âmbito de um pedido, notificações e emails enviados pela plataforma.</li>
    </ul>
    <h3>2.5 Dados técnicos e de segurança</h3>
    <ul>
        <li>Endereço IP, data e hora de acesso, tentativas de login (com sucesso ou falhadas), registos de auditoria de ações relevantes (por exemplo, alterações de pedidos, de produtos ou de estado de contas) e identificadores de sessão.</li>
    </ul>

    <h2 id="finalidades">3. Finalidades</h2>
    <ul>
        <li>Criar e gerir contas, autenticar utilizadores e manter a sessão iniciada.</li>
        <li>Publicar produtos, processar pedidos, pagamentos, entregas, cancelamentos, reembolsos e repasses aos agricultores, incluindo o cálculo da comissão da plataforma.</li>
        <li>Verificar a identidade e a habilitação dos agricultores, controlar a validade dos documentos e avisar o agricultor antes da sua expiração.</li>
        <li>Enviar notificações sobre pedidos, pagamentos, verificação e segurança da conta.</li>
        <li>Moderar conteúdos, tratar avaliações, denúncias e reclamações e mediar conflitos entre utilizadores.</li>
        <li>Prevenir fraude e abusos, proteger a plataforma (por exemplo, limitação de tentativas de login) e manter registos de auditoria.</li>
        <li>Produzir estatísticas e relatórios agregados sobre a atividade da plataforma e, para cada agricultor, sobre as suas vendas.</li>
        <li>Cumprir obrigações legais, fiscais e contabilísticas e responder a pedidos de autoridades competentes.</li>
    </ul>
    <p>Não vendemos dados pessoais nem os utilizamos para publicidade personalizada de terceiros.</p>

    <h2 id="fundamentos">4. Fundamentos para o tratamento</h2>
    <ul>
        <li><strong>Execução do contrato</strong> — para criar a conta e prestar o serviço de marketplace (pedidos, pagamentos, entregas, repasses).</li>
        <li><strong>Cumprimento de obrigações legais</strong> — por exemplo, conservação de documentos contabilísticos e fiscais e resposta a autoridades.</li>
        <li><strong>Interesse legítimo</strong> — segurança da plataforma, prevenção de fraude, verificação de agricultores para proteger os compradores, auditoria e melhoria do serviço, sempre ponderado com os direitos dos titulares.</li>
        <li><strong>Consentimento</strong> — quando exigido, por exemplo, para comunicações promocionais (se vierem a existir), podendo ser retirado a qualquer momento.</li>
    </ul>
    <p>O tratamento observa os princípios constitucionais de proteção da vida privada e a legislação moçambicana aplicável, incluindo, como referência geral para as transações e comunicações eletrónicas, a Lei n.º 3/2017 (Lei das Transações Electrónicas).</p>

    <h2 id="partilha">5. Partilha de dados</h2>
    <ul>
        <li><strong>Agricultores</strong> — veem o nome, telefone e dados de entrega do comprador apenas dos pedidos que incluem os seus produtos, e apenas para os cumprir. Não podem usar estes dados para outros fins.</li>
        <li><strong>Compradores</strong> — veem o nome público do agricultor, a sua localização geral (província/distrito), a descrição pública e as avaliações.</li>
        <li><strong>Público</strong> — produtos, nome público do agricultor e avaliações (com o nome do autor) são visíveis a qualquer visitante.</li>
        <li><strong>Prestadores de pagamento</strong> (por exemplo, operadores de M-Pesa, e-Mola, bancos ou processadores de cartões) — recebem os dados necessários para processar pagamentos e reembolsos.</li>
        <li><strong>Prestadores de serviços técnicos</strong> — alojamento, envio de email e cópias de segurança, que atuam por conta do Operador e sujeitos a deveres de confidencialidade.</li>
        <li><strong>Transportadores</strong> — quando usados pelo agricultor, recebem os dados de entrega necessários.</li>
        <li><strong>Autoridades</strong> — quando exigido por lei ou por decisão judicial, ou para defesa de direitos em processos legais.</li>
    </ul>

    <h2 id="transferencias">6. Serviços externos e transferências</h2>
    <p>Para apresentar as páginas, a plataforma carrega tipos de letra (Google Fonts) e bibliotecas de interface (jsDelivr) a partir de redes de distribuição de conteúdos externas. Ao carregar estes recursos, o seu navegador comunica com esses serviços, que podem receber o seu endereço IP e informação técnica do navegador e estar localizados fora de Moçambique. Não usamos ferramentas de análise ou de rastreio de terceiros. Se forem utilizados outros prestadores fora do país, o Operador adotará garantias adequadas para proteger os dados.</p>

    <h2 id="conservacao">7. Prazos de conservação</h2>
    <ul>
        <li><strong>Dados de conta</strong> — enquanto a conta estiver ativa; após o encerramento, são eliminados ou anonimizados num prazo de [PRAZO, por exemplo 90 dias], salvo o que tiver de ser conservado pelos motivos seguintes.</li>
        <li><strong>Pedidos, pagamentos, reembolsos e repasses</strong> — pelo prazo exigido pela legislação fiscal e comercial aplicável [PRAZO LEGAL A CONFIRMAR, por exemplo 10 anos].</li>
        <li><strong>Documentos de verificação de agricultores</strong> — enquanto a conta estiver ativa e, após o seu encerramento ou a substituição do documento, durante [PRAZO, por exemplo 1 ano] para efeitos de prova e prevenção de fraude.</li>
        <li><strong>Registos de segurança e auditoria</strong> — durante [PRAZO, por exemplo 2 anos].</li>
        <li><strong>Tentativas de login e tokens</strong> — eliminados quando deixam de ser necessários (tokens de "manter sessão" expiram ao fim de 30 dias).</li>
    </ul>

    <h2 id="seguranca">8. Segurança</h2>
    <p>Aplicamos medidas técnicas e organizativas adequadas, entre as quais: palavras-passe guardadas com hash, proteção contra pedidos forjados (CSRF), limitação de tentativas de login, cookies de sessão marcados como HttpOnly, autenticação em dois passos opcional, controlo de acessos por perfil, documentos de verificação fora da área pública, validação dos ficheiros carregados, registos de auditoria e cópias de segurança. Nenhum sistema é totalmente seguro; em caso de violação de dados com risco para os titulares, o Operador informará os afetados e as autoridades quando a lei o exigir.</p>

    <h2 id="direitos">9. Os seus direitos</h2>
    <p>Nos termos da lei aplicável, o titular pode, a qualquer momento:</p>
    <ul>
        <li>aceder aos seus dados pessoais e obter uma cópia;</li>
        <li>retificar dados inexatos ou incompletos (muitos podem ser alterados diretamente no perfil);</li>
        <li>pedir a eliminação dos dados, salvo quando a sua conservação for legalmente exigida ou necessária para pedidos em curso;</li>
        <li>opor-se ao tratamento baseado em interesse legítimo e pedir a limitação do tratamento;</li>
        <li>retirar o consentimento, quando o tratamento se basear nele;</li>
        <li>pedir a portabilidade dos dados que forneceu, num formato estruturado;</li>
        <li>apresentar reclamação junto da autoridade competente.</li>
    </ul>
    <p>Os pedidos podem ser enviados para [EMAIL DE PRIVACIDADE]. Podemos pedir prova de identidade antes de responder. Responderemos num prazo razoável, em regra até 30 dias.</p>

    <h2 id="menores">10. Menores</h2>
    <p>A plataforma destina-se a maiores de 18 anos. Não recolhemos intencionalmente dados de menores; se tomarmos conhecimento disso, os dados serão eliminados.</p>

    <h2 id="cookies">11. Cookies</h2>
    <p>Utilizamos apenas cookies e armazenamento local estritamente necessários e de preferência. Os detalhes estão na <a href="<?= base_path('cookies.php') ?>">Política de cookies</a>.</p>

    <h2 id="alteracoes">12. Alterações</h2>
    <p>Esta Política pode ser atualizada. As alterações relevantes serão comunicadas na plataforma ou por email antes de entrarem em vigor. A versão e a data em vigor são indicadas no início desta página.</p>

    <h2 id="contacto">13. Contacto</h2>
    <ul class="list-unstyled">
        <li><i class="bi bi-building me-2"></i>[NOME DA ENTIDADE OPERADORA]</li>
        <li><i class="bi bi-geo-alt me-2"></i>[ENDEREÇO]</li>
        <li><i class="bi bi-envelope me-2"></i>[EMAIL DE CONTACTO] · privacidade: [EMAIL DE PRIVACIDADE]</li>
        <li><i class="bi bi-telephone me-2"></i>[TELEFONE DE CONTACTO]</li>
    </ul>
    <p class="text-muted small mt-4 mb-0">Versão 1.0 — outubro de 2026. Consulte também os <a href="<?= base_path('termos.php') ?>">Termos de utilização</a>.</p>
</div>
</section>
<?php include __DIR__.'/includes/footer.php'; ?>
