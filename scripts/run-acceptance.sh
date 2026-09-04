#!/usr/bin/env bash
# One-command entry point: bring the environment up (if needed), install WP (if
# needed), then run the Phase 2A + Phase 2B integration/acceptance suite.
#
# Exit code is the suite's exit code: 0 = all passed, non-zero = failures.
SCRIPT_NAME="run-acceptance"
source "$(dirname "$0")/lib.sh"

ensure_up
ensure_installed

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
