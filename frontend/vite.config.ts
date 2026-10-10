import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
export default defineConfig({
  plugins: [vue()],
  server: { proxy: { '/api': { target: process.env.API_PROXY_URL || 'http://127.0.0.1:8080', changeOrigin: false }, '/ws': { target: process.env.WS_PROXY_URL || 'ws://127.0.0.1:8081', ws: true } } },
  build: { sourcemap: false, outDir: '../public', emptyOutDir: false },
});
