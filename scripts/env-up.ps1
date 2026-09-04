# Start a fresh local environment (containers + volumes) WITHOUT installing WP.
$script:ScriptName = 'env-up'
. (Join-Path $PSScriptRoot 'lib.ps1')

Ensure-Up
Log "containers up. Next: scripts/wp-install.ps1  (or just scripts/run-acceptance.ps1)"
Dc ps
