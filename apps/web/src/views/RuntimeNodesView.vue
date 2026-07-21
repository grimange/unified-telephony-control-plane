<template>
  <section
    class="workspace"
    aria-labelledby="runtime-nodes-title"
  >
    <div class="section-heading">
      <h2 id="runtime-nodes-title">
        Runtime nodes
      </h2>
      <UiButton
        type="button"
        variant="secondary"
        @click="load"
      >
        Refresh
      </UiButton>
    </div>

    <UiPanel
      v-if="can('runtime.nodes.manage')"
      title="Create runtime node"
      label="Runtime registry"
    >
      <form
        class="inline-form"
        @submit.prevent="run(createRuntimeNode)"
      >
        <UiFormField
          id="runtime-name"
          label="Display name"
          required
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="runtimeNodeForm.name"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="Runtime display name"
              required
            />
          </template>
        </UiFormField>
        <UiFormField
          id="runtime-slug"
          label="Slug"
          required
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="runtimeNodeForm.slug"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="runtime-slug"
              required
            />
          </template>
        </UiFormField>
        <UiFormField
          id="runtime-family"
          label="Runtime family"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiSelect
              :id="id"
              v-model="runtimeNodeForm.runtimeFamily"
              :aria-describedby="describedBy"
              :invalid="invalid"
            >
              <option
                v-for="family in runtimeFamilyOptions"
                :key="family.key"
                :value="family.key"
              >
                {{ family.label }}
              </option>
            </UiSelect>
          </template>
        </UiFormField>
        <UiFormField
          id="runtime-adapter"
          label="Adapter"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiSelect
              :id="id"
              v-model="runtimeNodeForm.adapterKey"
              :aria-describedby="describedBy"
              :invalid="invalid"
            >
              <option
                v-for="adapter in adapterOptionsFor(runtimeNodeForm.runtimeFamily)"
                :key="adapter.key"
                :value="adapter.key"
              >
                {{ adapter.label }}
              </option>
            </UiSelect>
          </template>
        </UiFormField>
        <UiButton type="submit">
          Create runtime node
        </UiButton>
      </form>
    </UiPanel>

    <UiLoadingState
      v-if="loading"
      label="Loading runtime nodes."
    />
    <UiEmptyState
      v-else-if="runtimeNodes.length === 0"
      title="No RuntimeNodes"
      message="No RuntimeNodes were returned."
    />
    <UiPanel
      v-else
      title="RuntimeNode list"
      label="Runtime registry"
    >
      <div class="data-table">
        <div
          v-for="node in runtimeNodes"
          :key="node.id"
          class="data-row runtime-row"
        >
          <span>
            <strong>{{ node.name }}</strong>
            <small>{{ node.slug }} · {{ node.runtime_family }} · {{ node.adapter_key }}</small>
            <span class="badge-row">
              <UiStatusBadge
                :label="`desired ${node.desired_state}`"
                :category="runtimeStatusCategory(node.desired_state)"
              />
              <UiStatusBadge
                :label="`observed ${node.observed_state}`"
                :category="runtimeStatusCategory(node.observed_state)"
              />
            </span>
          </span>
          <span class="row-actions">
            <UiButton
              v-if="can('runtime.nodes.manage')"
              type="button"
              variant="secondary"
              @click="run(() => setRuntimeDesiredState(node.id, node.desired_state === 'active' ? 'draining' : 'active'))"
            >
              {{ node.desired_state === 'active' ? 'Drain' : 'Activate' }}
            </UiButton>
            <UiButton
              v-if="can('runtime.nodes.manage')"
              type="button"
              variant="danger"
              @click="run(() => setRuntimeDesiredState(node.id, 'disabled'))"
            >
              Disable
            </UiButton>
          </span>
          <div class="subgrid">
            <form
              v-if="can('runtime.nodes.manage')"
              class="inline-form"
              @submit.prevent="run(() => addRuntimeEndpoint(node.id))"
            >
              <UiFormField
                id="endpoint-purpose"
                label="Purpose"
              >
                <template #default="{ id, describedBy, invalid }">
                  <UiSelect
                    :id="id"
                    v-model="endpointForm.purpose"
                    :aria-describedby="describedBy"
                    :invalid="invalid"
                  >
                    <option value="control">
                      Control
                    </option>
                    <option value="events">
                      Events
                    </option>
                    <option value="health">
                      Health
                    </option>
                  </UiSelect>
                </template>
              </UiFormField>
              <UiFormField
                id="endpoint-transport"
                label="Transport"
              >
                <template #default="{ id, describedBy, invalid }">
                  <UiSelect
                    :id="id"
                    v-model="endpointForm.transport"
                    :aria-describedby="describedBy"
                    :invalid="invalid"
                  >
                    <option value="https">
                      HTTPS
                    </option>
                    <option value="wss">
                      WSS
                    </option>
                    <option value="tcp">
                      TCP
                    </option>
                  </UiSelect>
                </template>
              </UiFormField>
              <UiFormField
                id="endpoint-host"
                label="Host"
                required
              >
                <template #default="{ id, describedBy, invalid }">
                  <UiTextInput
                    :id="id"
                    v-model="endpointForm.host"
                    :aria-describedby="describedBy"
                    :invalid="invalid"
                    autocomplete="off"
                    placeholder="runtime.local.test"
                    required
                  />
                </template>
              </UiFormField>
              <UiFormField
                id="endpoint-port"
                label="Port"
                required
              >
                <template #default="{ id, describedBy, invalid }">
                  <UiTextInput
                    :id="id"
                    v-model.number="endpointForm.port"
                    :aria-describedby="describedBy"
                    :invalid="invalid"
                    type="number"
                    min="1"
                    max="65535"
                    required
                  />
                </template>
              </UiFormField>
              <UiFormField
                id="endpoint-path"
                label="Path"
              >
                <template #default="{ id, describedBy, invalid }">
                  <UiTextInput
                    :id="id"
                    v-model="endpointForm.path"
                    :aria-describedby="describedBy"
                    :invalid="invalid"
                    autocomplete="off"
                    placeholder="/optional-path"
                  />
                </template>
              </UiFormField>
              <UiButton type="submit">
                Add endpoint
              </UiButton>
            </form>
            <div>
              <strong>Endpoints</strong>
              <p
                v-for="endpoint in node.endpoints"
                :key="endpoint.id"
                class="meta inline-record"
              >
                <span>{{ endpoint.purpose }} {{ endpoint.transport }}://{{ endpoint.host }}:{{ endpoint.port }}{{ endpoint.path ?? '' }}</span>
                <UiButton
                  v-if="can('runtime.nodes.manage')"
                  type="button"
                  variant="ghost"
                  @click="run(() => removeRuntimeEndpoint(node.id, endpoint.id))"
                >
                  Remove
                </UiButton>
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
              <UiButton type="submit">
                Set capabilities
              </UiButton>
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
              <UiFormField
                id="credential-type"
                label="Credential type"
                required
              >
                <template #default="{ id, describedBy, invalid }">
                  <UiTextInput
                    :id="id"
                    v-model="credentialForm.type"
                    :aria-describedby="describedBy"
                    :invalid="invalid"
                    autocomplete="off"
                    placeholder="control-api"
                    required
                  />
                </template>
              </UiFormField>
              <UiFormField
                id="credential-identifier"
                label="Identifier"
              >
                <template #default="{ id, describedBy, invalid }">
                  <UiTextInput
                    :id="id"
                    v-model="credentialForm.identifier"
                    :aria-describedby="describedBy"
                    :invalid="invalid"
                    autocomplete="off"
                    placeholder="identifier"
                  />
                </template>
              </UiFormField>
              <UiFormField
                id="credential-secret"
                label="Write-only secret"
                help="Secrets are submitted once and cannot be retrieved after submission."
                required
              >
                <template #default="{ id, describedBy, invalid }">
                  <UiTextInput
                    :id="id"
                    v-model="credentialForm.secret"
                    :aria-describedby="describedBy"
                    :invalid="invalid"
                    autocomplete="new-password"
                    type="password"
                    placeholder="Write-only secret"
                    required
                  />
                </template>
              </UiFormField>
              <UiButton type="submit">
                Save credential
              </UiButton>
            </form>
            <div>
              <strong>Credentials</strong>
              <p
                v-for="credential in node.credentials"
                :key="credential.id"
                class="meta inline-record"
              >
                <span>{{ credential.type }} v{{ credential.version }} · {{ credential.status }} · fingerprint {{ credential.fingerprint.slice(0, 12) }}</span>
                <UiButton
                  v-if="can('runtime.credentials.rotate')"
                  type="button"
                  variant="ghost"
                  @click="run(() => rotateRuntimeCredential(node.id, credential.id))"
                >
                  Rotate
                </UiButton>
                <UiButton
                  v-if="can('runtime.credentials.rotate') && canRetireCredential(node, credential)"
                  type="button"
                  variant="danger"
                  @click="run(() => retireRuntimeCredential(node.id, credential.id))"
                >
                  Retire
                </UiButton>
              </p>
              <UiAlert
                variant="info"
                title="Write-only credentials"
              >
                Secrets are write-only and cannot be retrieved after submission.
              </UiAlert>
            </div>
            <form
              v-if="can('runtime.nodes.manage') && adapterConfigurationSupported(node) && node.adapter_key === 'asterisk-ari'"
              class="inline-form"
              @submit.prevent="run(() => saveAsteriskAdapterConfiguration(node.id))"
            >
              <UiFormField
                id="asterisk-application-name"
                label="ARI application name"
                required
              >
                <template #default="{ id, describedBy, invalid }">
                  <UiTextInput
                    :id="id"
                    :model-value="asteriskConfigValue(node.id, 'application_name')"
                    :aria-describedby="describedBy"
                    :invalid="invalid"
                    autocomplete="off"
                    placeholder="ARI application name"
                    required
                    @update:model-value="setAsteriskConfigValue(node.id, 'application_name', $event)"
                  />
                </template>
              </UiFormField>
              <UiFormField
                v-for="field in asteriskNumberFields"
                :id="`asterisk-${field.key}`"
                :key="field.key"
                :label="field.label"
                required
              >
                <template #default="{ id, describedBy, invalid }">
                  <UiTextInput
                    :id="id"
                    :model-value="asteriskConfigValue(node.id, field.key)"
                    :aria-describedby="describedBy"
                    :invalid="invalid"
                    type="number"
                    :min="field.min"
                    required
                    @update:model-value="setAsteriskConfigValue(node.id, field.key, Number($event))"
                  />
                </template>
              </UiFormField>
              <UiButton type="submit">
                Save adapter configuration
              </UiButton>
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
    </UiPanel>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiEmptyState from '../components/ui/UiEmptyState.vue'
import UiFormField from '../components/ui/UiFormField.vue'
import UiLoadingState from '../components/ui/UiLoadingState.vue'
import UiPanel from '../components/ui/UiPanel.vue'
import UiSelect from '../components/ui/UiSelect.vue'
import UiStatusBadge from '../components/ui/UiStatusBadge.vue'
import UiTextInput from '../components/ui/UiTextInput.vue'
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

const asteriskNumberFields = [
  { key: 'connect_timeout_ms', label: 'Connect timeout', min: 250 },
  { key: 'request_timeout_ms', label: 'Request timeout', min: 250 },
  { key: 'websocket_handshake_timeout_ms', label: 'WebSocket handshake timeout', min: 250 },
  { key: 'heartbeat_interval_ms', label: 'Heartbeat interval', min: 1000 },
  { key: 'reconnect_min_delay_ms', label: 'Minimum reconnect delay', min: 100 },
  { key: 'reconnect_max_delay_ms', label: 'Maximum reconnect delay', min: 100 },
] as const

type AsteriskConfigKey = 'application_name' | typeof asteriskNumberFields[number]['key']

function asteriskConfigValue(runtimeNodeId: string, key: AsteriskConfigKey): string | number {
  const value = asteriskConfigurationForm(runtimeNodeId)[key]

  return typeof value === 'number' || typeof value === 'string' ? value : ''
}

function setAsteriskConfigValue(runtimeNodeId: string, key: AsteriskConfigKey, value: string | number): void {
  asteriskConfigurationForm(runtimeNodeId)[key] = value
}

function runtimeStatusCategory(status: string): 'success' | 'warning' | 'danger' | 'neutral' | 'information' {
  if (['active', 'ready', 'healthy', 'observed'].includes(status)) return 'success'
  if (['draft', 'draining', 'recovering', 'unobserved', 'degraded'].includes(status)) return 'warning'
  if (['failed', 'unavailable', 'disabled'].includes(status)) return 'danger'

  return 'neutral'
}

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
