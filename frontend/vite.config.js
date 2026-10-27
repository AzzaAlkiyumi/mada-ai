import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// In development the Vite server forwards API calls to Laravel, so the
// browser sees one origin and Sanctum's session cookies just work.
const backend = process.env.BACKEND_URL ?? 'http://127.0.0.1:8000'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    port: 5173,
    proxy: {
      '/api': backend,
      '/sanctum': backend,
    },
  },
})
