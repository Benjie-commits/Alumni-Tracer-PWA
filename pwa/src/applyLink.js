/**
 * Turn a posting's "how to apply" text into something tappable, safely: only an http(s) address or a
 * plain email address becomes a link. Anything else (including "javascript:" or other schemes typed
 * into the staff console) stays as plain text.
 *
 * @param {string|null|undefined} text
 * @returns {{ href: string, label: string } | null}
 */
export function applyLink(text) {
  const value = typeof text === 'string' ? text.trim() : ''
  if (value === '' || /\s/.test(value)) return null

  if (/^[^@\s/:]+@[^@\s/:]+\.[a-z]{2,}$/i.test(value)) {
    return { href: `mailto:${value}`, label: `Email ${value}` }
  }

  try {
    const url = new URL(value)
    if (url.protocol === 'https:' || url.protocol === 'http:') return { href: url.href, label: 'Apply online' }
  } catch {
    /* not a URL */
  }

  return null
}
