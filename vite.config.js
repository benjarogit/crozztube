import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  base: '/build/',
  plugins: [react()],
  build: {
    outDir: 'build',
    assetsDir: 'assets',
    manifest: "manifest.json",
    // Open SPA tabs can still request lazy Vidstack chunks from the previous build.
    emptyOutDir: false,
    rollupOptions: {
      input: ['resources/js/main.jsx', 'resources/js/embed-player.jsx', 'resources/js/livekitRecordingTemplate.js'],
    },
  },
  server: {
    origin: 'http://localhost:5173',
  },
});
