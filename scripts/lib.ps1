# Shared helpers for the disposable local WordPress integration-test environment.
# Dot-sourced by the other scripts/*.ps1 files — not run directly.
$ErrorActionPreference = 'Stop'

$script:RepoRoot  = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$script:DockerDir = Join-Path $script:RepoRoot 'docker'
Set-Location $script:DockerDir

if (-not (Test-Path (Join-Path $script:DockerDir '.env'))) {
  Copy-Item (Join-Path $script:DockerDir '.env.example') (Join-Path $script:DockerDir '.env')
  Write-Host "[lib] created docker/.env from docker/.env.example"
}

# Load docker/.env into a hashtable AND process env (compose reads it too).
$script:Env = @{}
Get-Content (Join-Path $script:DockerDir '.env') | ForEach-Object {
  if ($_ -match '^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$') {
    $script:Env[$Matches[1]] = $Matches[2]
    Set-Item -Path "Env:$($Matches[1])" -Value $Matches[2]
  }
}
function Cfg([string]$k, [string]$default) { if ($script:Env.ContainsKey($k) -and $script:Env[$k]) { $script:Env[$k] } else { $default } }

$script:WpUrl        = Cfg 'WP_URL'            'http://localhost:8080'
$script:WpTitle      = Cfg 'WP_TITLE'          'Hedayati Local Test'
$script:WpAdminUser  = Cfg 'WP_ADMIN_USER'     'admin'
$script:WpAdminPass  = Cfg 'WP_ADMIN_PASSWORD' 'admin_local_only'
$script:WpAdminEmail = Cfg 'WP_ADMIN_EMAIL'    'admin@hedayati.test'

docker compose version *> $null
if ($LASTEXITCODE -ne 0) { throw "Docker Compose not found. Install Docker Desktop / the compose plugin." }

function Dc         { docker compose @args }
function Wpcli      { docker compose run --rm -T wpcli @args }
function Invoke-OrDie([scriptblock]$b) { & $b; if ($LASTEXITCODE -ne 0) { throw "command failed (exit $LASTEXITCODE)" } }
function Log([string]$m) { Write-Host "[$($script:ScriptName)] $m" -ForegroundColor Cyan }

function Wait-ForWordpressFiles {
  Log "waiting for the WordPress container to generate wp-config.php ..."
  for ($i = 0; $i -lt 60; $i++) {
    docker compose exec -T wordpress test -f /var/www/html/wp-config.php *> $null
    if ($LASTEXITCODE -eq 0) { Log "wp-config.php present."; return }
    Start-Sleep -Seconds 2
  }
  throw "timed out waiting for wp-config.php"
}

function Ensure-Up {
  Invoke-OrDie { Dc up -d db wordpress }
  Invoke-OrDie { Dc build wpcli }
  Wait-ForWordpressFiles
}

function Ensure-Installed {
  Wpcli core is-installed *> $null
  if ($LASTEXITCODE -ne 0) { & (Join-Path $PSScriptRoot 'wp-install.ps1') }
}
