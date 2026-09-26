param(
    [ValidatePattern('^appcc_demo_es(?:_[a-z0-9]+)*$')]
    [string]$DatabaseName = 'appcc_demo_es',
    [string]$DatabaseUser = 'postgres',
    [ValidateRange(1, 65535)]
    [int]$DatabasePort = 5432
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$backendRoot = Join-Path $repoRoot 'backend'
if ($env:APP_ENV -and $env:APP_ENV -ne 'dev') {
    throw 'Setup exclusivo de desarrollo: APP_ENV debe ser dev o no estar definido.'
}
foreach ($tool in @('php', 'composer', 'node', 'npm.cmd')) {
    Get-Command $tool -ErrorAction Stop | Out-Null
}

function Invoke-Checked([string]$Program, [string[]]$Arguments) {
    & $Program @Arguments
    if ($LASTEXITCODE -ne 0) { throw "$Program ha fallado (codigo $LASTEXITCODE). Preparacion detenida." }
}

Push-Location $backendRoot
try {
    . ./bin/local-env.ps1
    if (-not (Test-Path -LiteralPath '.env.local')) {
        # La password no aparece en la linea de comandos ni en la salida.
        $password = Read-Host "Password PostgreSQL local para $DatabaseUser" -AsSecureString
        $credential = New-Object System.Management.Automation.PSCredential($DatabaseUser, $password)
        $encodedUser = [Uri]::EscapeDataString($DatabaseUser)
        $encodedPassword = [Uri]::EscapeDataString($credential.GetNetworkCredential().Password)
        $databaseUrl = "postgresql://${encodedUser}:${encodedPassword}@127.0.0.1:${DatabasePort}/${DatabaseName}?serverVersion=18&charset=utf8"
        $secretBytes = New-Object byte[] 32
        $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
        try { $rng.GetBytes($secretBytes) } finally { $rng.Dispose() }
        $secret = ([BitConverter]::ToString($secretBytes)).Replace('-', '').ToLowerInvariant()
        [IO.File]::WriteAllText((Join-Path $backendRoot '.env.local'), "APP_ENV=dev`nAPP_SECRET=$secret`nDATABASE_URL='$databaseUrl'`nAPPCC_EVIDENCIAS_DIR=%kernel.project_dir%/var/appcc/evidencias-$DatabaseName`n")
        $encodedPassword = $databaseUrl = $credential = $password = $null
    }
    # Primero las dependencias; no arrancar Symfony hasta validar el entorno efectivo.
    Invoke-Checked 'composer' @('install', '--prefer-dist', '--no-interaction', '--no-scripts')
    Invoke-Checked 'php' @('bin/check-local-dev.php')
    Invoke-Checked 'composer' @('run-script', 'post-install-cmd')
    Invoke-Checked 'php' @('bin/console', 'lexik:jwt:generate-keypair', '--skip-if-exists', '--no-interaction')
    Invoke-Checked 'php' @('bin/console', 'doctrine:database:create', '--if-not-exists', '--no-interaction')
    Invoke-Checked 'php' @('bin/console', 'doctrine:migrations:migrate', '--no-interaction')
    Invoke-Checked 'php' @('bin/console', 'doctrine:schema:validate')
    Invoke-Checked 'php' @('bin/console', 'app:plantillas:cargar')
    Invoke-Checked 'php' @('bin/console', 'app:demo:seed')
    Invoke-Checked 'php' @('bin/console', 'app:tareas:procesar')
    Set-Location (Join-Path $repoRoot 'frontend')
    Invoke-Checked 'npm.cmd' @('ci', '--no-audit', '--no-fund')
    Write-Host 'Preparado. Ejecuta scripts/dev-start.ps1 backend y scripts/dev-start.ps1 frontend en dos terminales.'
} finally { Pop-Location }
