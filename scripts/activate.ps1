# Activate Hedayati Core (plugin) + Hedayati (theme) and force the schema/role
# migrations to run now (normally admin_init-gated, which never fires in WP-CLI).
$script:ScriptName = 'activate'
. (Join-Path $PSScriptRoot 'lib.ps1')

Log "activating plugin: hedayati-core"
Invoke-OrDie { Wpcli plugin activate hedayati-core }

Log "activating theme: hedayati"
Invoke-OrDie { Wpcli theme activate hedayati }

Log "running schema + role migrations"
Invoke-OrDie { Wpcli eval 'Hedayati_DB_Schema::migrate(); Hedayati_Roles::register_roles(); echo "db=" . get_option( Hedayati_DB_Schema::OPTION_DB_VERSION ) . " roles=" . get_option( Hedayati_Roles::OPTION_ROLES_VERSION ) . " caps=" . count( (array) get_option( Hedayati_Roles::OPTION_MANAGED_CAPS ) ) . "\n";' }

Log "done."
