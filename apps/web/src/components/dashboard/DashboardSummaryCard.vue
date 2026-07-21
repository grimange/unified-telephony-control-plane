<template>
  <UiPanel
    :title="title"
    :label="label"
  >
    <template #actions>
      <strong v-if="state.status === 'success'">{{ state.countLabel }}</strong>
    </template>
    <UiLoadingState
      v-if="state.status === 'loading'"
    />
    <UiEmptyState
      v-else-if="state.status === 'empty'"
      title="No data"
      :message="state.emptyText"
    />
    <UiAlert
      v-else-if="state.status === 'unauthorized'"
      title="Not available"
    >
      Not available for the current session.
    </UiAlert>
    <UiAlert
      v-else-if="state.status === 'failure'"
      variant="error"
      title="Summary unavailable"
    >
      {{ state.message }}
    </UiAlert>
    <ul v-else-if="state.items.length > 0">
      <li
        v-for="item in state.items"
        :key="item"
      >
        {{ item }}
      </li>
    </ul>
    <UiEmptyState
      v-else
      title="No data"
      :message="state.emptyText"
    />
  </UiPanel>
</template>

<script setup lang="ts">
import UiAlert from '../ui/UiAlert.vue'
import UiEmptyState from '../ui/UiEmptyState.vue'
import UiLoadingState from '../ui/UiLoadingState.vue'
import UiPanel from '../ui/UiPanel.vue'

export type DashboardCardState =
  | { status: 'loading' }
  | { status: 'empty'; emptyText: string }
  | { status: 'unauthorized' }
  | { status: 'failure'; message: string }
  | { status: 'success'; countLabel: string; emptyText: string; items: string[] }

defineProps<{
  title: string
  label: string
  state: DashboardCardState
}>()
</script>
