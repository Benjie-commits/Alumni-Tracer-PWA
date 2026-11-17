import { reactive, computed } from 'vue'

const TOKEN_KEY = 'sunates.token'
// The account type ("alumni" or "student") is not personal, and the menus need it before /me loads.
const ROLE_KEY = 'sunates.role'

// Storage can throw (private windows, blocked site data), so every access is guarded and the app
// still works for the current tab without it.
function read(key) {
  try {
    return localStorage.getItem(key)
  } catch {
    return null
  }
}

function write(key, value) {
  try {
    if (value) localStorage.setItem(key, value)
    else localStorage.removeItem(key)
  } catch {
    /* keep going with the in-memory value */
  }
}

/**
 * Only the token and account type are persisted. The user's profile is fetched fresh after each load
 * and is never written to storage, so nothing personal stays on a shared phone after sign-out.
 */
export const session = reactive({
  token: read(TOKEN_KEY),
  role: read(ROLE_KEY) || 'alumni',
  user: null,
})

export const isSignedIn = computed(() => Boolean(session.token))
export const isStudent = computed(() => isSignedIn.value && session.role === 'student')

/** Where a signed-in person lands: students and alumni have different home screens. */
export function homeRoute() {
  return { name: isStudent.value ? 'student-home' : 'profile' }
}

export function startSession({ token, user }) {
  session.token = token
  session.user = user
  session.role = user?.role === 'student' ? 'student' : 'alumni'
  write(TOKEN_KEY, token)
  write(ROLE_KEY, session.role)
}

export function endSession() {
  session.token = null
  session.user = null
  session.role = 'alumni'
  write(TOKEN_KEY, null)
  write(ROLE_KEY, null)
}
