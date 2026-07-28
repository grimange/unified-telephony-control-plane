<template>
  <section
    class="workspace runtime-operations"
    aria-labelledby="runtime-operations-title"
  >
    <div class="section-heading">
      <div>
        <h2 id="runtime-operations-title">
          Runtime operations
        </h2>
        <p class="meta">
          Track control-plane operations issued to telephony runtimes.
        </p>
      </div>
      <UiStatusBadge
        class="live-updates-badge"
        :label="runtimeNodeRealtimeStatusText()"
        :category="realtimeStatusCategory"
      />
      <UiButton
        type="button"
        variant="secondary"
        :loading="runtimeOperationsResource.state.status === 'refreshing'"
        loading-label="Refreshing"
        @click="loadRuntimeOperations"
      >
        Refresh
      </UiButton>
    </div>

    <UiPanel
      title="Filter runtime operations"
      label="Operations"
    >
      <UiFilterBar
        @apply="applyFilters"
        @clear="clearFilters"
      >
        <UiFormField
          id="runtime-operation-node-filter"
          label="Runtime node ID"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="filterDraft.runtimeNodeId"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="Runtime node identifier"
            />
          </template>
        </UiFormField>
        <UiFormField
          id="runtime-operation-status-filter"
          label="Status"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiSelect
              :id="id"
              v-model="filterDraft.status"
              :aria-describedby="describedBy"
              :invalid="invalid"
            >
              <option value="">
                Any status
              </option>
              <option
                v-for="status in runtimeOperationStatuses"
                :key="status"
                :value="status"
              >
                {{ statusLabel(status) }}
              </option>
            </UiSelect>
          </template>
        </UiFormField>
        <UiFormField
          id="runtime-operation-type-filter"
          label="Operation type"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="filterDraft.operationType"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="runtime.node.inspect"
            />
          </template>
        </UiFormField>
        <UiFormField
          id="runtime-operation-correlation-filter"
          label="Correlation ID"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="filterDraft.correlationId"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="Correlation identifier"
            />
          </template>
        </UiFormField>
        <UiFormField
          id="runtime-operation-created-from-filter"
          label="Created from"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="filterDraft.createdFrom"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="2026-07-23T10:00:00Z"
            />
          </template>
        </UiFormField>
        <UiFormField
          id="runtime-operation-created-to-filter"
          label="Created to"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="filterDraft.createdTo"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="2026-07-23T11:00:00Z"
            />
          </template>
        </UiFormField>
      </UiFilterBar>
    </UiPanel>

    <UiDataList
      :status="runtimeOperationsResource.state.status"
      :error="runtimeOperationsResource.state.error"
      :has-data="runtimeOperations.length > 0"
      title="Runtime operation list"
      label="Operations"
      loading-label="Loading runtime operations."
      refreshing-label="Refreshing runtime operations."
      empty-title="No runtime operations"
      empty-message="No runtime operations matched the current filters."
      error-title="Runtime operations unavailable"
      forbidden-title="Runtime operations forbidden"
    >
      <template #actions>
        <UiListSummary
          :page="runtimeOperationPagination.page"
          :total="runtimeOperationPagination.total"
          :count="runtimeOperations.length"
          item-label="runtime operations"
        />
      </template>
      <div class="data-table">
        <div
          v-for="operation in runtimeOperations"
          :key="operation.id"
          class="data-row operation-row"
        >
          <span>
            <strong>{{ shortId(operation.id) }}</strong>
            <small>{{ operation.operation_type }} · {{ runtimeNodeLabel(operation) }}</small>
            <span class="badge-row">
              <UiStatusBadge
                :label="statusLabel(operation.status)"
                :category="operationStatusCategory(operation.status)"
              />
              <UiStatusBadge
                :label="`attempt ${operation.attempt.count}/${operation.attempt.max}`"
                category="neutral"
              />
              <UiStatusBadge
                v-if="operation.priority !== null"
                :label="`priority ${operation.priority}`"
                category="information"
              />
            </span>
          </span>
          <span>
            <small>Created {{ displayValue(operation.created_at) }}</small>
            <small>Started {{ displayValue(operation.started_at) }}</small>
            <small>Completed {{ displayValue(operation.completed_at ?? operation.cancelled_at) }}</small>
            <small v-if="operation.correlation_id">Correlation {{ shortId(operation.correlation_id) }}</small>
            <small v-if="operation.failure">{{ operation.failure.summary }}</small>
          </span>
          <span class="row-actions">
            <UiButton
              type="button"
              variant="secondary"
              :loading="selectedRuntimeOperationId === operation.id && selectedRuntimeOperationResource.state.status === 'loading'"
              loading-label="Loading details"
              @click="selectRuntimeOperation(operation.id)"
            >
              {{ selectedRuntimeOperationId === operation.id ? 'Selected' : 'Details' }}
            </UiButton>
          </span>
          <div
            v-if="selectedRuntimeOperationId === operation.id"
            class="subgrid runtime-operation-detail-grid"
          >
            <UiLoadingState
              v-if="selectedRuntimeOperationResource.state.status === 'loading'"
              label="Loading runtime operation detail."
            />
            <UiAlert
              v-if="selectedRuntimeOperationResource.state.status === 'error' || selectedRuntimeOperationResource.state.status === 'forbidden'"
              variant="error"
              title="Runtime operation detail unavailable"
            >
              {{ selectedRuntimeOperationResource.state.error }}
            </UiAlert>
            <section
              v-if="selectedRuntimeOperation"
              class="detail-section"
              aria-label="Selected runtime operation detail"
            >
              <h3>{{ shortId(selectedRuntimeOperation.id) }}</h3>
              <dl class="definition-grid">
                <div>
                  <dt>Operation type</dt>
                  <dd>{{ selectedRuntimeOperation.operation_type }}</dd>
                </div>
                <div>
                  <dt>Status</dt>
                  <dd>{{ statusLabel(selectedRuntimeOperation.status) }}</dd>
                </div>
                <div>
                  <dt>Runtime node</dt>
                  <dd>{{ runtimeNodeLabel(selectedRuntimeOperation) }}</dd>
                </div>
                <div>
                  <dt>Aggregate</dt>
                  <dd>{{ selectedRuntimeOperation.aggregate.type }} · {{ shortId(selectedRuntimeOperation.aggregate.id) }}</dd>
                </div>
                <div>
                  <dt>Attempt</dt>
                  <dd>{{ selectedRuntimeOperation.attempt.count }} / {{ selectedRuntimeOperation.attempt.max }}</dd>
                </div>
                <div>
                  <dt>Priority</dt>
                  <dd>{{ selectedRuntimeOperation.priority }}</dd>
                </div>
                <div>
                  <dt>Correlation</dt>
                  <dd>{{ shortId(selectedRuntimeOperation.correlation_id) }}</dd>
                </div>
                <div>
                  <dt>Request</dt>
                  <dd>{{ shortId(selectedRuntimeOperation.request_id) }}</dd>
                </div>
                <div>
                  <dt>Causation</dt>
                  <dd>{{ selectedRuntimeOperation.causation_id ? shortId(selectedRuntimeOperation.causation_id) : 'None' }}</dd>
                </div>
                <div>
                  <dt>Payload version</dt>
                  <dd>{{ selectedRuntimeOperation.payload_version }}</dd>
                </div>
                <div>
                  <dt>Available</dt>
                  <dd>{{ displayValue(selectedRuntimeOperation.available_at) }}</dd>
                </div>
                <div>
                  <dt>Expires</dt>
                  <dd>{{ displayValue(selectedRuntimeOperation.expires_at) }}</dd>
                </div>
                <div>
                  <dt>Created</dt>
                  <dd>{{ displayValue(selectedRuntimeOperation.created_at) }}</dd>
                </div>
                <div>
                  <dt>Started</dt>
                  <dd>{{ displayValue(selectedRuntimeOperation.started_at) }}</dd>
                </div>
                <div>
                  <dt>Completed</dt>
                  <dd>{{ displayValue(selectedRuntimeOperation.completed_at) }}</dd>
                </div>
                <div>
                  <dt>Cancelled</dt>
                  <dd>{{ displayValue(selectedRuntimeOperation.cancelled_at) }}</dd>
                </div>
                <div>
                  <dt>Failure</dt>
                  <dd>{{ failureLabel(selectedRuntimeOperation.failure) }}</dd>
                </div>
                <div>
                  <dt>Reconciliation</dt>
                  <dd>{{ reconciliationLabel(selectedRuntimeOperation.reconciliation) }}</dd>
                </div>
              </dl>
            </section>
          </div>
        </div>
      </div>
    </UiDataList>

    <UiPagination
      v-if="runtimeOperationsResource.state.status === 'success' || runtimeOperationsResource.state.status === 'refreshing'"
      :page="runtimeOperationPagination.page"
      :per-page="runtimeOperationPagination.per_page"
      :total="runtimeOperationPagination.total"
      :has-more="runtimeOperationPagination.has_more"
      :loading="runtimeOperationsResource.isRefreshing.value"
      :page-size-options="[10, 20, 50]"
      @previous="setPage(runtimeOperationPagination.page - 1)"
      @next="setPage(runtimeOperationPagination.page + 1)"
      @update:per-page="setPerPage"
    />
  </section>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch, onBeforeUnmount, onMounted } from 'vue'
import {
  identityApi,
  type RuntimeOperation,
  type RuntimeOperationDetail,
  type RuntimeOperationFailure,
  type RuntimeOperationReconciliationReference,
  type RuntimeOperationStatus,
} from '../api/platform'
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiDataList from '../components/ui/UiDataList.vue'
import UiFilterBar from '../components/ui/UiFilterBar.vue'
import UiFormField from '../components/ui/UiFormField.vue'
import UiListSummary from '../components/ui/UiListSummary.vue'
import UiLoadingState from '../components/ui/UiLoadingState.vue'
import UiPagination from '../components/ui/UiPagination.vue'
import UiPanel from '../components/ui/UiPanel.vue'
import UiSelect from '../components/ui/UiSelect.vue'
import UiStatusBadge from '../components/ui/UiStatusBadge.vue'
import UiTextInput from '../components/ui/UiTextInput.vue'
import { useAsyncResource } from '../composables/asyncState'
import { useListQueryState } from '../composables/listQueryState'
import { router } from '../router'
import { apiErrorMessage, can, displayValue, session, shortId, tenantContextVersion } from '../state/appState'
import {
  leaveRuntimeOperationRealtimeTenant,
  resynchronizeRuntimeNodeRealtime,
  runtimeNodeRealtimeConnectionState,
  runtimeNodeRealtimeStatusText,
  subscribeRuntimeOperationRealtime,
} from '../realtime/runtimeNodeRealtime'

type RuntimeOperationFilters = {
  runtimeNodeId: string
  status: string
  operationType: string
  createdFrom: string
  createdTo: string
  correlationId: string
}

const runtimeOperationStatuses: RuntimeOperationStatus[] = [
  'pending',
  'leased',
  'running',
  'retry_scheduled',
  'succeeded',
  'terminal_failed',
  'cancelled',
  'expired',
]
const filterDraft = reactive<RuntimeOperationFilters>({
  runtimeNodeId: '',
  status: '',
  operationType: '',
  createdFrom: '',
  createdTo: '',
  correlationId: '',
})
const runtimeOperationListQuery = useListQueryState<RuntimeOperationFilters>(router, {
  pagination: true,
  filters: {
    runtimeNodeId: { query: 'runtime_node_id' },
    status: { query: 'status', allowedValues: runtimeOperationStatuses },
    operationType: { query: 'operation_type' },
    createdFrom: { query: 'created_from' },
    createdTo: { query: 'created_to' },
    correlationId: { query: 'correlation_id' },
  },
  defaultPerPage: 20,
  allowedPerPage: [10, 20, 50],
})
const runtimeOperations = ref<RuntimeOperation[]>([])
const runtimeOperationPagination = ref({ page: 1, per_page: 20, total: 0, has_more: false })
const selectedRuntimeOperationId = ref<string | null>(null)
const selectedRuntimeOperation = ref<RuntimeOperationDetail | null>(null)
let detailRequestGeneration = 0

const runtimeOperationsResource = useAsyncResource(async () => {
  const response = await identityApi.runtimeOperations({
    runtime_node_id: runtimeOperationListQuery.state.filters.runtimeNodeId,
    status: runtimeOperationListQuery.state.filters.status as RuntimeOperationStatus | '',
    operation_type: runtimeOperationListQuery.state.filters.operationType,
    created_from: runtimeOperationListQuery.state.filters.createdFrom,
    created_to: runtimeOperationListQuery.state.filters.createdTo,
    correlation_id: runtimeOperationListQuery.state.filters.correlationId,
    page: runtimeOperationListQuery.state.page,
    per_page: runtimeOperationListQuery.state.perPage,
  })
  runtimeOperations.value = response.runtime_operations
  runtimeOperationPagination.value = response.pagination

  return response
}, {
  isEmpty: (response) => response.runtime_operations.length === 0,
  getErrorMessage: apiErrorMessage,
})

const selectedRuntimeOperationResource = useAsyncResource(async () => {
  const operationId = selectedRuntimeOperationId.value
  if (operationId === null) return null
  const generation = ++detailRequestGeneration
  const tenantGeneration = tenantContextVersion.value
  const response = await identityApi.runtimeOperation(operationId)
  if (generation !== detailRequestGeneration || selectedRuntimeOperationId.value !== operationId || tenantContextVersion.value !== tenantGeneration) {
    return selectedRuntimeOperation.value
  }
  selectedRuntimeOperation.value = response.runtime_operation

  return selectedRuntimeOperation.value
}, {
  isEmpty: (row) => row === null,
  getErrorMessage: apiErrorMessage,
})

const realtimeStatusCategory = computed(() => {
  if (runtimeNodeRealtimeConnectionState.value === 'connected') return 'success'
  if (runtimeNodeRealtimeConnectionState.value === 'connecting' || runtimeNodeRealtimeConnectionState.value === 'reconnecting') return 'information'
  if (runtimeNodeRealtimeConnectionState.value === 'unauthorized') return 'warning'

  return 'danger'
})

function activeTenantId(): string {
  return session.value?.active_tenant?.tenant_id ?? ''
}

function sessionCanViewRuntimeOperations(): boolean {
  return session.value !== null && activeTenantId() !== '' && can('runtime.nodes.view')
}

async function loadRuntimeOperations(): Promise<void> {
  const loaded = await runtimeOperationsResource.load()
  if (loaded !== null) subscribeAfterCanonicalSnapshot()
}

async function selectRuntimeOperation(runtimeOperationId: string): Promise<void> {
  if (selectedRuntimeOperationId.value !== runtimeOperationId) {
    selectedRuntimeOperation.value = null
    selectedRuntimeOperationResource.reset()
  }
  selectedRuntimeOperationId.value = runtimeOperationId
  await refreshSelectedRuntimeOperation(runtimeOperationId)
}

async function refreshSelectedRuntimeOperation(runtimeOperationId: string): Promise<void> {
  if (selectedRuntimeOperationId.value !== runtimeOperationId) return
  await selectedRuntimeOperationResource.load()
}

async function applyFilters(): Promise<void> {
  await runtimeOperationListQuery.applyFilters({
    search: '',
    filters: { ...filterDraft },
  })
}

async function clearFilters(): Promise<void> {
  filterDraft.runtimeNodeId = ''
  filterDraft.status = ''
  filterDraft.operationType = ''
  filterDraft.createdFrom = ''
  filterDraft.createdTo = ''
  filterDraft.correlationId = ''
  clearSelectedRuntimeOperation()
  await runtimeOperationListQuery.clear()
}

async function setPage(page: number): Promise<void> {
  if (await runtimeOperationListQuery.setPage(page)) return
  await loadRuntimeOperations()
}

async function setPerPage(perPage: number): Promise<void> {
  if (await runtimeOperationListQuery.setPerPage(perPage)) return
  await loadRuntimeOperations()
}

function subscribeAfterCanonicalSnapshot(): void {
  if (!sessionCanViewRuntimeOperations()) return
  subscribeRuntimeOperationRealtime({
    tenantId: activeTenantId(),
    refreshList: () => runtimeOperationsResource.load(),
    refreshSelectedRuntimeOperation,
    selectedRuntimeOperationId: () => selectedRuntimeOperationId.value,
    sessionActive: sessionCanViewRuntimeOperations,
  })
}

function clearSelectedRuntimeOperation(): void {
  selectedRuntimeOperationId.value = null
  selectedRuntimeOperation.value = null
  detailRequestGeneration++
  selectedRuntimeOperationResource.reset()
}

function syncFilterDraftFromQuery(): void {
  filterDraft.runtimeNodeId = runtimeOperationListQuery.state.filters.runtimeNodeId
  filterDraft.status = runtimeOperationListQuery.state.filters.status
  filterDraft.operationType = runtimeOperationListQuery.state.filters.operationType
  filterDraft.createdFrom = runtimeOperationListQuery.state.filters.createdFrom
  filterDraft.createdTo = runtimeOperationListQuery.state.filters.createdTo
  filterDraft.correlationId = runtimeOperationListQuery.state.filters.correlationId
}

function runtimeNodeLabel(operation: RuntimeOperation): string {
  if (operation.runtime_node !== null) return `${operation.runtime_node.name} (${shortId(operation.runtime_node.id)})`
  if (operation.runtime_node_id !== null) return shortId(operation.runtime_node_id)

  return 'Unassigned'
}

function statusLabel(status: string): string {
  return status.replaceAll('_', ' ')
}

function operationStatusCategory(status: string): 'neutral' | 'success' | 'warning' | 'danger' | 'information' {
  if (status === 'succeeded') return 'success'
  if (['pending', 'leased', 'running', 'retry_scheduled'].includes(status)) return 'information'
  if (['terminal_failed', 'cancelled', 'expired'].includes(status)) return 'danger'

  return 'neutral'
}

function failureLabel(failure: RuntimeOperationFailure | null): string {
  if (failure === null) return 'None'

  return `${failure.summary} · ${displayValue(failure.occurred_at)}`
}

function reconciliationLabel(reconciliation: RuntimeOperationReconciliationReference | null): string {
  if (reconciliation === null) return 'None'

  return `${reconciliation.target_type} ${shortId(reconciliation.target_id)} · ${reconciliation.status}`
}

function handleVisibilityChange(): void {
  if (globalThis.document?.visibilityState === 'visible') {
    void resynchronizeRuntimeNodeRealtime()
  }
}

watch(
  () => runtimeOperationListQuery.state,
  () => {
    syncFilterDraftFromQuery()
    void loadRuntimeOperations()
  },
  { deep: true, immediate: true },
)

watch(tenantContextVersion, () => {
  clearSelectedRuntimeOperation()
  leaveRuntimeOperationRealtimeTenant()
  void loadRuntimeOperations()
})

onMounted(() => {
  globalThis.document?.addEventListener('visibilitychange', handleVisibilityChange)
})

onBeforeUnmount(() => {
  globalThis.document?.removeEventListener('visibilitychange', handleVisibilityChange)
  leaveRuntimeOperationRealtimeTenant()
})
</script>
