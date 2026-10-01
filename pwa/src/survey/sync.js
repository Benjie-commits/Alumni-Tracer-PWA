import { computed, ref } from 'vue'
import { api } from '../api.js'
import { createSurveyQueue } from './queue.js'

/**
 * The app-wide survey outbox: answers waiting to reach the server, and the triggers that retry them.
 *
 * Retry happens (1) when the app opens, (2) when the browser says it is back online, (3) when the
 * app returns to the foreground, and (4) every 30 seconds while anything is waiting. The first
 * three matter most on iPhones, which cannot retry in the background, so a queued answer is sent the
 * next time the alumnus opens the app.
 */
const queue = createSurveyQueue()

const pending = ref(queue.size())
/** Answers the server will never accept (bad link, already answered, closed), reported once. */
const problems = ref([])

export const pendingSurveyCount = computed(() => pending.value)
export const surveyProblems = problems

export function dismissSurveyProblems() {
  problems.value = []
}

/** Post one queued entry. The survey link's token is the credential, so no sign-in is needed. */
export function sendEntry(entry) {
  return api.post(`/surveys/${entry.token}/responses`, {
    submission_id: entry.submission_id,
    answers: entry.answers,
    ...(entry.version ? { version: entry.version } : {}),
  }, { auth: false })
}

export function queueSurveyAnswers(entry) {
  queue.enqueue(entry)
  pending.value = queue.size()
  ensureTimer()
}

export async function syncSurveys() {
  if (queue.size() === 0) {
    pending.value = 0
    return { sent: 0, dropped: [], remaining: 0 }
  }

  const result = await queue.flush(sendEntry)

  pending.value = result.remaining
  if (result.dropped.length) problems.value = [...problems.value, ...result.dropped]

  return result
}

let timer = null
function ensureTimer() {
  if (timer || pending.value === 0) return

  timer = setInterval(async () => {
    await syncSurveys().catch(() => {})
    if (pending.value === 0) {
      clearInterval(timer)
      timer = null
    }
  }, 30_000)
}

let started = false

/** Call once at start-up. */
export function startSurveySync() {
  if (started) return
  started = true

  const retry = () => syncSurveys().catch(() => {})

  window.addEventListener('online', retry)
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') retry()
  })

  retry() // the app has just opened
  ensureTimer()
}
