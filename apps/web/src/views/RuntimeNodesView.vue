<template>
  <section
    class="workspace"
    aria-labelledby="runtime-nodes-title"
  >
    <div class="section-heading">
      <h2 id="runtime-nodes-title">
        Runtime nodes
      </h2>
      <button
        type="button"
        @click="load"
      >
        Refresh
      </button>
    </div>
    <form
      v-if="can('runtime.nodes.manage')"
      class="inline-form"
      @submit.prevent="run(createRuntimeNode)"
    >
      <input
        v-model="runtimeNodeForm.name"
        placeholder="Runtime display name"
        required
      >
      <input
        v-model="runtimeNodeForm.slug"
        placeholder="runtime-slug"
        required
      >
      <select v-model="runtimeNodeForm.runtimeFamily">
        <option
          v-for="family in runtimeFamilyOptions"
          :key="family.key"
          :value="family.key"
        >
          {{ family.label }}
        </option>
      </select>
      <select v-model="runtimeNodeForm.adapterKey">
        <option
          v-for="adapter in adapterOptionsFor(runtimeNodeForm.runtimeFamily)"
          :key="adapter.key"
          :value="adapter.key"
        >
          {{ adapter.label }}
        </option>
      </select>
      <button type="submit">
        Create runtime node
      </button>
    </form>
    <p
      v-if="loading"
      class="meta"
      role="status"
      aria-live="polite"
    >
      Loading runtime nodes.
    </p>
    <p
      v-else-if="runtimeNodes.length === 0"
      class="meta"
    >
      No RuntimeNodes were returned.
    </p>
    <div
      v-else
      class="data-table"
    >
      <div
        v-for="node in runtimeNodes"
        :key="node.id"
        class="data-row runtime-row"
      >
        <span>
          <strong>{{ node.name }}</strong>
          <small>{{ node.slug }} · {{ node.runtime_family }} · {{ node.adapter_key }}</small>
          <small>desired {{ node.desired_state }} · observed {{ node.observed_state }}</small>
        </span>
        <span class="row-actions">
          <button
            v-if="can('runtime.nodes.manage')"
            type="button"
            @click="run(() => setRuntimeDesiredState(node.id, node.desired_state === 'active' ? 'draining' : 'active'))"
          >
            {{ node.desired_state === 'active' ? 'Drain' : 'Activate' }}
          </button>
          <button
            v-if="can('runtime.nodes.manage')"
            type="button"
            @click="run(() => setRuntimeDesiredState(node.id, 'disabled'))"
          >
            Disable
          </button>
        </span>
        <div class="subgrid">
          <form
            v-if="can('runtime.nodes.manage')"
            class="inline-form"
            @submit.prevent="run(() => addRuntimeEndpoint(node.id))"
          >
            <select v-model="endpointForm.purpose">
              <option value="control">
                Control
              </option>
              <option value="events">
                Events
              </option>
              <option value="health">
                Health
              </option>
            </select>
            <select v-model="endpointForm.transport">
              <option value="https">
                HTTPS
              </option>
              <option value="wss">
                WSS
              </option>
              <option value="tcp">
                TCP
              </option>
            </select>
            <input
              v-model="endpointForm.host"
              placeholder="runtime.local.test"
              required
            >
            <input
              v-model.number="endpointForm.port"
              type="number"
              min="1"
              max="65535"
              required
            >
            <input
              v-model="endpointForm.path"
              placeholder="/optional-path"
            >
            <button type="submit">
              Add endpoint
            </button>
          </form>
          <div>
            <strong>Endpoints</strong>
            <p
              v-for="endpoint in node.endpoints"
              :key="endpoint.id"
              class="meta"
            >
              {{ endpoint.purpose }} {{ endpoint.transport }}://{{ endpoint.host }}:{{ endpoint.port }}{{ endpoint.path ?? '' }}
              <button
                v-if="can('runtime.nodes.manage')"
                type="button"
                @click="run(() => removeRuntimeEndpoint(node.id, endpoint.id))"
              >
                Remove
              </button>
            </p>
          </div>
          <form
            v-if="can('runtime.nodes.manage')"
            class="inline-form"
            @submit.prevent="run(() => setRuntimeCapabilities(node.id))"
          >
            <label
              v-for="capability in capabilityOptionsFor(node)"
              :key="capability"
              class="check-label"
            >
              <input
                v-model="runtimeCapabilitySelections[node.id]"
                type="checkbox"
                :value="capability"
              >
              {{ capabilityLabel(capability) }}
            </label>
            <button type="submit">
              Set capabilities
            </button>
          </form>
          <div>
            <strong>Declared capabilities</strong>
            <p class="meta">
              {{ node.capabilities.join(', ') || 'None' }}
            </p>
          </div>
          <form
            v-if="can('runtime.credentials.rotate')"
            class="inline-form"
            @submit.prevent="run(() => createRuntimeCredential(node.id))"
          >
            <input
              v-model="credentialForm.type"
              placeholder="control-api"
              required
            >
            <input
              v-model="credentialForm.identifier"
              placeholder="identifier"
            >
            <input
              v-model="credentialForm.secret"
              type="password"
              placeholder="Write-only secret"
              required
            >
            <button type="submit">
              Save credential
            </button>
          </form>
          <div>
            <strong>Credentials</strong>
            <p
              v-for="credential in node.credentials"
              :key="credential.id"
              class="meta"
            >
              {{ credential.type }} v{{ credential.version }} · {{ credential.status }} · fingerprint {{ credential.fingerprint.slice(0, 12) }}
              <button
                v-if="can('runtime.credentials.rotate')"
                type="button"
                @click="run(() => rotateRuntimeCredential(node.id, credential.id))"
              >
                Rotate
              </button>
              <button
                v-if="can('runtime.credentials.rotate') && canRetireCredential(node, credential)"
                type="button"
                @click="run(() => retireRuntimeCredential(node.id, credential.id))"
              >
                Retire
              </button>
            </p>
            <p class="meta">
              Secrets are write-only and cannot be retrieved after submission.
            </p>
          </div>
          <form
            v-if="can('runtime.nodes.manage') && adapterConfigurationSupported(node) && node.adapter_key === 'asterisk-ari'"
            class="inline-form"
            @submit.prevent="run(() => saveAsteriskAdapterConfiguration(node.id))"
          >
            <input
              v-model="asteriskConfigurationForm(node.id).application_name"
              placeholder="ARI application name"
              required
            >
            <input
              v-model.number="asteriskConfigurationForm(node.id).connect_timeout_ms"
              aria-label="Connect timeout"
              type="number"
              min="250"
              required
            >
            <input
              v-model.number="asteriskConfigurationForm(node.id).request_timeout_ms"
              aria-label="Request timeout"
              type="number"
              min="250"
              required
            >
            <input
              v-model.number="asteriskConfigurationForm(node.id).websocket_handshake_timeout_ms"
              aria-label="WebSocket handshake timeout"
              type="number"
              min="250"
              required
            >
            <input
              v-model.number="asteriskConfigurationForm(node.id).heartbeat_interval_ms"
              aria-label="Heartbeat interval"
              type="number"
              min="1000"
              required
            >
            <input
              v-model.number="asteriskConfigurationForm(node.id).reconnect_min_delay_ms"
              aria-label="Minimum reconnect delay"
              type="number"
              min="100"
              required
            >
            <input
              v-model.number="asteriskConfigurationForm(node.id).reconnect_max_delay_ms"
              aria-label="Maximum reconnect delay"
              type="number"
              min="100"
              required
            >
            <button type="submit">
              Save adapter configuration
            </button>
          </form>
          <div v-if="runtimeEvidence[node.id]">
            <strong>Runtime evidence</strong>
            <p class="meta">
              Desired state: {{ runtimeEvidence[node.id].desired_state }} · Observed state: {{ runtimeEvidence[node.id].observed_state }}
            </p>
            <p class="meta">
              Last observation: {{ displayValue(runtimeEvidence[node.id].observed_at) }}
            </p>
            <p class="meta">
              Configuration generation: {{ runtimeEvidence[node.id].desired_configuration_generation }} · Observed generation: {{ displayValue(runtimeEvidence[node.id].observed_configuration_generation) }}
            </p>
            <p class="meta">
              Event connection status: {{ runtimeEvidence[node.id].connection.state }} · Latest connection time: {{ displayValue(runtimeEvidence[node.id].connection.latest_epoch_opened_at) }} · Latest disconnect time: {{ displayValue(runtimeEvidence[node.id].connection.latest_epoch_closed_at) }}
            </p>
            <p class="meta">
              Reconciliation state: {{ runtimeEvidence[node.id].reconciliation.state }} · Next retry: {{ displayValue(runtimeEvidence[node.id].reconciliation.next_retry_at) }}
            </p>
            <p class="meta">
              Sanitized failure: {{ displayValue(runtimeEvidence[node.id].reconciliation.sanitized_failure_code ?? runtimeEvidence[node.id].reconciliation.sanitized_failure_class) }}
            </p>
            <p class="meta">
              Last successful inspection: {{ displayValue(runtimeEvidence[node.id].inspection.last_success_at) }}
            </p>
          </div>
          <div v-if="runtimeHistory[node.id]?.history.length">
            <strong>History</strong>
            <p
              v-for="entry in runtimeHistory[node.id].history"
              :key="entry.id"
              class="meta"
            >
              {{ entry.timestamp }} · {{ entry.action }} · {{ entry.actor }} · {{ entry.summary }}
            </p>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import {
  adapterConfigurationSupported,
  adapterOptionsFor,
  addRuntimeEndpoint,
  asteriskConfigurationForm,
  can,
  canRetireCredential,
  capabilityLabel,
  capabilityOptionsFor,
  createRuntimeCredential,
  createRuntimeNode,
  credentialForm,
  displayValue,
  endpointForm,
  fail,
  refreshRuntimeNodes,
  removeRuntimeEndpoint,
  retireRuntimeCredential,
  rotateRuntimeCredential,
  runtimeCapabilitySelections,
  runtimeEvidence,
  runtimeFamilyOptions,
  runtimeHistory,
  runtimeNodeForm,
  runtimeNodes,
  saveAsteriskAdapterConfiguration,
  setRuntimeCapabilities,
  setRuntimeDesiredState,
  tenantContextVersion,
} from '../state/appState'

const loading = ref(false)

async function run(action: () => Promise<void>): Promise<void> {
  try {
    await action()
  } catch (errorValue) {
    fail(errorValue)
  }
}

async function load(): Promise<void> {
  loading.value = true
  await run(refreshRuntimeNodes)
  loading.value = false
}

onMounted(load)
watch(tenantContextVersion, load)
</script>
