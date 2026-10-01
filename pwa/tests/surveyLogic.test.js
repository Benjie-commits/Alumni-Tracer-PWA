import { test } from 'node:test'
import assert from 'node:assert/strict'
import { cleanAnswers, isEmpty, newSubmissionId, validateAnswers, visibleQuestions } from '../src/survey/logic.js'

const choice = (key, extra = {}) => ({
  key, type: 'single_choice', label: key, options: [{ value: 'a', label: 'A' }, { value: 'b', label: 'B' }], ...extra,
})
const text = (key, extra = {}) => ({ key, type: 'text', label: key, ...extra })

const keys = (questions) => questions.map((q) => q.key)

test('isEmpty treats false and zero as real answers', () => {
  for (const empty of [undefined, null, '', []]) assert.equal(isEmpty(empty), true)
  for (const filled of [false, 0, 'x', ['a']]) assert.equal(isEmpty(filled), false)
})

test('a conditional question appears only when its earlier answer matches', () => {
  const questions = [choice('activity'), text('employer', { show_if: { key: 'activity', in: ['a'] } })]

  assert.deepEqual(keys(visibleQuestions(questions, {})), ['activity'])
  assert.deepEqual(keys(visibleQuestions(questions, { activity: 'b' })), ['activity'])
  assert.deepEqual(keys(visibleQuestions(questions, { activity: 'a' })), ['activity', 'employer'])
})

test('a stale answer to a hidden question does not unlock further questions', () => {
  const questions = [
    choice('activity'),
    text('employer', { show_if: { key: 'activity', in: ['a'] } }),
    text('detail', { show_if: { key: 'employer', in: ['Acme'] } }),
  ]

  // The alumnus typed "Acme", then changed their mind about the activity.
  assert.deepEqual(keys(visibleQuestions(questions, { activity: 'b', employer: 'Acme' })), ['activity'])
  assert.deepEqual(keys(visibleQuestions(questions, { activity: 'a', employer: 'Acme' })), ['activity', 'employer', 'detail'])
})

test('multi-choice answers match when any ticked option is listed', () => {
  const questions = [
    { key: 'hurdles', type: 'multi_choice', label: 'h', options: [{ value: 'x', label: 'X' }, { value: 'y', label: 'Y' }] },
    text('more', { show_if: { key: 'hurdles', in: ['y'] } }),
  ]

  assert.deepEqual(keys(visibleQuestions(questions, { hurdles: ['x', 'y'] })), ['hurdles', 'more'])
  assert.deepEqual(keys(visibleQuestions(questions, { hurdles: ['x'] })), ['hurdles'])
  assert.deepEqual(keys(visibleQuestions(questions, { hurdles: [] })), ['hurdles'])
})

test('yes/no answers match booleans, including "no"', () => {
  const questions = [
    { key: 'started', type: 'yes_no', label: 's' },
    text('workers', { show_if: { key: 'started', in: [true] } }),
    text('why_not', { show_if: { key: 'started', in: [false] } }),
  ]

  assert.deepEqual(keys(visibleQuestions(questions, { started: true })), ['started', 'workers'])
  assert.deepEqual(keys(visibleQuestions(questions, { started: false })), ['started', 'why_not'])
})

test('cleaning keeps only visible, non-empty answers in the shape the server expects', () => {
  const questions = [
    choice('activity'),
    text('employer', { show_if: { key: 'activity', in: ['a'] } }),
    { key: 'staff', type: 'number', label: 'n' },
    { key: 'rating', type: 'scale', label: 'r', min: 1, max: 5 },
    { key: 'started', type: 'yes_no', label: 's' },
    { key: 'notes', type: 'long_text', label: 'n' },
    { key: 'hurdles', type: 'multi_choice', label: 'h', options: [{ value: 'x', label: 'X' }, { value: 'y', label: 'Y' }] },
  ]

  const clean = cleanAnswers(questions, {
    activity: 'b', employer: 'Left over', staff: '12', rating: '4', started: false, notes: '   ', hurdles: [],
  })

  assert.deepEqual(clean, { activity: 'b', staff: 12, rating: 4, started: false })
})

test('text answers are trimmed', () => {
  assert.deepEqual(cleanAnswers([text('employer')], { employer: '  Acme Ltd  ' }), { employer: 'Acme Ltd' })
})

test('validation asks for required answers only where the question applies', () => {
  const questions = [
    choice('activity', { required: true }),
    text('employer', { required: true, show_if: { key: 'activity', in: ['a'] } }),
  ]

  assert.deepEqual(Object.keys(validateAnswers(questions, {})), ['activity'])
  assert.deepEqual(Object.keys(validateAnswers(questions, { activity: 'b' })), [])
  assert.deepEqual(Object.keys(validateAnswers(questions, { activity: 'a' })), ['employer'])
  assert.deepEqual(Object.keys(validateAnswers(questions, { activity: 'a', employer: '   ' })), ['employer'], 'spaces are not an answer')
})

test('a required yes/no is satisfied by "no"', () => {
  const questions = [{ key: 'started', type: 'yes_no', label: 's', required: true }]

  assert.deepEqual(validateAnswers(questions, { started: false }), {})
  assert.deepEqual(Object.keys(validateAnswers(questions, {})), ['started'])
})

test('number bounds and text limits are checked', () => {
  const questions = [
    { key: 'staff', type: 'number', label: 'n', min: 0, max: 100 },
    text('employer'),
    { key: 'notes', type: 'long_text', label: 'n' },
  ]

  assert.ok(validateAnswers(questions, { staff: '-1' }).staff)
  assert.ok(validateAnswers(questions, { staff: '101' }).staff)
  assert.ok(validateAnswers(questions, { staff: 'lots' }).staff)
  assert.deepEqual(validateAnswers(questions, { staff: '0' }), {})
  assert.ok(validateAnswers(questions, { employer: 'x'.repeat(256) }).employer)
  assert.ok(validateAnswers(questions, { notes: 'x'.repeat(2001) }).notes)
  assert.deepEqual(validateAnswers(questions, { notes: 'x'.repeat(2000) }), {})
})

test('submission ids are valid, unique version-4 UUIDs', () => {
  const ids = new Set(Array.from({ length: 50 }, newSubmissionId))

  assert.equal(ids.size, 50)
  for (const id of ids) assert.match(id, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
})

test('submission ids still work on phones without crypto.randomUUID', () => {
  const original = Object.getOwnPropertyDescriptor(globalThis, 'crypto')
  Object.defineProperty(globalThis, 'crypto', { value: { getRandomValues: (a) => a.fill(7) }, configurable: true })

  try {
    assert.match(newSubmissionId(), /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
  } finally {
    Object.defineProperty(globalThis, 'crypto', original)
  }
})
