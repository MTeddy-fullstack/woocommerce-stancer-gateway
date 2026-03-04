[CmdletBinding()]
param(
    [string]$Version = "",
    [string]$OutputDir = "dist",
    [switch]$Clean
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$rootDir = Split-Path -Parent $scriptDir
$pluginMainFile = Join-Path $rootDir "woocommerce-stancer-gateway.php"

if (-not (Test-Path $pluginMainFile)) {
    throw "Plugin main file not found: $pluginMainFile"
}

if ([string]::IsNullOrWhiteSpace($Version)) {
    $mainFileContent = Get-Content -Path $pluginMainFile -Raw
    $versionMatch = [regex]::Match($mainFileContent, '^\s*\*\s*Version:\s*(.+)$', [System.Text.RegularExpressions.RegexOptions]::Multiline)
    if (-not $versionMatch.Success) {
        throw "Unable to detect plugin version from header in $pluginMainFile"
    }
    $Version = $versionMatch.Groups[1].Value.Trim()
}

$pluginSlug = "woocommerce-stancer-gateway"
$packageName = "$pluginSlug-$Version.zip"
$outputPath = Join-Path $rootDir $OutputDir
$zipPath = Join-Path $outputPath $packageName

$stagingRoot = Join-Path $rootDir ".build"
$stagingPluginDir = Join-Path $stagingRoot $pluginSlug

if ($Clean) {
    if (Test-Path $outputPath) {
        Remove-Item -Path $outputPath -Recurse -Force
    }
    if (Test-Path $stagingRoot) {
        Remove-Item -Path $stagingRoot -Recurse -Force
    }
}

New-Item -ItemType Directory -Path $outputPath -Force | Out-Null
if (Test-Path $stagingRoot) {
    Remove-Item -Path $stagingRoot -Recurse -Force
}
New-Item -ItemType Directory -Path $stagingPluginDir -Force | Out-Null

$excludedTopLevelNames = @(
    ".git",
    ".github",
    ".build",
    "dist",
    "scripts",
    "tests",
    "vendor",
    "node_modules"
)

$excludedFilePatterns = @(
    "*.log",
    "*.zip"
)

$items = Get-ChildItem -Path $rootDir -Force
foreach ($item in $items) {
    if ($excludedTopLevelNames -contains $item.Name) {
        continue
    }

    if ($item.PSIsContainer) {
        Copy-Item -Path $item.FullName -Destination (Join-Path $stagingPluginDir $item.Name) -Recurse -Force
        continue
    }

    $isExcludedFile = $false
    foreach ($pattern in $excludedFilePatterns) {
        if ($item.Name -like $pattern) {
            $isExcludedFile = $true
            break
        }
    }

    if ($isExcludedFile) {
        continue
    }

    if ($item.Name -in @(".editorconfig", ".gitignore", "composer.json", "phpcs.xml", "phpstan.neon", "phpunit.xml.dist")) {
        continue
    }

    Copy-Item -Path $item.FullName -Destination (Join-Path $stagingPluginDir $item.Name) -Force
}

if (Test-Path $zipPath) {
    Remove-Item -Path $zipPath -Force
}

Compress-Archive -Path (Join-Path $stagingRoot $pluginSlug) -DestinationPath $zipPath -CompressionLevel Optimal
Remove-Item -Path $stagingRoot -Recurse -Force

Write-Host "Package created: $zipPath"
