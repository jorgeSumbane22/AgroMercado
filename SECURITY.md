# Segurança e operação

## Antes de publicar

Defina credenciais fortes no ambiente, ative HTTPS, altere as credenciais demonstrativas, mantenha `config/`, `.env`, `backups/` e documentos privados fora do acesso público e configure permissões mínimas para `uploads/`.

## Recuperação de conta

O modo local mostra o link de recuperação no ecrã para permitir testes sem serviço de email. Em produção, defina `mail_enabled=1`, `mail_from` e um serviço de envio confiável. Os tokens são guardados como hash, expiram em 60 minutos e só podem ser usados uma vez.

## Pagamentos

O checkout regista pagamentos pendentes. O endpoint `api/pagamentos.php?webhook=1&provider=nome` aceita eventos JSON assinados por HMAC-SHA256 no header `X-Payment-Signature`, usando `payment_webhook_secret`. A ativação de M-Pesa/e-Mola exige as credenciais, URL e formato oficial do provedor; o pacote não contém chaves fictícias.

## Backups

Execute `tools/backup.sh` num servidor Linux com `mysqldump` ou siga `tools/README.md` para XAMPP Windows. Guarde os backups fora do web root, cifre-os quando necessário, mantenha retenção e teste restaurações regularmente.

## Testes

Execute `php -l` em todos os PHP e `tests/smoke.sh` com o servidor local ativo. Teste também login, rate limiting, recuperação de password, upload inválido, checkout concorrente, permissões, denúncias e webhooks.
