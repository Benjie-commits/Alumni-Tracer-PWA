<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api.js'
import { session } from '../session.js'
import { useForm } from '../useForm.js'
import Field from '../components/Field.vue'

// A continuing student's home: who they are, and asking for an alumni mentor (staff make the match).
const me = ref(null)
const state = ref(null)
const note = ref('')
const loadError = ref('')
const saved = ref('')
const { busy, message, errors, submit } = useForm()

onMounted(async () => {
  try {
    const [user, mentorship] = await Promise.all([api.get('/me'), api.get('/me/mentor-request')])
    me.value = user.data
    session.user = user.data
    state.value = mentorship.data
    note.value = mentorship.data.note || ''
  } catch (e) {
    loadError.value = e.offline ? "You're offline. Reconnect to see your details." : e.message
  }
})

async function save(wants) {
  saved.value = ''
  const ok = await submit(async () => {
    state.value = (await api.put('/me/mentor-request', { wants_mentor: wants, note: wants ? note.value || null : null })).data
  })
  if (ok) saved.value = wants ? 'Request sent. The university will match you with a mentor and let you know.' : 'Request withdrawn.'
}
</script>

<template>
  <h1>Hello{{ me?.student?.first_name ? `, ${me.student.first_name}` : '' }} 👋</h1>
  <p v-if="me?.student" class="muted">
    {{ me.student.programme || 'Programme not set' }} · Year {{ me.student.year_of_study }} · {{ me.student.student_number }}
  </p>
  <p v-if="loadError" class="alert bad" role="alert">{{ loadError }}</p>

  <section class="card">
    <h2>Find opportunities</h2>
    <p>Internships, jobs and university events chosen for your programme.</p>
    <RouterLink class="button block" :to="{ name: 'opportunities' }">See internships &amp; events →</RouterLink>
  </section>

  <section v-if="state" class="card" aria-live="polite">
    <h2>Mentorship</h2>

    <template v-if="state.mentor">
      <p class="alert ok">You have a mentor: <strong>{{ state.mentor.name }}</strong> (since {{ state.mentor.since }}).</p>
      <p v-if="state.mentor.focus">Focus: {{ state.mentor.focus }}</p>
      <p v-if="state.mentor.email">Contact: <a :href="`mailto:${state.mentor.email}`">{{ state.mentor.email }}</a></p>
    </template>

    <template v-else-if="state.requested">
      <p class="alert ok" role="status">
        ✓ You asked for a mentor on {{ new Date(state.requested_at).toLocaleDateString() }}. The university is finding a good match.
      </p>
      <p v-if="state.note" class="muted">Your note: “{{ state.note }}”</p>
      <button type="button" class="secondary" :disabled="busy" @click="save(false)">Withdraw my request</button>
    </template>

    <form v-else novalidate @submit.prevent="save(true)">
      <p>An alumnus working in your field can guide you on careers, internships and life after university.</p>
      <p v-if="message" class="alert bad" role="alert">{{ message }}</p>
      <Field label="What would you like help with? (optional)" :error="errors.note" hint="e.g. getting an internship in software, preparing for interviews">
        <textarea v-model="note" rows="3" maxlength="500"></textarea>
      </Field>
      <button class="block" type="submit" :disabled="busy">{{ busy ? 'Sending…' : 'Ask for a mentor' }}</button>
    </form>

    <p v-if="saved" class="small muted" role="status">{{ saved }}</p>
  </section>
</template>
