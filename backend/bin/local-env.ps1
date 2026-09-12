# Dot-source desde PowerShell: . ./bin/local-env.ps1
# Configura únicamente el proceso actual; no cambia php.ini del sistema.
$backendRoot = Split-Path -Parent $PSScriptRoot
$localIniDir = Join-Path $backendRoot 'var/php-conf'
$phpModules = & php -m
if ($phpModules -notcontains 'sodium') {
    New-Item -ItemType Directory -Path $localIniDir -Force | Out-Null
    [System.IO.File]::WriteAllText((Join-Path $localIniDir 'sodium.ini'), "extension=sodium`n")
    $env:PHP_INI_SCAN_DIR = $localIniDir
}
if (-not $env:OPENSSL_CONF) {
    $phpExecutable = (Get-Command php).Source
    $opensslConfig = Join-Path (Split-Path -Parent $phpExecutable) 'extras/ssl/openssl.cnf'
    if (Test-Path -LiteralPath $opensslConfig) { $env:OPENSSL_CONF = $opensslConfig }
}
$phpModules = & php -m
if ($phpModules -notcontains 'sodium') { throw 'Habilita ext-sodium en la instalación PHP antes de continuar.' }
