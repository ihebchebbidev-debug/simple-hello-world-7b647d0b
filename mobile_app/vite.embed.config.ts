/**
 * Vite config used to build the mobile app as a static bundle hosted
 * under /mobileapp on the admin dashboard.
 *  - base: '/mobileapp/' so assets resolve under that prefix
 *  - BrowserRouter at runtime so deep links like /mobileapp/login
 *    work with the hosted static entrypoint and SPA rewrite rules
 *  - PWA / Service Worker disabled — we never want the embedded mobile
 *    SW to fight with the dashboard's own routing
 */
import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

/**
 * Emits `/mobileapp/version.json` so the runtime version checker can detect a
 * new deployment and force-reload stale tabs. A new build id is produced on
 * every push (Vercel commit SHA, else a timestamp) — this is what guarantees
 * "every code push ⇒ the customer's app updates to the new version".
 */
function emitVersionJson(): Plugin {
  const buildId =
    process.env.VERCEL_GIT_COMMIT_SHA ||
    process.env.VERCEL_DEPLOYMENT_ID ||
    process.env.COMMIT_SHA ||
    String(Date.now());
  return {
    name: 'emit-version-json',
    apply: 'build',
    generateBundle() {
      this.emitFile({
        type: 'asset',
        fileName: 'version.json',
        source: JSON.stringify({ buildId, builtAt: new Date().toISOString() }),
      });
    },
  };
}

export default defineConfig({
  base: '/mobileapp/',
  plugins: [react(), emitVersionJson()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
      'virtual:pwa-register': path.resolve(__dirname, 'src/lib/registerSW.noop.ts'),
    },
  },
  define: {
    'import.meta.env.VITE_USE_HASH_ROUTER': JSON.stringify('false'),
    'import.meta.env.VITE_PWA_ENABLED': JSON.stringify('false'),
  },
  build: {
    outDir: path.resolve(__dirname, '../public/mobileapp'),
    emptyOutDir: true,
    sourcemap: false,
  },
});
