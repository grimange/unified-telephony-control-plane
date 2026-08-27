<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { createPlatformApiClient, type LivenessResponse, type PlatformApiClient, type ReadinessResponse, type VersionResponse } from '../api/platform'
import UiAlert from './ui/UiAlert.vue'
import UiButton from './ui/UiButton.vue'
import UiLoadingState from './ui/UiLoadingState.vue'
import UiPanel from './ui/UiPanel.vue'
import UiStatusBadge from './ui/UiStatusBadge.vue'

const props = withDefaults(defineProps<{ client?: PlatformApiClient }>(), {
  client: () => createPlatformApiClient(),
})

type ResourceState<T> = { status: 'loading' | 'success' | 'error'; value: T | null }
const liveness = ref<ResourceState<LivenessResponse>>({ status: 'loading', value: null })
const readiness = ref<ResourceState<ReadinessResponse>>({ status: 'loading', value: null })
const version = ref<ResourceState<VersionResponse>>({ status: 'loading', value: null })
const refreshing = ref(false)
const dependencyEntries = computed(() => Object.entries(readiness.value.value?.dependencies ?? {}))

async function loadResource<T>(resource: { value: ResourceState<T> }, request: () => Promise<T>): Promise<void> {
  resource.value.status = 'loading'
  resource.value.value = null
  try {
    resource.value.value = await request()
    resource.value.status = 'success'
  } catch {
    resource.value.status = 'error'
  }
}

async function loadStatus(): Promise<void> {
  refreshing.value = true
  await Promise.all([
    loadResource(liveness, props.client.getLiveness),
    loadResource(readiness, props.client.getReadiness),
    loadResource(version, props.client.getVersion),
  ])
  refreshing.value = false
}

function statusCategory(status: string | undefined): 'neutral' | 'success' | 'warning' | 'danger' {
  if (status === 'ok' || status === 'ready') return 'success'
  if (status === 'not_ready') return 'warning'
  return 'neutral'
}

onMounted(loadStatus)
</script>

<template>
  <section
    class="workspace system-status"
    aria-labelledby="system-status-title"
  >
    <div class="section-heading">
      <div>
        <h2 id="system-status-title">
          System status
        </h2>
        <p class="meta">
          Inspect control-plane health and version evidence. These checks do not represent every telephony dependency.
        </p>
      </div>
      <UiButton
        type="button"
        variant="secondary"
        :loading="refreshing"
        loading-label="Refreshing"
        @click="loadStatus"
      >
        Refresh
      </UiButton>
    </div>

    <div class="status-grid">
      <UiPanel
        title="API liveness"
        label="Health check"
        description="The liveness endpoint reports whether the API process responds."
      >
        <UiLoadingState
          v-if="liveness.status === 'loading'"
          label="Checking API liveness."
        />
        <UiAlert
          v-else-if="liveness.status === 'error'"
          variant="error"
          title="Liveness unavailable"
        >
          The canonical liveness endpoint could not be read.
        </UiAlert>
        <div
          v-else
          class="status-fact"
        >
          <UiStatusBadge
            :label="liveness.value?.status === 'ok' ? 'API live' : 'Unavailable'"
            :category="statusCategory(liveness.value?.status)"
          />
          <span class="meta">Service {{ liveness.value?.service }}</span>
        </div>
      </UiPanel>

      <UiPanel
        title="API readiness"
        label="Health check"
        description="The readiness endpoint reports the API contract and its declared dependencies."
      >
        <UiLoadingState
          v-if="readiness.status === 'loading'"
          label="Checking API readiness."
        />
        <UiAlert
          v-else-if="readiness.status === 'error'"
          variant="error"
          title="Readiness unavailable"
        >
          The canonical readiness endpoint could not be read.
        </UiAlert>
        <div
          v-else
          class="status-fact"
        >
          <UiStatusBadge
            :label="readiness.value?.status === 'ready' ? 'API ready' : 'API not ready'"
            :category="statusCategory(readiness.value?.status)"
          />
          <span class="meta">Status {{ readiness.value?.status }}</span>
          <ul
            v-if="dependencyEntries.length"
            class="dependencies"
            aria-label="Readiness dependencies"
          >
            <li
              v-for="[name, dependencyStatus] in dependencyEntries"
              :key="name"
            >
              <span>{{ name }}</span><strong>{{ dependencyStatus }}</strong>
            </li>
          </ul>
        </div>
      </UiPanel>

      <UiPanel
        title="API version"
        label="Build evidence"
        description="Version information returned by the running API."
      >
        <UiLoadingState
          v-if="version.status === 'loading'"
          label="Loading API version."
        />
        <UiAlert
          v-else-if="version.status === 'error'"
          variant="error"
          title="Version unavailable"
        >
          The canonical version endpoint could not be read.
        </UiAlert>
        <dl
          v-else
          class="status-details"
        >
          <div><dt>Version</dt><dd>{{ version.value?.version }}</dd></div>
          <div><dt>Service</dt><dd>{{ version.value?.service }}</dd></div>
          <div><dt>Commit</dt><dd>{{ version.value?.commit }}</dd></div>
          <div><dt>Built</dt><dd>{{ version.value?.built_at }}</dd></div>
        </dl>
      </UiPanel>
    </div>

    <UiPanel
      title="Health semantics"
      label="Operator context"
    >
      <p class="meta">
        Liveness and readiness describe the API health contracts only. Readiness is not a claim that External Trunks, Telephony Nodes, routes, or call paths are healthy.
      </p>
    </UiPanel>
  </section>
</template>

<style scoped>
.status-fact { display: grid; gap: 0.75rem; }
.status-details { display: grid; gap: 0.65rem; margin: 0; }
.status-details div { display: flex; justify-content: space-between; gap: 1rem; }
.status-details dt { color: var(--color-text-muted); }
.status-details dd { margin: 0; overflow-wrap: anywhere; text-align: right; }
.dependencies { display: grid; gap: 0.35rem; margin: 0; padding-left: 1.1rem; }
.dependencies li { display: flex; justify-content: space-between; gap: 1rem; }
</style>
