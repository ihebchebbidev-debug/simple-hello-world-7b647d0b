/**
 * Vite config used to build the mobile app as a static bundle hosted
 * under /mobileapp on the admin dashboard.
 *  - base: '/mobileapp/' so assets resolve under that prefix
 *  - HashRouter at runtime (VITE_USE_HASH_ROUTER=true) so deep links
 *    survive a refresh without needing server SPA rewrites
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
    'import.meta.env.VITE_USE_HASH_ROUTER': JSON.stringify('true'),
  },
  build: {
    outDir: path.resolve(__dirname, '../public/mobileapp'),
    emptyOutDir: true,
    sourcemap: false,
  },
});
