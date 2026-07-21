<template>
  <button
    ref="buttonElement"
    class="ui-button"
    :class="[`ui-button--${variant}`]"
    :type="type"
    :disabled="disabled || loading"
    :aria-busy="loading ? 'true' : undefined"
  >
    <span v-if="loading">{{ loadingLabel }}</span>
    <slot v-else />
  </button>
</template>

<script setup lang="ts">
import { ref } from 'vue'

withDefaults(defineProps<{
  variant?: 'primary' | 'secondary' | 'danger' | 'ghost'
  type?: 'button' | 'submit' | 'reset'
  disabled?: boolean
  loading?: boolean
  loadingLabel?: string
}>(), {
  variant: 'primary',
  type: 'button',
  disabled: false,
  loading: false,
  loadingLabel: 'Loading',
})

const buttonElement = ref<HTMLButtonElement | null>(null)

defineExpose({
  focus: () => buttonElement.value?.focus(),
})
</script>
