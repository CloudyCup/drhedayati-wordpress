#!/usr/bin/env bash
# One-command entry point: bring the environment up (if needed), install WP (if
# needed), then run the Phase 2A + Phase 2B integration/acceptance suite.
#
# Exit code is the suite's exit code: 0 = all passed, non-zero = failures.
SCRIPT_NAME="run-acceptance"
source "$(dirname "$0")/lib.sh"

ensure_up
ensure_installed

# Preflight: print the environment type the wpcli container actually detects,
# BEFORE running the suite, so a disposable-environment-guard refusal (see
# docker/wp-tests/helpers.php) is instantly diagnosable from CI output alone
# instead of requiring a re-run with extra debugging.
detected_env="$(wpcli eval 'echo wp_get_environment_type();' 2>/dev/null || true)"
log "preflight: wp_get_environment_type() in the wpcli container => '${detected_env:-<empty/failed>}'"
if [ "$detected_env" != "local" ]; then
  log "WARNING: expected 'local' — the acceptance suite's disposable-environment guard will refuse to run (by design; see docker-compose.yml WP_ENVIRONMENT_TYPE)."
fi

log "running integration/acceptance suite (docker/wp-tests/run.php)"
echo "------------------------------------------------------------------------"
set +e
wpcli eval-file /wp-tests/run.php
rc=$?
set -e
echo "------------------------------------------------------------------------"

if [ "$rc" -eq 0 ]; then
  log "RESULT: PASS"
else
  log "RESULT: FAIL (exit $rc)"
fi
exit "$rc"
