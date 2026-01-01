<# 
.SYNOPSIS
    Automated Release Script for DPV Hub (PowerShell)
.EXAMPLE
    .\release.ps1           # 1.0.0 -> 1.0.1 (patch)
    .\release.ps1 minor     # 1.0.0 -> 1.1.0
    .\release.ps1 major     # 1.0.0 -> 2.0.0
    .\release.ps1 -Auto     # No confirmation prompt
#>

param(
    [ValidateSet("major", "minor", "patch")]
    [string]$BumpType = "patch",
    [switch]$Auto
)

$ErrorActionPreference = "Stop"

# Paths - handle both running from scripts/ or from project root
$ScriptDir = $PSScriptRoot
if ($ScriptDir -eq "") { $ScriptDir = Get-Location }

$ProjectRoot = if (Test-Path (Join-Path $ScriptDir "..\VERSION")) {
    Split-Path -Parent $ScriptDir
} else {
    $ScriptDir
}

$VersionFile = Join-Path $ProjectRoot "VERSION"
$ChangelogFile = Join-Path $ProjectRoot "CHANGELOG.md"

# Check VERSION file
if (-not (Test-Path $VersionFile)) {
    Write-Host "ERROR: VERSION file not found at $VersionFile" -ForegroundColor Red
    Write-Host "Creating VERSION file with 1.0.0..."
    Set-Content -Path $VersionFile -Value "1.0.0" -NoNewline
}

# Read current version
$CurrentVersion = (Get-Content $VersionFile -Raw).Trim()
Write-Host "Current version: $CurrentVersion" -ForegroundColor Yellow

# Parse version
$VersionParts = $CurrentVersion -split '\.'
$Major = [int]$VersionParts[0]
$Minor = if ($VersionParts.Length -gt 1) { [int]$VersionParts[1] } else { 0 }
$Patch = if ($VersionParts.Length -gt 2) { [int]$VersionParts[2] } else { 0 }

# Calculate new version
switch ($BumpType) {
    "major" { $Major++; $Minor = 0; $Patch = 0 }
    "minor" { $Minor++; $Patch = 0 }
    "patch" { $Patch++ }
}

$NewVersion = "$Major.$Minor.$Patch"
Write-Host "New version: $NewVersion" -ForegroundColor Green

# Confirm (unless -Auto)
if (-not $Auto) {
    $Confirm = Read-Host "Create release v$NewVersion? (y/n)"
    if ($Confirm -ne 'y' -and $Confirm -ne 'Y') {
        Write-Host "Aborted." -ForegroundColor Yellow
        exit 0
    }
}

# Update VERSION file
Set-Content -Path $VersionFile -Value $NewVersion -NoNewline
Write-Host "[OK] Updated VERSION file" -ForegroundColor Green

# Update CHANGELOG
$Today = Get-Date -Format "yyyy-MM-dd"
if (Test-Path $ChangelogFile) {
    $ChangelogContent = Get-Content $ChangelogFile -Raw
    $NewSection = "## [Unreleased]`n`n### Added`n- Nothing yet`n`n### Changed`n- Nothing yet`n`n### Fixed`n- Nothing yet`n`n---`n`n## [$NewVersion] - $Today"
    $ChangelogContent = $ChangelogContent -replace '## \[Unreleased\]', $NewSection
    Set-Content -Path $ChangelogFile -Value $ChangelogContent
    Write-Host "[OK] Updated CHANGELOG.md" -ForegroundColor Green
}

# Git operations
Write-Host "Committing changes..." -ForegroundColor Cyan
Set-Location $ProjectRoot
git add -A
git commit -m "Release v$NewVersion"

Write-Host "Creating tag v$NewVersion..." -ForegroundColor Cyan
git tag -a "v$NewVersion" -m "Release v$NewVersion"

Write-Host "Pushing to remote..." -ForegroundColor Cyan
git push origin main
git push origin "v$NewVersion"

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "  Release v$NewVersion created!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
