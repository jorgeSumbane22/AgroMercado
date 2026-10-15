# Backup e restauração

## Backup Linux/macOS
Defina as variáveis AGRO_DB_* e execute `tools/backup.sh`. O script cria dumps comprimidos, arquivo tar dos uploads, checksum SHA-256 e remove backups com mais de 30 dias.

## XAMPP Windows
Use o mysqldump.exe da pasta mysql/bin: `mysqldump.exe -u root agro_marketplace > backups\\agro_marketplace.sql` e comprima a pasta uploads separadamente.

## Restauração
Confirme primeiro o destino e faça uma cópia de segurança. Depois execute `mysql -u root agro_marketplace < backups\\agro_marketplace.sql` e restaure a pasta uploads. Nunca restaure um ficheiro não verificado.
