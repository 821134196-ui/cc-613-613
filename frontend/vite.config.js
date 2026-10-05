import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// 前端 5173，接口代理到 Laravel 8000，避免跨域。
export default defineConfig({
  plugins: [react()],
  server: {
    host: '127.0.0.1',
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
})
