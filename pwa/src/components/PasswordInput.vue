<script setup>
import { ref } from 'vue'

// A password box with a show/hide "eye" (HCI: let people check what they typed instead of
// guessing, which cuts sign-in and registration errors, especially on small phone keyboards).
defineOptions({ inheritAttrs: false })
const model = defineModel({ type: String, default: '' })
const shown = ref(false)
</script>

<template>
  <span class="pw">
    <input v-bind="$attrs" v-model="model" :type="shown ? 'text' : 'password'" autocapitalize="none" spellcheck="false" />
    <button
      type="button"
      class="pw-eye"
      :aria-label="shown ? 'Hide password' : 'Show password'"
      :aria-pressed="shown"
      :title="shown ? 'Hide password' : 'Show password'"
      @click="shown = !shown"
    >
      <svg v-if="!shown" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
      <svg v-else viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 19c-7 0-11-7-11-7a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 7 11 7a18.5 18.5 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
    </button>
  </span>
</template>
