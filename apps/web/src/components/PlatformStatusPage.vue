<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { createPlatformApiClient, type PlatformApiClient, type ReadinessResponse, type VersionResponse } from '../api/platform'
import { frontendBuildInfo } from '../buildInfo'

const props = withDefaults(defineProps<{
  client?: PlatformApiClient
}>(), {
  client: () => createPlatformApiClient(),
})

type LoadState = 'loading' | 'healthy' | 'degraded' | 'unreachable'

const state = ref<LoadState>('loading')
const liveStatus = ref('checking')
const readiness = ref<ReadinessResponse | null>(null)
const backendVersion = ref<VersionResponse | null>(null)

const statusLabel = computed(() => {
  if (state.value === 'healthy') {
    return 'Healthy'
  }

  if (state.value === 'degraded') {
    return 'Degraded'
  }

  if (state.value === 'unreachable') {
    return 'Unreachable'
  }

  return 'Loading'
})

const dependencyEntries = computed(() => Object.entries(readiness.value?.dependencies ?? {}))

onMounted(async () => {
  try {
    const [live, ready, version] = await Promise.all([
      props.client.getLiveness(),
      props.client.getReadiness(),
      props.client.getVersion(),
    ])

    liveStatus.value = live.status
    readiness.value = ready
    backendVersion.value = version
    state.value = ready.status === 'ready' ? 'healthy' : 'degraded'
  } catch {
    liveStatus.value = 'unreachable'
    state.value = 'unreachable'
  }
})
</script>

<template>
  <main
    class="shell"
    aria-labelledby="page-title"
  >
    <section class="summary">
      <p class="eyebrow">
        Platform status
      </p>
      <h1 id="page-title">
        Unified Telephony Control Plane
      </h1>
      <p class="description">
        Vendor-neutral control-plane foundation for telephony applications, runtime readiness, and future deployment verification.
      </p>
    </section>

    <section
      class="status-grid"
      aria-label="Application status"
    >
      <div class="panel status-panel">
        <span class="panel-label">Overall</span>
        <strong :class="['state', `state-${state}`]">{{ statusLabel }}</strong>
      </div>

      <div class="panel">
        <span class="panel-label">Frontend version</span>
        <strong>{{ frontendBuildInfo.version }}</strong>
        <span class="meta">Commit {{ frontendBuildInfo.commit }} | Built {{ frontendBuildInfo.builtAt }}</span>
      </div>

      <div class="panel">
        <span class="panel-label">API liveness</span>
        <strong>{{ liveStatus }}</strong>
      </div>

      <div class="panel">
        <span class="panel-label">API readiness</span>
        <strong>{{ readiness?.status ?? 'checking' }}</strong>
        <ul
          v-if="dependencyEntries.length"
          class="dependencies"
          aria-label="Readiness dependencies"
        >
          <li
            v-for="[name, dependencyStatus] in dependencyEntries"
            :key="name"
          >
            <span>{{ name }}</span>
            <strong>{{ dependencyStatus }}</strong>
          </li>
        </ul>
        <span
          v-else
          class="meta"
        >No required dependencies configured for this phase.</span>
      </div>

      <div class="panel backend-panel">
        <span class="panel-label">Backend version</span>
        <strong>{{ backendVersion?.version ?? 'unknown' }}</strong>
        <span class="meta">
          Service {{ backendVersion?.service ?? 'utcp-api' }} | Commit {{ backendVersion?.commit ?? 'unknown' }} | Built
          {{ backendVersion?.built_at ?? 'unknown' }}
        </span>
      </div>
    </section>
  </main>
</template>
