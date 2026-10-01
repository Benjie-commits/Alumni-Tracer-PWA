<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '../api.js'
import { cleanAnswers, validateAnswers, visibleQuestions, newSubmissionId } from '../survey/logic.js'
import { queueSurveyAnswers, syncSurveys } from '../survey/sync.js'
import { classifyFailure, OUTCOME } from '../survey/queue.js'
import QuestionField from '../components/QuestionField.vue'

const route = useRoute()
const token = route.params.token

// loading | open | completed | expired | missing | offline | error | done | queued
const stage = ref('loading')
const survey = ref(null)
const answers = reactive({})
const errors = reactive({})
const message = ref('')
const busy = ref(false)

// Chosen once per visit: a double tap or a retry is recognised by the server as the same submission.
const submissionId = newSubmissionId()

// A completed or expired survey comes back with no questions at all, so guard for that.
const visible = computed(() => (survey.value?.questions ? visibleQuestions(survey.value.questions, answers) : []))

// When a question stops applying, forget what was typed into it so it can never be sent.
watch(visible, (now) => {
  const keys = new Set(now.map((q) => q.key))
  for (const key of Object.keys(answers)) if (!keys.has(key)) delete answers[key]
})

onMounted(async () => {
  try {
    const { data } = await api.get(`/surveys/${token}`, { auth: false })
    survey.value = data
    stage.value = { open: 'open', completed: 'completed', expired: 'expired' }[data.status] ?? 'error'
  } catch (error) {
    if (error.offline) stage.value = 'offline'
    else if (error.status === 404) stage.value = 'missing'
    else {
      stage.value = 'error'
      message.value = error.message
    }
  }
})

function clearErrors() {
  for (const key of Object.keys(errors)) delete errors[key]
}

async function showFirstError() {
  await nextTick()
  document.querySelector('.question.invalid')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

async function submit() {
  clearErrors()
  message.value = ''

  Object.assign(errors, validateAnswers(survey.value.questions, answers))
  if (Object.keys(errors).length) {
    message.value = 'Please answer the highlighted questions.'
    return showFirstError()
  }

  const entry = {
    submission_id: submissionId,
    token,
    version: survey.value.version,
    answers: cleanAnswers(survey.value.questions, answers),
  }

  busy.value = true
  try {
    await api.post(`/surveys/${token}/responses`, {
      submission_id: entry.submission_id, answers: entry.answers, version: entry.version,
    }, { auth: false })
    stage.value = 'done'
  } catch (error) {
    if (!(error instanceof ApiError)) throw error
    await handleFailure(error, entry)
  } finally {
    busy.value = false
  }
}

async function handleFailure(error, entry) {
  // No signal, or the server is struggling: keep the answers on the phone and send them later.
  if (classifyFailure(error) === OUTCOME.RETRY) {
    queueSurveyAnswers(entry)
    stage.value = 'queued'
    syncSurveys().catch(() => {})
    return
  }

  if (error.status === 422) {
    for (const [field, messages] of Object.entries(error.errors)) errors[field.replace(/^answers\./, '')] = messages[0]
    message.value = 'Please check the highlighted questions.'
    return showFirstError()
  }

  if (error.status === 409) stage.value = 'completed'
  else if (error.status === 410) stage.value = 'expired'
  else if (error.status === 404) stage.value = 'missing'
  else {
    stage.value = 'error'
    message.value = error.message
  }
}

const questionNumber = (question) => visible.value.indexOf(question) + 1
</script>

<template>
  <p v-if="stage === 'loading'" class="muted">Loading your survey…</p>

  <template v-else-if="stage === 'open'">
    <h1>Hi {{ survey.first_name }}</h1>
    <p class="muted">{{ survey.intro }}</p>

    <form class="card" novalidate @submit.prevent="submit">
      <p v-if="message" class="alert bad" role="alert">{{ message }}</p>

      <QuestionField
        v-for="question in visible"
        :key="question.key"
        v-model="answers[question.key]"
        :question="question"
        :error="errors[question.key] || ''"
        :index="questionNumber(question)"
      />

      <button class="block" type="submit" :disabled="busy">{{ busy ? 'Sending…' : 'Send my answers' }}</button>
      <p class="hint small center" style="margin-bottom:0">It takes about two minutes. You can skip questions marked optional.</p>
    </form>
  </template>

  <section v-else-if="stage === 'done'" class="card center">
    <h1>Thank you, {{ survey.first_name }}!</h1>
    <p>Your answers help Soroti University support its graduates and improve its programmes.</p>
  </section>

  <section v-else-if="stage === 'queued'" class="card center">
    <h1>Saved on your phone</h1>
    <p>We couldn't reach the server just now. Your answers are safe on this phone and will be sent automatically when you are back online. You don't need to do anything.</p>
    <p class="hint small">Opening the app again will also send them.</p>
  </section>

  <section v-else-if="stage === 'completed'" class="card center">
    <h1>Already done</h1>
    <p>This survey has already been completed. Thank you!</p>
  </section>

  <section v-else-if="stage === 'expired'" class="card center">
    <h1>This survey has closed</h1>
    <p>The time to answer this one has passed. We'll be in touch for the next one.</p>
  </section>

  <section v-else-if="stage === 'missing'" class="card center">
    <h1>We can't find that survey</h1>
    <p>The link may be incomplete. Try opening it again from your message, or <RouterLink :to="{ name: 'login' }">sign in</RouterLink> to see the surveys waiting for you.</p>
  </section>

  <section v-else-if="stage === 'offline'" class="card center">
    <h1>You're offline</h1>
    <p>Connect to the internet to open the survey. Once it's open you can finish it even if the connection drops.</p>
    <button type="button" @click="$router.go(0)">Try again</button>
  </section>

  <section v-else class="card center">
    <h1>Something went wrong</h1>
    <p>{{ message || 'Please try again in a moment.' }}</p>
    <button type="button" @click="$router.go(0)">Try again</button>
  </section>
</template>
