import { copyFileSync, existsSync, mkdirSync, statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, '..');
const publicDir = path.resolve(rootDir, '../public');
mkdirSync(publicDir, { recursive: true });

const candidatePaths = [
  path.resolve(rootDir, 'android/app/build/outputs/apk/debug/app-debug.apk'),
  path.resolve(rootDir, 'android/app/build/outputs/apk/release/app-release.apk'),
  path.resolve(rootDir, 'android/app/build/outputs/apk/debug/app-debug.apk'),
];

const source = candidatePaths.find((file) => existsSync(file));

if (!source) {
  console.error('No Android APK found in mobile_app/android/app/build/outputs/apk');
  process.exit(1);
}

const destinations = [
  path.resolve(publicDir, 'flehty.apk'),
  path.resolve(publicDir, 'flehtyapp.apk'),
];

for (const dest of destinations) {
  copyFileSync(source, dest);
  const sizeMb = (statSync(dest).size / (1024 * 1024)).toFixed(2);
  console.log(`APK copied to ${path.relative(process.cwd(), dest)} (${sizeMb} MB)`);
}
