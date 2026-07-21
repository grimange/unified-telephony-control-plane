<template>
  <span
    class="ui-status-badge"
    :class="[`ui-status-badge--${category}`]"
  >
    {{ label }}
  </span>
</template>

<script setup lang="ts">
withDefaults(defineProps<{
  label: string
  category?: 'neutral' | 'success' | 'warning' | 'danger' | 'information'
}>(), {
  category: 'neutral',
})
</script>
