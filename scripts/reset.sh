#!/usr/bin/env bash
# Reset the DATABASE to a known-clean state (drops every table, reinstalls WP,
# re-activates the plugin + theme). Containers and the WP core volume are kept.
# For a total wipe use scripts/env-down.sh.
SCRIPT_NAME="reset"
source "$(dirname "$0")/lib.sh"

ensure_up

log "dropping all tables (wp db reset)"
wpcli db reset --yes

log "reinstalling WordPress"
wpcli core install \
  --url="$WP_URL" \
  --title="$WP_TITLE" \
  --admin_user="$WP_ADMIN_USER" \
  --admin_password="$WP_ADMIN_PASSWORD" \
  --admin_email="$WP_ADMIN_EMAIL" \
  --skip-email

"$LIB_DIR/activate.sh"
log "environment reset to a clean state."
