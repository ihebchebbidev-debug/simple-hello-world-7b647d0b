<#
Build script for Windows: install dependencies, prepare Capacitor Android, and build a release APK.
Usage: npm run android:full
#>

param()

function Write-Info($message) {
    Write-Host "[INFO] $message"
}

function Write-ErrorAndExit($message) {
    Write-Host "[ERROR] $message" -ForegroundColor Red
    exit 1
}

Set-Location -Path $PSScriptRoot

Write-Info "Installing npm dependencies..."
npm install | Out-Null
if ($LASTEXITCODE -ne 0) {
    Write-ErrorAndExit "npm install failed."
}

Write-Info "Building mobile web assets with Android config..."
npm run build:android-web | Out-Null
if ($LASTEXITCODE -ne 0) {
    Write-ErrorAndExit "Web build failed."
}

Write-Info "Ensuring Capacitor Android project exists and is synced..."
npx cap add android 2>$null | Out-Null
npx cap sync android | Out-Null
if ($LASTEXITCODE -ne 0) {
    Write-ErrorAndExit "Capacitor sync failed."
}

$gradlePath = Join-Path -Path $PSScriptRoot -ChildPath "android\gradlew.bat"
if (-Not (Test-Path $gradlePath)) {
    Write-ErrorAndExit "gradlew.bat not found at android\gradlew.bat. Make sure Android project was generated."
}

Write-Info "Building Android release APK..."
Push-Location -Path (Join-Path -Path $PSScriptRoot -ChildPath 'android')
& $gradlePath assembleRelease
$exitCode = $LASTEXITCODE
Pop-Location
if ($exitCode -ne 0) {
    Write-ErrorAndExit "Gradle assembleRelease failed."
}

Write-Info "APK build complete."
Write-Info "Find release APK at android\app\build\outputs\apk\release\"
