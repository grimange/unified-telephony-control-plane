<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { identityApi, type HostMaintenance, type KubernetesHost } from '../api/platform'
import { apiErrorMessage, can } from '../state/appState'

const hosts = ref<KubernetesHost[]>([])
const loading = ref(true)
const error = ref('')
const maintenances = ref<HostMaintenance[]>([])
const canMaintain = can('platform.infrastructure.maintain')

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    hosts.value = (await identityApi.kubernetesHosts()).hosts
    maintenances.value = (await identityApi.hostMaintenances()).maintenances
  }
  catch (exception) { error.value = apiErrorMessage(exception) }
  finally { loading.value = false }
}

async function requestMaintenance(host: KubernetesHost): Promise<void> {
  try {
    await identityApi.requestHostMaintenance(host.uid)
    await load()
  } catch (exception) { error.value = apiErrorMessage(exception) }
}

function maintenanceFor(host: KubernetesHost): HostMaintenance | undefined {
  return maintenances.value.find((maintenance) => maintenance.node_uid === host.uid)
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
        <p v-if="host.runtime_nodes.length > 0">
          Active telephony work: {{ host.runtime_nodes.reduce((total, node) => total + node.active_telephony_work, 0) }}
        </p>
        <p
          v-if="maintenanceFor(host)"
          class="meta"
        >
          Maintenance: {{ maintenanceFor(host)?.phase }}
          <span v-if="maintenanceFor(host)?.failure_code">({{ maintenanceFor(host)?.failure_code }})</span>
        </p>
        <button
          v-if="canMaintain && !maintenanceFor(host)"
          type="button"
          @click="requestMaintenance(host)"
        >
          Prepare for maintenance
        </button>
      </article>
    </div>
  </section>
</template>
