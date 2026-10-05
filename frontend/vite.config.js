import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// 开发：根路径 + /api 代理到 Laravel
// 生产：构建到 Laravel public/app，以 /app/ 为资源前缀，由 Laravel 一并托管
export default defineConfig(({ mode }) => ({
  base: mode === 'production' ? '/app/' : '/',
  plugins: [react()],
  server: {
    host: '0.0.0.0',
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: '../backend/public/app',
    emptyOutDir: true,
  },
}))
