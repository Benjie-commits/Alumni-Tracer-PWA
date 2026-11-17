<script setup>
import PasswordInput from "../components/PasswordInput.vue"
import { reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api.js'
import { useForm } from '../useForm.js'
import Field from '../components/Field.vue'

// Opened from the link in the reset email: /reset-password?token=...&email=...
const route = useRoute()
const token = typeof route.query.token === 'string' ? route.query.token : ''
const form = reactive({
  email: typeof route.query.email === 'string' ? route.query.email : '',
  password: '',
  password_confirmation: '',
})
const done = ref('')
const { busy, message, errors, submit } = useForm()

async function reset() {
  await submit(async () => {
    done.value = (await api.post('/auth/reset-password', { ...form, token }, { auth: false })).message
  })
}
</script>

<template>
  <h1>Choose a new password</h1>

  <p v-if="!token" class="alert bad" role="alert">
    This page needs the link from your reset email. <RouterLink :to="{ name: 'forgot-password' }">Ask for a new link</RouterLink>.
  </p>

  <template v-else-if="done">
    <p class="alert ok" role="status">{{ done }}</p>
    <p class="center"><RouterLink class="btn" :to="{ name: 'login' }">Sign in</RouterLink></p>
  </template>

  <form v-else class="card" novalidate @submit.prevent="reset">
    <p v-if="message" class="alert bad" role="alert">{{ message }}</p>
    <p v-if="errors.token" class="alert bad" role="alert">
      {{ errors.token }} <RouterLink :to="{ name: 'forgot-password' }">Ask for a new link</RouterLink>.
    </p>

    <Field label="Email" :error="errors.email">
      <input v-model.trim="form.email" type="email" autocomplete="username" autocapitalize="none" required />
    </Field>
    <Field label="New password" :error="errors.password" hint="At least 8 characters. You'll be signed out on other phones.">
      <PasswordInput v-model="form.password" autocomplete="new-password" minlength="8" required />
    </Field>
    <Field label="Type it again" :error="errors.password_confirmation">
      <PasswordInput v-model="form.password_confirmation" autocomplete="new-password" required />
    </Field>

    <button class="block" type="submit" :disabled="busy">{{ busy ? 'Saving…' : 'Save new password' }}</button>
  </form>
</template>
