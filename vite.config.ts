import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

// Stable per-build id (Vercel commit SHA, else a timestamp). Used for the
// version checker AND as the persisted-cache "buster" so a new deploy discards
// any localStorage cache from an older build (whose data shapes may differ).
const BUILD_ID =
  process.env.VERCEL_GIT_COMMIT_SHA ||
  process.env.VERCEL_DEPLOYMENT_ID ||
  process.env.COMMIT_SHA ||
  String(Date.now());

/**
 * Emits `/version.json` into the build output so the client-side version
 * checker can detect new Vercel deployments and auto-refresh stale tabs.
 */
function emitVersionJson(): Plugin {
  return {
    name: 'emit-version-json',
    apply: 'build',
    generateBundle() {
      this.emitFile({
        type: 'asset',
        fileName: 'version.json',
        source: JSON.stringify({ buildId: BUILD_ID, builtAt: new Date().toISOString() }),
      });
    },
  };
}

export default defineConfig({
  define: { __BUILD_ID__: JSON.stringify(BUILD_ID) },
  plugins: [react(), emitVersionJson()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
    },
  },
  server: {
    port: 5173,
    host: true,
  },
  build: {
    // Split heavy third-party libs into long-cacheable chunks so a code
    // change to our app doesn't bust the vendor cache. Smaller initial
    // entry → faster login on cold visits.
    rollupOptions: {
      output: {
        manualChunks: {
          'react-vendor':   ['react', 'react-dom', 'react-router-dom'],
          'query-vendor':   ['@tanstack/react-query'],
          'charts-vendor':  ['recharts'],
          'i18n-vendor':    ['i18next', 'react-i18next'],
        },
      },
    },
    chunkSizeWarningLimit: 1000,
  },
});
