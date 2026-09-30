import { createApp } from 'vue'
import { registerSW } from 'virtual:pwa-register'
import App from './App.vue'
import { router } from './router.js'
import { setUnauthorizedHandler } from './api.js'
import './style.css'

// An expired or revoked token sends the user back to sign in rather than showing broken screens.
setUnauthorizedHandler(() => {
  if (router.currentRoute.value.name !== 'login') router.replace({ name: 'login' })
})

createApp(App).use(router).mount('#app')

// With registerType "autoUpdate" the new service worker takes over on the next load.
registerSW({ immediate: true })
