import { test, beforeEach } from 'node:test'
import assert from 'node:assert/strict'
import { api, ApiError, setUnauthorizedHandler } from '../src/api.js'
import { session, startSession, endSession } from '../src/session.js'

function respond(status, body) {
  return async () => ({
    status,
    ok: status >= 200 && status < 300,
    json: async () => {
      if (body === undefined) throw new Error('no body')
      return body
    },
  })
}

let calls
beforeEach(() => {
  calls = []
  endSession()
  setUnauthorizedHandler(() => {})
})

function stubFetch(handler) {
  globalThis.fetch = async (url, init) => {
    calls.push({ url, init })
    return handler(url, init)
  }
}

test('sends JSON and the bearer token when signed in', async () => {
  startSession({ token: 'abc123', user: null })
  stubFetch(respond(200, { data: 1 }))

  await api.post('/me/employment-records', { employer: 'X' })

  assert.equal(calls[0].url, '/api/v1/me/employment-records')
  assert.equal(calls[0].init.headers.Authorization, 'Bearer abc123')
  assert.equal(calls[0].init.headers['Content-Type'], 'application/json')
  assert.equal(calls[0].init.body, '{"employer":"X"}')
})

test('omits the token for public calls', async () => {
  startSession({ token: 'abc123', user: null })
  stubFetch(respond(200, { data: [] }))

  await api.get('/reference/programmes', { auth: false })

  assert.equal(calls[0].init.headers.Authorization, undefined)
})

test('maps Laravel validation errors onto ApiError.errors', async () => {
  stubFetch(respond(422, { message: 'The email has already been taken.', errors: { email: ['The email has already been taken.'] } }))

  await assert.rejects(api.post('/auth/register', {}, { auth: false }), (error) => {
    assert.ok(error instanceof ApiError)
    assert.equal(error.status, 422)
    assert.deepEqual(error.errors, { email: ['The email has already been taken.'] })
    assert.equal(error.offline, false)
    return true
  })
})

test('a network failure becomes an offline ApiError', async () => {
  stubFetch(async () => {
    throw new TypeError('Failed to fetch')
  })

  await assert.rejects(api.get('/me'), (error) => {
    assert.equal(error.offline, true)
    assert.equal(error.status, 0)
    return true
  })
})

test('a 401 on an authenticated call ends the session and notifies the app', async () => {
  startSession({ token: 'expired', user: null })
  let notified = 0
  setUnauthorizedHandler(() => notified++)
  stubFetch(respond(401, { message: 'Unauthenticated.' }))

  await assert.rejects(api.get('/me'))

  assert.equal(session.token, null)
  assert.equal(notified, 1)
})

test('a 401 from the login call itself is just a failed sign-in, not a session expiry', async () => {
  let notified = 0
  setUnauthorizedHandler(() => notified++)
  stubFetch(respond(401, { message: 'Bad credentials' }))

  await assert.rejects(api.post('/auth/login', {}, { auth: false }))

  assert.equal(notified, 0)
})

test('204 responses resolve to null without reading a body', async () => {
  startSession({ token: 't', user: null })
  stubFetch(respond(204))

  assert.equal(await api.post('/auth/logout'), null)
})
