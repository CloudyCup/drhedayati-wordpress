# One-command entry point: bring the environment up (if needed), install WP (if
# needed), then run the Phase 2A + Phase 2B integration/acceptance suite.
# Exit code is the suite's exit code: 0 = all passed, non-zero = failures.
$script:ScriptName = 'run-acceptance'
. (Join-Path $PSScriptRoot 'lib.ps1')

Ensure-Up
Ensure-Installed

# Preflight: print the environment type the wpcli container actually detects,
# BEFORE running the suite, so a disposable-environment-guard refusal (see
# docker/wp-tests/helpers.php) is instantly diagnosable from CI output alone.
$detectedEnv = (Wpcli eval 'echo wp_get_environment_type();' 2>$null | Out-String).Trim()
if (-not $detectedEnv) { $detectedEnv = '<empty/failed>' }
Log "preflight: wp_get_environment_type() in the wpcli container => '$detectedEnv'"
if ($detectedEnv -ne 'local') {
  Log "WARNING: expected 'local' - the acceptance suite's disposable-environment guard will refuse to run (by design; see docker-compose.yml WP_ENVIRONMENT_TYPE)."
}

Log "running integration/acceptance suite (docker/wp-tests/run.php)"
Write-Host "------------------------------------------------------------------------"
Wpcli eval-file /wp-tests/run.php
$rc = $LASTEXITCODE
Write-Host "------------------------------------------------------------------------"

if ($rc -eq 0) { Log "RESULT: PASS" } else { Log "RESULT: FAIL (exit $rc)" }
exit $rc
