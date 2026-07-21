<template>
  <p
    class="ui-loading-state"
    role="status"
    aria-live="polite"
  >
    {{ label }}
  </p>
</template>

<script setup lang="ts">
withDefaults(defineProps<{
  label?: string
}>(), {
  label: 'Loading.',
})
</script>
