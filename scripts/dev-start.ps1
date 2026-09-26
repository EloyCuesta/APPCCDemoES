param(
    [Parameter(Mandatory = $true, Position = 0)]
    [ValidateSet('backend', 'frontend', 'tareas')]
    [string]$Service
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
Push-Location $repoRoot
try {
    if ($Service -eq 'frontend') {
        Set-Location frontend
        & npm.cmd run dev -- --hostname localhost --port 3000
    } else {
        Set-Location backend
        . ./bin/local-env.ps1
        & php bin/check-local-dev.php
        if ($LASTEXITCODE -ne 0) { throw 'Entorno local rechazado.' }
        & php bin/console app:tareas:procesar
        if ($LASTEXITCODE -ne 0) { throw 'No se pudo procesar la agenda.' }
        if ($Service -eq 'backend') {
            & php -d variables_order=EGPCS -d upload_max_filesize=12M -d post_max_size=24M -S 127.0.0.1:8000 -t public
        }
    }
    if ($LASTEXITCODE -ne 0) { throw "El proceso termino con codigo $LASTEXITCODE." }
} finally { Pop-Location }
