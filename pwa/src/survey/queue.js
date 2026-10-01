/**
 * Offline queue for survey answers (spec section 4: "queues a response locally and retries
 * submission on reconnect... iOS Safari does not support background sync, so retry must also
 * trigger on next app open").
 *
 * Answers wait here until the server confirms them. Retrying is always safe: each entry carries
 * the submission id the phone chose, and the server treats a repeat of the same id as "already saved".
 * The queue only ever holds survey answers, and an entry is deleted the moment it is confirmed.
 */

const KEY = 'sunates.surveyQueue'

/** Outcomes of one send, decided from the HTTP status. */
export const OUTCOME = { SENT: 'sent', RETRY: 'retry', DROP: 'drop' }

/**
 * @param {{status: number}} error an ApiError
 * @returns {'retry'|'drop'}
 */
export function classifyFailure(error) {
  // 0 = no connection; 408/429/5xx = the server or network is struggling: keep the answers and try later.
  if (error.status === 0 || error.status === 408 || error.status === 429 || error.status >= 500) return OUTCOME.RETRY

  // 404 (bad link), 409 (already answered), 410 (closed), 422 (answers rejected): retrying cannot change the outcome.
  return OUTCOME.DROP
}

/** Merely reading window.localStorage throws in some browsers when site data is blocked. */
function defaultStorage() {
  try {
    return globalThis.localStorage
  } catch {
    return undefined
  }
}

/**
 * @param {{getItem(k: string): string|null, setItem(k: string, v: string): void}} [storage]
 *        defaults to localStorage; the queue still works for this session if storage is blocked
 */
export function createSurveyQueue(storage = defaultStorage()) {
  let memory = []
  let flushing = null

  function load() {
    try {
      const raw = storage?.getItem(KEY)
      if (raw === null || raw === undefined) return memory
      const parsed = JSON.parse(raw)
      return Array.isArray(parsed) ? parsed : []
    } catch {
      return memory // private mode, blocked storage, or a corrupted value
    }
  }

  function save(entries) {
    memory = entries
    try {
      storage?.setItem(KEY, JSON.stringify(entries))
    } catch {
      /* keep the in-memory copy */
    }
  }

  const queue = {
    /** @returns {Array<{submission_id: string, token: string, version: number|null, answers: object, queued_at: string}>} */
    all: () => load().slice(),

    size: () => load().length,

    /** Queueing the same submission twice (a double tap) keeps one copy. */
    enqueue(entry) {
      const entries = load()
      if (entries.some((e) => e.submission_id === entry.submission_id)) return

      save([...entries, { ...entry, queued_at: new Date().toISOString() }])
    },

    remove(submissionId) {
      save(load().filter((e) => e.submission_id !== submissionId))
    },

    /**
     * Try to send everything waiting, oldest first.
     *
     * @param {(entry: object) => Promise<unknown>} send posts one entry; rejects with an ApiError (status) on failure
     * @returns {Promise<{sent: number, dropped: Array<{token: string, status: number, message: string}>, remaining: number}>}
     */
    flush(send) {
      // One flush at a time: the online event, the app opening and the timer can all fire together.
      if (flushing) return flushing

      flushing = (async () => {
        const result = { sent: 0, dropped: [], remaining: 0 }

        for (const entry of load()) {
          try {
            await send(entry)
            queue.remove(entry.submission_id)
            result.sent++
          } catch (error) {
            if (typeof error?.status !== 'number') throw error // a bug, not a network problem

            if (classifyFailure(error) === OUTCOME.DROP) {
              queue.remove(entry.submission_id)
              result.dropped.push({ token: entry.token, status: error.status, message: error.message })
              continue
            }

            break // offline or the server is struggling: keep this and everything after it
          }
        }

        result.remaining = load().length

        return result
      })().finally(() => {
        flushing = null
      })

      return flushing
    },
  }

  return queue
}
