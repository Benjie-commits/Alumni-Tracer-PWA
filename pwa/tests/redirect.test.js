import { test } from 'node:test'
import assert from 'node:assert/strict'
import { safeRedirect } from '../src/redirect.js'

test('keeps ordinary in-app paths', () => {
  assert.equal(safeRedirect('/work'), '/work')
  assert.equal(safeRedirect('/work?tab=1#top'), '/work?tab=1#top')
})

test('falls back to home for anything that could leave the app', () => {
  for (const hostile of ['//evil.example', '/\\evil.example', 'https://evil.example', 'javascript:alert(1)', 'work', '', undefined, null, ['/work'], 42]) {
    assert.equal(safeRedirect(hostile), '/', `should reject ${JSON.stringify(hostile)}`)
  }
})
