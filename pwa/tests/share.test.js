import { test } from 'node:test'
import assert from 'node:assert/strict'
import { shareOrCopy } from '../src/share.js'

const URL = 'https://alumni.example/verify/abc'

test('uses the share sheet when the phone has one', async () => {
  const shared = []
  const nav = { share: async (data) => { shared.push(data) } }

  assert.equal(await shareOrCopy(URL, 'My degree', nav), 'shared')
  assert.deepEqual(shared, [{ title: 'My degree', url: URL }])
})

test('closing the share sheet is not an error and does not copy behind the user\'s back', async () => {
  let copied = false
  const nav = {
    share: async () => { throw Object.assign(new Error('cancelled'), { name: 'AbortError' }) },
    clipboard: { writeText: async () => { copied = true } },
  }

  assert.equal(await shareOrCopy(URL, 't', nav), 'cancelled')
  assert.equal(copied, false)
})

test('falls back to the clipboard when sharing fails or is unavailable', async () => {
  const written = []
  const clipboard = { writeText: async (t) => { written.push(t) } }

  assert.equal(await shareOrCopy(URL, 't', { clipboard }), 'copied')
  assert.equal(await shareOrCopy(URL, 't', { share: async () => { throw new TypeError('NotAllowedError') }, clipboard }), 'copied')
  assert.deepEqual(written, [URL, URL])
})

test('reports failure honestly when nothing works', async () => {
  const nav = { clipboard: { writeText: async () => { throw new Error('denied') } } }

  assert.equal(await shareOrCopy(URL, 't', nav), 'failed')
  assert.equal(await shareOrCopy(URL, 't', {}), 'failed')
  assert.equal(await shareOrCopy(URL, 't', undefined), 'failed')
})
