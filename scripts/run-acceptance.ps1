# One-command entry point: bring the environment up (if needed), install WP (if
# needed), then run the Phase 2A + Phase 2B integration/acceptance suite.
# Exit code is the suite's exit code: 0 = all passed, non-zero = failures.
$script:ScriptName = 'run-acceptance'
. (Join-Path $PSScriptRoot 'lib.ps1')

Ensure-Up
Ensure-Installed

Log "running integration/acceptance suite (docker/wp-tests/run.php)"
Write-Host "------------------------------------------------------------------------"
Wpcli eval-file /wp-tests/run.php
$rc = $LASTEXITCODE
Write-Host "------------------------------------------------------------------------"

if ($rc -eq 0) { Log "RESULT: PASS" } else { Log "RESULT: FAIL (exit $rc)" }
exit $rc
