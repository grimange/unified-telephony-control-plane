<template>
  <UiPanel
    :title="title"
    :label="label"
  >
    <template #actions>
      <div class="dashboard-card__status">
        <strong v-if="state.status === 'success'">{{ state.countLabel }}</strong>
        <UiStatusBadge
          :label="statusLabel"
          :category="statusCategory"
        />
      </div>
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
import { computed } from 'vue'
import UiAlert from '../ui/UiAlert.vue'
import UiEmptyState from '../ui/UiEmptyState.vue'
import UiLoadingState from '../ui/UiLoadingState.vue'
import UiPanel from '../ui/UiPanel.vue'
import UiStatusBadge from '../ui/UiStatusBadge.vue'

export type DashboardCardState =
  | { status: 'loading' }
  | { status: 'empty'; emptyText: string }
  | { status: 'unauthorized' }
  | { status: 'failure'; message: string }
  | { status: 'success'; countLabel: string; emptyText: string; items: string[] }

const props = defineProps<{
  title: string
  label: string
  state: DashboardCardState
}>()

const statusLabel = computed(() => ({
  loading: 'Loading',
  success: 'Available',
  empty: 'Not configured',
  unauthorized: 'Restricted',
  failure: 'Unavailable',
}[props.state.status]))

const statusCategory = computed(() => ({
  loading: 'information',
  success: 'success',
  empty: 'neutral',
  unauthorized: 'neutral',
  failure: 'danger',
}[props.state.status] as 'neutral' | 'success' | 'danger' | 'information'))
</script>
