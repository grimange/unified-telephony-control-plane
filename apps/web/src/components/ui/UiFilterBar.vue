<template>
  <form
    class="ui-filter-bar"
    role="search"
    @submit.prevent="$emit('apply')"
    @reset.prevent="$emit('clear')"
  >
    <div class="ui-filter-bar__controls">
      <slot />
    </div>
    <div class="ui-filter-bar__actions">
      <UiButton type="submit">
        Apply
      </UiButton>
      <UiButton
        type="reset"
        variant="secondary"
      >
        Clear
      </UiButton>
    </div>
  </form>
</template>

<script setup lang="ts">
import UiButton from './UiButton.vue'

defineEmits<{
  apply: []
  clear: []
}>()
</script>
