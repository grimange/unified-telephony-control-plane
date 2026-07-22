<template>
  <UiFormField
    :id="controlId"
    :label="field.label"
    :help="field.help"
    :error="effectiveError"
    :required="field.required"
  >
    <template #default="{ id, describedBy, invalid }">
      <UiTextInput
        v-if="field.input_type === 'text'"
        :id="id"
        :type="field.write_only ? 'password' : 'text'"
        :model-value="textValue"
        :disabled="disabled"
        :readonly="field.read_only"
        :required="field.required"
        :invalid="invalid"
        :aria-describedby="describedBy"
        :minlength="field.validation?.min_length"
        :maxlength="field.validation?.max_length"
        :autocomplete="field.write_only ? 'new-password' : 'off'"
        @update:model-value="$emit('update:modelValue', $event)"
      />

      <UiTextInput
        v-else-if="field.input_type === 'integer'"
        :id="id"
        type="number"
        inputmode="numeric"
        :model-value="integerValue"
        :disabled="disabled"
        :readonly="field.read_only"
        :required="field.required"
        :invalid="invalid"
        :aria-describedby="describedBy"
        :min="field.validation?.min"
        :max="field.validation?.max"
        :step="field.validation?.step"
        autocomplete="off"
        @update:model-value="updateInteger"
      />

      <textarea
        v-else-if="field.input_type === 'json'"
        :id="id"
        class="ui-control runtime-catalog-field__textarea"
        :value="jsonValue"
        :disabled="disabled"
        :readonly="field.read_only"
        :required="field.required"
        :aria-describedby="describedBy"
        :aria-invalid="invalid ? 'true' : undefined"
        autocomplete="off"
        spellcheck="false"
        @input="$emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
      />

      <UiAlert
        v-else
        variant="error"
        title="Unsupported configuration field"
      >
        Field {{ field.key }} uses unsupported type {{ field.input_type }}.
      </UiAlert>
    </template>
  </UiFormField>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { RuntimeAdapterConfigurationFieldDescriptor } from '../../api/platform'
import UiAlert from '../ui/UiAlert.vue'
import UiFormField from '../ui/UiFormField.vue'
import UiTextInput from '../ui/UiTextInput.vue'

const props = withDefaults(defineProps<{
  field: RuntimeAdapterConfigurationFieldDescriptor
  runtimeNodeId: string
  modelValue: unknown
  error?: string
  disabled?: boolean
}>(), {
  error: '',
  disabled: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: unknown]
}>()

function safeDomId(value: string): string {
  return value.replace(/[^a-zA-Z0-9_-]+/g, '-')
}

const controlId = computed(() => (
  `runtime-node-${safeDomId(props.runtimeNodeId)}-adapter-field-${safeDomId(props.field.key)}`
))

const unsupportedError = computed(() => {
  const inputType = props.field.input_type as string
  if (inputType === 'text' || inputType === 'integer' || inputType === 'json') return ''

  return props.field.required
    ? `Required field ${props.field.key} uses unsupported type ${inputType}.`
    : `Optional field ${props.field.key} uses unsupported type ${inputType}.`
})

const effectiveError = computed(() => props.error || unsupportedError.value)
const textValue = computed(() => props.modelValue == null ? '' : String(props.modelValue))
const integerValue = computed(() => props.modelValue === null || props.modelValue === undefined ? '' : props.modelValue as string | number)
const jsonValue = computed(() => props.modelValue == null ? '' : String(props.modelValue))

function updateInteger(value: string): void {
  if (value === '') {
    emit('update:modelValue', '')
    return
  }

  emit('update:modelValue', Number(value))
}
</script>
