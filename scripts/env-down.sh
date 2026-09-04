#!/usr/bin/env bash
# Tear everything down: stop and remove containers, networks AND volumes.
# This erases the local database and WordPress core — it is fully disposable.
SCRIPT_NAME="env-down"
source "$(dirname "$0")/lib.sh"

log "removing containers + volumes"
dc down -v --remove-orphans
log "done. Nothing left behind."
