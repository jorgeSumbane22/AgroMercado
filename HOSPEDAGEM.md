# Publicar o AgroMercado no InfinityFree

Guia para alojar o AgroMercado gratuitamente no [InfinityFree](https://www.infinityfree.com) (PHP 8 + MySQL).
Serve também, com pequenas diferenças, para qualquer alojamento partilhado com cPanel.

> **Segurança:** a base de dados para alojamento (`database/hosting.sql`) mantém os produtos e agricultores de
> demonstração, mas **nenhuma conta fica com palavra-passe utilizável** — as palavras-passe de demonstração são
> públicas. A conta SuperAdmin é configurada no passo 5.

## 1. Criar a conta de alojamento

1. Crie uma conta em infinityfree.com e, na *Client Area*, clique em **Create Account**.
2. Escolha um subdomínio gratuito (por exemplo `agromercado.free.nf`) ou use um domínio seu.
3. Abra a conta criada. Em **Account details** ficam os dados de que vai precisar:
   *FTP hostname*, *FTP username*, a **palavra-passe da conta** e o *MySQL hostname*.

## 2. Criar a base de dados

1. No painel da conta (*Control Panel*), abra **MySQL Databases** e crie uma base (por exemplo `agro`).
   O nome final fica com um prefixo, como `if0_12345678_agro`.
2. Tome nota de: **MySQL Host Name** (ex.: `sql123.infinityfree.com`), **nome da base**, **utilizador**
   (ex.: `if0_12345678`) e **palavra-passe** (a da conta de alojamento).
3. Clique em **phpMyAdmin** ao lado da base, abra o separador **Importar** e envie `database/hosting.sql`.

Se alterar o `schema.sql`, volte a gerar o ficheiro de alojamento no seu computador:

```bat
C:\xampp\php\php.exe tools\gerar-sql-alojamento.php
```

## 3. Enviar os ficheiros

Use o **FileZilla** (recomendado) com os dados de FTP do passo 1, ou o *Online File Manager* do painel.

1. Entre na pasta **`htdocs`** do alojamento e apague o ficheiro de boas-vindas que lá estiver (`index2.html`).
2. Envie **todo o conteúdo** da pasta do projeto para dentro de `htdocs` (não a pasta em si),
   incluindo os ficheiros escondidos `.htaccess` e `uploads/.htaccess`.
3. Não é preciso enviar: `.git/`, `backups/`, `tests/` e `docs/` (se existir).

## 4. Configurar o `.env`

No servidor, crie o ficheiro **`htdocs/.env`** (copie `.env.example`) com os dados do passo 2:

```ini
AGRO_DB_HOST=sql123.infinityfree.com
AGRO_DB_NAME=if0_12345678_agro
AGRO_DB_USER=if0_12345678
AGRO_DB_PASS=a-palavra-passe-da-conta

# Instalado na raiz do domínio: deixar vazio
AGRO_BASE_PATH=

APP_ENV=production
APP_DEBUG=0

PAYMENT_WEBHOOK_SECRET=escreva-aqui-um-segredo-longo-e-aleatorio

# Uso único (passo 5): uma chave longa e aleatória, com pelo menos 24 caracteres
AGRO_SETUP_KEY=escreva-aqui-uma-chave-longa-e-aleatoria
```

O `.htaccess` impede que o `.env`, os ficheiros `.sql` e as pastas internas sejam abertos pelo navegador.

## 5. Configurar a conta SuperAdmin

1. Abra `https://o-seu-dominio/setup.php?key=A-SUA-CHAVE` (a mesma de `AGRO_SETUP_KEY`).
2. Indique o seu nome, um **email real** e uma palavra-passe forte (mínimo 12 caracteres).
3. **Apague a linha `AGRO_SETUP_KEY` do `.env`.** A página `setup.php` já fica desativada depois de usada,
   mas sem a chave deixa de responder de todo.
4. Entre em `https://o-seu-dominio/login.php` com o email e a palavra-passe que definiu.

As contas de demonstração (agricultores e compradores `@agromercado.local`) continuam sem acesso.
Os seus produtos aparecem no catálogo; pode desativá-los no painel quando tiver produtos reais.

## 6. HTTPS

Na *Client Area*, em **SSL Certificates**, confirme que o domínio tem certificado gratuito (nos subdomínios do
InfinityFree costuma ser automático; num domínio próprio, peça-o aí). Depois use sempre o endereço `https://`.

## 7. Verificar

- [ ] A página inicial, o marketplace e o login abrem em `https://`.
- [ ] `https://o-seu-dominio/.env` e `https://o-seu-dominio/database/hosting.sql` **não abrem** (erro 403).
- [ ] Entra com a conta SuperAdmin; uma conta de demonstração **não** entra.
- [ ] Consegue registar um comprador de teste, adicionar um produto ao carrinho e concluir um pedido.
- [ ] Como agricultor de teste, consegue publicar um produto com imagem (pasta `uploads/` com permissão de escrita).

## Limitações do alojamento gratuito

- **Sem tarefas agendadas (cron).** Os emails (recuperação de palavra-passe, confirmações) ficam na fila e
  aparecem em *Admin → Emails enviados*, mas não são enviados automaticamente. Para enviar emails é preciso um
  alojamento com cron e um servidor SMTP (ver `tools/README.md`).
- **Sem backups automáticos.** Exporte a base de dados periodicamente no phpMyAdmin (*Exportar*) e guarde uma
  cópia da pasta `uploads/`.
- O InfinityFree é adequado para demonstração e portfólio; para uso comercial real, prefira um alojamento pago.
