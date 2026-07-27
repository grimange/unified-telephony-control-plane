<template>
  <button
    ref="buttonElement"
    class="ui-button"
    :class="[`ui-button--${variant}`]"
    :type="type"
    :disabled="disabled"
    :aria-disabled="loading && !disabled ? 'true' : undefined"
    :aria-busy="loading ? 'true' : undefined"
    @click="handleClick"
    @keydown.enter="handleLoadingKeydown"
    @keydown.space="handleLoadingKeydown"
  >
    <span><slot /></span>
    <span v-if="loading"> {{ loadingLabel }}</span>
  </button>
</template>

<script setup lang="ts">
import { ref } from 'vue'

const props = withDefaults(defineProps<{
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
const emit = defineEmits<{
  click: [event: MouseEvent]
}>()

function cancelLoadingActivation(event: Event): void {
  event.preventDefault()
  event.stopPropagation()
  if ('stopImmediatePropagation' in event) event.stopImmediatePropagation()
}

function handleClick(event: MouseEvent): void {
  if (props.loading) {
    cancelLoadingActivation(event)
    return
  }

  emit('click', event)
}

function handleLoadingKeydown(event: KeyboardEvent): void {
  if (!props.loading) return
  cancelLoadingActivation(event)
}

defineExpose({
  focus: () => buttonElement.value?.focus(),
})
</script>
