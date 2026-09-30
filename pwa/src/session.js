import { reactive, computed } from 'vue'

const TOKEN_KEY = 'sunates.token'

// Storage can throw (private windows, blocked site data), so every access is guarded and the app
// still works for the current tab without it.
function readToken() {
  try {
    return localStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

function writeToken(token) {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token)
    else localStorage.removeItem(TOKEN_KEY)
  } catch {
    /* keep going with the in-memory token */
  }
}

/**
 * Only the token is persisted. The user's profile is fetched fresh after each load and is never
 * written to storage, so nothing personal stays on a shared phone after sign-out.
 */
export const session = reactive({
  token: readToken(),
  user: null,
})

export const isSignedIn = computed(() => Boolean(session.token))

export function startSession({ token, user }) {
  session.token = token
  session.user = user
  writeToken(token)
}

export function endSession() {
  session.token = null
  session.user = null
  writeToken(null)
}
