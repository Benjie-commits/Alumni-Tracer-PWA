<script setup>
import PasswordInput from "../components/PasswordInput.vue"
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

// Step two: when your details match a Registrar record, a code goes to the phone or email on that
// record (not to what you typed), and only that code claims the record.
const challenge = ref(null)
const code = ref('')
const resent = ref(false)
const channelLabel = { whatsapp: 'WhatsApp', sms: 'SMS', email: 'email' }

async function register() {
  let signedIn = false
  await submit(async () => {
    const payload = {
      ...form,
      graduation_year: form.graduation_year === '' ? null : Number(form.graduation_year),
      programme_id: form.programme_id === '' ? null : Number(form.programme_id),
      phone: form.phone || null,
      device_name: deviceName(),
    }
    const result = await api.post('/auth/register', payload, { auth: false })
    if (result?.data?.status === 'code_sent') {
      challenge.value = result.data
      return
    }
    startSession(result)
    signedIn = true
  })
  if (signedIn) router.replace({ name: 'profile' })
}

async function confirmCode() {
  const ok = await submit(async () => {
    startSession(await api.post('/auth/register/verify', {
      challenge: challenge.value.challenge,
      code: code.value,
      device_name: deviceName(),
    }, { auth: false }))
  })
  if (ok) router.replace({ name: 'profile' })
}

async function resendCode() {
  resent.value = false
  const ok = await submit(async () => {
    challenge.value = (await api.post('/auth/register/resend', { challenge: challenge.value.challenge }, { auth: false })).data
  })
  resent.value = ok
}
</script>

<template>
  <h1>Create your alumni account</h1>

  <template v-if="challenge">
    <form class="card" novalidate @submit.prevent="confirmCode">
      <h2>Enter your code</h2>
      <p>
        We found your graduate record and sent a 6-digit code by {{ channelLabel[challenge.channel] || challenge.channel }}
        to <strong>{{ challenge.sent_to }}</strong>, the contact the Registrar holds for you.
      </p>
      <p v-if="message" class="alert bad" role="alert">{{ message }}</p>
      <p v-if="resent" class="alert ok" role="status">A new code is on its way.</p>

      <Field label="Code" :error="errors.code || errors.email" hint="It expires in 15 minutes.">
        <input v-model.trim="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" required />
      </Field>
      <button class="block" type="submit" :disabled="busy">{{ busy ? 'Checking…' : 'Confirm and create account' }}</button>
      <p class="center small">
        No code? <button type="button" class="link" :disabled="busy" @click="resendCode">Send another</button>
      </p>
      <p class="hint small">
        Not your number or email any more? Contact the Registrar's office to update your record, then register again.
      </p>
    </form>
  </template>

  <template v-else>
  <p class="who-choice">
    <span class="chip on">🎓 I have graduated</span>
    <RouterLink class="chip" :to="{ name: 'register-student' }">📚 I'm a continuing student</RouterLink>
  </p>
  <p class="muted">
    Enter your details as they appear on your academic records. If we find you, we'll send a code to the phone or email
    on your record to confirm it's you; if not, the Registrar's office will review your registration.
  </p>

  <form class="card" novalidate @submit.prevent="register">
    <p v-if="message" class="alert bad" role="alert">{{ message }}</p>

    <Field label="Student number" :error="errors.student_number" hint="10 digits, e.g. 2101600227: your cohort (year you joined), then 01600, then your own 3 digits.">
      <input v-model.trim="form.student_number" inputmode="numeric" autocomplete="off" maxlength="14" placeholder="2101600227" required />
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
      <PasswordInput v-model="form.password" autocomplete="new-password" minlength="8" required />
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
  </template>

  <p class="center">Already registered? <RouterLink :to="{ name: 'login' }">Sign in</RouterLink></p>
</template>
