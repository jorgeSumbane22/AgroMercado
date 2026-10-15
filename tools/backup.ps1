<#
  Backup do AgroMercado em Windows/XAMPP.
  - Dump da base de dados (mysqldump do XAMPP) comprimido em .zip
  - Arquivo .zip da pasta uploads/
  - Ficheiro de checksums SHA-256
  - Remove backups com mais de N dias (por omissão 30)
  - Regista o resultado na tabela backup_logs (se o mysql.exe estiver disponível)

  Uso:  powershell -NoProfile -ExecutionPolicy Bypass -File tools\backup.ps1
        powershell -NoProfile -ExecutionPolicy Bypass -File tools\backup.ps1 -RetentionDays 14 -BackupDir D:\backups\agro
#>
param(
    [string]$XamppDir = 'C:\xampp',
    [string]$BackupDir = '',
    [int]$RetentionDays = 30
)
$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
if (-not $BackupDir) { $BackupDir = Join-Path $Root 'backups' }

# --- Configuração: variáveis de ambiente > .env > valores do XAMPP ---
$cfg = @{ AGRO_DB_HOST = '127.0.0.1'; AGRO_DB_NAME = 'agro_marketplace'; AGRO_DB_USER = 'root'; AGRO_DB_PASS = '' }
$envFile = Join-Path $Root '.env'
if (Test-Path $envFile) {
    foreach ($line in Get-Content $envFile -Encoding UTF8) {
        if ($line -match '^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*?)\s*$' -and $cfg.ContainsKey($Matches[1])) {
            $cfg[$Matches[1]] = $Matches[2].Trim('"', "'")
        }
    }
}
foreach ($k in @($cfg.Keys)) { $v = [Environment]::GetEnvironmentVariable($k); if ($null -ne $v) { $cfg[$k] = $v } }

$mysqldump = Join-Path $XamppDir 'mysql\bin\mysqldump.exe'
$mysql     = Join-Path $XamppDir 'mysql\bin\mysql.exe'
if (-not (Test-Path $mysqldump)) { throw "mysqldump.exe não encontrado em $mysqldump" }
New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null

$stamp      = Get-Date -Format 'yyyyMMdd_HHmmss'
$dbName     = $cfg.AGRO_DB_NAME
$sqlFile    = Join-Path $BackupDir "${dbName}_${stamp}.sql"
$dbZip      = Join-Path $BackupDir "${dbName}_${stamp}.sql.zip"
$uploadsZip = Join-Path $BackupDir "uploads_${stamp}.zip"
$sumFile    = Join-Path $BackupDir "${stamp}.sha256"

function Write-BackupLog([string]$type, [string]$file, [string]$status, $size, [string]$checksum, [string]$err) {
    if (-not (Test-Path $mysql)) { return }
    $q = { param($s) if ($null -eq $s -or $s -eq '') { 'NULL' } else { "'" + ($s -replace '\\', '\\' -replace "'", "''") + "'" } }
    $sql = "INSERT INTO backup_logs(backup_type,file_path,status,size_bytes,checksum,error_message) VALUES(" +
           "'$type'," + (& $q $file) + ",'$status'," + ($(if ($null -eq $size) { 'NULL' } else { [int64]$size })) + "," + (& $q $checksum) + "," + (& $q $err) + ")"
    $ErrorActionPreference = 'Continue'
    $mysqlArgs = @('-h', $cfg.AGRO_DB_HOST, '-u', $cfg.AGRO_DB_USER, '--default-character-set=utf8mb4', $dbName, '-e', $sql)
    & $mysql @mysqlArgs 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) { Write-Warning 'Não foi possível registar o backup em backup_logs.' }
}

$env:MYSQL_PWD = $cfg.AGRO_DB_PASS
try {
    # 1) Base de dados (--result-file evita a conversão de codificação do redirecionamento do PowerShell)
    Write-Host "A exportar a base de dados $dbName..."
    & $mysqldump --single-transaction --routines --events --default-character-set=utf8mb4 `
        -h $cfg.AGRO_DB_HOST -u $cfg.AGRO_DB_USER --result-file="$sqlFile" $dbName
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path $sqlFile)) { throw "mysqldump terminou com código $LASTEXITCODE" }
    Compress-Archive -Path $sqlFile -DestinationPath $dbZip -CompressionLevel Optimal -Force
    Remove-Item $sqlFile -Force

    # 2) Uploads
    Write-Host 'A comprimir uploads/...'
    $uploads = Join-Path $Root 'uploads'
    if (Test-Path $uploads) { Compress-Archive -Path (Join-Path $uploads '*') -DestinationPath $uploadsZip -CompressionLevel Optimal -Force }

    # 3) Checksums (formato compatível com sha256sum: "<hash>  <ficheiro>")
    $files = @($dbZip, $uploadsZip) | Where-Object { Test-Path $_ }
    $lines = foreach ($f in $files) { '{0}  {1}' -f (Get-FileHash -Algorithm SHA256 $f).Hash.ToLower(), (Split-Path -Leaf $f) }
    [IO.File]::WriteAllLines($sumFile, [string[]]$lines)

    foreach ($f in $files) {
        $item = Get-Item $f
        $hash = (Get-FileHash -Algorithm SHA256 $f).Hash.ToLower()
        $type = if ($item.Name -like 'uploads_*') { 'uploads' } else { 'database' }
        Write-BackupLog $type $item.FullName 'completed' $item.Length $hash ''
        Write-Host ("  {0}  {1:N0} bytes" -f $item.Name, $item.Length)
    }

    # 4) Retenção
    $limit = (Get-Date).AddDays(-$RetentionDays)
    Get-ChildItem $BackupDir -File | Where-Object { $_.Name -notin @('.gitkeep', '.htaccess') -and $_.LastWriteTime -lt $limit } |
        ForEach-Object { Write-Host "A remover backup antigo: $($_.Name)"; Remove-Item $_.FullName -Force }

    Write-Host "Backup concluído em $BackupDir"
    exit 0
} catch {
    Write-Error "Backup falhou: $_"
    Write-BackupLog 'full' $BackupDir 'failed' $null '' "$_"
    if (Test-Path $sqlFile) { Remove-Item $sqlFile -Force }
    exit 1
} finally {
    Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue
}
