<script setup>
import { onMounted, reactive, ref } from 'vue'
import { api } from '../api.js'
import { useForm } from '../useForm.js'
import Field from '../components/Field.vue'

const records = ref([])
const types = ref([])
const loading = ref(true)
const loadError = ref('')
const editingId = ref(null) // null = not editing, 'new' = adding, otherwise a record id
const { busy, message, errors, submit, clear } = useForm()

const blank = () => ({
  employer: '', job_title: '', sector: '', employment_type: 'employed',
  start_date: '', end_date: '', is_current: true, city: '', country: '',
})
const form = reactive(blank())

const monthYear = (iso) =>
  iso ? new Date(iso).toLocaleDateString(undefined, { month: 'short', year: 'numeric' }) : null

async function load() {
  try {
    const [list, refs] = await Promise.all([api.get('/me/employment-records'), api.get('/reference/options', { auth: false })])
    records.value = list.data
    types.value = refs.data.employment_type
  } catch (error) {
    loadError.value = error.offline ? "You're offline. Reconnect and refresh to see your work history." : error.message
  } finally {
    loading.value = false
  }
}
onMounted(load)

function startAdd() {
  clear()
  Object.assign(form, blank())
  editingId.value = 'new'
}

function startEdit(record) {
  clear()
  // Copy only the editable fields (never the id), turning nulls into '' for the inputs.
  const defaults = blank()
  for (const key of Object.keys(defaults)) form[key] = record[key] ?? (key === 'is_current' ? false : '')
  editingId.value = record.id
}

function cancel() {
  editingId.value = null
  clear()
}

async function save() {
  const ok = await submit(async () => {
    const payload = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]))
    // A current job has no end date, whatever was typed before ticking the box.
    if (payload.is_current) payload.end_date = null

    if (editingId.value === 'new') await api.post('/me/employment-records', payload)
    else await api.put(`/me/employment-records/${editingId.value}`, payload)
    records.value = (await api.get('/me/employment-records')).data
  })
  if (ok) editingId.value = null
}

async function remove(record) {
  if (!window.confirm(`Remove ${record.employer} from your work history?`)) return
  await submit(async () => {
    await api.delete(`/me/employment-records/${record.id}`)
    records.value = records.value.filter((r) => r.id !== record.id)
  })
}
</script>

<template>
  <h1>Work history</h1>
  <p class="muted">Where you've worked since graduating. This helps the university understand graduate outcomes.</p>

  <p v-if="loadError" class="alert bad" role="alert">{{ loadError }}</p>
  <p v-else-if="loading" class="muted">Loading…</p>

  <template v-else>
    <p v-if="message && editingId === null" class="alert bad" role="alert">{{ message }}</p>

    <section v-if="editingId === null" class="card">
      <p v-if="records.length === 0" class="muted">Nothing here yet.</p>
      <div v-for="record in records" :key="record.id" class="job">
        <strong>{{ record.employer }}</strong>
        <span v-if="record.job_title">{{ record.job_title }}</span>
        <span class="muted small">
          {{ monthYear(record.start_date) || 'Start date not given' }} –
          {{ record.is_current ? 'Present' : monthYear(record.end_date) || 'End date not given' }}
        </span>
        <span class="actions">
          <button type="button" class="link" @click="startEdit(record)">Edit</button>
          <button type="button" class="link" @click="remove(record)">Remove</button>
        </span>
      </div>
      <button class="block" type="button" style="margin-top:12px" @click="startAdd">+ Add a job</button>
    </section>

    <form v-else class="card" novalidate @submit.prevent="save">
      <h2>{{ editingId === 'new' ? 'Add a job' : 'Edit job' }}</h2>
      <p v-if="message" class="alert bad" role="alert">{{ message }}</p>

      <Field label="Employer or business" :error="errors.employer"><input v-model.trim="form.employer" required /></Field>
      <Field label="Your role" :error="errors.job_title"><input v-model.trim="form.job_title" /></Field>
      <Field label="Type of work" :error="errors.employment_type">
        <select v-model="form.employment_type">
          <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
      </Field>
      <Field label="Sector (optional)" :error="errors.sector" hint="e.g. Education, Health, Agriculture"><input v-model.trim="form.sector" /></Field>
      <div class="row">
        <Field label="Started" :error="errors.start_date"><input v-model="form.start_date" type="date" :max="new Date().toISOString().slice(0, 10)" /></Field>
        <Field v-if="!form.is_current" label="Ended" :error="errors.end_date"><input v-model="form.end_date" type="date" /></Field>
      </div>
      <label class="check"><input v-model="form.is_current" type="checkbox" /> <span>I still work here</span></label>
      <div class="row">
        <Field label="Town / city" :error="errors.city"><input v-model.trim="form.city" /></Field>
        <Field label="Country" :error="errors.country"><input v-model.trim="form.country" /></Field>
      </div>

      <div class="actions">
        <button type="submit" :disabled="busy">{{ busy ? 'Saving…' : 'Save' }}</button>
        <button type="button" class="secondary" :disabled="busy" @click="cancel">Cancel</button>
      </div>
    </form>
  </template>
</template>
