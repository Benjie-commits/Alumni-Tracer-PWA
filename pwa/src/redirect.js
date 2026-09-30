/**
 * Only same-app paths are honoured after sign-in, so a crafted ?redirect= link cannot bounce a
 * user to another site ("//evil.example" and "/\evil.example" are protocol-relative tricks).
 */
export function safeRedirect(value) {
  if (typeof value !== 'string') return '/'
  if (!value.startsWith('/') || value.startsWith('//') || value.startsWith('/\\')) return '/'
  return value
}
