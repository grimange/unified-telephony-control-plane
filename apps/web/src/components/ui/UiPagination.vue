<template>
  <nav
    class="ui-pagination"
    aria-label="Pagination"
  >
    <UiButton
      type="button"
      variant="secondary"
      :disabled="page <= 1"
      aria-label="Go to previous page"
      @click="$emit('previous')"
    >
      Previous
    </UiButton>
    <p
      class="meta"
      aria-live="polite"
      aria-atomic="true"
    >
      Page {{ page }}<span v-if="totalPages"> of {{ totalPages }}</span>
    </p>
    <UiButton
      type="button"
      variant="secondary"
      :disabled="!hasMore"
      aria-label="Go to next page"
      @click="$emit('next')"
    >
      Next
    </UiButton>
    <label
      v-if="pageSizeOptions.length > 1"
      class="ui-pagination__size"
    >
      <span>Rows per page</span>
      <select
        :value="perPage"
        @change="$emit('update:perPage', Number(($event.target as HTMLSelectElement).value))"
      >
        <option
          v-for="option in pageSizeOptions"
          :key="option"
          :value="option"
        >
          {{ option }}
        </option>
      </select>
    </label>
  </nav>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import UiButton from './UiButton.vue'

const props = withDefaults(defineProps<{
  page: number
  perPage: number
  total?: number
  hasMore?: boolean
  pageSizeOptions?: number[]
}>(), {
  total: undefined,
  hasMore: false,
  pageSizeOptions: () => [],
})

defineEmits<{
  previous: []
  next: []
  'update:perPage': [value: number]
}>()

const totalPages = computed(() => (
  props.total === undefined ? null : Math.max(1, Math.ceil(props.total / props.perPage))
))
</script>
