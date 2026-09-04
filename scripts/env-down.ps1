# Tear everything down: stop and remove containers, networks AND volumes.
# This erases the local database and WordPress core - it is fully disposable.
$script:ScriptName = 'env-down'
. (Join-Path $PSScriptRoot 'lib.ps1')

Log "removing containers + volumes"
Invoke-OrDie { Dc down -v --remove-orphans }
Log "done. Nothing left behind."
