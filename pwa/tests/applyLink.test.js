import { test } from 'node:test'
import assert from 'node:assert/strict'
import { applyLink } from '../src/applyLink.js'

test('a web address becomes an "apply online" link', () => {
  assert.deepEqual(applyLink('https://jobs.example.org/apply?id=7'), { href: 'https://jobs.example.org/apply?id=7', label: 'Apply online' })
})

test('an email address becomes a mailto link', () => {
  assert.deepEqual(applyLink(' hr@example.co.ug '), { href: 'mailto:hr@example.co.ug', label: 'Email hr@example.co.ug' })
})

test('dangerous or unknown schemes are never links', () => {
  for (const text of ['javascript:alert(1)', 'data:text/html,hi', 'vbscript:x', 'ftp://example.org/file', 'file:///etc/passwd']) {
    assert.equal(applyLink(text), null, text)
  }
})

test('instructions in words stay plain text', () => {
  assert.equal(applyLink('Drop your CV at the Registrar\'s office'), null)
  assert.equal(applyLink(''), null)
  assert.equal(applyLink(null), null)
})
