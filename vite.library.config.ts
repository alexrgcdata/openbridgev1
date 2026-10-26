import { defineConfig } from 'vite'
import { resolve } from 'node:path'

export default defineConfig({
  build: {
    outDir: 'dist-library',
    emptyOutDir: true,
    lib: { entry: resolve(import.meta.dirname, 'src/openbridge.ts'), formats: ['es'], fileName: () => 'openbridge.js' },
  },
})
