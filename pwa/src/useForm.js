import { reactive, ref } from 'vue'
import { ApiError } from './api.js'

/**
 * Shared submit handling: busy flag, per-field validation messages from the API, and one
 * top-level message for everything else (including being offline).
 */
export function useForm() {
  const busy = ref(false)
  const message = ref('')
  const errors = reactive({})

  function clear() {
    message.value = ''
    for (const key of Object.keys(errors)) delete errors[key]
  }

  /** @returns {Promise<boolean>} true when the action succeeded */
  async function submit(action) {
    clear()
    busy.value = true
    try {
      await action()
      return true
    } catch (error) {
      if (!(error instanceof ApiError)) throw error

      for (const [field, messages] of Object.entries(error.errors)) errors[field] = messages[0]

      if (error.offline) message.value = "You're offline. Reconnect and try again."
      else if (Object.keys(error.errors).length === 0) message.value = error.message
      else message.value = 'Please fix the highlighted fields.'
      return false
    } finally {
      busy.value = false
    }
  }

  return { busy, message, errors, submit, clear }
}
