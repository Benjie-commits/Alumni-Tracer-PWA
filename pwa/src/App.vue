<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from './api.js'
import { endSession, isSignedIn, isStudent } from './session.js'
import { currentTheme, nextTheme, setTheme } from './theme.js'
import { discardQueuedSurveys, dismissSurveyProblems, pendingSurveyCount, surveyProblems, syncSurveys } from './survey/sync.js'

const router = useRouter()
const sendingNow = ref(false)

const theme = ref(currentTheme())
function toggleTheme() {
  theme.value = nextTheme(theme.value)
  setTheme(theme.value)
}

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
  // Survey answers waiting on this phone hold personal information, and phones are often shared.
  // Try to send them first; if that is impossible, let the person decide rather than silently
  // keeping (or silently losing) them.
  if (pendingSurveyCount.value > 0) {
    await syncSurveys().catch(() => {})
  }
  if (pendingSurveyCount.value > 0) {
    const n = pendingSurveyCount.value
    const discard = window.confirm(
      `${n === 1 ? 'A survey answer has' : `${n} survey answers have`} not been sent yet because you're offline.\n\n` +
        'OK: delete them from this phone (choose this if the phone is shared).\n' +
        'Cancel: keep them here and send them automatically when you are back online.',
    )
    if (discard) discardQueuedSurveys()
  }

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
    <a class="brand" href="/" title="Back to the landing page">SUN Alumni</a>
    <span>
      <button type="button" class="link" :aria-label="`Switch to ${nextTheme(theme)} mode`" @click="toggleTheme">
        {{ theme === 'dark' ? '☀ Light' : '☾ Dark' }}
      </button>
      <button v-if="isSignedIn" type="button" class="link" style="margin-left:14px" @click="signOut">Sign out</button>
    </span>
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
    <template v-if="isStudent">
      <RouterLink :to="{ name: 'student-home' }">Home</RouterLink>
      <RouterLink :to="{ name: 'opportunities' }">Internships &amp; events</RouterLink>
    </template>
    <template v-else>
      <RouterLink :to="{ name: 'profile' }">My profile</RouterLink>
      <RouterLink :to="{ name: 'work' }">Work</RouterLink>
      <RouterLink :to="{ name: 'opportunities' }">Jobs &amp; events</RouterLink>
    </template>
  </nav>
</template>
