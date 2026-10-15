# Diagnóstico de completude — AgroMercado

## Síntese

O projeto atual está funcional como protótipo demonstrativo local para XAMPP. O RAR original contém praticamente a mesma aplicação, com páginas PHP, schema, dados fictícios, marketplace, autenticação, carrinho, checkout, dashboards e áreas administrativas. A comparação encontrou diferenças principalmente de documentação, ficheiros SQL separados e ajustes visuais já realizados. Não encontrei um bloqueio estrutural que impeça a execução local.

Para ser considerado completo para produção, o sistema ainda precisa das seguintes camadas.

## Prioridade 1 — Necessário antes de produção

| Lacuna | Estado atual | Trabalho necessário |
|---|---|---|
| Pagamentos reais | O checkout apenas regista método, referência e estado local | Integrar API oficial de M-Pesa/e-Mola ou outro provedor, webhooks, confirmação assíncrona, idempotência, reconciliação e tratamento de falhas |
| Recuperação de password | Existe formulário, mas não há fluxo completo de email com token de utilização única | Criar tokens expiráveis, envio de email, página de redefinição, invalidação após uso e logs de segurança |
| Segurança operacional | Existem CSRF, PDO, bcrypt, permissões e validação de uploads | Acrescentar rate limiting, proteção contra brute force, headers HTTP, gestão de segredos fora do código, rotação de sessões, 2FA opcional e revisão de autorização por endpoint |
| Produção de imagens | Existem imagens demo locais e uploads de produtos | Definir licença/proveniência das imagens, armazenamento persistente, thumbnails, remoção segura, limites por utilizador e política de retenção |
| Backups e recuperação | Não existe rotina de backup configurada na aplicação | Criar backups automáticos da base e uploads, retenção, teste de restauração e procedimento documentado |
| Deploy | O sistema está preparado para XAMPP local | Configurar ambiente de produção, HTTPS, domínio, PHP/MySQL seguros, permissões de pastas, logs e variáveis de ambiente |

## Prioridade 2 — Necessário para operação comercial real

| Lacuna | Estado atual | Trabalho necessário |
|---|---|---|
| Entrega e logística | O pedido guarda endereço e estados manuais | Definir zonas, custos, prazos, responsável pela entrega, notificações e eventual integração logística |
| Gestão de agricultores | Aprovação existe, mas o fluxo documental é básico | Upload de documentos, validade, histórico de revisão, motivo de rejeição e revalidação |
| Moderação | A página de denúncias é apenas placeholder | Criar tabela de denúncias, abertura pelo utilizador, evidências, estados, responsável e ações de moderação |
| Notificações | Notificações internas existem | Acrescentar email/SMS/WhatsApp conforme necessidade, preferências do utilizador e reenvio de falhas |
| Relatórios | Existem agregações e gráficos básicos | Exportação CSV/XLSX, filtros por data, auditoria dos indicadores e relatórios financeiros mais completos |
| Escalabilidade | Consultas e listas são adequadas ao demo | Paginação administrativa completa, índices revistos, cache, filas para emails e testes com volume maior |

## Prioridade 3 — Qualidade e experiência

| Lacuna | Estado atual | Trabalho necessário |
|---|---|---|
| Testes | Foi validado lint PHP, smoke HTTP, login, permissões, carrinho e checkout | Criar testes automatizados unitários, integração SQL, APIs, upload, permissões, checkout concorrente e testes de navegador/mobile |
| Perfis e imagens de agricultores | A interface mostra fotografias demo através de mapeamento por ID | Permitir upload e edição da fotografia pelo agricultor, guardar o caminho no perfil e remover o mapeamento hardcoded |
| Catálogo | Os títulos dos produtos já estão em português, mas vários nomes técnicos de ficheiros continuam em inglês | Renomear assets e referências no código/schema para português, com atualização segura de todas as referências |
| Imagens com texto | Ainda não foi feita a tradução de textos dentro das imagens | Editar ou regenerar as imagens que contêm texto, validar legibilidade e substituir os ficheiros sem quebrar o catálogo |
| Acessibilidade | Há `alt` em imagens e Bootstrap Icons | Rever foco de teclado, labels, contraste, mensagens para leitores de ecrã, estados de erro e navegação sem rato |
| Conteúdo legal | Não há páginas completas de termos, privacidade e cookies | Adicionar políticas, consentimentos e gestão de dados pessoais conforme o país de operação |

## O que já está coberto

A versão local já cobre autenticação por papel, SuperAdmin JorgeSumbane, CRUD de produtos, categorias, stock, marketplace com filtros, carrinho, checkout transacional com baixa de stock, pedidos, avaliações, favoritos, notificações internas, auditoria, dashboards e imagens locais de demonstração.

## Próxima etapa solicitada

A tradução dos nomes dos produtos deve ser feita depois deste diagnóstico. O trabalho deverá abranger o nome apresentado ao utilizador, os nomes dos ficheiros de imagem, os caminhos no `schema.sql`/`seed.sql`, referências PHP/CSS/HTML, `alt` text e eventuais textos incorporados dentro das próprias imagens. Recomenda-se primeiro criar um mapa antigo → novo, depois renomear os ficheiros e finalmente validar todas as referências.
