import { test } from 'node:test'
import assert from 'node:assert/strict'
import { ApiError } from '../src/api.js'
import { useForm } from '../src/useForm.js'

test('a successful action resolves true and leaves no messages', async () => {
  const form = useForm()

  assert.equal(await form.submit(async () => {}), true)
  assert.equal(form.busy.value, false)
  assert.equal(form.message.value, '')
})

test('busy is true while the action runs and false afterwards, even on failure', async () => {
  const form = useForm()
  let during
  await form.submit(async () => {
    during = form.busy.value
    throw new ApiError(500, { message: 'boom' })
  })

  assert.equal(during, true)
  assert.equal(form.busy.value, false)
})

test('field errors are surfaced per field and a general prompt is shown', async () => {
  const form = useForm()

  const ok = await form.submit(async () => {
    throw new ApiError(422, { errors: { email: ['Taken.', 'Ignored.'], password: ['Too short.'] } })
  })

  assert.equal(ok, false)
  assert.equal(form.errors.email, 'Taken.')
  assert.equal(form.errors.password, 'Too short.')
  assert.equal(form.message.value, 'Please fix the highlighted fields.')
})

test('errors from a previous attempt are cleared on the next one', async () => {
  const form = useForm()
  await form.submit(async () => {
    throw new ApiError(422, { errors: { email: ['Taken.'] } })
  })

  await form.submit(async () => {})

  assert.equal(form.errors.email, undefined)
  assert.equal(form.message.value, '')
})

test('being offline gets a plain-language message', async () => {
  const form = useForm()

  await form.submit(async () => {
    throw new ApiError(0, null)
  })

  assert.match(form.message.value, /offline/i)
})

test('non-API errors are not swallowed', async () => {
  const form = useForm()

  await assert.rejects(form.submit(async () => {
    throw new RangeError('a real bug')
  }), RangeError)
  assert.equal(form.busy.value, false)
})
