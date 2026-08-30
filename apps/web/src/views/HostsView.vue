<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { identityApi, type KubernetesHost } from '../api/platform'

const hosts = ref<KubernetesHost[]>([])
const loading = ref(true)
const error = ref('')

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try { hosts.value = (await identityApi.kubernetesHosts()).hosts }
  catch { error.value = 'Kubernetes host observations are unavailable.' }
  finally { loading.value = false }
}

onMounted(load)
</script>

<template>
  <section class="page-stack">
    <div class="section-heading">
      <div>
        <p class="eyebrow">
          Infrastructure
        </p>
        <h2>Hosts</h2>
        <p class="meta">
          Status and placement are observed from Kubernetes.
        </p>
      </div>
      <button
        type="button"
        @click="load"
      >
        Refresh
      </button>
    </div>
    <p v-if="loading">
      Loading hosts…
    </p>
    <p
      v-else-if="error"
      role="alert"
    >
      {{ error }}
    </p>
    <p v-else-if="hosts.length === 0">
      No Kubernetes hosts were observed.
    </p>
    <div
      v-else
      class="card-grid"
    >
      <article
        v-for="host in hosts"
        :key="host.uid"
        class="card"
      >
        <h3>{{ host.name }}</h3>
        <p :class="host.ready ? 'status-positive' : 'status-negative'">
          {{ host.ready ? 'Ready' : 'NotReady' }}
        </p>
        <p>
          Addresses: {{ host.addresses.map((a) => `${a.type}: ${a.address}`).join(', ') || 'None' }}
        </p>
        <p>
          Runtime Nodes: {{ host.runtime_nodes.map((node) => node.name).join(', ') || 'None' }}
        </p>
        <p>
          Workloads: {{ host.workloads.length }}
        </p>
      </article>
    </div>
  </section>
</template>
