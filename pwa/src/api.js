import { session, endSession } from './session.js'

const BASE = (import.meta.env?.VITE_API_BASE_URL || '/api/v1').replace(/\/$/, '')

export class ApiError extends Error {
  constructor(status, body) {
    super(body?.message || (status === 0 ? 'You appear to be offline.' : 'Something went wrong.'))
    this.status = status
    // Laravel validation shape: { errors: { field: ['message', ...] } }
    this.errors = body?.errors || {}
  }

  get offline() {
    return this.status === 0
  }
}

let onUnauthorized = () => {}

/** Called once from main.js so this module never has to import the router. */
export function setUnauthorizedHandler(handler) {
  onUnauthorized = handler
}

async function request(method, path, body, { auth = true } = {}) {
  const headers = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (auth && session.token) headers.Authorization = `Bearer ${session.token}`

  let response
  try {
    response = await fetch(`${BASE}${path}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch {
    throw new ApiError(0, null)
  }

  if (response.status === 204) return null

  const payload = await response.json().catch(() => null)

  if (response.status === 401 && auth) {
    endSession()
    onUnauthorized()
  }

  if (!response.ok) throw new ApiError(response.status, payload)
  return payload
}

export const api = {
  get: (path, options) => request('GET', path, undefined, options),
  post: (path, body, options) => request('POST', path, body, options),
  put: (path, body, options) => request('PUT', path, body, options),
  delete: (path, options) => request('DELETE', path, undefined, options),
}

/** Device label sent with sign-in so repeat logins from one phone replace the old token. */
export function deviceName() {
  const ua = navigator.userAgent || ''
  const kind = /Android/i.test(ua) ? 'android' : /iPhone|iPad/i.test(ua) ? 'ios' : 'web'
  return `alumni-pwa-${kind}`
}
