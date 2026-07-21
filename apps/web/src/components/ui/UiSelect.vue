<template>
  <select
    class="ui-control"
    :value="modelValue"
    :disabled="disabled"
    :required="required"
    :aria-invalid="invalid ? 'true' : undefined"
    @change="$emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
  >
    <slot />
  </select>
</template>

<script setup lang="ts">
withDefaults(defineProps<{
  modelValue: string | number
  disabled?: boolean
  required?: boolean
  invalid?: boolean
}>(), {
  disabled: false,
  required: false,
  invalid: false,
})

defineEmits<{
  'update:modelValue': [value: string]
}>()
</script>
