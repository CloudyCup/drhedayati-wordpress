#!/usr/bin/env bash
# Activate Hedayati Core (plugin) + Hedayati (theme) and force the schema/role
# migrations to run now (they are normally admin_init-gated, which never fires
# under WP-CLI).
SCRIPT_NAME="activate"
source "$(dirname "$0")/lib.sh"

log "activating plugin: hedayati-core"
wpcli plugin activate hedayati-core

log "activating theme: hedayati"
wpcli theme activate hedayati

log "running schema + role migrations"
wpcli eval 'Hedayati_DB_Schema::migrate(); Hedayati_Roles::register_roles(); echo "db="            . get_option( Hedayati_DB_Schema::OPTION_DB_VERSION )       . " roles=" . get_option( Hedayati_Roles::OPTION_ROLES_VERSION ) . " caps=" . count( (array) get_option( Hedayati_Roles::OPTION_MANAGED_CAPS ) ) . "\n";'

log "done."
