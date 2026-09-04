#!/usr/bin/env bash
# Install WordPress automatically into the running environment, then activate the
# Hedayati plugin + theme. Idempotent: skips the core install if already done.
SCRIPT_NAME="wp-install"
source "$(dirname "$0")/lib.sh"

ensure_up

if wpcli core is-installed >/dev/null 2>&1; then
  log "WordPress already installed — skipping core install."
else
  log "installing WordPress ($WP_URL)"
  wpcli core install \
    --url="$WP_URL" \
    --title="$WP_TITLE" \
    --admin_user="$WP_ADMIN_USER" \
    --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="$WP_ADMIN_EMAIL" \
    --skip-email
  wpcli option update timezone_string 'Asia/Tehran'
  wpcli option update blogdescription 'Disposable local integration-test environment'
  wpcli rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
fi

"$LIB_DIR/activate.sh"

log "WordPress ready at $WP_URL  (admin: $WP_ADMIN_USER / $WP_ADMIN_PASSWORD)"
