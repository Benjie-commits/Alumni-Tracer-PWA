<script setup>
import { reactive } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, deviceName } from '../api.js'
import { startSession } from '../session.js'
import { safeRedirect } from '../redirect.js'
import { useForm } from '../useForm.js'
import Field from '../components/Field.vue'

const route = useRoute()
const router = useRouter()
const form = reactive({ email: '', password: '' })
const { busy, message, errors, submit } = useForm()

async function signIn() {
  const ok = await submit(async () => {
    const result = await api.post('/auth/login', { ...form, device_name: deviceName() }, { auth: false })
    startSession(result)
  })
  if (ok) router.replace(safeRedirect(route.query.redirect))
}
</script>

<template>
  <h1>Welcome back</h1>
  <p class="muted">Sign in to update your details and take tracer surveys.</p>

  <form class="card" novalidate @submit.prevent="signIn">
    <p v-if="message" class="alert bad" role="alert">{{ message }}</p>

    <Field label="Email" :error="errors.email">
      <input v-model.trim="form.email" type="email" inputmode="email" autocomplete="username" autocapitalize="none" required />
    </Field>
    <Field label="Password" :error="errors.password">
      <input v-model="form.password" type="password" autocomplete="current-password" required />
    </Field>

    <button class="block" type="submit" :disabled="busy">{{ busy ? 'Signing in…' : 'Sign in' }}</button>
  </form>

  <p class="center">New here? <RouterLink :to="{ name: 'register' }">Create your alumni account</RouterLink></p>
</template>
