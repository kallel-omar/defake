$ErrorActionPreference = "Stop"

$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

Write-Host "Starting DeFake dev environment..." -ForegroundColor Cyan

Start-Process powershell -WorkingDirectory $projectRoot -ArgumentList @(
    "-NoExit",
    "-Command",
    "symfony server:start"
)

Start-Sleep -Seconds 2

Start-Process powershell -WorkingDirectory $projectRoot -ArgumentList @(
    "-NoExit",
    "-Command",
    "php bin/console messenger:consume async -vv --time-limit=3600 --memory-limit=256M"
)

Write-Host "DeFake dev server and Messenger worker started." -ForegroundColor Green