import { test } from 'node:test'
import assert from 'node:assert/strict'
import { currentTheme, nextTheme } from '../src/theme.js'

const media = (dark) => () => ({ matches: dark })

test('with no choice saved, the device setting decides', () => {
  assert.equal(currentTheme({ dataset: {} }, media(true)), 'dark')
  assert.equal(currentTheme({ dataset: {} }, media(false)), 'light')
})

test('a saved choice wins over the device setting', () => {
  assert.equal(currentTheme({ dataset: { theme: 'light' } }, media(true)), 'light')
  assert.equal(currentTheme({ dataset: { theme: 'dark' } }, media(false)), 'dark')
})

test('the switch flips between the two', () => {
  assert.equal(nextTheme('dark'), 'light')
  assert.equal(nextTheme('light'), 'dark')
})
