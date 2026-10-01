import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { VitePWA } from 'vite-plugin-pwa'
import path from 'path'

const backendTarget = process.env.VITE_BACKEND_URL || 'http://127.0.0.1:8000'
const iotBackendTarget = process.env.VITE_IOT_BACKEND_URL || backendTarget
// Bump when a previously interrupted dependency optimization leaves browsers
// holding valid-looking `?v=` URLs that reference obsolete generated chunks.
const optimizerCacheVersion = '2026-09-22-1'

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
        // New service workers take over on the next load instead of waiting
        // for every tab to close — without this, a deployed change needed a
        // hard refresh before it appeared.
        skipWaiting: true,
        clientsClaim: true,
        // Cache all JS/CSS/SVG assets for offline shell — exclude index.html so
        // the browser always fetches the fresh version with current asset hashes
        globPatterns: ['**/*.{js,css,html,svg,ico,woff,woff2}'],
        globIgnores: ['**/theme-screenshots/**', '**/Web-Templates/**'],
        cleanupOutdatedCaches: true,
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
            // Business data is NEVER cached by the service worker. The old
            // NetworkFirst rule here could serve up-to-1-hour-old responses
            // (and timed out to cache after 5s), so a refresh showed stale
            // lists until a hard refresh. Always hit the network instead.
            urlPattern: /\/api\//i,
            handler: 'NetworkOnly',
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
  base: '/',
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  optimizeDeps: {
    // Commit discovered dependency bundles immediately. If the initial SPA
    // crawl fails on a stale browser import, keeping the bundle in deps_temp
    // causes every subsequent request to look for chunks that were never
    // promoted into the active deps directory.
    holdUntilCrawlEnd: false,
    // Including an explicit version in the optimizer configuration changes
    // Vite's browser hash. This prevents a long-lived cached dependency module
    // from referring to chunk names produced by an abandoned `deps_temp` run.
    esbuildOptions: {
      banner: { js: `/* noovaerp-optimizer-cache:${optimizerCacheVersion} */` },
    },
  },
  server: {
    port: 5173,
    proxy: {
      // Proxy IoT API calls.
      // Defaults to main backend unless VITE_IOT_BACKEND_URL is explicitly set.
      // Must be before the general /api proxy to take precedence.
      '/api/v1/iot': {
        target: iotBackendTarget,
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/api\/v1\/iot/, '/api/v1/iot'),
      },
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
      // Proxy published website routes to backend (serves complete HTML).
      // NOTE: must be '/w/' (trailing slash) so it only matches published-site
      // URLs (/w/{slug}/...) and does NOT greedily swallow /website-builder,
      // /warehouse, /widgets, etc., which are client-side SPA routes.
      '/w/': {
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
