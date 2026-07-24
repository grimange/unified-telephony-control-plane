<template>
  <div class="ui-form-field">
    <!-- eslint-disable-next-line vuejs-accessibility/label-has-for -- UiFormField passes the generated id to its control slot and route/view axe tests verify rendered associations. -->
    <label
      class="ui-form-field__label"
      :for="controlId"
    >
      {{ label }}
      <span
        v-if="required"
        aria-hidden="true"
      >required</span>
    </label>
    <slot
      :id="controlId"
      :described-by="describedBy"
      :invalid="Boolean(error)"
    />
    <p
      v-if="help"
      :id="helpId"
      class="ui-form-field__help"
    >
      {{ help }}
    </p>
    <p
      v-if="error"
      :id="errorId"
      class="ui-form-field__error"
    >
      {{ error }}
    </p>
  </div>
</template>

<script setup lang="ts">
import { computed, useId } from 'vue'

const props = withDefaults(defineProps<{
  id?: string
  label: string
  help?: string
  error?: string
  required?: boolean
}>(), {
  id: undefined,
  help: '',
  error: '',
  required: false,
})

const generatedId = useId()
const controlId = computed(() => props.id ?? `field-${generatedId}`)
const helpId = computed(() => `${controlId.value}-help`)
const errorId = computed(() => `${controlId.value}-error`)
const describedBy = computed(() => [
  props.help ? helpId.value : '',
  props.error ? errorId.value : '',
].filter(Boolean).join(' ') || undefined)
</script>
