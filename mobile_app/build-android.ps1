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
$localGradleHome = Join-Path $env:USERPROFILE "gradle_home_clean"
if (-Not (Test-Path $localGradleHome)) {
    Write-Info "Creating local Gradle home at $localGradleHome (off OneDrive)..."
    New-Item -ItemType Directory -Path $localGradleHome -Force | Out-Null
}
$env:GRADLE_USER_HOME = $localGradleHome
Write-Info "GRADLE_USER_HOME set to: $env:GRADLE_USER_HOME"

# Also set the project-level Gradle cache directory off OneDrive
$env:GRADLE_OPTS = "-Dgradle.user.home=$localGradleHome"

# ── 0b. Purge ALL Gradle caches that can cause corruption ─────────────────────
# The "Corrupt serialized resolution result" and "Problems reading data from
# Binary store" errors come from stale/corrupt files in caches/ and .tmp/.
# Nuking them forces a clean re-resolve on next build.
$cacheDirs = @(
    (Join-Path $localGradleHome "caches"),
    (Join-Path $localGradleHome ".tmp")
)
foreach ($dir in $cacheDirs) {
    if (Test-Path $dir) {
        Write-Info "Removing Gradle cache: $dir ..."
        Remove-Item -Recurse -Force $dir -ErrorAction SilentlyContinue
    }
}


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

# ── 3b. App icon + splash from resources/ (Flehty logo) ──────────────────────
Write-Info "Generating Android launcher icons and splash from resources/..."
npx @capacitor/assets generate --android --assetPath resources 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-ErrorAndExit "Icon generation failed. Check resources/icon-only.png exists."
}
Write-Success "App icons generated."

# ── 3c. Ensure the Gradle wrapper supports the installed JDK ─────────────────
# JDK 21 emits class file major version 65, which Gradle < 8.5 cannot read
# ("Unsupported class file major version 65"). Older android/ folders generated
# by Capacitor 6 ship Gradle 8.2, so pin a modern wrapper before building.
$requiredGradle = "8.11.1"
$wrapperProps = Join-Path $PSScriptRoot "android\gradle\wrapper\gradle-wrapper.properties"
if (Test-Path $wrapperProps) {
    $props = Get-Content $wrapperProps -Raw
    if ($props -match "gradle-(\d+)\.(\d+)") {
        $major = [int]$Matches[1]; $minor = [int]$Matches[2]
        if ($major -lt 8 -or ($major -eq 8 -and $minor -lt 5)) {
            Write-Info "Gradle wrapper $major.$minor is too old for modern JDKs; upgrading to $requiredGradle..."
            $props = $props -replace "gradle-\d+(\.\d+)*(-\w+)?-(all|bin)\.zip", "gradle-$requiredGradle-all.zip"
            Set-Content -Path $wrapperProps -Value $props -NoNewline
            Write-Success "Gradle wrapper upgraded to $requiredGradle."
        }
    }
}



# ── 4. Clean old Gradle build caches inside the project ──────────────────────
$androidDir = Join-Path -Path $PSScriptRoot -ChildPath "android"
$gradlePath = Join-Path -Path $androidDir -ChildPath "gradlew.bat"
if (-Not (Test-Path $gradlePath)) {
    Write-ErrorAndExit "gradlew.bat not found at android\gradlew.bat. Make sure Android project was generated."
}

Write-Info "Stopping Gradle daemons..."
Push-Location -Path $androidDir
& $gradlePath --stop 2>&1 | Out-Null
Stop-Process -Name java -Force -ErrorAction SilentlyContinue
Pop-Location

Write-Info "Aggressively cleaning previous Gradle build and caches..."

# ── 0b. Purge ALL Gradle caches that can cause corruption ─────────────────────
# The "Corrupt serialized resolution result" and "Problems reading data from
# Binary store" errors come from stale/corrupt files in caches/ and .tmp/.
# Nuking them forces a clean re-resolve on next build.
$cacheDirs = @(
    (Join-Path $localGradleHome "caches"),
    (Join-Path $localGradleHome ".tmp"),
    (Join-Path $localGradleHome "daemon")
)
foreach ($dir in $cacheDirs) {
    if (Test-Path $dir) {
        Write-Info "Removing Gradle cache: $dir ..."
        Remove-Item -Recurse -Force $dir -ErrorAction SilentlyContinue
    }
}

Push-Location -Path $androidDir
# Wipe ALL local project caches and build dirs for every subproject
Remove-Item -Recurse -Force .gradle -ErrorAction SilentlyContinue
Remove-Item -Recurse -Force build -ErrorAction SilentlyContinue
Remove-Item -Recurse -Force app\build -ErrorAction SilentlyContinue
Remove-Item -Recurse -Force capacitor-cordova-android-plugins\build -ErrorAction SilentlyContinue

# Also wipe build dirs inside node_modules capacitor subprojects
Get-ChildItem -Path ..\node_modules\@capacitor -Directory -ErrorAction SilentlyContinue | ForEach-Object {
    $subBuild = Join-Path $_.FullName "android\build"
    if (Test-Path $subBuild) {
        Remove-Item -Recurse -Force $subBuild -ErrorAction SilentlyContinue
    }
}

& $gradlePath clean 2>&1 | Out-Null
Pop-Location
Write-Success "Gradle clean complete."

# ── 5. Build release APK ─────────────────────────────────────────────────────
Write-Info "Building Android release APK (this may take a few minutes on first run)..."
Push-Location -Path $androidDir
& $gradlePath assembleRelease --no-daemon --no-build-cache --no-configuration-cache --stacktrace 2>&1
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
