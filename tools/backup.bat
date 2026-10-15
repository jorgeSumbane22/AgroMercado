@echo off
rem Backup do AgroMercado (Windows/XAMPP). Encaminha os argumentos para backup.ps1.
rem Exemplo: tools\backup.bat -RetentionDays 14
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0backup.ps1" %*
exit /b %ERRORLEVEL%
