<#
.SYNOPSIS
    Build the two deployable ZIPs from canonical source ONLY.

.DESCRIPTION
    Canonical inputs (the ONLY inputs):
        plugin/hedayati-core/   ->  staging-export/hedayati-core.zip
        theme/hedayati/         ->  staging-export/hedayati.zip

    Never packages package-plugin/, any pre-existing *.zip, or anything else.
    Uses the project's approved `tar -a` convention (docs/DECISIONS.md D23) — NOT
    PowerShell Compress-Archive, which produced archives the host mis-extracted.

    Verifies each archive's top-level layout and that the version string inside
    the plugin ZIP matches plugin/hedayati-core/hedayati-core.php.

    This script does NOT deploy. The output ZIPs stay gitignored.

.EXAMPLE
    pwsh ./scripts/build-packages.ps1
#>

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

# ── Locate the repo root (this script lives in <root>/scripts) ────────────────
$RepoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $RepoRoot

$PluginSrc = Join-Path $RepoRoot 'plugin/hedayati-core'
$ThemeSrc  = Join-Path $RepoRoot 'theme/hedayati'
$OutDir    = Join-Path $RepoRoot 'staging-export'

$PluginZip = Join-Path $OutDir 'hedayati-core.zip'
$ThemeZip  = Join-Path $OutDir 'hedayati.zip'

foreach ($p in @($PluginSrc, $ThemeSrc)) {
    if (-not (Test-Path $p)) { throw "Canonical source missing: $p" }
}
if (-not (Test-Path (Join-Path $PluginSrc 'hedayati-core.php'))) { throw 'plugin entry file missing' }
if (-not (Test-Path (Join-Path $ThemeSrc  'style.css')))         { throw 'theme entry file missing' }
if (-not (Get-Command tar -ErrorAction SilentlyContinue))        { throw 'tar not found on PATH (Windows 10+ / Git for Windows ships bsdtar)' }

New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

# ── Canonical plugin version (source of truth) ───────────────────────────────
$pluginPhp = Get-Content (Join-Path $PluginSrc 'hedayati-core.php') -Raw
if ($pluginPhp -notmatch "define\(\s*'HEDAYATI_CORE_VERSION',\s*'([0-9]+\.[0-9]+\.[0-9]+)'\s*\)") {
    throw 'Could not read HEDAYATI_CORE_VERSION from plugin/hedayati-core/hedayati-core.php'
}
$canonicalVersion = $Matches[1]
Write-Host "Canonical Hedayati Core version: $canonicalVersion" -ForegroundColor Cyan

# ── Build (tar -a, run from the parent dir so the ZIP root is the folder) ─────
function Build-Zip([string]$parentDir, [string]$folder, [string]$outZip) {
    if (Test-Path $outZip) { Remove-Item $outZip -Force }
    Push-Location $parentDir
    try {
        # tar -a: auto-compress by extension (.zip). Portable: same invocation on
        # macOS/Linux bsdtar and Windows bsdtar.
        & tar -a -c -f $outZip $folder
        if ($LASTEXITCODE -ne 0) { throw "tar failed for $folder (exit $LASTEXITCODE)" }
    }
    finally { Pop-Location }
}

Build-Zip (Join-Path $RepoRoot 'plugin') 'hedayati-core' $PluginZip
Build-Zip (Join-Path $RepoRoot 'theme')  'hedayati'      $ThemeZip

# ── Verify archive layouts ──────────────────────────────────────────────────
function Assert-InZip([string]$zip, [string]$entry) {
    $list = & tar -tf $zip
    if ($LASTEXITCODE -ne 0) { throw "cannot read $zip" }
    if (-not ($list | Where-Object { $_ -eq $entry -or $_ -eq "$entry" })) {
        throw "archive $zip is missing expected entry: $entry`n--- contents ---`n$($list -join "`n")"
    }
}

Assert-InZip $PluginZip 'hedayati-core/hedayati-core.php'
Assert-InZip $ThemeZip  'hedayati/style.css'

# ── Verify the version inside the plugin ZIP matches canonical source ────────
$tmp = Join-Path ([System.IO.Path]::GetTempPath()) ("hedayati-verify-" + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force -Path $tmp | Out-Null
try {
    Push-Location $tmp
    & tar -xf $PluginZip 'hedayati-core/hedayati-core.php'
    if ($LASTEXITCODE -ne 0) { throw 'could not extract plugin entry file for verification' }
    Pop-Location
    $zipPhp = Get-Content (Join-Path $tmp 'hedayati-core/hedayati-core.php') -Raw
    if ($zipPhp -notmatch "define\(\s*'HEDAYATI_CORE_VERSION',\s*'([0-9]+\.[0-9]+\.[0-9]+)'\s*\)") {
        throw 'plugin ZIP entry file has no HEDAYATI_CORE_VERSION'
    }
    if ($Matches[1] -ne $canonicalVersion) {
        throw "VERSION MISMATCH: ZIP has $($Matches[1]) but canonical source is $canonicalVersion"
    }
    if ($zipPhp -notmatch 'Version:\s*' + [regex]::Escape($canonicalVersion)) {
        throw "plugin ZIP header 'Version:' line does not match $canonicalVersion"
    }
}
finally {
    Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
}

Write-Host ''
Write-Host "OK  $PluginZip" -ForegroundColor Green
Write-Host "    root: hedayati-core/hedayati-core.php   version: $canonicalVersion"
Write-Host "OK  $ThemeZip" -ForegroundColor Green
Write-Host "    root: hedayati/style.css"
Write-Host ''
Write-Host 'Build complete. These ZIPs are gitignored. Deploy per docs/DEPLOYMENT.md.' -ForegroundColor Cyan
