import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

export default defineConfig({
  plugins: [react()],
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
