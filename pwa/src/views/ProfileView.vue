<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '../api.js'
import { useForm } from '../useForm.js'
import CredentialLinks from '../components/CredentialLinks.vue'
import Field from '../components/Field.vue'

const profile = ref(null)
const options = ref({ employment_status: [], further_study_status: [] })
const loadError = ref('')
const saved = ref(false)
const { busy, message, errors, submit } = useForm()

const EDITABLE = [
  'email', 'phone', 'whatsapp_number', 'country', 'city', 'linkedin_url',
  'employment_status', 'further_study_status', 'further_study_institution', 'further_study_programme',
]
const form = reactive(Object.fromEntries(EDITABLE.map((key) => [key, ''])))

const status = computed(() => profile.value?.verification_status?.value)
const lastUpdated = computed(() => {
  const iso = profile.value?.profile_updated_at
  return iso ? new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' }) : null
})

function fill(data) {
  profile.value = data
  for (const key of EDITABLE) form[key] = data[key] ?? ''
}

const surveys = ref([])
const prefs = reactive({ sms: true, whatsapp: true })
const prefsSaved = ref(false)
const prefsError = ref('')

onMounted(async () => {
  try {
    const [{ data }, refs] = await Promise.all([api.get('/me/profile'), api.get('/reference/options', { auth: false })])
    fill(data)
    options.value = refs.data
  } catch (error) {
    loadError.value = error.offline
      ? "You're offline, so your profile can't be loaded. Reconnect and refresh."
      : error.message
    return
  }

  // Secondary cards: a failure here must not hide the profile itself.
  const [waiting, preferences] = await Promise.allSettled([api.get('/me/surveys'), api.get('/me/notification-preferences')])
  if (waiting.status === 'fulfilled') surveys.value = waiting.value.data
  if (preferences.status === 'fulfilled') Object.assign(prefs, preferences.value.data)
})

async function savePreference(channel) {
  prefsSaved.value = false
  prefsError.value = ''
  try {
    Object.assign(prefs, (await api.put('/me/notification-preferences', { [channel]: prefs[channel] })).data)
    prefsSaved.value = true
  } catch (error) {
    prefs[channel] = !prefs[channel] // put the switch back: it did not save
    prefsError.value = error.offline ? "You're offline, so that didn't save. Try again when you're connected." : error.message
  }
}

async function save() {
  saved.value = false
  const ok = await submit(async () => {
    const payload = Object.fromEntries(EDITABLE.map((key) => [key, form[key] === '' ? null : form[key]]))
    fill((await api.put('/me/profile', payload)).data)
  })
  saved.value = ok
}
</script>

<template>
  <p v-if="loadError" class="alert bad" role="alert">{{ loadError }}</p>
  <p v-else-if="!profile" class="muted">Loading your profile…</p>

  <template v-else>
    <h1>{{ profile.full_name }}</h1>

    <p v-if="status === 'pending'" class="alert warn">
      <strong>Waiting for the Registrar.</strong> We couldn't match you to a graduate record automatically, so a member
      of staff will check your registration. You can still fill in your details below.
    </p>
    <p v-else-if="status === 'rejected'" class="alert bad">
      <strong>We couldn't verify your registration.</strong> Please contact the Registrar's office with your student number.
    </p>
    <p v-else class="alert ok">Verified graduate of Soroti University.</p>

    <section v-if="surveys.length" class="card survey-card">
      <h2>{{ surveys.length === 1 ? 'A survey is waiting for you' : 'Surveys are waiting for you' }}</h2>
      <p v-for="survey in surveys" :key="survey.token" class="survey-row">
        <span>{{ survey.title }}<span class="hint small"> · open until {{ new Date(survey.expires_at).toLocaleDateString(undefined, { day: 'numeric', month: 'long' }) }}</span></span>
        <RouterLink class="btn" :to="{ name: 'survey', params: { token: survey.token } }">Answer (2 min)</RouterLink>
      </p>
    </section>

    <section class="card">
      <h2>Your academic record</h2>
      <dl class="facts">
        <dt>Student number</dt><dd>{{ profile.student_number || 'Pending verification' }}</dd>
        <template v-if="profile.programme">
          <dt>Programme</dt><dd>{{ profile.programme.name }}</dd>
          <dt>School</dt><dd>{{ profile.programme.school }}</dd>
        </template>
        <dt>Graduated</dt><dd>{{ profile.graduation_year || '—' }}</dd>
        <template v-if="profile.class_of_award"><dt>Award</dt><dd>{{ profile.class_of_award }}</dd></template>
      </dl>
      <p class="hint small">Something wrong here? Only the Registrar's office can change academic records.</p>
    </section>

    <CredentialLinks v-if="status === 'verified'" />

    <form class="card" novalidate @submit.prevent="save">
      <h2>Keep your details up to date</h2>
      <p class="hint small" v-if="lastUpdated">You last confirmed these on {{ lastUpdated }}.</p>

      <p v-if="message" class="alert bad" role="alert">{{ message }}</p>
      <p v-if="saved" class="alert ok" role="status">Saved. Thank you!</p>

      <Field label="Email" :error="errors.email">
        <input v-model.trim="form.email" type="email" inputmode="email" autocomplete="email" />
      </Field>
      <Field label="Phone" :error="errors.phone">
        <input v-model.trim="form.phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="+256700000000" />
      </Field>
      <Field label="WhatsApp number" :error="errors.whatsapp_number" hint="If different from your phone.">
        <input v-model.trim="form.whatsapp_number" type="tel" inputmode="tel" />
      </Field>
      <div class="row">
        <Field label="Town / city" :error="errors.city"><input v-model.trim="form.city" autocomplete="address-level2" /></Field>
        <Field label="Country" :error="errors.country"><input v-model.trim="form.country" autocomplete="country-name" /></Field>
      </div>

      <Field
        label="LinkedIn profile (optional)"
        :error="errors.linkedin_url"
        hint="Only university staff can see this. If we can't reach you, they may look at your profile by hand to check your details. We never connect to your LinkedIn account."
      >
        <input v-model.trim="form.linkedin_url" type="url" inputmode="url" autocomplete="url" placeholder="linkedin.com/in/your-name" />
      </Field>

      <Field label="What are you doing now?" :error="errors.employment_status">
        <select v-model="form.employment_status">
          <option value="">Prefer not to say</option>
          <option v-for="o in options.employment_status" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
      </Field>
      <Field label="Further study" :error="errors.further_study_status">
        <select v-model="form.further_study_status">
          <option value="">Prefer not to say</option>
          <option v-for="o in options.further_study_status" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
      </Field>
      <template v-if="form.further_study_status === 'studying' || form.further_study_status === 'planned'">
        <Field label="Institution" :error="errors.further_study_institution"><input v-model.trim="form.further_study_institution" /></Field>
        <Field label="Course" :error="errors.further_study_programme"><input v-model.trim="form.further_study_programme" /></Field>
      </template>

      <button class="block" type="submit" :disabled="busy">{{ busy ? 'Saving…' : 'Save changes' }}</button>
    </form>

    <section class="card">
      <h2>Messages from the university</h2>
      <p class="hint small" style="margin-top:0">We send survey links and the occasional reminder. Switch off whichever you don't want.</p>
      <p v-if="prefsError" class="alert bad" role="alert">{{ prefsError }}</p>
      <label class="check">
        <input v-model="prefs.whatsapp" type="checkbox" @change="savePreference('whatsapp')" />
        <span>WhatsApp messages</span>
      </label>
      <label class="check">
        <input v-model="prefs.sms" type="checkbox" @change="savePreference('sms')" />
        <span>SMS messages</span>
      </label>
      <p v-if="prefsSaved" class="hint small" role="status" style="margin-bottom:0">Saved.</p>
    </section>

    <p class="center"><RouterLink :to="{ name: 'work' }">Add or update your work history →</RouterLink></p>
  </template>
</template>
