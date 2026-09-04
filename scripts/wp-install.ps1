# Install WordPress automatically, then activate the Hedayati plugin + theme.
# Idempotent: skips the core install if already done.
$script:ScriptName = 'wp-install'
. (Join-Path $PSScriptRoot 'lib.ps1')

Ensure-Up

Wpcli core is-installed *> $null
if ($LASTEXITCODE -eq 0) {
  Log "WordPress already installed - skipping core install."
} else {
  Log "installing WordPress ($script:WpUrl)"
  Invoke-OrDie {
    Wpcli core install `
      --url=$script:WpUrl `
      --title=$script:WpTitle `
      --admin_user=$script:WpAdminUser `
      --admin_password=$script:WpAdminPass `
      --admin_email=$script:WpAdminEmail `
      --skip-email
  }
  Wpcli option update timezone_string 'Asia/Tehran'
  Wpcli rewrite structure '/%postname%/' --hard *> $null
}

& (Join-Path $PSScriptRoot 'activate.ps1')
Log "WordPress ready at $script:WpUrl  (admin: $script:WpAdminUser / $script:WpAdminPass)"
