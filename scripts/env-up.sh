#!/usr/bin/env bash
# Start a fresh local environment (containers + volumes) WITHOUT installing WP.
# Use scripts/wp-install.sh (or scripts/run-acceptance.sh) for the full flow.
SCRIPT_NAME="env-up"
source "$(dirname "$0")/lib.sh"

ensure_up
log "containers up. Next: scripts/wp-install.sh  (or just scripts/run-acceptance.sh)"
dc ps
