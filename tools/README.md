# Ferramentas: backup, restauro e fila de email

## Backup Linux/macOS
Defina as variáveis AGRO_DB_* e execute `tools/backup.sh`. O script cria dumps comprimidos, arquivo tar dos uploads, checksum SHA-256 e remove backups com mais de 30 dias.

## Backup Windows (XAMPP)
`tools\backup.ps1` (ou o atalho `tools\backup.bat`):

- exporta a base de dados com `C:\xampp\mysql\bin\mysqldump.exe` (`--single-transaction`) para `backups\agro_marketplace_AAAAMMDD_HHMMSS.sql.zip`;
- comprime `uploads\` para `backups\uploads_AAAAMMDD_HHMMSS.zip`;
- escreve `backups\AAAAMMDD_HHMMSS.sha256` (formato `sha256sum`);
- apaga backups com mais de 30 dias (`-RetentionDays N` para mudar);
- regista cada ficheiro na tabela `backup_logs` (estado, tamanho, checksum).

As credenciais vêm das variáveis de ambiente `AGRO_DB_*` ou do `.env` (por omissão root sem password do XAMPP).

```bat
tools\backup.bat
tools\backup.bat -RetentionDays 14 -BackupDir D:\backups\agromercado
```

Guarde uma cópia fora do servidor (disco externo/nuvem) e, de preferência, fora do web root (`-BackupDir`).

### Agendar com o Agendador de Tarefas
Backup diário às 02:00 (execute numa linha de comandos como administrador; ajuste o caminho):

```bat
schtasks /Create /TN "AgroMercado\Backup diario" /SC DAILY /ST 02:00 /RU SYSTEM /RL HIGHEST ^
  /TR "powershell.exe -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\agro-marketplace\tools\backup.ps1"
```

Ver/testar/remover: `schtasks /Query /TN "AgroMercado\Backup diario"`, `schtasks /Run /TN "AgroMercado\Backup diario"`, `schtasks /Delete /TN "AgroMercado\Backup diario" /F`.
O MySQL do XAMPP tem de estar a correr à hora agendada.

## Restauro (Windows)
```bat
powershell -NoProfile -ExecutionPolicy Bypass -File tools\restaurar.ps1 -Dump backups\agro_marketplace_20261015_020000.sql.zip
powershell -NoProfile -ExecutionPolicy Bypass -File tools\restaurar.ps1 -Dump backups\agro_marketplace_20261015_020000.sql.zip -Uploads backups\uploads_20261015_020000.zip
```

O script confirma o checksum SHA-256, mostra o destino e só avança se escrever o nome da base de dados. Com `-Uploads`, a pasta `uploads\` atual é renomeada (`uploads.antes_restauro_...`) antes de extrair. Para ensaiar sem tocar na base real use `-Database agro_restore_teste` (e apague-a no fim). Teste restauros regularmente.

## Restauro manual
Confirme primeiro o destino e faça uma cópia de segurança. Depois execute `mysql -u root agro_marketplace < agro_marketplace.sql` e restaure a pasta uploads. Nunca restaure um ficheiro não verificado.

## Fila de email
`php tools/enviar-emails.php [limite]` envia os emails em fila (`email_outbox`) e volta a tentar os falhados (até 3 tentativas). Só corre na linha de comandos. Sem SMTP configurado (ou com `MAIL_ENABLED=0`) os emails ficam no estado "Só registo", visíveis em Admin → Emails enviados.

Agendar a cada 5 minutos:

```bat
schtasks /Create /TN "AgroMercado\Fila de email" /SC MINUTE /MO 5 ^
  /TR "C:\xampp\php\php.exe C:\xampp\htdocs\agro-marketplace\tools\enviar-emails.php"
```
