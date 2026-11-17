<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api.js'

/**
 * FR-5 mentorship: offer to mentor current students, and see the students the university has matched
 * with you. A student's contact details appear only if they agreed to share them.
 */
const state = ref(null)
const optedIn = ref(false)
const topics = ref('')
const busy = ref(false)
const message = ref('')
const saved = ref(false)

onMounted(async () => {
  try {
    state.value = (await api.get('/me/mentoring')).data
    optedIn.value = state.value.opted_in
    topics.value = state.value.topics || ''
  } catch {
    /* secondary card: if it cannot load, it stays hidden */
  }
})

async function save() {
  busy.value = true
  message.value = ''
  saved.value = false
  try {
    state.value = (await api.put('/me/mentoring', { opted_in: optedIn.value, topics: topics.value || null })).data
    saved.value = true
  } catch (error) {
    optedIn.value = state.value.opted_in
    message.value = error.errors?.opted_in?.[0] || (error.offline ? "You're offline. Try again when you're connected." : error.message)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section v-if="state" class="card">
    <h2>Mentor a current student</h2>
    <p class="hint small" style="margin-top:0">
      Share your experience with a Soroti University student in your field. The university makes each introduction;
      you can stop offering at any time.
    </p>

    <p v-if="!state.can_opt_in" class="alert warn">Mentoring opens once the Registrar has verified your record.</p>
    <template v-else>
      <p v-if="message" class="alert bad" role="alert">{{ message }}</p>
      <label class="check">
        <input v-model="optedIn" type="checkbox" />
        <span>I'm willing to mentor a current student</span>
      </label>
      <label v-if="optedIn" class="field">
        <span class="label">What could you help with? (optional)</span>
        <input v-model.trim="topics" maxlength="255" placeholder="e.g. careers in agribusiness, applying for a master's" />
      </label>
      <button type="button" class="block" :disabled="busy" @click="save">{{ busy ? 'Saving…' : 'Save' }}</button>
      <p v-if="saved" class="hint small" role="status">Saved.</p>
    </template>

    <template v-if="state.mentees.length">
      <h2 style="margin-top:16px">Your mentees</h2>
      <div v-for="m in state.mentees" :key="m.id" class="job">
        <strong>{{ m.name }}</strong>
        <span class="hint small">
          {{ m.programme || 'Programme not recorded' }}<template v-if="m.year_of_study"> · year {{ m.year_of_study }}</template>
          <template v-if="m.focus"> · {{ m.focus }}</template>
        </span>
        <span v-if="m.email || m.phone" class="small">
          <a v-if="m.email" :href="`mailto:${m.email}`">{{ m.email }}</a><template v-if="m.email && m.phone"> · </template>
          <a v-if="m.phone" :href="`tel:${m.phone}`">{{ m.phone }}</a>
        </span>
        <span v-else class="hint small">The university will introduce you.</span>
      </div>
    </template>
  </section>
</template>
