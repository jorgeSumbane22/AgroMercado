# Segurança e operação

## Antes de publicar (checklist)

- [ ] Copiar `.env.example` para `.env` e definir `AGRO_DB_*` com um utilizador MySQL próprio (não root) e password forte.
- [ ] `APP_ENV=production` e `APP_DEBUG=0` (em produção os links de recuperação/confirmação nunca aparecem no ecrã).
- [ ] `AGRO_BASE_PATH` correto (vazio quando a aplicação está na raiz do domínio).
- [ ] HTTPS ativo: os cookies de sessão e `agro_remember` passam a `Secure` automaticamente.
- [ ] Confirmar que `.env`, `config/`, `database/`, `backups/`, `tools/` e ficheiros `.md/.sql/.sh` respondem 403 (ver `.htaccess`; o smoke test verifica alguns).
- [ ] SMTP configurado (Admin → Configurações ou `SMTP_*`/`MAIL_*` no `.env`) e "Enviar email de teste" com sucesso.
- [ ] Agendar `tools/enviar-emails.php` (fila de email) e `tools/backup.ps1` (ver `tools/README.md`).
- [ ] Alterar/remover as credenciais demonstrativas; ativar 2FA nas contas de administrador.
- [ ] Permissões mínimas para `uploads/` (sem execução de scripts).

## Configuração (.env)

`config/config.php` lê `<projeto>/.env` (linhas `CHAVE=valor`, comentários `#`, aspas opcionais) sem sobrepor variáveis já definidas no ambiente real. Para email/SMTP, um valor **não vazio** no ambiente tem prioridade sobre `app_settings` (`config_value()`), e o painel mostra esses campos só de leitura. A password SMTP guardada no painel é só de escrita (nunca é mostrada).

## Sessões e "Manter sessão iniciada"

- O cookie de sessão termina ao fechar o browser (`cookie_lifetime=0`, `gc_maxlifetime` 8 h), é `HttpOnly`, `SameSite=Lax`, `use_strict_mode`.
- "Manter sessão iniciada" emite um token selector:validator em `user_tokens` (30 dias; só o SHA-256 do validador é guardado, comparação com `hash_equals`) no cookie `agro_remember`. Cada uso roda o token (o anterior é aceite apenas 30 s para pedidos paralelos) e fica na auditoria.
- Logout só por POST + CSRF (um GET a `logout.php` mostra uma confirmação) e revoga o token deste browser.
- Redefinir a password (`redefinir-password.php`) revoga todos os tokens; em "Segurança da conta" há "Esquecer todos os dispositivos". Contas bloqueadas/inativas perdem a sessão e os tokens no pedido seguinte.
- Pendente: as páginas de alteração de password nos perfis (comprador/agricultor/admin) devem chamar `revoke_remember_tokens($userId)`.

## Verificação de email

Novas contas ficam com `email_verified_at` NULL e recebem um link (`verificar-email.php?s=…&v=…`, 48 h, uso único). O login é permitido; é mostrado um aviso com "Reenviar" (máx. 3/hora). Páginas sensíveis podem exigir email confirmado com `require_verified_email()`. Em `APP_ENV=local` o link aparece no ecrã para testes. Notificações por email só vão para emails confirmados e respeitam as preferências (`email_enabled`, `order_updates`).

## Verificação em dois passos (TOTP)

Qualquer utilizador pode ativá-la em `seguranca.php` (RFC 6238: SHA-1, 6 dígitos, 30 s, tolerância ±30 s). O segredo só é gravado depois de confirmado com um código; desativar exige password + código. No login, a sessão só é criada depois do código (`login-2fa.php`, válido 5 min após a password, máx. 5 tentativas/10 min por conta e 20 por IP). Não há códigos de recuperação nem QR code (o segredo é mostrado em grupos e como URI `otpauth://`). Se um utilizador perder o telemóvel, um administrador pode desativar no MySQL:

```sql
UPDATE users SET totp_enabled=0, totp_secret=NULL WHERE email='utilizador@exemplo.co.mz';
```

O segredo TOTP fica em claro na base de dados: proteja os backups.

## Limite de pedidos

`rate_limit($balde, $max, $segundos)` (tabela `rate_limits`, janela fixa): API JSON 120 pedidos/min por IP e endpoint (HTTP 429), login 5 falhas/15 min por email+IP, recuperação de password 10/hora por IP e 3/hora por conta, cadastro 10/hora por IP, reenvio de confirmação 3/hora, 2FA 5/10 min.

## Recuperação de conta

Os tokens são guardados como hash, expiram em 60 minutos e só podem ser usados uma vez; usar o link também confirma o email. O email segue pela fila (`email_outbox`). Atenção: o corpo dos emails (com links de recuperação/confirmação) fica visível aos administradores em Admin → Emails enviados.

## Email

Fila `email_outbox` com cliente SMTP próprio (STARTTLS/SSL com verificação de certificado, AUTH LOGIN, UTF-8/base64). Sem SMTP (ou `MAIL_ENABLED=0`) as mensagens ficam "Só registo". Falhas guardam `last_error` e são repetidas pelo worker até 3 tentativas; "Reenviar" no painel força nova tentativa.

## Pagamentos

O checkout regista pagamentos pendentes. O endpoint `api/pagamentos.php?webhook=1&provider=nome` aceita eventos JSON assinados por HMAC-SHA256 no header `X-Payment-Signature`, usando `payment_webhook_secret`. A ativação de M-Pesa/e-Mola exige as credenciais, URL e formato oficial do provedor; o pacote não contém chaves fictícias.

## Backups

Windows/XAMPP: `tools\backup.ps1` (dump + uploads em zip, SHA-256, retenção de 30 dias, registo em `backup_logs`) e `tools\restaurar.ps1` (verifica checksum e pede confirmação). Linux: `tools/backup.sh`. Guarde os backups fora do web root e fora do servidor, cifre-os quando necessário e teste restauros regularmente.

## Testes

Execute `php -l` em todos os PHP e `tests/smoke.sh` com o servidor local ativo. Teste também login (com e sem 2FA), "manter sessão", logout, confirmação de email, rate limiting, recuperação de password, upload inválido, checkout concorrente, permissões, denúncias e webhooks.
