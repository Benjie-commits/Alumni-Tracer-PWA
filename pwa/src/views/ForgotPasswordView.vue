<script setup>
import { reactive, ref } from 'vue'
import { api } from '../api.js'
import { useForm } from '../useForm.js'
import Field from '../components/Field.vue'

const form = reactive({ email: '' })
const sent = ref('')
const { busy, message, errors, submit } = useForm()

async function send() {
  sent.value = ''
  await submit(async () => {
    sent.value = (await api.post('/auth/forgot-password', form, { auth: false })).message
  })
}
</script>

<template>
  <h1>Forgot your password?</h1>
  <p class="muted">Enter the email you sign in with. We'll email you a link to choose a new password.</p>

  <form class="card" novalidate @submit.prevent="send">
    <p v-if="message" class="alert bad" role="alert">{{ message }}</p>
    <p v-if="sent" class="alert ok" role="status">{{ sent }}</p>

    <Field label="Email" :error="errors.email">
      <input v-model.trim="form.email" type="email" inputmode="email" autocomplete="username" autocapitalize="none" required />
    </Field>

    <button class="block" type="submit" :disabled="busy">{{ busy ? 'Sending…' : 'Email me a link' }}</button>
  </form>

  <p class="center small">
    No longer have that email? Contact the Registrar's office to update your record.
  </p>
  <p class="center"><RouterLink :to="{ name: 'login' }">Back to sign in</RouterLink></p>
</template>
