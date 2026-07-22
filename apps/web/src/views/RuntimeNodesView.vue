<template>
  <section
    class="workspace"
    aria-labelledby="runtime-nodes-title"
  >
    <div class="section-heading">
      <h2 id="runtime-nodes-title">
        Runtime nodes
      </h2>
      <UiStatusBadge
        class="live-updates-badge"
        :label="runtimeNodeRealtimeStatusText()"
        :category="runtimeNodeRealtimeStatusCategory"
      />
      <UiButton
        type="button"
        variant="secondary"
        :loading="runtimeNodesResource.state.status === 'refreshing'"
        loading-label="Refreshing"
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
        @submit.prevent="runRuntimeAction(runtimeCreateActionKey, createRuntimeNode, 'RuntimeNode created.')"
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
        <UiButton
          type="submit"
          :loading="runtimeActionSubmitting(runtimeCreateActionKey)"
          loading-label="Creating"
        >
          Create runtime node
        </UiButton>
      </form>
    </UiPanel>

    <UiAlert
      v-if="runtimeActionError(runtimeCreateActionKey)"
      variant="error"
      title="RuntimeNode action failed"
    >
      {{ runtimeActionError(runtimeCreateActionKey) }}
    </UiAlert>
    <UiDataList
      :status="runtimeNodesResource.state.status"
      :error="runtimeNodesResource.state.error"
      :has-data="runtimeNodes.length > 0"
      title="RuntimeNode list"
      label="Runtime registry"
      loading-label="Loading runtime nodes."
      refreshing-label="Refreshing runtime nodes."
      empty-title="No RuntimeNodes"
      empty-message="No RuntimeNodes were returned."
      error-title="Runtime nodes unavailable"
      forbidden-title="Runtime nodes forbidden"
    >
      <template #actions>
        <UiListSummary
          :count="runtimeNodes.length"
          item-label="RuntimeNodes"
        />
      </template>
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
              type="button"
              variant="secondary"
              :loading="nodeDetailStatus(node.id) === 'loading'"
              loading-label="Loading details"
              @click="toggleNodeDetails(node)"
            >
              {{ isNodeExpanded(node.id) ? 'Hide details' : 'Details' }}
            </UiButton>
            <UiButton
              v-if="can('runtime.nodes.manage')"
              type="button"
              variant="secondary"
              :disabled="runtimeActionSubmitting(runtimeDesiredStateActionKey(node, node.desired_state === 'active' ? 'draining' : 'active'))"
              :loading="runtimeActionSubmitting(runtimeDesiredStateActionKey(node, node.desired_state === 'active' ? 'draining' : 'active'))"
              @click="runRuntimeAction(runtimeDesiredStateActionKey(node, node.desired_state === 'active' ? 'draining' : 'active'), () => setRuntimeDesiredState(node.id, node.desired_state === 'active' ? 'draining' : 'active'), 'RuntimeNode desired state updated.')"
            >
              {{ node.desired_state === 'active' ? 'Drain' : 'Activate' }}
            </UiButton>
            <UiButton
              v-if="can('runtime.nodes.manage')"
              type="button"
              variant="danger"
              :disabled="runtimeActionSubmitting(runtimeDesiredStateActionKey(node, 'disabled'))"
              :loading="runtimeActionSubmitting(runtimeDesiredStateActionKey(node, 'disabled'))"
              @click="runRuntimeAction(runtimeDesiredStateActionKey(node, 'disabled'), () => setRuntimeDesiredState(node.id, 'disabled'), 'RuntimeNode disabled.')"
            >
              Disable
            </UiButton>
          </span>
          <div
            v-if="isNodeExpanded(node.id)"
            class="subgrid"
          >
            <UiLoadingState
              v-if="nodeDetailStatus(node.id) === 'loading'"
              label="Loading runtime node details."
            />
            <UiAlert
              v-if="nodeDetailStatus(node.id) === 'error' || nodeDetailStatus(node.id) === 'forbidden'"
              variant="error"
              title="RuntimeNode details unavailable"
            >
              {{ runtimeNodeDetailStates[node.id]?.error }}
            </UiAlert>
            <form
              v-if="can('runtime.nodes.manage')"
              class="inline-form"
              @submit.prevent="runRuntimeAction(runtimeEndpointAddActionKey(node), () => addRuntimeEndpoint(node.id), 'RuntimeNode endpoint added.')"
            >
              <UiFormField
                :id="runtimeFieldId(node.id, 'endpoint-purpose')"
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
                :id="runtimeFieldId(node.id, 'endpoint-transport')"
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
                :id="runtimeFieldId(node.id, 'endpoint-host')"
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
                :id="runtimeFieldId(node.id, 'endpoint-port')"
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
                :id="runtimeFieldId(node.id, 'endpoint-path')"
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
              <UiButton
                type="submit"
                :loading="runtimeActionSubmitting(runtimeEndpointAddActionKey(node))"
                loading-label="Adding endpoint"
              >
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
                  :disabled="runtimeActionSubmitting(runtimeEndpointRemoveActionKey(node, endpoint.id))"
                  :loading="runtimeActionSubmitting(runtimeEndpointRemoveActionKey(node, endpoint.id))"
                  @click="runRuntimeAction(runtimeEndpointRemoveActionKey(node, endpoint.id), () => removeRuntimeEndpoint(node.id, endpoint.id), 'RuntimeNode endpoint removed.')"
                >
                  Remove
                </UiButton>
              </p>
            </div>
            <form
              v-if="can('runtime.nodes.manage')"
              class="inline-form"
              @submit.prevent="runRuntimeAction(runtimeCapabilitiesActionKey(node), () => setRuntimeCapabilities(node.id), 'RuntimeNode capabilities updated.')"
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
              <UiButton
                type="submit"
                :loading="runtimeActionSubmitting(runtimeCapabilitiesActionKey(node))"
                loading-label="Saving capabilities"
              >
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
              @submit.prevent="runRuntimeAction(runtimeCredentialCreateActionKey(node), () => createRuntimeCredential(node.id), 'RuntimeNode credential saved.')"
            >
              <UiFormField
                :id="runtimeFieldId(node.id, 'credential-type')"
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
                :id="runtimeFieldId(node.id, 'credential-identifier')"
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
                :id="runtimeFieldId(node.id, 'credential-secret')"
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
              <UiButton
                type="submit"
                :loading="runtimeActionSubmitting(runtimeCredentialCreateActionKey(node))"
                loading-label="Saving credential"
              >
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
                  :disabled="runtimeActionSubmitting(runtimeCredentialRotateActionKey(node, credential.id))"
                  :loading="runtimeActionSubmitting(runtimeCredentialRotateActionKey(node, credential.id))"
                  @click="runRuntimeAction(runtimeCredentialRotateActionKey(node, credential.id), () => rotateRuntimeCredential(node.id, credential.id), 'RuntimeNode credential rotated.')"
                >
                  Rotate
                </UiButton>
                <UiButton
                  v-if="can('runtime.credentials.rotate') && canRetireCredential(node, credential)"
                  type="button"
                  variant="danger"
                  :disabled="runtimeActionSubmitting(runtimeCredentialRetireActionKey(node, credential.id))"
                  :loading="runtimeActionSubmitting(runtimeCredentialRetireActionKey(node, credential.id))"
                  @click="runRuntimeAction(runtimeCredentialRetireActionKey(node, credential.id), () => retireRuntimeCredential(node.id, credential.id), 'RuntimeNode credential retired.')"
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
              v-if="can('runtime.nodes.manage') && adapterConfigurationSupported(node) && adapterConfigurationDescriptorsFor(node).length > 0"
              class="inline-form"
              @submit.prevent="runRuntimeAction(runtimeAdapterConfigurationActionKey(node), () => saveRuntimeAdapterConfiguration(node), 'RuntimeNode adapter configuration saved.')"
            >
              <RuntimeNodeCatalogField
                v-for="field in adapterConfigurationDescriptorsFor(node)"
                :key="field.key"
                :field="field"
                :runtime-node-id="node.id"
                :model-value="adapterConfigurationForm(node.id)[field.key]"
                :error="adapterConfigurationFieldError(node.id, field.key)"
                :disabled="runtimeActionSubmitting(runtimeAdapterConfigurationActionKey(node))"
                @update:model-value="setAdapterConfigurationFormValue(node.id, field.key, $event)"
              />
              <UiAlert
                v-if="unsupportedAdapterConfigurationFields(node).length > 0"
                :variant="adapterConfigurationSubmissionBlocked(node) ? 'error' : 'warning'"
                title="Unsupported adapter configuration"
              >
                {{ unsupportedAdapterConfigurationSummary(node) }}
              </UiAlert>
              <UiAlert
                v-if="runtimeActionError(runtimeAdapterConfigurationActionKey(node))"
                variant="error"
                title="Adapter configuration save failed"
              >
                {{ runtimeActionError(runtimeAdapterConfigurationActionKey(node)) }}
              </UiAlert>
              <UiButton
                type="submit"
                :disabled="adapterConfigurationSubmissionBlocked(node)"
                :loading="runtimeActionSubmitting(runtimeAdapterConfigurationActionKey(node))"
                loading-label="Saving adapter configuration"
              >
                Save adapter configuration
              </UiButton>
            </form>
            <UiAlert
              v-else-if="can('runtime.nodes.manage') && adapterConfigurationSupported(node)"
              variant="warning"
              title="Adapter configuration unavailable"
            >
              This adapter reports configuration support but did not provide writable field descriptors.
            </UiAlert>
            <UiAlert
              v-else-if="can('runtime.nodes.manage')"
              variant="info"
              title="Adapter configuration not available"
            >
              This adapter does not expose writable configuration fields.
            </UiAlert>
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
    </UiDataList>
  </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import RuntimeNodeCatalogField from '../components/runtime/RuntimeNodeCatalogField.vue'
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiDataList from '../components/ui/UiDataList.vue'
import UiFormField from '../components/ui/UiFormField.vue'
import UiListSummary from '../components/ui/UiListSummary.vue'
import UiLoadingState from '../components/ui/UiLoadingState.vue'
import UiPanel from '../components/ui/UiPanel.vue'
import UiSelect from '../components/ui/UiSelect.vue'
import UiStatusBadge from '../components/ui/UiStatusBadge.vue'
import UiTextInput from '../components/ui/UiTextInput.vue'
import { useAsyncAction, useAsyncActionMap, useAsyncResource } from '../composables/asyncState'
import { useListQueryState } from '../composables/listQueryState'
import type { RuntimeNode } from '../api/platform'
import { router } from '../router'
import {
  disconnectRuntimeNodeRealtime,
  resynchronizeRuntimeNodeRealtime,
  runtimeNodeRealtimeConnectionState,
  runtimeNodeRealtimeStatusText,
  subscribeRuntimeNodeRealtime,
} from '../realtime/runtimeNodeRealtime'
import { notify } from '../state/notifications'
import {
  adapterConfigurationSupported,
  adapterConfigurationDescriptorsFor,
  adapterConfigurationFieldError,
  adapterConfigurationForm,
  adapterConfigurationSubmissionBlocked,
  adapterOptionsFor,
  addRuntimeEndpoint,
  apiErrorMessage,
  can,
  canRetireCredential,
  capabilityLabel,
  capabilityOptionsFor,
  createRuntimeCredential,
  createRuntimeNode,
  credentialForm,
  displayValue,
  endpointForm,
  loadRuntimeNodeDetails,
  refreshRuntimeNodes,
  removeRuntimeEndpoint,
  retireRuntimeCredential,
  rotateRuntimeCredential,
  runtimeCapabilitySelections,
  runtimeEvidence,
  runtimeFamilyOptions,
  runtimeHistory,
  runtimeNodeDetailStates,
  runtimeNodeForm,
  runtimeNodes,
  saveRuntimeAdapterConfiguration,
  session,
  setRuntimeCapabilities,
  setAdapterConfigurationFormValue,
  setRuntimeDesiredState,
  tenantContextVersion,
  unsupportedAdapterConfigurationFields,
} from '../state/appState'

const expandedRuntimeNodeIds = ref<string[]>([])
useListQueryState(router, {})
const runtimeNodesResource = useAsyncResource(refreshRuntimeNodes, {
  isEmpty: () => runtimeNodes.value.length === 0,
  getErrorMessage: apiErrorMessage,
})
const detailAction = useAsyncAction(async (node: RuntimeNode) => loadRuntimeNodeDetails(node), {
  getErrorMessage: apiErrorMessage,
})
const runtimeActions = useAsyncActionMap<void>({
  getErrorMessage: apiErrorMessage,
})
const runtimeCreateActionKey = 'runtime-node:create'
let backgroundedAt = 0

const runtimeNodeRealtimeStatusCategory = computed((): 'success' | 'warning' | 'danger' | 'neutral' | 'information' => {
  if (runtimeNodeRealtimeConnectionState.value === 'connected') return 'success'
  if (runtimeNodeRealtimeConnectionState.value === 'connecting') return 'information'
  if (runtimeNodeRealtimeConnectionState.value === 'reconnecting') return 'warning'
  if (runtimeNodeRealtimeConnectionState.value === 'unauthorized') return 'warning'
  if (runtimeNodeRealtimeConnectionState.value === 'disconnected') return 'danger'

  return 'neutral'
})

function runtimeStatusCategory(status: string): 'success' | 'warning' | 'danger' | 'neutral' | 'information' {
  if (['active', 'ready', 'healthy', 'observed'].includes(status)) return 'success'
  if (['draft', 'draining', 'recovering', 'unobserved', 'degraded'].includes(status)) return 'warning'
  if (['failed', 'unavailable', 'disabled'].includes(status)) return 'danger'

  return 'neutral'
}

function safeDomId(value: string): string {
  return value.replace(/[^A-Za-z0-9_-]/g, '-')
}

function runtimeFieldId(runtimeNodeId: string, field: string): string {
  return `${field}-${safeDomId(runtimeNodeId)}`
}

function runtimeDesiredStateActionKey(node: RuntimeNode, desiredState: string): string {
  return `runtime-node:${node.id}:desired:${desiredState}`
}

function runtimeEndpointAddActionKey(node: RuntimeNode): string {
  return `runtime-node:${node.id}:endpoint:add`
}

function runtimeEndpointRemoveActionKey(node: RuntimeNode, endpointId: string): string {
  return `runtime-node:${node.id}:endpoint:${endpointId}:remove`
}

function runtimeCapabilitiesActionKey(node: RuntimeNode): string {
  return `runtime-node:${node.id}:capabilities`
}

function runtimeCredentialCreateActionKey(node: RuntimeNode): string {
  return `runtime-node:${node.id}:credential:create`
}

function runtimeCredentialRotateActionKey(node: RuntimeNode, credentialId: string): string {
  return `runtime-node:${node.id}:credential:${credentialId}:rotate`
}

function runtimeCredentialRetireActionKey(node: RuntimeNode, credentialId: string): string {
  return `runtime-node:${node.id}:credential:${credentialId}:retire`
}

function runtimeAdapterConfigurationActionKey(node: RuntimeNode): string {
  return `runtime-node:${node.id}:adapter-configuration`
}

function runtimeActionSubmitting(key: string): boolean {
  return runtimeActions.isSubmitting(key)
}

function runtimeActionError(key: string): string {
  const state = runtimeActions.stateFor(key)

  return state.status === 'failed' ? state.error : ''
}

function unsupportedAdapterConfigurationSummary(node: RuntimeNode): string {
  return unsupportedAdapterConfigurationFields(node)
    .map((field) => `${field.key} (${field.input_type})`)
    .join(', ')
}

function isNodeExpanded(runtimeNodeId: string): boolean {
  return expandedRuntimeNodeIds.value.includes(runtimeNodeId)
}

function nodeDetailStatus(runtimeNodeId: string): string {
  return runtimeNodeDetailStates[runtimeNodeId]?.status ?? 'idle'
}

async function toggleNodeDetails(node: RuntimeNode): Promise<void> {
  if (isNodeExpanded(node.id)) {
    expandedRuntimeNodeIds.value = expandedRuntimeNodeIds.value.filter((runtimeNodeId) => runtimeNodeId !== node.id)

    return
  }

  expandedRuntimeNodeIds.value = [...expandedRuntimeNodeIds.value, node.id]
  await detailAction.run(node)
  if (detailAction.state.status === 'failed') {
    notify({
      variant: 'error',
      title: 'RuntimeNode details unavailable',
      message: detailAction.state.error,
    })
  }
}

async function runRuntimeAction(key: string, action: () => Promise<void>, successMessage: string): Promise<void> {
  await runtimeActions.run(key, action)
  if (runtimeActions.stateFor(key).status === 'succeeded') {
    notify({
      variant: 'success',
      title: 'RuntimeNode updated',
      message: successMessage,
    })

    return
  }

  if (runtimeActions.stateFor(key).status === 'failed') {
    notify({
      variant: 'error',
      title: 'RuntimeNode action failed',
      message: runtimeActions.stateFor(key).error,
    })
  }
}

async function load(): Promise<void> {
  await runtimeNodesResource.load()
  subscribeAfterCanonicalSnapshot()
}

function subscribeAfterCanonicalSnapshot(): void {
  const activeTenantId = session.value?.active_tenant?.tenant_id ?? ''
  if (!session.value || activeTenantId === '' || !['success', 'empty'].includes(runtimeNodesResource.state.status)) {
    disconnectRuntimeNodeRealtime()

    return
  }

  subscribeRuntimeNodeRealtime({
    tenantId: activeTenantId,
    refreshList: async () => {
      await runtimeNodesResource.load()
    },
    refreshNodeDetails: async (runtimeNodeId: string) => {
      const node = runtimeNodes.value.find((candidate) => candidate.id === runtimeNodeId)
      if (node) await loadRuntimeNodeDetails(node, true)
    },
    openRuntimeNodeIds: () => [...expandedRuntimeNodeIds.value],
    sessionActive: () => session.value?.active_tenant?.tenant_id === activeTenantId,
  })
}

function handleVisibilityChange(): void {
  const browserDocument = globalThis.document
  if (!browserDocument) return

  if (browserDocument.visibilityState === 'hidden') {
    backgroundedAt = Date.now()

    return
  }

  if (backgroundedAt > 0 && Date.now() - backgroundedAt >= 5_000) {
    void resynchronizeRuntimeNodeRealtime()
  }
  backgroundedAt = 0
}

watch(
  tenantContextVersion,
  () => {
    expandedRuntimeNodeIds.value = []
    void load()
  },
  { immediate: true },
)

onMounted(() => {
  globalThis.document?.addEventListener('visibilitychange', handleVisibilityChange)
})

onBeforeUnmount(() => {
  globalThis.document?.removeEventListener('visibilitychange', handleVisibilityChange)
  disconnectRuntimeNodeRealtime()
})
</script>
