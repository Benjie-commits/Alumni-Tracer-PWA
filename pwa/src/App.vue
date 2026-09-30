<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from './api.js'
import { endSession, isSignedIn } from './session.js'

const router = useRouter()
const online = ref(navigator.onLine)
const setOnline = () => (online.value = navigator.onLine)

onMounted(() => {
  window.addEventListener('online', setOnline)
  window.addEventListener('offline', setOnline)
})
onBeforeUnmount(() => {
  window.removeEventListener('online', setOnline)
  window.removeEventListener('offline', setOnline)
})

async function signOut() {
  try {
    await api.post('/auth/logout')
  } catch {
    /* offline or already expired: the local sign-out below is what matters */
  }
  endSession()
  router.replace({ name: 'login' })
}
</script>

<template>
  <header class="topbar">
    <span class="brand">SUN Alumni</span>
    <button v-if="isSignedIn" type="button" class="link" @click="signOut">Sign out</button>
  </header>

  <p v-if="!online" class="offline" role="status">
    You're offline. You can look around, but changes can't be saved until you reconnect.
  </p>

  <main class="page">
    <RouterView />
  </main>

  <nav v-if="isSignedIn" class="tabs" aria-label="Main">
    <RouterLink :to="{ name: 'profile' }">My profile</RouterLink>
    <RouterLink :to="{ name: 'work' }">Work</RouterLink>
  </nav>
</template>
