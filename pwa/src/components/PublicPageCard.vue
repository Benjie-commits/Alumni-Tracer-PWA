<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api.js'

/**
 * Your public page: off until you switch it on. You choose which optional parts show, and can take the
 * page down at any time. Name, programme and graduation year are the Registrar-verified baseline.
 */
const SECTIONS = [
  ['work', 'My work history'],
  ['location', 'Where I am based (city and country)'],
  ['mentor', 'That I can mentor students, and on what'],
  ['linkedin', 'My LinkedIn link'],
]

const state = ref(null)
const published = ref(false)
const headline = ref('')
const sections = ref([])
const busy = ref(false)
const message = ref('')
const saved = ref(false)
const copied = ref(false)

function apply(data) {
  state.value = data
  published.value = data.published
  headline.value = data.headline || ''
  sections.value = [...data.sections]
}

onMounted(async () => {
  try {
    apply((await api.get('/me/public-page')).data)
  } catch {
    /* secondary card: if it cannot load, it stays hidden */
  }
})

async function save() {
  busy.value = true
  message.value = ''
  saved.value = false
  try {
    apply((await api.put('/me/public-page', { published: published.value, headline: headline.value || null, sections: sections.value })).data)
    saved.value = true
  } catch (error) {
    published.value = state.value.published
    message.value = error.offline ? "You're offline. Try again when you're connected." : error.message
  } finally {
    busy.value = false
  }
}

async function copyLink() {
  try {
    await navigator.clipboard.writeText(state.value.url)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    /* the link is shown on screen; copying by hand still works */
  }
}
</script>

<template>
  <section v-if="state" class="card">
    <h2>Your public page</h2>
    <p class="hint small" style="margin-top:0">
      A page about you on the university's website, for employers, students and fellow graduates. Nothing is shown
      unless you switch it on, and you decide each part.
    </p>

    <p v-if="!state.can_publish" class="alert warn">Your page opens once the Registrar has verified your record.</p>
    <template v-else>
      <p v-if="message" class="alert bad" role="alert">{{ message }}</p>
      <label class="check">
        <input v-model="published" type="checkbox" />
        <span>Show my page publicly</span>
      </label>

      <template v-if="published">
        <p class="hint small" style="margin:0 0 10px">Always shown: your name, programme, graduation year and that the Registrar confirmed it.</p>
        <label class="field">
          <span class="label">One line about you (optional)</span>
          <input v-model.trim="headline" maxlength="160" placeholder="e.g. Clinical laboratory scientist turning data into diagnoses." />
        </label>
        <fieldset class="question" style="margin-bottom:14px">
          <legend class="q-label">Also show</legend>
          <label v-for="[key, label] in SECTIONS" :key="key" class="check">
            <input v-model="sections" type="checkbox" :value="key" />
            <span>{{ label }}</span>
          </label>
        </fieldset>
      </template>

      <button type="button" class="block" :disabled="busy" @click="save">{{ busy ? 'Saving…' : 'Save' }}</button>
      <p v-if="saved" class="hint small" role="status">Saved.</p>

      <div v-if="state.published && state.url" class="actions" style="margin-top:12px">
        <a class="button" :href="state.url" target="_blank" rel="noopener">View my page</a>
        <button type="button" class="secondary" @click="copyLink">{{ copied ? 'Copied' : 'Copy link' }}</button>
      </div>
    </template>
  </section>
</template>
