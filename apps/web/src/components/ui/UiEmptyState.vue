<template>
  <div class="ui-empty-state">
    <strong>{{ title }}</strong>
    <p>
      <slot>{{ message }}</slot>
    </p>
  </div>
</template>

<script setup lang="ts">
withDefaults(defineProps<{
  title?: string
  message?: string
}>(), {
  title: 'No results',
  message: 'No records were returned.',
})
</script>
