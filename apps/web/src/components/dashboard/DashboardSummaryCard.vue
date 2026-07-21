<template>
  <article class="dashboard-card">
    <header class="section-heading">
      <div>
        <p class="panel-label">
          {{ label }}
        </p>
        <h3>{{ title }}</h3>
      </div>
      <strong v-if="state.status === 'success'">{{ state.countLabel }}</strong>
    </header>
    <p
      v-if="state.status === 'loading'"
      class="meta"
      role="status"
      aria-live="polite"
    >
      Loading.
    </p>
    <p
      v-else-if="state.status === 'empty'"
      class="meta"
    >
      {{ state.emptyText }}
    </p>
    <p
      v-else-if="state.status === 'unauthorized'"
      class="meta"
    >
      Not available for the current session.
    </p>
    <p
      v-else-if="state.status === 'failure'"
      class="form-error"
      role="alert"
    >
      {{ state.message }}
    </p>
    <ul v-else-if="state.items.length > 0">
      <li
        v-for="item in state.items"
        :key="item"
      >
        {{ item }}
      </li>
    </ul>
    <p
      v-else
      class="meta"
    >
      {{ state.emptyText }}
    </p>
  </article>
</template>

<script setup lang="ts">
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
