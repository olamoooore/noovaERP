import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { VitePWA } from 'vite-plugin-pwa'
import path from 'path'

const backendTarget = process.env.VITE_BACKEND_URL || 'http://localhost:8000'

export default defineConfig({
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      injectRegister: null,
      includeAssets: ['pwa-192x192.svg', 'pwa-512x512.svg', 'apple-touch-icon.svg'],
      manifest: {
        name: 'NoovaERP - Point of Sale',
        short_name: 'NoovaERP POS',
        description: 'NoovaERP Point of Sale Terminal — installable, fast, offline-ready',
        theme_color: '#1e293b',
        background_color: '#0f172a',
        display: 'standalone',
        orientation: 'any',
        start_url: './pos/terminal',
        scope: './',
        icons: [
          {
            src: 'pwa-192x192.svg',
            sizes: '192x192',
            type: 'image/svg+xml',
            purpose: 'any',
          },
          {
            src: 'pwa-512x512.svg',
            sizes: '512x512',
            type: 'image/svg+xml',
            purpose: 'any maskable',
          },
        ],
        categories: ['business', 'finance', 'productivity'],
      },
      workbox: {
        // Cache all JS/CSS/HTML assets for offline shell
        globPatterns: ['**/*.{js,css,html,svg,ico,woff,woff2}'],
        // Exclude large files and theme screenshots
        globIgnores: ['**/theme-screenshots/**', '**/Web-Templates/**'],
        // Allow large chunks (WebsiteEditor is 2.4MB)
        maximumFileSizeToCacheInBytes: 3 * 1024 * 1024, // 3 MB
        // Runtime caching for API calls
        runtimeCaching: [
          {
            // Cache product images from storage
            urlPattern: /\/storage\/.*/i,
            handler: 'CacheFirst',
            options: {
              cacheName: 'storage-images',
              expiration: {
                maxEntries: 500,
                maxAgeSeconds: 60 * 60 * 24 * 7, // 7 days
              },
              cacheableResponse: {
                statuses: [0, 200],
              },
            },
          },
          {
            // Cache API GET requests (products, categories) with network-first
            urlPattern: /\/api\/(?:products|categories|pos).*$/i,
            handler: 'NetworkFirst',
            options: {
              cacheName: 'api-cache',
              expiration: {
                maxEntries: 100,
                maxAgeSeconds: 60 * 60, // 1 hour
              },
              cacheableResponse: {
                statuses: [0, 200],
              },
              networkTimeoutSeconds: 5,
            },
          },
        ],
        // Fallback to index.html for SPA navigation (offline support)
        navigateFallback: 'index.html',
        navigateFallbackDenylist: [/^\/api/, /^\/storage/, /^\/w\//, /^\/store/],
      },
      devOptions: {
        enabled: false,
        type: 'module',
        navigateFallback: 'index.html',
      },
    }),
  ],
  base: process.env.NODE_ENV === 'production' ? '/noovaerp/' : '/',
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: backendTarget,
        changeOrigin: true,
        configure: (proxy, _options) => {
          proxy.on('proxyReq', (proxyReq, req, _res) => {
            // Set the Host header to the tenant domain for multi-tenancy
            proxyReq.setHeader('Host', 'demo.localhost');
          });
        },
      },
      '/storage': {
        target: backendTarget,
        changeOrigin: true,
      },
      // Proxy published website routes to backend (serves complete HTML)
      '/w': {
        target: backendTarget,
        changeOrigin: true,
        configure: (proxy, _options) => {
          proxy.on('proxyReq', (proxyReq, req, _res) => {
            proxyReq.setHeader('Host', 'demo.localhost');
          });
        },
      },
    },
  },
})
