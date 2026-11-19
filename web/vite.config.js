import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// In development the page, the API and the live feed share one origin through
// this proxy, exactly as they will behind nginx in production.
const api = process.env.API_URL || 'http://127.0.0.1:8000'
const feed = process.env.FEED_URL || 'http://127.0.0.1:8090'

export default defineConfig({
  plugins: [vue()],
  server: {
    proxy: {
      '/api': { target: api, changeOrigin: true },
      '/ws': { target: feed, ws: true },
    },
  },
})
