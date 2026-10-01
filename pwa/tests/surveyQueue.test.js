import { test } from 'node:test'
import assert from 'node:assert/strict'
import { classifyFailure, createSurveyQueue, OUTCOME } from '../src/survey/queue.js'
import { ApiError } from '../src/api.js'

/** A stand-in for localStorage. */
function fakeStorage(initial = {}) {
  const data = { ...initial }
  return { data, getItem: (k) => (k in data ? data[k] : null), setItem: (k, v) => { data[k] = String(v) } }
}

const entry = (id, extra = {}) => ({ submission_id: id, token: `tok-${id}`, version: 3, answers: { current_activity: 'employed' }, ...extra })

const fail = (status, message = 'x') => Promise.reject(new ApiError(status, status === 0 ? null : { message }))

test('queued answers survive a reload because they live in storage', () => {
  const storage = fakeStorage()
  createSurveyQueue(storage).enqueue(entry('a'))

  const afterReload = createSurveyQueue(storage)

  assert.equal(afterReload.size(), 1)
  assert.equal(afterReload.all()[0].submission_id, 'a')
  assert.ok(afterReload.all()[0].queued_at, 'the time it was queued is recorded')
})

test('queueing the same submission twice (a double tap) keeps one copy', () => {
  const queue = createSurveyQueue(fakeStorage())

  queue.enqueue(entry('a'))
  queue.enqueue(entry('a'))

  assert.equal(queue.size(), 1)
})

test('everything is sent oldest first and removed once confirmed', async () => {
  const queue = createSurveyQueue(fakeStorage())
  ;['a', 'b', 'c'].forEach((id) => queue.enqueue(entry(id)))
  const order = []

  const result = await queue.flush(async (e) => { order.push(e.submission_id) })

  assert.deepEqual(order, ['a', 'b', 'c'])
  assert.deepEqual(result, { sent: 3, dropped: [], remaining: 0 })
  assert.equal(queue.size(), 0)
})

test('going offline keeps the entry and everything after it', async () => {
  const queue = createSurveyQueue(fakeStorage())
  ;['a', 'b', 'c'].forEach((id) => queue.enqueue(entry(id)))

  const result = await queue.flush((e) => (e.submission_id === 'b' ? fail(0) : Promise.resolve()))

  assert.equal(result.sent, 1)
  assert.equal(result.remaining, 2)
  assert.deepEqual(queue.all().map((e) => e.submission_id), ['b', 'c'])
})

test('a struggling server is retried later, not abandoned', async () => {
  for (const status of [429, 500, 502, 503, 408]) {
    const queue = createSurveyQueue(fakeStorage())
    queue.enqueue(entry('a'))

    const result = await queue.flush(() => fail(status))

    assert.equal(result.remaining, 1, `HTTP ${status} should keep the answers`)
    assert.deepEqual(result.dropped, [])
  }
})

test('answers the server will never accept are dropped and reported, and the rest still go', async () => {
  const queue = createSurveyQueue(fakeStorage())
  ;['closed', 'dupe', 'good'].forEach((id) => queue.enqueue(entry(id)))

  const result = await queue.flush((e) => {
    if (e.submission_id === 'closed') return fail(410, 'This survey has closed.')
    if (e.submission_id === 'dupe') return fail(409, 'Already completed.')
    return Promise.resolve()
  })

  assert.equal(result.sent, 1)
  assert.equal(result.remaining, 0)
  assert.deepEqual(result.dropped.map((d) => [d.token, d.status]), [['tok-closed', 410], ['tok-dupe', 409]])
  assert.equal(result.dropped[0].message, 'This survey has closed.')
})

test('each kind of failure is classified', () => {
  for (const status of [0, 408, 429, 500, 503]) assert.equal(classifyFailure({ status }), OUTCOME.RETRY, `status ${status}`)
  for (const status of [404, 409, 410, 422, 400, 403]) assert.equal(classifyFailure({ status }), OUTCOME.DROP, `status ${status}`)
})

test('overlapping flushes send each entry only once', async () => {
  const queue = createSurveyQueue(fakeStorage())
  queue.enqueue(entry('a'))
  let sends = 0
  const slowSend = async () => { sends++; await new Promise((r) => setTimeout(r, 20)) }

  // The online event, the app opening and the timer can all fire at once.
  const results = await Promise.all([queue.flush(slowSend), queue.flush(slowSend), queue.flush(slowSend)])

  assert.equal(sends, 1)
  assert.equal(results[0], results[1], 'callers share the one in-flight flush')
  assert.equal(queue.size(), 0)
})

test('a later flush after a finished one works normally', async () => {
  const queue = createSurveyQueue(fakeStorage())
  queue.enqueue(entry('a'))
  await queue.flush(async () => {})

  queue.enqueue(entry('b'))
  const result = await queue.flush(async () => {})

  assert.equal(result.sent, 1)
})

test('a programming error is surfaced rather than mistaken for being offline', async () => {
  const queue = createSurveyQueue(fakeStorage())
  queue.enqueue(entry('a'))

  await assert.rejects(queue.flush(() => Promise.reject(new TypeError('a real bug'))), TypeError)

  assert.equal(queue.size(), 1, 'the answers are still safe')
  // and the queue is not stuck "flushing" forever
  assert.deepEqual(await queue.flush(async () => {}), { sent: 1, dropped: [], remaining: 0 })
})

test('corrupt storage is treated as an empty queue instead of crashing the app', () => {
  const queue = createSurveyQueue(fakeStorage({ 'sunates.surveyQueue': '{not json' }))

  assert.equal(queue.size(), 0)
  queue.enqueue(entry('a'))
  assert.equal(queue.size(), 1)
})

test('storage that is not an array is ignored', () => {
  assert.equal(createSurveyQueue(fakeStorage({ 'sunates.surveyQueue': '{"a":1}' })).size(), 0)
})

test('blocked storage still queues for the current session', async () => {
  const blocked = { getItem: () => { throw new Error('SecurityError') }, setItem: () => { throw new Error('QuotaExceeded') } }
  const queue = createSurveyQueue(blocked)

  queue.enqueue(entry('a'))

  assert.equal(queue.size(), 1)
  assert.deepEqual(await queue.flush(async () => {}), { sent: 1, dropped: [], remaining: 0 })
})

test('no storage at all (undefined) is fine too', () => {
  const queue = createSurveyQueue(undefined)

  queue.enqueue(entry('a'))

  assert.equal(queue.size(), 1)
})

test('confirmed answers leave no trace in storage', async () => {
  const storage = fakeStorage()
  const queue = createSurveyQueue(storage)
  queue.enqueue(entry('a', { answers: { employer_name: 'Secret Ltd' } }))
  assert.ok(storage.data['sunates.surveyQueue'].includes('Secret Ltd'))

  await queue.flush(async () => {})

  assert.ok(!storage.data['sunates.surveyQueue'].includes('Secret Ltd'))
})
