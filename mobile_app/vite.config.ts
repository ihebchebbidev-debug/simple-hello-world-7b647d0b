import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';
import { VitePWA } from 'vite-plugin-pwa';

/**
 * Emits `/version.json` so the version checker can detect new Vercel
 * deployments and auto-refresh stale tabs.
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
  plugins: [

    react(),
    emitVersionJson(),
    VitePWA({
      strategies: 'injectManifest',
      srcDir: 'src',
      filename: 'sw.ts',
      registerType: 'autoUpdate',
      injectRegister: false, // we register manually with iframe/preview guards
      manifest: false, // we ship a static public/manifest.webmanifest
      devOptions: { enabled: false }, // never run the SW in dev
      injectManifest: {
        globPatterns: ['**/*.{js,css,html,ico,png,svg,webmanifest,woff2}'],
        maximumFileSizeToCacheInBytes: 5 * 1024 * 1024,
      },
    }),
  ],
  resolve: {
    alias: { '@': path.resolve(__dirname, 'src') },
  },
  server: { port: 5174, host: true },
});
