<script setup>
import { computed } from 'vue'

/**
 * One survey question, drawn according to its type. Choices are large tap targets (radio buttons
 * and checkboxes in a fieldset) because most respondents are on a phone.
 */
const props = defineProps({
  question: { type: Object, required: true },
  modelValue: { default: undefined },
  error: { type: String, default: '' },
  index: { type: Number, required: true },
})
const emit = defineEmits(['update:modelValue'])

const id = computed(() => `q-${props.question.key}`)
const isChoice = computed(() => ['single_choice', 'multi_choice', 'yes_no', 'scale'].includes(props.question.type))

const scaleValues = computed(() => {
  const { min = 1, max = 5 } = props.question
  return Array.from({ length: max - min + 1 }, (_, i) => min + i)
})

function toggleMulti(value, checked) {
  const current = Array.isArray(props.modelValue) ? props.modelValue : []
  emit('update:modelValue', checked ? [...current, value] : current.filter((v) => v !== value))
}
</script>

<template>
  <component :is="isChoice ? 'fieldset' : 'div'" :id="id" class="question" :class="{ invalid: error }">
    <component :is="isChoice ? 'legend' : 'label'" :for="isChoice ? undefined : `${id}-input`" class="q-label">
      <span class="q-num">{{ index }}.</span> {{ question.label }}
      <span v-if="!question.required" class="optional">(optional)</span>
    </component>

    <template v-if="question.type === 'single_choice'">
      <label v-for="option in question.options" :key="option.value" class="choice">
        <input type="radio" :name="id" :value="option.value" :checked="modelValue === option.value" @change="emit('update:modelValue', option.value)" />
        <span>{{ option.label }}</span>
      </label>
    </template>

    <template v-else-if="question.type === 'multi_choice'">
      <label v-for="option in question.options" :key="option.value" class="choice">
        <input type="checkbox" :value="option.value" :checked="Array.isArray(modelValue) && modelValue.includes(option.value)" @change="toggleMulti(option.value, $event.target.checked)" />
        <span>{{ option.label }}</span>
      </label>
    </template>

    <template v-else-if="question.type === 'yes_no'">
      <div class="yesno">
        <label class="choice">
          <input type="radio" :name="id" :checked="modelValue === true" @change="emit('update:modelValue', true)" /><span>Yes</span>
        </label>
        <label class="choice">
          <input type="radio" :name="id" :checked="modelValue === false" @change="emit('update:modelValue', false)" /><span>No</span>
        </label>
      </div>
    </template>

    <template v-else-if="question.type === 'scale'">
      <div class="scale">
        <label v-for="n in scaleValues" :key="n" class="scale-point">
          <input type="radio" :name="id" :value="n" :checked="modelValue === n" @change="emit('update:modelValue', n)" />
          <span>{{ n }}</span>
        </label>
      </div>
      <div v-if="question.min_label || question.max_label" class="scale-labels small muted">
        <span>{{ question.min_label }}</span><span>{{ question.max_label }}</span>
      </div>
    </template>

    <textarea v-else-if="question.type === 'long_text'" :id="`${id}-input`" rows="4" maxlength="2000" :value="modelValue ?? ''" @input="emit('update:modelValue', $event.target.value)"></textarea>

    <input v-else-if="question.type === 'number'" :id="`${id}-input`" type="number" inputmode="numeric" :min="question.min" :max="question.max" :value="modelValue ?? ''" @input="emit('update:modelValue', $event.target.value)" />

    <input v-else :id="`${id}-input`" type="text" maxlength="255" :value="modelValue ?? ''" @input="emit('update:modelValue', $event.target.value)" />

    <small v-if="error" class="error" role="alert">{{ error }}</small>
  </component>
</template>
