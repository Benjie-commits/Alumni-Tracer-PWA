<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api.js'
import { applyLink } from '../applyLink.js'
import { isStudent } from '../session.js'

// FR-5: jobs, internships and events the university has aimed at graduates like you.
const tab = ref('jobs')
const jobs = ref([])
const events = ref([])
const loaded = ref(false)
const error = ref('')

const day = (iso) => new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' })
const when = (iso) => new Date(iso).toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })
const safeUrl = (value) => {
  try {
    const url = new URL(value)
    return url.protocol === 'https:' || url.protocol === 'http:' ? url.href : null
  } catch {
    return null
  }
}

const rsvpError = ref('')
const answers = [
  { value: 'going', label: "I'm going" },
  { value: 'maybe', label: 'Maybe' },
  { value: 'no', label: "Can't make it" },
]

// Pressing the answer you already gave takes it back.
async function answer(event, value) {
  rsvpError.value = ''
  try {
    if (event.my_rsvp === value) {
      await api.delete(`/me/events/${event.id}/rsvp`)
      if (value === 'going') event.going_count -= 1
      event.my_rsvp = null
      return
    }
    const wasGoing = event.my_rsvp === 'going'
    const result = await api.put(`/me/events/${event.id}/rsvp`, { response: value })
    event.my_rsvp = result.data.my_rsvp
    event.going_count = result.data.going_count ?? event.going_count + (value === 'going' ? 1 : 0) - (wasGoing ? 1 : 0)
  } catch (e) {
    rsvpError.value = e?.offline ? "You're offline. Reconnect to answer." : (e?.message || 'Your answer could not be saved.')
  }
}

onMounted(async () => {
  const [j, e] = await Promise.allSettled([api.get('/me/jobs'), api.get('/me/events')])
  if (j.status === 'fulfilled') jobs.value = j.value.data
  if (e.status === 'fulfilled') events.value = e.value.data
  if (j.status === 'rejected' && e.status === 'rejected') {
    error.value = j.reason?.offline ? "You're offline, so opportunities can't be loaded. Reconnect and refresh." : j.reason?.message
  }
  loaded.value = true
})
</script>

<template>
  <h1>Opportunities</h1>
  <p class="muted">Jobs, internships and events shared by Soroti University for {{ isStudent ? 'students in your programme' : 'graduates like you' }}.</p>

  <div class="actions" role="tablist">
    <button type="button" :class="{ secondary: tab !== 'jobs' }" role="tab" :aria-selected="tab === 'jobs'" @click="tab = 'jobs'">
      Jobs ({{ jobs.length }})
    </button>
    <button type="button" :class="{ secondary: tab !== 'events' }" role="tab" :aria-selected="tab === 'events'" @click="tab = 'events'">
      Events ({{ events.length }})
    </button>
  </div>

  <p v-if="error" class="alert bad" role="alert">{{ error }}</p>
  <p v-else-if="!loaded" class="muted">Loading…</p>

  <template v-else-if="tab === 'jobs'">
    <p v-if="!jobs.length" class="card muted">Nothing for you right now. Keep your programme and details up to date so we can show you what fits.</p>
    <article v-for="job in jobs" :key="job.id" class="card">
      <h2 style="margin-bottom:4px">{{ job.title }}</h2>
      <p class="hint small" style="margin-top:0">
        {{ job.type.label }} · {{ job.organisation }}<template v-if="job.location"> · {{ job.location }}</template>
        <template v-if="job.closes_on"> · apply by {{ day(job.closes_on) }}</template>
      </p>
      <p style="white-space:pre-line">{{ job.description }}</p>
      <p v-if="applyLink(job.how_to_apply)">
        <a class="btn" :href="applyLink(job.how_to_apply).href" target="_blank" rel="noopener noreferrer">{{ applyLink(job.how_to_apply).label }}</a>
      </p>
      <p v-else class="small"><strong>How to apply:</strong> {{ job.how_to_apply }}</p>
    </article>
  </template>

  <template v-else>
    <p v-if="!events.length" class="card muted">No upcoming events for you right now.</p>
    <p v-if="rsvpError" class="alert bad" role="alert">{{ rsvpError }}</p>
    <article v-for="event in events" :key="event.id" class="card">
      <h2 style="margin-bottom:4px">{{ event.title }}</h2>
      <p class="hint small" style="margin-top:0">
        {{ when(event.starts_at) }}<template v-if="event.venue"> · {{ event.venue }}</template>
      </p>
      <p style="white-space:pre-line">{{ event.description }}</p>
      <p v-if="safeUrl(event.online_url)">
        <a :href="safeUrl(event.online_url)" target="_blank" rel="noopener noreferrer">Join online</a>
      </p>
      <div class="actions" role="group" :aria-label="`Will you come to ${event.title}?`">
        <button
          v-for="a in answers" :key="a.value" type="button"
          :class="{ secondary: event.my_rsvp !== a.value }" :aria-pressed="event.my_rsvp === a.value"
          @click="answer(event, a.value)"
        >{{ a.label }}</button>
      </div>
      <p v-if="event.going_count" class="hint small">{{ event.going_count }} {{ event.going_count === 1 ? 'person is' : 'people are' }} going.</p>
    </article>
  </template>
</template>
