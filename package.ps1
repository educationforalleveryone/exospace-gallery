# Exospace Packaging Script - v2 Deep Clean
$source = Get-Location
$dest = "$home\Desktop\Exospace-Package\Main-File"
$docSource = "$source\documentation"
$docDest = "$home\Desktop\Exospace-Package\Documentation"

Write-Host "[*] Starting Packaging Process..." -ForegroundColor Cyan

# 1. Clean previous build
if (Test-Path "$home\Desktop\Exospace-Package") {
    Remove-Item "$home\Desktop\Exospace-Package" -Recurse -Force
}
New-Item -ItemType Directory -Force -Path $dest | Out-Null
New-Item -ItemType Directory -Force -Path $docDest | Out-Null

# 2. Copy Documentation
Write-Host "[*] Copying Documentation..." -ForegroundColor Yellow
if (Test-Path $docSource) {
    Copy-Item "$docSource\*" $docDest -Recurse
}

# 3. Copy Project Files
# We exclude 'storage' entirely here to rebuild it clean later
Write-Host "[*] Copying Project Files..." -ForegroundColor Yellow
$exclude = @('.git', 'node_modules', '.idea', '.vscode', 'tests', 'storage')
Get-ChildItem $source -Exclude $exclude | Copy-Item -Destination $dest -Recurse

# 4. DEEP CLEAN: Bootstrap Cache (The Fix)
Write-Host "[*] Scrubbing Bootstrap Cache..." -ForegroundColor Yellow
# Remove everything in bootstrap/cache except .gitignore
if (Test-Path "$dest\bootstrap\cache") {
    Get-ChildItem "$dest\bootstrap\cache" -Exclude ".gitignore" | Remove-Item -Force
}

# 5. DEEP CLEAN: Database (The Fix)
Write-Host "[*] Removing Test Database..." -ForegroundColor Yellow
if (Test-Path "$dest\database\database.sqlite") {
    Remove-Item "$dest\database\database.sqlite" -Force
}

# 6. Rebuild Clean Storage Structure
Write-Host "[*] Rebuilding Clean Storage..." -ForegroundColor Yellow
$storageDirs = @(
    "$dest\storage\app\public",
    "$dest\storage\framework\cache",
    "$dest\storage\framework\cache\data",
    "$dest\storage\framework\sessions",
    "$dest\storage\framework\views",
    "$dest\storage\logs"
)

foreach ($dir in $storageDirs) {
    New-Item -ItemType Directory -Path $dir -Force | Out-Null
    # Add .gitignore to keep folder structure in zip
    Set-Content "$dir\.gitignore" "*"
}

# 7. Cleanup Sensitive/Dev Files
Write-Host "[*] Removing Sensitive Files..." -ForegroundColor Red
$filesToDelete = @(
    ".env",
    ".env.bak",
    "package.ps1",
    "public\storage",
    "public\hot",
    "composer.lock",
    "storage\install_data.json",
    "storage\.installed"
)

# Ensure .env.example is present (needed for installer)
Copy-Item "$source\.env.example" "$dest\.env.example" -Force

foreach ($file in $filesToDelete) {
    $path = "$dest\$file"
    if (Test-Path $path) {
        Remove-Item $path -Recurse -Force
    }
}

Write-Host "SUCCESS! Files are cleaned and ready at: Desktop\Exospace-Package" -ForegroundColor Green