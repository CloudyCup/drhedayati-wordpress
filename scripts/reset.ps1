# Reset the DATABASE to a known-clean state (drops every table, reinstalls WP,
# re-activates the plugin + theme). Containers and the WP core volume are kept.
$script:ScriptName = 'reset'
. (Join-Path $PSScriptRoot 'lib.ps1')

Ensure-Up

Log "dropping all tables (wp db reset)"
Invoke-OrDie { Wpcli db reset --yes }

Log "reinstalling WordPress"
Invoke-OrDie {
  Wpcli core install `
    --url=$script:WpUrl `
    --title=$script:WpTitle `
    --admin_user=$script:WpAdminUser `
    --admin_password=$script:WpAdminPass `
    --admin_email=$script:WpAdminEmail `
    --skip-email
}

& (Join-Path $PSScriptRoot 'activate.ps1')
Log "environment reset to a clean state."
