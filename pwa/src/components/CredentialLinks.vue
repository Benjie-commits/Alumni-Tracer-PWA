<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api.js'
import { shareOrCopy } from '../share.js'

/**
 * "Request a verified credential link" (spec section 2.3): a private link the alumnus gives an
 * employer, who then sees their degree confirmed without having to search for them.
 */
const links = ref([])
const meta = ref({ can_create: false, valid_days: 90, max_active: 5 })
const loaded = ref(false)
const busy = ref(false)
const message = ref('')
const feedback = ref({})

const expiry = (iso) => new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' })

async function load() {
  try {
    const result = await api.get('/me/credential-links')
    links.value = result.data
    meta.value = result.meta
  } catch {
    /* secondary card: if it cannot load, it just stays hidden */
  } finally {
    loaded.value = true
  }
}
onMounted(load)

async function create() {
  busy.value = true
  message.value = ''
  try {
    const { data } = await api.post('/me/credential-links', {})
    links.value = [data, ...links.value]
  } catch (error) {
    message.value = error.errors?.profile?.[0] || (error.offline ? "You're offline. Try again when you're connected." : error.message)
  } finally {
    busy.value = false
  }
}

async function share(link) {
  const outcome = await shareOrCopy(link.url, 'My Soroti University degree, verified')
  feedback.value = { ...feedback.value, [link.id]: { shared: 'Shared.', copied: 'Link copied.', failed: "Couldn't copy. Press and hold the link to copy it." }[outcome] || '' }
}

async function remove(link) {
  if (!window.confirm('Stop this link working? Anyone who has it will no longer be able to verify you with it.')) return
  try {
    await api.delete(`/me/credential-links/${link.id}`)
    links.value = links.value.filter((l) => l.id !== link.id)
  } catch (error) {
    message.value = error.offline ? "You're offline. Try again when you're connected." : error.message
  }
}
</script>

<template>
  <section v-if="loaded" class="card">
    <h2>Prove your degree to an employer</h2>
    <p class="hint small" style="margin-top:0">
      Give an employer a private link. They see your name, programme and graduation year confirmed by the Registrar, and
      nothing else. It works for {{ meta.valid_days }} days and you can stop it any time.
    </p>

    <p v-if="!meta.can_create" class="alert warn">
      A link isn't available until your graduation is confirmed against the Registrar's records.
    </p>
    <p v-if="message" class="alert bad" role="alert">{{ message }}</p>

    <div v-for="link in links" :key="link.id" class="job">
      <span class="small" style="overflow-wrap:anywhere">{{ link.url }}</span>
      <span class="hint small">
        Works until {{ expiry(link.expires_at) }} · opened {{ link.views }} {{ link.views === 1 ? 'time' : 'times' }}
      </span>
      <span class="actions">
        <button type="button" class="link" @click="share(link)">Share or copy</button>
        <button type="button" class="link" @click="remove(link)">Stop this link</button>
        <span v-if="feedback[link.id]" class="hint small" role="status">{{ feedback[link.id] }}</span>
      </span>
    </div>

    <button v-if="meta.can_create && links.length < meta.max_active" class="block" type="button" :disabled="busy" style="margin-top:12px" @click="create">
      {{ busy ? 'Creating…' : links.length ? '+ Create another link' : 'Create a link for an employer' }}
    </button>
  </section>
</template>
