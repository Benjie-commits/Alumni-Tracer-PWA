<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, deviceName } from '../api.js'
import { startSession } from '../session.js'
import { useForm } from '../useForm.js'
import Field from '../components/Field.vue'

const router = useRouter()
const schools = ref([])
const form = reactive({
  student_number: '',
  first_name: '',
  last_name: '',
  graduation_year: '',
  programme_id: '',
  email: '',
  phone: '',
  password: '',
  consent: false,
})
const { busy, message, errors, submit } = useForm()

onMounted(async () => {
  try {
    schools.value = (await api.get('/reference/programmes', { auth: false })).data
  } catch {
    // The programme list is a convenience; registration works without it.
  }
})

async function register() {
  const ok = await submit(async () => {
    const payload = {
      ...form,
      graduation_year: form.graduation_year === '' ? null : Number(form.graduation_year),
      programme_id: form.programme_id === '' ? null : Number(form.programme_id),
      phone: form.phone || null,
      device_name: deviceName(),
    }
    startSession(await api.post('/auth/register', payload, { auth: false }))
  })
  if (ok) router.replace({ name: 'profile' })
}
</script>

<template>
  <h1>Create your alumni account</h1>
  <p class="muted">
    Enter your details as they appear on your academic records. If we find you we'll verify you straight away;
    if not, the Registrar's office will review your registration.
  </p>

  <form class="card" novalidate @submit.prevent="register">
    <p v-if="message" class="alert bad" role="alert">{{ message }}</p>

    <Field label="Student number" :error="errors.student_number" hint="e.g. SU/2021/014, as on your transcript or ID">
      <input v-model.trim="form.student_number" autocomplete="off" autocapitalize="characters" required />
    </Field>
    <div class="row">
      <Field label="First name" :error="errors.first_name">
        <input v-model.trim="form.first_name" autocomplete="given-name" required />
      </Field>
      <Field label="Surname" :error="errors.last_name">
        <input v-model.trim="form.last_name" autocomplete="family-name" required />
      </Field>
    </div>
    <Field label="Year you graduated" :error="errors.graduation_year">
      <input v-model="form.graduation_year" type="number" inputmode="numeric" min="1950" :max="new Date().getFullYear() + 1" required />
    </Field>
    <Field label="Programme (optional)" :error="errors.programme_id" hint="Helps the Registrar if we can't find you automatically.">
      <select v-model="form.programme_id">
        <option value="">Choose your programme…</option>
        <template v-for="school in schools" :key="school.id">
          <optgroup v-for="department in school.departments" :key="department.id" :label="`${school.name} › ${department.name}`">
            <option v-for="programme in department.programmes" :key="programme.id" :value="programme.id">{{ programme.name }}</option>
          </optgroup>
        </template>
      </select>
    </Field>

    <Field label="Email" :error="errors.email" hint="You'll sign in with this.">
      <input v-model.trim="form.email" type="email" inputmode="email" autocomplete="email" autocapitalize="none" required />
    </Field>
    <Field label="Phone (optional)" :error="errors.phone" hint="For survey reminders by SMS or WhatsApp.">
      <input v-model.trim="form.phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="+256700000000" />
    </Field>
    <Field label="Choose a password" :error="errors.password" hint="At least 8 characters.">
      <input v-model="form.password" type="password" autocomplete="new-password" minlength="8" required />
    </Field>

    <label class="check">
      <input v-model="form.consent" type="checkbox" />
      <span class="small">
        I agree that Soroti University may keep and use my details to contact me, study graduate outcomes and report to
        the National Council for Higher Education. I can ask the Registrar to correct or remove my record.
      </span>
    </label>
    <p v-if="errors.consent" class="error small" role="alert">{{ errors.consent }}</p>

    <button class="block" type="submit" :disabled="busy">{{ busy ? 'Creating account…' : 'Create account' }}</button>
  </form>

  <p class="center">Already registered? <RouterLink :to="{ name: 'login' }">Sign in</RouterLink></p>
</template>
