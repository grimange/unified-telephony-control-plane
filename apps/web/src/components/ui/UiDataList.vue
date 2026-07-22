<template>
  <UiAlert
    v-if="status === 'forbidden'"
    variant="error"
    :title="forbiddenTitle"
  >
    {{ error }}
  </UiAlert>
  <UiAlert
    v-else-if="status === 'error'"
    variant="error"
    :title="errorTitle"
  >
    {{ error }}
  </UiAlert>
  <UiLoadingState
    v-if="(status === 'loading' || status === 'idle') && !hasData"
    :label="loadingLabel"
  />
  <UiEmptyState
    v-else-if="status === 'empty' && !hasData"
    :title="emptyTitle"
    :message="emptyMessage"
  />
  <UiPanel
    v-else-if="status === 'success' || status === 'refreshing' || hasData"
    :title="title"
    :label="label"
  >
    <template
      v-if="$slots.actions"
      #actions
    >
      <slot name="actions" />
    </template>
    <UiLoadingState
      v-if="status === 'refreshing'"
      :label="refreshingLabel"
    />
    <slot />
  </UiPanel>
</template>

<script setup lang="ts">
import type { AsyncResourceStatus } from '../../composables/asyncState'
import UiAlert from './UiAlert.vue'
import UiEmptyState from './UiEmptyState.vue'
import UiLoadingState from './UiLoadingState.vue'
import UiPanel from './UiPanel.vue'

withDefaults(defineProps<{
  status: AsyncResourceStatus
  error?: string
  title: string
  label?: string
  loadingLabel: string
  refreshingLabel: string
  emptyTitle: string
  emptyMessage: string
  errorTitle: string
  forbiddenTitle: string
  hasData?: boolean
}>(), {
  error: '',
  label: '',
  hasData: false,
})
</script>
