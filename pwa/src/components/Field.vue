<script setup>
defineProps({
  label: { type: String, required: true },
  error: { type: String, default: '' },
  hint: { type: String, default: '' },
})
</script>

<template>
  <label class="field" :class="{ invalid: error }">
    <span class="label">{{ label }}</span>
    <slot />
    <small v-if="error" class="error" role="alert">{{ error }}</small>
    <small v-else-if="hint" class="hint">{{ hint }}</small>
  </label>
</template>
