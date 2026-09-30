<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '../api.js'
import { useForm } from '../useForm.js'
import Field from '../components/Field.vue'

const profile = ref(null)
const options = ref({ employment_status: [], further_study_status: [] })
const loadError = ref('')
const saved = ref(false)
const { busy, message, errors, submit } = useForm()

const EDITABLE = [
  'email', 'phone', 'whatsapp_number', 'country', 'city',
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

onMounted(async () => {
  try {
    const [{ data }, refs] = await Promise.all([api.get('/me/profile'), api.get('/reference/options', { auth: false })])
    fill(data)
    options.value = refs.data
  } catch (error) {
    loadError.value = error.offline
      ? "You're offline, so your profile can't be loaded. Reconnect and refresh."
      : error.message
  }
})

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

    <p class="center"><RouterLink :to="{ name: 'work' }">Add or update your work history →</RouterLink></p>
  </template>
</template>
