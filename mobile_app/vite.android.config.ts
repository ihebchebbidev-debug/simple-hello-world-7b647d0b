/**
 * Vite config for the Android/Capacitor build.
 * base must be '/' so asset paths in index.html resolve correctly
 * under https://localhost/ inside the Capacitor WebView.
 * The web build uses /mobileapp/ as base; that prefix does not exist
 * inside the APK, causing the white-screen bug.
 */
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

export default defineConfig({
  base: '/',
  plugins: [react()],
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
    outDir: 'dist',
    emptyOutDir: true,
    sourcemap: false,
  },
});
