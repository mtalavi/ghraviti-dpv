<# 
.SYNOPSIS
    Automated Release Script for DPV Hub (PowerShell)
.DESCRIPTION
    Creates a new release with semantic versioning, updates CHANGELOG,
    creates git tag and pushes to remote.
.PARAMETER BumpType
    Type of version bump: major, minor, or patch (default: patch)
.EXAMPLE
    .\release.ps1 patch   # 1.0.0 -> 1.0.1 (bug fixes)
    .\release.ps1 minor   # 1.0.0 -> 1.1.0 (new features)  
    .\release.ps1 major   # 1.0.0 -> 2.0.0 (breaking changes)
#>

param(
    [ValidateSet("major", "minor", "patch")]
    [string]$BumpType = "patch"
)

$ErrorActionPreference = "Stop"

# Paths
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$VersionFile = Join-Path $ProjectRoot "VERSION"
$ChangelogFile = Join-Path $ProjectRoot "CHANGELOG.md"

# Check VERSION file
if (-not (Test-Path $VersionFile)) {
    Write-Host "ERROR: VERSION file not found at $VersionFile" -ForegroundColor Red
    exit 1
}

# Read current version
$CurrentVersion = (Get-Content $VersionFile -Raw).Trim()
Write-Host "Current version: " -NoNewline
Write-Host $CurrentVersion -ForegroundColor Yellow

# Parse version
$VersionParts = $CurrentVersion -split '\.'
$Major = [int]$VersionParts[0]
$Minor = [int]$VersionParts[1]
$Patch = [int]$VersionParts[2]

# Calculate new version
switch ($BumpType) {
    "major" {
        $Major++
        $Minor = 0
        $Patch = 0
    }
    "minor" {
        $Minor++
        $Patch = 0
    }
    "patch" {
        $Patch++
    }
}

$NewVersion = "$Major.$Minor.$Patch"
Write-Host "New version: " -NoNewline
Write-Host $NewVersion -ForegroundColor Green

# Confirm
$Confirm = Read-Host "Create release v$NewVersion? (y/n)"
if ($Confirm -ne 'y' -and $Confirm -ne 'Y') {
    Write-Host "Aborted." -ForegroundColor Yellow
    exit 0
}

# Update VERSION file
Set-Content -Path $VersionFile -Value $NewVersion -NoNewline
Write-Host "✓ Updated VERSION file" -ForegroundColor Green

# Update CHANGELOG
$Today = Get-Date -Format "yyyy-MM-dd"
if (Test-Path $ChangelogFile) {
    $ChangelogContent = Get-Content $ChangelogFile -Raw
    $NewSection = @"
## [Unreleased]

### Added
- Nothing yet

### Changed
- Nothing yet

### Fixed
- Nothing yet

---

## [$NewVersion] - $Today
"@
    $ChangelogContent = $ChangelogContent -replace '## \[Unreleased\]', $NewSection
    Set-Content -Path $ChangelogFile -Value $ChangelogContent
    Write-Host "✓ Updated CHANGELOG.md" -ForegroundColor Green
}

# Git operations
Write-Host "Committing changes..." -ForegroundColor Cyan
git add -A
git commit -m "Release v$NewVersion"

Write-Host "Creating tag v$NewVersion..." -ForegroundColor Cyan
git tag -a "v$NewVersion" -m "Release v$NewVersion"

Write-Host "Pushing to remote..." -ForegroundColor Cyan
git push origin main
git push origin "v$NewVersion"

Write-Host ""
Write-Host "═══════════════════════════════════════════════════════════════" -ForegroundColor Green
Write-Host "  ✓ Release v$NewVersion created successfully!" -ForegroundColor Green
Write-Host "═══════════════════════════════════════════════════════════════" -ForegroundColor Green
Write-Host ""
Write-Host "To view all releases: git tag --list"
Write-Host "To checkout a release: git checkout v$NewVersion"
