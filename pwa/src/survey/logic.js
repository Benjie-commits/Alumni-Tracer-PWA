/**
 * Survey rules that run on the phone. They mirror the server (SurveyDefinition / SurveyAnswers),
 * which stays the authority: this exists so people see only the questions that apply and get
 * instant feedback, not to be trusted.
 */

const TEXT_LIMIT = 255
const LONG_TEXT_LIMIT = 2000

export function isEmpty(value) {
  return value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0)
}

function matches(rule, effective) {
  if (!(rule.key in effective)) return false

  const given = effective[rule.key]

  // A multi-choice answer matches when any ticked option is listed.
  return Array.isArray(given) ? given.some((v) => rule.in.includes(v)) : rule.in.includes(given)
}

/**
 * Questions that apply given the answers so far. A question can only depend on an earlier one, and
 * an answer to a question that is hidden does not count, so changing an earlier answer cannot leave
 * later questions visible because of a stale reply.
 */
export function visibleQuestions(questions, answers) {
  const visible = []
  const effective = {}

  for (const question of questions) {
    if (question.show_if && !matches(question.show_if, effective)) continue

    visible.push(question)

    if (!isEmpty(answers[question.key])) effective[question.key] = answers[question.key]
  }

  return visible
}

/** What is actually sent: only visible, non-empty answers, in the shape the server expects. */
export function cleanAnswers(questions, answers) {
  const clean = {}

  for (const question of visibleQuestions(questions, answers)) {
    const value = answers[question.key]
    if (isEmpty(value)) continue

    switch (question.type) {
      case 'number':
      case 'scale':
        clean[question.key] = Number(value)
        break
      case 'text':
      case 'long_text':
        clean[question.key] = String(value).trim()
        if (clean[question.key] === '') delete clean[question.key]
        break
      default:
        clean[question.key] = value
    }
  }

  return clean
}

/** @returns {Record<string, string>} question key => message, empty when everything is fine */
export function validateAnswers(questions, answers) {
  const errors = {}

  for (const question of visibleQuestions(questions, answers)) {
    const value = answers[question.key]
    const blank = isEmpty(value) || (typeof value === 'string' && value.trim() === '')

    if (blank) {
      if (question.required) errors[question.key] = 'Please answer this question.'
      continue
    }

    if (question.type === 'number') {
      const n = Number(value)
      if (!Number.isFinite(n)) errors[question.key] = 'Enter a number.'
      else if (question.min !== undefined && n < question.min) errors[question.key] = `The smallest allowed value is ${question.min}.`
      else if (question.max !== undefined && n > question.max) errors[question.key] = `The largest allowed value is ${question.max}.`
    } else if (question.type === 'text' && String(value).length > TEXT_LIMIT) {
      errors[question.key] = `Please keep this under ${TEXT_LIMIT} characters.`
    } else if (question.type === 'long_text' && String(value).length > LONG_TEXT_LIMIT) {
      errors[question.key] = `Please keep this under ${LONG_TEXT_LIMIT} characters.`
    }
  }

  return errors
}

/** A fresh id for one submission. crypto.randomUUID is missing on older phones, so fall back. */
export function newSubmissionId() {
  const c = globalThis.crypto
  if (c?.randomUUID) return c.randomUUID()

  const bytes = new Uint8Array(16)
  if (c?.getRandomValues) c.getRandomValues(bytes)
  else for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256)

  bytes[6] = (bytes[6] & 0x0f) | 0x40 // version 4
  bytes[8] = (bytes[8] & 0x3f) | 0x80 // variant 10
  const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('')

  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}
