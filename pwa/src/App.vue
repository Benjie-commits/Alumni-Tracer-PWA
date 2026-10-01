<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from './api.js'
import { endSession, isSignedIn } from './session.js'
import { dismissSurveyProblems, pendingSurveyCount, surveyProblems, syncSurveys } from './survey/sync.js'

const router = useRouter()
const sendingNow = ref(false)

async function sendSurveysNow() {
  sendingNow.value = true
  try {
    await syncSurveys()
  } catch {
    /* still offline: the banner stays and the automatic retries carry on */
  } finally {
    sendingNow.value = false
  }
}
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

  <p v-if="pendingSurveyCount > 0" class="queued" role="status">
    {{ pendingSurveyCount === 1 ? 'A survey answer is' : `${pendingSurveyCount} survey answers are` }} saved on your phone and will be sent as soon as you're online.
    <button type="button" class="link" :disabled="sendingNow" @click="sendSurveysNow">{{ sendingNow ? 'Trying…' : 'Send now' }}</button>
  </p>

  <p v-if="surveyProblems.length" class="alert bad problems" role="alert">
    {{ surveyProblems.length === 1 ? 'One survey answer' : `${surveyProblems.length} survey answers` }} could not be accepted (the survey may have closed or was already completed).
    <button type="button" class="link" @click="dismissSurveyProblems">Dismiss</button>
  </p>

  <main class="page">
    <RouterView />
  </main>

  <nav v-if="isSignedIn" class="tabs" aria-label="Main">
    <RouterLink :to="{ name: 'profile' }">My profile</RouterLink>
    <RouterLink :to="{ name: 'work' }">Work</RouterLink>
  </nav>
</template>
