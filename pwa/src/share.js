/**
 * Hand a link to someone else: the phone's share sheet (WhatsApp, SMS, email...) where there is
 * one, otherwise the clipboard. The browser objects are passed in so this can be tested.
 *
 * @returns {Promise<'shared'|'copied'|'cancelled'|'failed'>}
 */
export async function shareOrCopy(url, title, nav = globalThis.navigator) {
  if (nav?.share) {
    try {
      await nav.share({ title, url })
      return 'shared'
    } catch (error) {
      // Closing the share sheet is not an error, and must not silently fall through to a surprise copy.
      if (error?.name === 'AbortError') return 'cancelled'
      // Any other failure: try the clipboard instead.
    }
  }

  try {
    await nav.clipboard.writeText(url)
    return 'copied'
  } catch {
    return 'failed'
  }
}
