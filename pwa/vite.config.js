import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'

// Alumni PWA (spec section 8: Vue 3 + Vite, Workbox service worker).
export default defineConfig({
  plugins: [
    vue(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['favicon.svg', 'icons/apple-touch-icon.png'],
      manifest: {
        name: 'SUN-ATES Alumni',
        short_name: 'SUN Alumni',
        description: 'Soroti University alumni: keep your record up to date, take tracer surveys and stay connected.',
        lang: 'en',
        theme_color: '#12355b',
        background_color: '#12355b',
        display: 'standalone',
        orientation: 'portrait',
        start_url: '/',
        scope: '/',
        icons: [
          { src: 'icons/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: 'icons/icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: 'icons/maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: {
        // The app shell works offline. Personal API responses are deliberately NOT cached: alumni often
        // use shared or borrowed phones, so nothing personal may outlive the signed-in session.
        navigateFallback: '/index.html',
        navigateFallbackDenylist: [/^\/api\//, /^\/admin/, /^\/livewire/, /^\/u\//],
        globPatterns: ['**/*.{js,css,html,png,svg}'],
        runtimeCaching: [
          {
            // Public lookups (schools, programmes, dropdown options) are safe to keep for slow connections.
            urlPattern: ({ url }) => url.pathname.startsWith('/api/v1/reference/'),
            handler: 'StaleWhileRevalidate',
            options: { cacheName: 'reference-data', expiration: { maxEntries: 10, maxAgeSeconds: 7 * 24 * 3600 } },
          },
        ],
      },
    }),
  ],
  server: {
    // In development the API is proxied, so there is no cross-origin traffic at all. "/u/" is the
    // "stop messaging me" page from every SMS: a server-rendered page, not part of this app.
    proxy: { '/api': 'http://127.0.0.1:8000', '/u': 'http://127.0.0.1:8000' },
  },
  build: {
    target: 'es2020',
  },
})
