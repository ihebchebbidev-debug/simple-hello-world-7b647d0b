/**
 * Vite config used to build the mobile app as a static bundle hosted
 * under /mobileapp on the admin dashboard.
 *  - base: '/mobileapp/' so assets resolve under that prefix
 *  - BrowserRouter at runtime so deep links like /mobileapp/login
 *    work with the hosted static entrypoint and SPA rewrite rules
 *  - PWA / Service Worker disabled — we never want the embedded mobile
 *    SW to fight with the dashboard's own routing
 */
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

export default defineConfig({
  base: '/mobileapp/',
  plugins: [react()],
  resolve: {
    alias: { '@': path.resolve(__dirname, 'src') },
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
