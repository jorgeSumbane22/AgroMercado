# AgroMercado — Sistema de Comercialização Agrícola

O AgroMercado é um marketplace agrícola em PHP 8+ e MySQL que aproxima agricultores e compradores em Moçambique. A aphttplicação foi organizada para funcionar diretamente no XAMPP, em `://localhost/agro-marketplace/`, usando PHP renderizado no servidor, PDO, sessões, Bootstrap 5, JavaScript vanilla e endpoints JSON para operações assíncronas.

## Requisitos

É necessário ter XAMPP com Apache, MySQL, PHP 8+, phpMyAdmin e a extensão PDO MySQL ativa. Não são necessários Composer, Node.js, Laravel, React ou outro framework.

## Instalação no XAMPP

Copie a pasta `agro-marketplace` para `C:\xampp\htdocs\agro-marketplace`. Inicie Apache e MySQL no painel do XAMPP. Abra o phpMyAdmin em `http://localhost/phpmyadmin`, e importe o ficheiro único `database/schema.sql`. Ele cria a base, as tabelas e os dados fictícios. Em seguida, abra `http://localhost/agro-marketplace/`. Se já tinha uma base criada com uma versão anterior, importe apenas `database/patch_2026_10.sql` por cima dela.

A ligação padrão usa `127.0.0.1`, base `agro_marketplace`, utilizador `root` e password vazia, que correspondem à configuração típica de uma instalação local do XAMPP. Se a sua instalação usar credenciais diferentes, defina as variáveis `AGRO_DB_HOST`, `AGRO_DB_NAME`, `AGRO_DB_USER` e `AGRO_DB_PASS` no ambiente PHP ou ajuste `config/database.php`.

## Credenciais demonstrativas

Todas as contas seguintes são criadas pelo `schema.sql`. As passwords são armazenadas como hashes bcrypt e nunca em texto simples. A aplicação atual faz login pelo email; JorgeSumbane é o nome apresentado no painel do SuperAdmin.

| Perfil | Email | Password |
|---|---|---|
| SuperAdmin — JorgeSumbane | `jorge.sumbane@agromercado.local` | `Admin@123` |
| Agricultor | `joao@agromercado.local` | `Farmer@123` |
| Agricultor | `maria@agromercado.local` | `Farmer@123` |
| Comprador | `carlos_matola@agromercado.local` | `Buyer@123` |
| Comprador | `beatriz_nunes@agromercado.local` | `Buyer@123` |

## Funcionalidades implementadas

A página inicial inclui hero, pesquisa, categorias, produtos em destaque, agricultores verificados, processo de compra, estatísticas, benefícios e chamada para cadastro. O marketplace disponibiliza pesquisa por produto, descrição, categoria, agricultor e localização, filtros de preço, stock e verificação, ordenação e paginação.

Os compradores podem cadastrar-se, iniciar sessão, consultar produtos, ver detalhes, guardar favoritos, adicionar ao carrinho através de Fetch API, alterar quantidades, finalizar pedidos, indicar dados de entrega, escolher um método de pagamento preparado, acompanhar estados e consultar histórico. O checkout valida stock dentro de uma transação e reduz a disponibilidade no momento da criação do pedido.

Os agricultores podem criar perfil, aguardar verificação, publicar produtos com upload seguro, editar e ativar/desativar anúncios, gerir stock, consultar pedidos, atualizar estados, consultar clientes, vendas, relatórios, notificações e perfil. O SuperAdmin dispõe de dashboards, gestão de agricultores, compradores, produtos, categorias, pedidos, pagamentos, avaliações, verificações, relatórios, auditoria e configurações.

As integrações reais de M-Pesa e e-Mola não são inventadas: o sistema guarda método, referência e estado do pagamento para futura ligação a APIs oficiais. Imagens de demonstração são mantidas localmente em `assets/img/` e os uploads de agricultores em `uploads/produtos/`. O fallback `product-placeholder.svg` evita imagens inexistentes.

## Hierarquia visual atualizada

Os produtos usam fotografia real local nos cartões, marketplace, detalhes e carrinho. As categorias usam fotografia de produto como imagem de capa com o ícone da categoria sobreposto. O hero usa uma fotografia agrícola real local. Os agricultores em destaque e os perfis públicos usam fotografias reais em `assets/img/farmers/`. Status de pedidos usam badges com cor, ícone e texto; menu/sidebar e botões usam ícones Bootstrap; e os dashboards de agricultor, comprador e SuperAdmin usam indicadores com ícones e gráficos Chart.js. A validação visual está registada em `visual-validation.md`.

## Estrutura principal

```text
agro-marketplace/
├── index.php, login.php, cadastro.php, marketplace.php
├── produto.php, agricultor.php, carrinho.php, checkout.php
├── pedido-confirmado.php
├── config/                 # configuração e PDO
├── includes/               # autenticação, permissões, layout e funções
├── admin/                  # área SuperAdmin
├── agricultor/             # área do agricultor
├── comprador/              # área do comprador
├── api/                    # endpoints JSON Fetch API
├── assets/css, assets/js   # apresentação e interações
├── assets/img              # imagens demo e fallback
├── uploads/                # uploads locais protegidos
└── database/               # schema.sql (instalação nova) e patch_2026_10.sql (atualização)
```

## Segurança

A aplicação usa `password_hash()` e `password_verify()`, prepared statements PDO, token CSRF para formulários e APIs, escape de outputs com `htmlspecialchars`, controlo de permissões por papel, sessões com cookies HttpOnly/SameSite, validação de inputs, validação MIME/extensão/tamanho de uploads, nomes aleatórios de ficheiros, transações de checkout, constraints e auditoria.

## Teste recomendado

Teste o login com cada perfil. Como comprador, pesquise `tomate`, aplique filtros, abra um produto, tente adicionar uma quantidade acima do stock, adicione um produto ao carrinho, altere a quantidade e conclua um pedido. Verifique a baixa de stock no agricultor e atualize o estado do pedido. Como SuperAdmin, aprove um agricultor pendente, consulte pagamentos, relatórios e auditoria. Teste também um upload JPG/PNG/WEBP válido, um ficheiro não-imagem e um ficheiro superior a 3 MB.

## Observações de produção

Antes de publicar externamente, configure HTTPS, credenciais fortes de base de dados, limites adequados de upload, política de backups, serviço de email para recuperação de password, integração oficial de pagamentos, armazenamento de imagens apropriado e revisão adicional de permissões para operações administrativas sensíveis.

## Funcionalidades operacionais acrescentadas

A versão completa inclui rate limiting básico no login, headers HTTP de segurança, recuperação de password com token hash de utilização única, consentimentos legais, perfil de agricultor com fotografia e documentos, verificações documentais pelo SuperAdmin, denúncias com estados e moderação, zonas de entrega com taxa e prazo, reconciliação administrativa de pagamentos, endpoint de webhook HMAC, preferências de notificações, páginas de Termos, Privacidade e Cookies, configurações administrativas persistentes, script de backup e smoke tests.

O `database/schema.sql` é agora o ficheiro recomendado para uma instalação nova. Ele consolida estrutura, dados fictícios e o patch de outubro de 2026 (`patch_2026_10.sql`), que acrescenta o estado por item dos pedidos, o prazo de entrega, o motivo de cancelamento e a moderação de avaliações.

## Pagamentos externos

A aplicação continua segura por defeito com M-Pesa e e-Mola desativados. O endpoint `api/pagamentos.php?webhook=1&provider=nome` aceita eventos JSON assinados com HMAC-SHA256 através de `X-Payment-Signature`. Para produção, configure o segredo e os parâmetros oficiais do provedor em `app_settings`, implemente o adaptador do provedor escolhido e teste idempotência, falhas, reembolsos e reconciliação. Não foram incluídas chaves fictícias.

## Recuperação de password

Só quando `app_env` é `local` (Configurações do SuperAdmin) o link de recuperação aparece no ecrã, para facilitar os testes; com `production` nunca é mostrado. Há um limite de 3 pedidos por conta por hora. Em produção, ative `mail_enabled`, configure `mail_from` e substitua `mail()` por um serviço SMTP/API confiável. O token expira em 60 minutos, é guardado apenas como hash e é invalidado depois da utilização.

## Backup e testes

Use `tools/backup.sh` em ambientes com `mysqldump`; para XAMPP Windows, consulte `tools/README.md`. O smoke test (`bash tests/smoke.sh`, ou `BASE_URL=... bash tests/smoke.sh`) verifica páginas públicas, ficheiros protegidos, login dos três perfis, permissões e as áreas autenticadas. O ficheiro `.env.example` mostra as variáveis de ambiente recomendadas e `SECURITY.md` contém o checklist de segurança e deploy.

## Nomes de produtos

Os títulos e os nomes dos ficheiros de imagens do catálogo foram normalizados para português, por exemplo `abacate.jpg`, `feijao.jpg`, `cenoura.jpg`, `mandioca.jpg`, `milho.jpg`, `quiabo.jpg`, `batata-doce.jpg` e `hortalicas.jpg`. Os ficheiros foram renomeados sem alterar o conteúdo visual das fotografias; as imagens do catálogo não continham texto incorporado para tradução.

## Correções de outubro de 2026

Depois de um teste de aceitação foram corrigidos: a recuperação de password (o link deixou de aparecer fora do ambiente local), a API de estados de pedidos e a API de avaliações (davam erro fatal), ações administrativas por GET sem CSRF, XSS no painel do SuperAdmin, a taxa de entrega gravada como zero, métodos de pagamento inválidos, o limite de stock no carrinho, a publicação de produtos por agricultores não verificados, unidades e províncias gravadas vazias, e a sincronização do estado de pagamento dos pedidos.

Novas funcionalidades: estado por agricultor em pedidos com vários agricultores; cancelamento de pedidos com reposição de stock (comprador, agricultor e SuperAdmin); avaliações pelo comprador; alteração de password e preferências de notificação; histórico de stock do agricultor; gestão de utilizadores, zonas de entrega, detalhe do pedido, moderação de avaliações e edição de categorias no SuperAdmin; paginação e exportação CSV nas listas administrativas; "Manter sessão iniciada" (30 dias). Contas bloqueadas perdem a sessão imediatamente.

Os erros PHP já não são mostrados ao visitante (ficam no log do Apache); defina `APP_DEBUG=1` para os ver em desenvolvimento. A ligação à base usa `sql_mode` estrito, por isso valores inválidos geram erro em vez de serem gravados vazios.

## Funcionalidades de novembro de 2026

Para atualizar uma base existente, importe `database/patch_2026_11.sql` (depois do `patch_2026_10.sql`). Uma instalação nova só precisa do `schema.sql`.

- **Configuração:** copie `.env.example` para `.env` e ajuste base de dados, `AGRO_BASE_PATH` (vazio na raiz de um domínio), `APP_ENV=production` e SMTP. Variáveis definidas no `.env` sobrepõem-se às Configurações do SuperAdmin.
- **Contas e segurança:** confirmação de email no registo (obrigatória para comprar e publicar produtos), "Manter sessão iniciada" com token rotativo de 30 dias, logout por POST, verificação em dois passos (TOTP, em "Segurança da conta") e limite de pedidos nas APIs (120 por minuto por IP).
- **Email:** fila em `email_outbox`, envio SMTP (STARTTLS/SSL) e notificações por email conforme as preferências. Sem SMTP configurado, os emails ficam registados em Admin › Emails enviados. Para processar a fila: `php tools/enviar-emails.php`.
- **Comércio:** comissão da plataforma por item (`commission_percent`, 10% por defeito), repasses aos agricultores (Admin › Repasses e Agricultor › Financeiro), reembolsos (Admin › Reembolsos), cupões de desconto (Admin › Cupões; demo `BEMVINDO10`), faturas numeradas `FT AAAA/000000` com recibo imprimível (`recibo.php`) e mensagens entre comprador e agricultor em cada pedido.
- **Catálogo:** galeria de até 5 fotografias por produto, pesquisa com sugestões no marketplace, validade dos documentos dos agricultores (`php tools/verificar-documentos.php`, diário), notificações do SuperAdmin e relatórios imprimíveis/PDF.
- **Design:** novo visual (Bricolage Grotesque + Figtree, verde-campo e amarelo-milho), com modo escuro.
- **Textos legais:** Termos, Privacidade e Cookies são rascunhos completos que devem ser revistos por um jurista antes da publicação.
- **Backups no Windows:** `tools/backup.bat` (ou `backup.ps1`) e `tools/restaurar.ps1`; o agendamento com o Agendador de Tarefas está em `tools/README.md`.
- **Testes:** `php tests/run.php` (31 testes unitários e de integração; criam e apagam os seus próprios dados) e `bash tests/smoke.sh`.

Continua a depender de terceiros: contrato e credenciais de M-Pesa/e-Mola/cartão (o webhook HMAC está pronto), uma conta SMTP, HTTPS no servidor e a revisão jurídica dos textos legais.
