<script setup>
import PasswordInput from "../components/PasswordInput.vue"
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

let staffRedirect = null

async function signIn() {
  staffRedirect = null
  const ok = await submit(async () => {
    const result = await api.post('/auth/login', { ...form, device_name: deviceName() }, { auth: false })
    if (result?.data?.status === 'staff') {
      staffRedirect = result.data.redirect
      return
    }
    startSession(result)
  })
  // Staff accounts continue to the admin dashboard (a full page load into the server-rendered console).
  if (ok && staffRedirect) return window.location.assign(staffRedirect)
  if (ok) router.replace(safeRedirect(route.query.redirect))
}
</script>

<template>
  <h1>Welcome back</h1>
  <p class="muted">One sign-in for alumni, students and university staff.</p>

  <form class="card" novalidate @submit.prevent="signIn">
    <p v-if="message" class="alert bad" role="alert">{{ message }}</p>

    <Field label="Email" :error="errors.email">
      <input v-model.trim="form.email" type="email" inputmode="email" autocomplete="username" autocapitalize="none" required />
    </Field>
    <Field label="Password" :error="errors.password">
      <PasswordInput v-model="form.password" autocomplete="current-password" required />
    </Field>

    <button class="block" type="submit" :disabled="busy">{{ busy ? 'Signing in…' : 'Sign in' }}</button>
  </form>

  <p class="center small"><RouterLink :to="{ name: 'forgot-password' }">Forgot your password?</RouterLink></p>
  <p class="center">New here? <RouterLink :to="{ name: 'register' }">Create an account</RouterLink> (graduates and continuing students)</p>
</template>
