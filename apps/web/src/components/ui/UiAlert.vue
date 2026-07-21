<template>
  <div
    class="ui-alert"
    :class="[`ui-alert--${variant}`]"
    :role="variant === 'error' ? 'alert' : 'status'"
    :aria-live="variant === 'error' ? 'assertive' : 'polite'"
  >
    <strong>{{ title }}</strong>
    <p v-if="$slots.default">
      <slot />
    </p>
  </div>
</template>

<script setup lang="ts">
withDefaults(defineProps<{
  variant?: 'info' | 'success' | 'warning' | 'error'
  title: string
}>(), {
  variant: 'info',
})
</script>
