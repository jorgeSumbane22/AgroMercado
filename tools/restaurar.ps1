<#
  Restauro do AgroMercado em Windows/XAMPP. ATENÇÃO: substitui o conteúdo da base de dados indicada.

  Uso:
    powershell -NoProfile -ExecutionPolicy Bypass -File tools\restaurar.ps1 -Dump backups\agro_marketplace_20261015_020000.sql.zip
    powershell -NoProfile -ExecutionPolicy Bypass -File tools\restaurar.ps1 -Dump backups\x.sql.zip -Uploads backups\uploads_20261015_020000.zip
    (-Database outra_bd para restaurar numa base de dados de teste)

  O script verifica o checksum SHA-256 (ficheiro <stamp>.sha256 ao lado do backup) e pede confirmação escrita antes de alterar algo.
#>
param(
    [Parameter(Mandatory = $true)][string]$Dump,
    [string]$Uploads = '',
    [string]$Database = '',
    [string]$XamppDir = 'C:\xampp'
)
$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot

$cfg = @{ AGRO_DB_HOST = '127.0.0.1'; AGRO_DB_NAME = 'agro_marketplace'; AGRO_DB_USER = 'root'; AGRO_DB_PASS = '' }
$envFile = Join-Path $Root '.env'
if (Test-Path $envFile) {
    foreach ($line in Get-Content $envFile -Encoding UTF8) {
        if ($line -match '^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*?)\s*$' -and $cfg.ContainsKey($Matches[1])) { $cfg[$Matches[1]] = $Matches[2].Trim('"', "'") }
    }
}
foreach ($k in @($cfg.Keys)) { $v = [Environment]::GetEnvironmentVariable($k); if ($null -ne $v) { $cfg[$k] = $v } }
if (-not $Database) { $Database = $cfg.AGRO_DB_NAME }
if ($Database -notmatch '^[A-Za-z0-9_]+$') { throw "Nome de base de dados inválido: $Database" }

$mysql = Join-Path $XamppDir 'mysql\bin\mysql.exe'
if (-not (Test-Path $mysql)) { throw "mysql.exe não encontrado em $mysql" }
$Dump = (Resolve-Path $Dump).Path

function Test-Checksum([string]$file) {
    $stamp = [regex]::Match((Split-Path -Leaf $file), '(\d{8}_\d{6})').Groups[1].Value
    $sumFile = Join-Path (Split-Path -Parent $file) "$stamp.sha256"
    if (-not $stamp -or -not (Test-Path $sumFile)) { Write-Warning "Sem ficheiro de checksum para $(Split-Path -Leaf $file)."; return }
    $expected = (Get-Content $sumFile | Where-Object { $_ -match [regex]::Escape((Split-Path -Leaf $file)) + '$' } | Select-Object -First 1) -replace '\s.*$', ''
    $actual = (Get-FileHash -Algorithm SHA256 $file).Hash.ToLower()
    if (-not $expected) { Write-Warning "Checksum de $(Split-Path -Leaf $file) não encontrado em $sumFile."; return }
    if ($expected -ne $actual) { throw "Checksum inválido para $file (esperado $expected, obtido $actual). Restauro cancelado." }
    Write-Host "Checksum OK: $(Split-Path -Leaf $file)"
}

Test-Checksum $Dump
if ($Uploads) { $Uploads = (Resolve-Path $Uploads).Path; Test-Checksum $Uploads }

Write-Host ''
Write-Host "Vai SUBSTITUIR a base de dados '$Database' em $($cfg.AGRO_DB_HOST) com: $Dump" -ForegroundColor Yellow
if ($Uploads) { Write-Host "e SUBSTITUIR a pasta uploads\ com: $Uploads" -ForegroundColor Yellow }
Write-Host 'Faça antes um backup atual (tools\backup.ps1) se ainda não o fez.'
$answer = Read-Host "Para confirmar escreva o nome da base de dados ($Database)"
if ($answer -ne $Database) { Write-Host 'Restauro cancelado.'; exit 1 }

$tmp = Join-Path ([IO.Path]::GetTempPath()) ("agro_restore_" + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $tmp | Out-Null
$env:MYSQL_PWD = $cfg.AGRO_DB_PASS
try {
    $sql = $Dump
    if ($Dump -like '*.zip') {
        Expand-Archive -Path $Dump -DestinationPath $tmp -Force
        $sql = (Get-ChildItem $tmp -Filter *.sql | Select-Object -First 1).FullName
        if (-not $sql) { throw 'O arquivo não contém nenhum ficheiro .sql.' }
    }
    Write-Host "A restaurar $Database..."
    & $mysql -h $cfg.AGRO_DB_HOST -u $cfg.AGRO_DB_USER --default-character-set=utf8mb4 -e "CREATE DATABASE IF NOT EXISTS ``$Database`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    if ($LASTEXITCODE -ne 0) { throw 'Não foi possível criar/aceder à base de dados.' }
    # "source" lê o ficheiro diretamente (sem passar pelo pipeline do PowerShell, que alteraria a codificação).
    & $mysql -h $cfg.AGRO_DB_HOST -u $cfg.AGRO_DB_USER --default-character-set=utf8mb4 $Database -e ("source " + ($sql -replace '\\', '/'))
    if ($LASTEXITCODE -ne 0) { throw "mysql terminou com código $LASTEXITCODE" }

    if ($Uploads) {
        $dest = Join-Path $Root 'uploads'
        $old = "$dest.antes_restauro_" + (Get-Date -Format 'yyyyMMdd_HHmmss')
        if (Test-Path $dest) { Rename-Item $dest $old; Write-Host "Pasta uploads anterior guardada em $old" }
        Expand-Archive -Path $Uploads -DestinationPath $dest -Force
    }
    Write-Host 'Restauro concluído.' -ForegroundColor Green
    exit 0
} catch {
    Write-Error "Restauro falhou: $_"
    exit 1
} finally {
    Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue
    Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
}
