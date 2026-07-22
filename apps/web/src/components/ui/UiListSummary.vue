<template>
  <p class="ui-list-summary meta">
    <template v-if="typeof total === 'number'">
      Page {{ page }} · {{ total }} {{ itemLabel }}
    </template>
    <template v-else>
      {{ count }} {{ itemLabel }}
    </template>
  </p>
</template>

<script setup lang="ts">
withDefaults(defineProps<{
  page?: number
  total?: number
  count: number
  itemLabel: string
}>(), {
  page: 1,
  total: undefined,
})
</script>
