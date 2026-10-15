<?php require_once __DIR__.'/includes/functions.php'; $page_title='Termos de utilização'; include __DIR__.'/includes/header.php'; ?>
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
    <h1 class="mt-2">Termos de utilização</h1>
    <p class="text-muted mb-2">Versão 1.0 — outubro de 2026</p>

    <div class="alert alert-warning legal-draft" role="note">
        <strong><i class="bi bi-exclamation-triangle me-1"></i>Rascunho — deve ser revisto por um jurista antes da publicação.</strong>
        Este texto é uma proposta de trabalho e não constitui aconselhamento jurídico. Os elementos entre parênteses retos (por exemplo, [EMAIL DE CONTACTO]) devem ser preenchidos pelo operador da plataforma.
    </div>

    <nav class="legal-toc" aria-label="Índice">
        <strong>Índice</strong>
        <ol class="mt-2">
            <li><a href="#objeto">Objeto e aceitação</a></li>
            <li><a href="#definicoes">Definições</a></li>
            <li><a href="#papel">Papel da plataforma</a></li>
            <li><a href="#contas">Contas e segurança</a></li>
            <li><a href="#verificacao">Verificação de agricultores</a></li>
            <li><a href="#obrigacoes-agricultores">Obrigações dos agricultores</a></li>
            <li><a href="#obrigacoes-compradores">Obrigações dos compradores</a></li>
            <li><a href="#pedidos">Pedidos</a></li>
            <li><a href="#precos">Preços, pagamentos e comissão</a></li>
            <li><a href="#entregas">Entregas</a></li>
            <li><a href="#cancelamentos">Cancelamentos e reembolsos</a></li>
            <li><a href="#avaliacoes">Avaliações e denúncias</a></li>
            <li><a href="#proibido">Conteúdo e condutas proibidas</a></li>
            <li><a href="#propriedade">Propriedade intelectual</a></li>
            <li><a href="#suspensao">Suspensão e encerramento de contas</a></li>
            <li><a href="#responsabilidade">Limitação de responsabilidade</a></li>
            <li><a href="#dados">Dados pessoais e cookies</a></li>
            <li><a href="#alteracoes">Alterações aos termos</a></li>
            <li><a href="#lei">Lei aplicável e litígios</a></li>
            <li><a href="#contacto">Contacto</a></li>
        </ol>
    </nav>

    <h2 id="objeto">1. Objeto e aceitação</h2>
    <p>Estes Termos de utilização regulam o acesso e a utilização do AgroMercado (a "Plataforma"), um mercado digital que aproxima agricultores e outros produtores agrícolas de compradores em Moçambique. A Plataforma é operada por [NOME DA ENTIDADE OPERADORA], com sede em [ENDEREÇO] (o "Operador").</p>
    <p>Ao criar uma conta, ou ao utilizar a Plataforma, o utilizador declara que leu, compreendeu e aceita estes Termos, a <a href="<?= base_path('privacidade.php') ?>">Política de privacidade</a> e a <a href="<?= base_path('cookies.php') ?>">Política de cookies</a>. Se não concordar, não deve utilizar a Plataforma.</p>
    <p>Para criar uma conta é necessário ter pelo menos 18 anos e capacidade legal para celebrar contratos, ou atuar em representação de uma pessoa coletiva com poderes para tal.</p>

    <h2 id="definicoes">2. Definições</h2>
    <ul>
        <li><strong>Agricultor</strong> — utilizador que publica e vende produtos agrícolas na Plataforma (pessoa singular, cooperativa, associação ou empresa).</li>
        <li><strong>Comprador</strong> — utilizador que adquire produtos através da Plataforma, para consumo próprio ou para revenda.</li>
        <li><strong>Administração</strong> — equipa do Operador responsável pela gestão, moderação e verificação (SuperAdmin).</li>
        <li><strong>Pedido</strong> — encomenda de um ou mais produtos feita por um Comprador a um ou mais Agricultores.</li>
        <li><strong>Comissão</strong> — percentagem cobrada pela Plataforma sobre o valor das vendas concluídas.</li>
    </ul>

    <h2 id="papel">3. Papel da plataforma</h2>
    <p>O AgroMercado atua como intermediário tecnológico: disponibiliza o espaço onde os Agricultores apresentam os seus produtos e onde os Compradores fazem pedidos, bem como ferramentas de comunicação, pagamento, acompanhamento de pedidos e avaliação.</p>
    <p>Salvo indicação expressa em contrário, o contrato de compra e venda é celebrado diretamente entre o Comprador e o Agricultor. O Operador não é proprietário dos produtos anunciados, não os produz nem os armazena, e não garante a sua qualidade, quantidade ou conformidade, sem prejuízo das obrigações de moderação e de cooperação descritas nestes Termos.</p>

    <h2 id="contas">4. Contas e segurança</h2>
    <ul>
        <li>O utilizador deve fornecer dados verdadeiros, completos e atualizados (nome, email, telefone e, quando aplicável, endereço de entrega) e mantê-los atualizados.</li>
        <li>Cada pessoa só pode ter uma conta por perfil (agricultor ou comprador), salvo autorização da Administração.</li>
        <li>As credenciais são pessoais e intransmissíveis. O utilizador é responsável por manter a palavra-passe em segredo e por toda a atividade realizada na sua conta. A opção "Manter sessão iniciada" só deve ser utilizada em dispositivos pessoais.</li>
        <li>Qualquer uso não autorizado da conta deve ser comunicado de imediato através de [EMAIL DE CONTACTO].</li>
        <li>A Plataforma pode exigir a confirmação do endereço de email e medidas adicionais de segurança (por exemplo, autenticação em dois passos) para determinadas operações.</li>
    </ul>

    <h2 id="verificacao">5. Verificação de agricultores</h2>
    <p>Para publicar produtos visíveis no marketplace, o Agricultor tem de ser verificado pela Administração. Para isso, pode ser pedido o envio de documentos como documento de identificação (BI, passaporte ou DIRE), NUIT, licença ou alvará de atividade e certificados de qualidade ou de origem.</p>
    <ul>
        <li>Os documentos de identificação e as licenças devem indicar a respetiva data de validade. Documentos expirados deixam de ser considerados válidos.</li>
        <li>Quando o único documento de identificação aprovado expira, a conta pode voltar ao estado "pendente" e os produtos ativos podem ser desativados até à aprovação de um documento válido. O Agricultor é avisado antes da data de validade e no momento em que o documento expira.</li>
        <li>A verificação confirma apenas que os documentos apresentados foram analisados; não constitui certificação da qualidade dos produtos nem garantia do cumprimento de obrigações fiscais ou sanitárias pelo Agricultor.</li>
        <li>A Administração pode recusar ou revogar a verificação se os documentos forem falsos, ilegíveis, inválidos ou se houver indícios de fraude.</li>
    </ul>

    <h2 id="obrigacoes-agricultores">6. Obrigações dos agricultores</h2>
    <ul>
        <li>Publicar descrições, fotografias, preços, unidades de venda, quantidades mínimas, datas de colheita e de validade exatas e não enganosas. As fotografias devem corresponder ao produto efetivamente vendido.</li>
        <li>Manter o stock atualizado e retirar ou desativar produtos indisponíveis.</li>
        <li>Garantir que os produtos cumprem a legislação aplicável, incluindo normas de higiene, segurança alimentar, rotulagem, sanidade vegetal e animal, e que não são produtos proibidos ou sujeitos a restrições sem as devidas autorizações.</li>
        <li>Confirmar, preparar e entregar (ou disponibilizar para recolha) os pedidos dentro dos prazos indicados, atualizando o estado do pedido na Plataforma.</li>
        <li>Utilizar os dados de entrega dos Compradores apenas para cumprir os respetivos pedidos, nos termos da Política de privacidade.</li>
        <li>Cumprir as suas obrigações fiscais, laborais e legais, incluindo a emissão de documentos de venda quando exigidos por lei.</li>
    </ul>

    <h2 id="obrigacoes-compradores">7. Obrigações dos compradores</h2>
    <ul>
        <li>Fornecer dados de contacto e de entrega corretos e estar disponível para receber o pedido no local e prazo acordados.</li>
        <li>Pagar o preço total do pedido, incluindo a taxa de entrega aplicável, pelos métodos disponíveis.</li>
        <li>Verificar os produtos no momento da receção e comunicar qualquer problema o mais rapidamente possível, preferencialmente no próprio dia.</li>
        <li>Não utilizar a Plataforma para fins fraudulentos, nem fazer pedidos sem intenção de os receber e pagar.</li>
    </ul>

    <h2 id="pedidos">8. Pedidos</h2>
    <ul>
        <li>O pedido é submetido quando o Comprador finaliza o checkout. A submissão constitui uma proposta de compra, que se considera aceite quando o Agricultor confirma o pedido na Plataforma.</li>
        <li>Um pedido pode incluir produtos de vários Agricultores; nesse caso, cada Agricultor é responsável pela parte que lhe diz respeito e o estado de cada item pode evoluir de forma independente.</li>
        <li>Os estados possíveis são: pendente, confirmado, em preparação, pronto para entrega, em transporte, entregue e cancelado. O Comprador é notificado das alterações de estado.</li>
        <li>Produtos agrícolas são bens naturais e perecíveis: pequenas variações de calibre, cor ou peso, dentro do razoável e de acordo com a unidade de venda indicada, não constituem desconformidade.</li>
    </ul>

    <h2 id="precos">9. Preços, pagamentos e comissão</h2>
    <ul>
        <li>Os preços são definidos pelos Agricultores e apresentados em meticais (MT), por unidade de venda. A taxa de entrega, quando aplicável, é apresentada antes da confirmação do pedido.</li>
        <li>Os métodos de pagamento disponíveis (por exemplo, numerário na entrega, M-Pesa, e-Mola, transferência bancária ou cartão) dependem da configuração da Plataforma e podem variar. Pagamentos eletrónicos são processados por prestadores de serviços de pagamento terceiros, sujeitos aos seus próprios termos.</li>
        <li>Um pagamento só se considera confirmado após validação pelo respetivo prestador ou pela Administração.</li>
        <li>Pela intermediação, a Plataforma cobra ao Agricultor uma comissão sobre o valor dos produtos vendidos (excluindo a taxa de entrega, salvo indicação em contrário). A comissão é definida pela plataforma, atualmente indicada no painel do agricultor, e pode ser alterada mediante aviso prévio; a alteração não se aplica a pedidos já realizados.</li>
        <li>Os valores devidos aos Agricultores (valor das vendas deduzido da comissão e de eventuais reembolsos) são pagos através de repasses periódicos, pelos meios e prazos indicados no painel do agricultor.</li>
    </ul>

    <h2 id="entregas">10. Entregas</h2>
    <ul>
        <li>As entregas são feitas nas zonas de entrega ativas na Plataforma, com a taxa e o prazo estimado indicados no checkout. Os prazos são estimativas e podem ser afetados por condições meteorológicas, estado das vias ou outras circunstâncias fora do controlo das partes.</li>
        <li>Salvo acordo diferente, a entrega é da responsabilidade do Agricultor (diretamente ou através de transportador por si escolhido). O risco de perda ou deterioração passa para o Comprador no momento da entrega.</li>
        <li>Se o Comprador não estiver disponível no local e prazo acordados, o Agricultor deve tentar contactá-lo; produtos perecíveis não recebidos por facto imputável ao Comprador podem não ser reembolsáveis.</li>
    </ul>

    <h2 id="cancelamentos">11. Cancelamentos e reembolsos</h2>
    <ul>
        <li>O Comprador pode pedir o cancelamento de um pedido, ou de um item, através da Plataforma (quando a opção estiver disponível), das mensagens do pedido ou do contacto com a Administração. Enquanto o pedido estiver pendente, o cancelamento deve ser aceite; depois de entrar em preparação, depende do acordo do Agricultor.</li>
        <li>O Agricultor pode cancelar um pedido por falta de stock ou outro motivo justificado, devendo indicar o motivo. O stock é reposto automaticamente.</li>
        <li>Se o pedido (ou item) cancelado já tiver sido pago, o valor correspondente é reembolsado pelo mesmo meio de pagamento, sempre que possível, ou por outro meio acordado, num prazo razoável após a confirmação do cancelamento.</li>
        <li>Produtos entregues danificados, deteriorados, em quantidade inferior ou diferentes do anunciado devem ser reclamados de imediato, com fotografias sempre que possível. A Administração pode mediar o conflito e decidir sobre reembolsos totais ou parciais, que podem ser deduzidos dos repasses ao Agricultor.</li>
        <li>Ficam salvaguardados os direitos que a lei de defesa do consumidor aplicável confira ao Comprador.</li>
    </ul>

    <h2 id="avaliacoes">12. Avaliações e denúncias</h2>
    <ul>
        <li>Após a entrega, o Comprador pode avaliar os produtos comprados. As avaliações devem ser honestas, baseadas em experiência real e respeitosas.</li>
        <li>Não são permitidas avaliações falsas, compradas, de concorrentes, ofensivas ou com dados pessoais de terceiros. A Administração pode ocultar avaliações que violem estas regras.</li>
        <li>Qualquer utilizador autenticado pode denunciar produtos ou conteúdos que considere ilegais, enganosos ou contrários a estes Termos. As denúncias são analisadas pela Administração, que pode remover conteúdos e aplicar as medidas previstas na secção 15.</li>
    </ul>

    <h2 id="proibido">13. Conteúdo e condutas proibidas</h2>
    <p>É proibido, nomeadamente:</p>
    <ul>
        <li>Anunciar produtos ilegais, contrafeitos, roubados, impróprios para consumo, espécies protegidas ou produtos sujeitos a licença sem a possuir (por exemplo, certos pesticidas, sementes ou animais).</li>
        <li>Publicar informação falsa ou enganosa, incluindo preços, origem, quantidades ou certificações inexistentes.</li>
        <li>Publicar conteúdos ofensivos, discriminatórios, difamatórios, violentos, sexualmente explícitos ou que violem direitos de terceiros.</li>
        <li>Desviar transações para fora da Plataforma com o objetivo de evitar a comissão, ou utilizar os dados de outros utilizadores para fins diferentes do pedido.</li>
        <li>Tentar aceder sem autorização a contas, sistemas ou dados, introduzir código malicioso, recolher dados de forma automatizada ou sobrecarregar a Plataforma.</li>
        <li>Fazer-se passar por outra pessoa ou entidade, ou criar contas falsas.</li>
    </ul>

    <h2 id="propriedade">14. Propriedade intelectual</h2>
    <p>A marca, o design, o software e os conteúdos próprios do AgroMercado pertencem ao Operador ou aos seus licenciadores. O utilizador mantém os direitos sobre as fotografias e textos que publica, mas concede ao Operador uma licença gratuita e não exclusiva para os reproduzir e apresentar na Plataforma e em comunicações promocionais da Plataforma, enquanto o conteúdo estiver publicado. O utilizador garante que tem direito a publicar esse conteúdo.</p>

    <h2 id="suspensao">15. Suspensão e encerramento de contas</h2>
    <ul>
        <li>A Administração pode, de forma proporcional, advertir, ocultar conteúdos, desativar produtos, suspender ou bloquear contas em caso de violação destes Termos, suspeita fundada de fraude, documentos falsos ou expirados, ou por imposição legal. Sempre que possível, o utilizador é informado do motivo.</li>
        <li>O utilizador pode pedir o encerramento da sua conta a qualquer momento através de [EMAIL DE CONTACTO]. Pedidos em curso, repasses pendentes e obrigações legais de conservação de dados mantêm-se até à sua conclusão.</li>
    </ul>

    <h2 id="responsabilidade">16. Limitação de responsabilidade</h2>
    <ul>
        <li>O Operador empenha-se em manter a Plataforma disponível e segura, mas não garante funcionamento ininterrupto ou isento de erros; podem ocorrer interrupções para manutenção ou por falhas de terceiros (energia, telecomunicações, prestadores de pagamento).</li>
        <li>Na medida permitida por lei, o Operador não é responsável pela qualidade, segurança, legalidade ou entrega dos produtos, que são da responsabilidade do Agricultor, nem por danos indiretos, lucros cessantes ou perda de dados resultantes da utilização da Plataforma.</li>
        <li>Nada nestes Termos exclui ou limita a responsabilidade que não possa ser excluída ou limitada por lei, nomeadamente em caso de dolo ou culpa grave, nem os direitos imperativos dos consumidores.</li>
    </ul>

    <h2 id="dados">17. Dados pessoais e cookies</h2>
    <p>O tratamento de dados pessoais é descrito na <a href="<?= base_path('privacidade.php') ?>">Política de privacidade</a>. A utilização de cookies e tecnologias semelhantes é descrita na <a href="<?= base_path('cookies.php') ?>">Política de cookies</a>.</p>

    <h2 id="alteracoes">18. Alterações aos termos</h2>
    <p>O Operador pode alterar estes Termos para refletir alterações legais, técnicas ou do serviço. As alterações relevantes são comunicadas com antecedência razoável (por exemplo, por notificação na Plataforma ou por email). A utilização continuada após a entrada em vigor implica a aceitação da nova versão. A versão e a data em vigor são indicadas no início desta página.</p>

    <h2 id="lei">19. Lei aplicável e litígios</h2>
    <p>Estes Termos regem-se pela lei da República de Moçambique, incluindo, como referência geral para as comunicações e transações por via eletrónica, a Lei n.º 3/2017 (Lei das Transações Electrónicas). As partes procurarão resolver qualquer litígio de forma amigável, podendo recorrer à mediação da Administração. Na falta de acordo, são competentes os tribunais de [FORO COMPETENTE], sem prejuízo das regras imperativas de competência aplicáveis aos consumidores.</p>

    <h2 id="contacto">20. Contacto</h2>
    <p>Para questões sobre estes Termos, reclamações ou pedidos de apoio:</p>
    <ul class="list-unstyled">
        <li><i class="bi bi-building me-2"></i>[NOME DA ENTIDADE OPERADORA]</li>
        <li><i class="bi bi-geo-alt me-2"></i>[ENDEREÇO]</li>
        <li><i class="bi bi-envelope me-2"></i>[EMAIL DE CONTACTO]</li>
        <li><i class="bi bi-telephone me-2"></i>[TELEFONE DE CONTACTO]</li>
    </ul>
    <p class="text-muted small mt-4 mb-0">Versão 1.0 — outubro de 2026.</p>
</div>
</section>
<?php include __DIR__.'/includes/footer.php'; ?>
