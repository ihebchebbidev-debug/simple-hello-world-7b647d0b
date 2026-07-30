<#
Build script for Windows: install dependencies, prepare Capacitor Android, and build a release APK.
Usage: npm run android:full

FIX: Redirects GRADLE_USER_HOME to C:\gradle_home to avoid OneDrive file-locking
     conflicts that corrupt Gradle's jar cache.
#>

param()

function Write-Info($message) {
    Write-Host "[INFO] $message" -ForegroundColor Cyan
}

function Write-Success($message) {
    Write-Host "[OK]   $message" -ForegroundColor Green
}

function Write-ErrorAndExit($message) {
    Write-Host "[ERROR] $message" -ForegroundColor Red
    exit 1
}

Set-Location -Path $PSScriptRoot

# ── 0. Redirect Gradle home OFF OneDrive ──────────────────────────────────────
$localGradleHome = Join-Path $env:USERPROFILE "gradle_home"
if (-Not (Test-Path $localGradleHome)) {
    Write-Info "Creating local Gradle home at $localGradleHome (off OneDrive)..."
    New-Item -ItemType Directory -Path $localGradleHome -Force | Out-Null
}
$env:GRADLE_USER_HOME = $localGradleHome
Write-Info "GRADLE_USER_HOME set to: $env:GRADLE_USER_HOME"

# Also set the project-level Gradle cache directory off OneDrive
$env:GRADLE_OPTS = "-Dgradle.user.home=$localGradleHome"

# ── 1. npm install ────────────────────────────────────────────────────────────
Write-Info "Installing npm dependencies..."
npm install 2>&1 | Out-Null
if ($LASTEXITCODE -ne 0) {
    Write-ErrorAndExit "npm install failed."
}
Write-Success "npm install complete."

# ── 2. Build web assets (Android config) ──────────────────────────────────────
Write-Info "Building mobile web assets with Android config..."
npx tsc -b 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Info "TypeScript build had issues, continuing anyway..."
}
npx vite build --config vite.android.config.ts 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-ErrorAndExit "Vite build failed."
}
Write-Success "Web build complete."

# ── 3. Capacitor sync ────────────────────────────────────────────────────────
Write-Info "Ensuring Capacitor Android project exists and is synced..."
npx cap add android 2>&1 | Out-Null
npx cap sync android 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-ErrorAndExit "Capacitor sync failed."
}
Write-Success "Capacitor sync complete."

# ── 4. Clean old Gradle build caches inside the project ──────────────────────
$androidDir = Join-Path -Path $PSScriptRoot -ChildPath "android"
$gradlePath = Join-Path -Path $androidDir -ChildPath "gradlew.bat"
if (-Not (Test-Path $gradlePath)) {
    Write-ErrorAndExit "gradlew.bat not found at android\gradlew.bat. Make sure Android project was generated."
}

Write-Info "Cleaning previous Gradle build..."
Push-Location -Path $androidDir
& $gradlePath clean 2>&1 | Out-Null
Pop-Location
Write-Success "Gradle clean complete."

# ── 5. Build release APK ─────────────────────────────────────────────────────
Write-Info "Building Android release APK (this may take a few minutes on first run)..."
Push-Location -Path $androidDir
& $gradlePath assembleRelease --no-daemon --stacktrace 2>&1
$exitCode = $LASTEXITCODE
Pop-Location

if ($exitCode -ne 0) {
    Write-ErrorAndExit "Gradle assembleRelease failed with exit code $exitCode."
}

# ── 6. Report success ────────────────────────────────────────────────────────
$apkPath = Join-Path -Path $androidDir -ChildPath "app\build\outputs\apk\release"
Write-Success "APK build complete!"
Write-Info "Find release APK at: $apkPath"

if (Test-Path "$apkPath\app-release-unsigned.apk") {
    $apkSize = (Get-Item "$apkPath\app-release-unsigned.apk").Length / 1MB
    Write-Info ("APK size: {0:N1} MB" -f $apkSize)
}
