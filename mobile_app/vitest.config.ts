import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'node:path';

const appRoot = path.resolve(__dirname);

export default defineConfig({
  root: appRoot,
  plugins: [react()],
  resolve: {
    alias: { '@': path.resolve(appRoot, 'src') },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: [path.resolve(appRoot, 'src/test/setup.ts')],
  },
});
