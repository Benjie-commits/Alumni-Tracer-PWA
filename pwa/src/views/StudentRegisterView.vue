<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, deviceName } from '../api.js'
import { homeRoute, startSession } from '../session.js'
import { useForm } from '../useForm.js'
import Field from '../components/Field.vue'
import PasswordInput from '../components/PasswordInput.vue'

// Continuing students: a short form (one screen, only what is needed) for events, internships and mentoring.
const router = useRouter()
const schools = ref([])
const form = reactive({
  student_number: '',
  first_name: '',
  last_name: '',
  programme_id: '',
  year_of_study: '',
  email: '',
  phone: '',
  password: '',
})
const { busy, message, errors, submit } = useForm()

onMounted(async () => {
  try {
    schools.value = (await api.get('/reference/programmes', { auth: false })).data
  } catch {
    /* shown as an empty list; the error message below explains if submitting fails */
  }
})

async function register() {
  const ok = await submit(async () => {
    startSession(await api.post('/auth/register-student', {
      ...form,
      programme_id: form.programme_id === '' ? null : Number(form.programme_id),
      year_of_study: form.year_of_study === '' ? null : Number(form.year_of_study),
      phone: form.phone || null,
      device_name: deviceName(),
    }, { auth: false }))
  })
  if (ok) router.replace(homeRoute())
}
</script>

<template>
  <p class="small"><RouterLink :to="{ name: 'register' }">← Back</RouterLink></p>
  <h1>Student account</h1>
  <p class="muted">For continuing students at Soroti University: see events and internships, and ask for an alumni mentor.</p>

  <form class="card" novalidate @submit.prevent="register">
    <p v-if="message" class="alert bad" role="alert">{{ message }}</p>

    <Field label="Student number" :error="errors.student_number" hint="10 digits, e.g. 2401600227: your cohort (year you joined), then 01600, then your own 3 digits.">
      <input v-model.trim="form.student_number" inputmode="numeric" autocomplete="off" maxlength="14" placeholder="2401600227" required />
    </Field>
    <div class="row">
      <Field label="First name" :error="errors.first_name">
        <input v-model.trim="form.first_name" autocomplete="given-name" required />
      </Field>
      <Field label="Surname" :error="errors.last_name">
        <input v-model.trim="form.last_name" autocomplete="family-name" required />
      </Field>
    </div>
    <Field label="Programme" :error="errors.programme_id">
      <select v-model="form.programme_id" required>
        <option value="">Choose your programme…</option>
        <template v-for="school in schools" :key="school.id">
          <optgroup v-for="department in school.departments" :key="department.id" :label="`${school.name} › ${department.name}`">
            <option v-for="programme in department.programmes" :key="programme.id" :value="programme.id">{{ programme.name }}</option>
          </optgroup>
        </template>
      </select>
    </Field>
    <Field label="Year of study" :error="errors.year_of_study">
      <select v-model="form.year_of_study" required>
        <option value="">Choose…</option>
        <option v-for="y in 5" :key="y" :value="y">Year {{ y }}</option>
      </select>
    </Field>
    <Field label="Email" :error="errors.email" hint="You'll sign in with this.">
      <input v-model.trim="form.email" type="email" inputmode="email" autocomplete="email" autocapitalize="none" required />
    </Field>
    <Field label="Phone (optional)" :error="errors.phone">
      <input v-model.trim="form.phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="+256700000000" />
    </Field>
    <Field label="Choose a password" :error="errors.password" hint="At least 8 characters.">
      <PasswordInput v-model="form.password" autocomplete="new-password" minlength="8" required />
    </Field>

    <button class="block" type="submit" :disabled="busy">{{ busy ? 'Creating your account…' : 'Create student account' }}</button>
  </form>

  <p class="center">Already have an account? <RouterLink :to="{ name: 'login' }">Sign in</RouterLink></p>
</template>
