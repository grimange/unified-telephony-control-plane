<template>
  <section
    class="workspace runtime-reconciliations"
    aria-labelledby="runtime-reconciliations-title"
  >
    <div class="section-heading">
      <h2 id="runtime-reconciliations-title">
        Runtime reconciliations
      </h2>
      <UiStatusBadge
        class="live-updates-badge"
        :label="runtimeNodeRealtimeStatusText()"
        :category="realtimeStatusCategory"
      />
      <UiButton
        type="button"
        variant="secondary"
        :loading="runtimeReconciliationsResource.state.status === 'refreshing'"
        loading-label="Refreshing"
        @click="loadRuntimeReconciliations"
      >
        Refresh
      </UiButton>
    </div>

    <UiPanel
      title="Filter runtime reconciliations"
      label="Reconciliations"
    >
      <UiFilterBar
        @apply="applyFilters"
        @clear="clearFilters"
      >
        <UiFormField
          id="runtime-reconciliation-node-filter"
          label="RuntimeNode ID"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="filterDraft.runtimeNodeId"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="RuntimeNode identifier"
            />
          </template>
        </UiFormField>
        <UiFormField
          id="runtime-reconciliation-status-filter"
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
                v-for="status in runtimeReconciliationStatuses"
                :key="status"
                :value="status"
              >
                {{ statusLabel(status) }}
              </option>
            </UiSelect>
          </template>
        </UiFormField>
        <UiFormField
          id="runtime-reconciliation-target-type-filter"
          label="Target type"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiSelect
              :id="id"
              v-model="filterDraft.targetType"
              :aria-describedby="describedBy"
              :invalid="invalid"
            >
              <option value="">
                Any target
              </option>
              <option
                v-for="targetType in runtimeReconciliationTargetTypes"
                :key="targetType"
                :value="targetType"
              >
                {{ statusLabel(targetType) }}
              </option>
            </UiSelect>
          </template>
        </UiFormField>
        <UiFormField
          id="runtime-reconciliation-operation-filter"
          label="Runtime Operation ID"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="filterDraft.runtimeOperationId"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="Runtime Operation identifier"
            />
          </template>
        </UiFormField>
        <UiFormField
          id="runtime-reconciliation-updated-from-filter"
          label="Updated from"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="filterDraft.updatedFrom"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="2026-07-24T10:00:00Z"
            />
          </template>
        </UiFormField>
        <UiFormField
          id="runtime-reconciliation-updated-to-filter"
          label="Updated to"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="filterDraft.updatedTo"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="2026-07-24T11:00:00Z"
            />
          </template>
        </UiFormField>
      </UiFilterBar>
    </UiPanel>

    <UiDataList
      :status="runtimeReconciliationsResource.state.status"
      :error="runtimeReconciliationsResource.state.error"
      :has-data="runtimeReconciliations.length > 0"
      title="Runtime Reconciliation list"
      label="Reconciliations"
      loading-label="Loading Runtime Reconciliations."
      refreshing-label="Refreshing Runtime Reconciliations."
      empty-title="No Runtime Reconciliations"
      empty-message="No Runtime Reconciliations matched the current filters."
      error-title="Runtime Reconciliations unavailable"
      forbidden-title="Runtime Reconciliations forbidden"
    >
      <template #actions>
        <UiListSummary
          :page="runtimeReconciliationPagination.page"
          :total="runtimeReconciliationPagination.total"
          :count="runtimeReconciliations.length"
          item-label="Runtime Reconciliations"
        />
      </template>
      <div class="data-table">
        <div
          v-for="reconciliation in runtimeReconciliations"
          :key="reconciliation.id"
          class="data-row reconciliation-row"
        >
          <span>
            <strong>{{ shortId(reconciliation.id) }}</strong>
            <small>{{ targetLabel(reconciliation) }} · {{ runtimeNodeLabel(reconciliation) }}</small>
            <span class="badge-row">
              <UiStatusBadge
                :label="statusLabel(reconciliation.status)"
                :category="reconciliationStatusCategory(reconciliation.status)"
              />
              <UiStatusBadge
                :label="driftLabel(reconciliation.has_drift)"
                :category="driftCategory(reconciliation.has_drift)"
              />
              <UiStatusBadge
                :label="`attempt ${reconciliation.attempt_count}`"
                category="neutral"
              />
            </span>
          </span>
          <span>
            <small>Desired {{ reconciliation.desired_generation }}</small>
            <small>Observed {{ generationLabel(reconciliation.observed_generation) }}</small>
            <small>Last attempted {{ displayValue(reconciliation.last_checked_at) }}</small>
            <small>Last success {{ reconciliation.status === 'converged' ? displayValue(reconciliation.last_checked_at) : 'None' }}</small>
            <small>Updated {{ displayValue(reconciliation.updated_at) }}</small>
            <small v-if="reconciliation.failure">{{ reconciliation.failure.summary }}</small>
            <small v-if="reconciliation.runtime_operation">Operation {{ shortId(reconciliation.runtime_operation.id) }} · {{ reconciliation.runtime_operation.status }}</small>
          </span>
          <span class="row-actions">
            <UiButton
              type="button"
              variant="secondary"
              :loading="selectedRuntimeReconciliationId === reconciliation.id && selectedRuntimeReconciliationResource.state.status === 'loading'"
              loading-label="Loading details"
              @click="selectRuntimeReconciliation(reconciliation.id)"
            >
              {{ selectedRuntimeReconciliationId === reconciliation.id ? 'Selected' : 'Details' }}
            </UiButton>
          </span>
          <div
            v-if="selectedRuntimeReconciliationId === reconciliation.id"
            class="subgrid runtime-reconciliation-detail-grid"
          >
            <UiLoadingState
              v-if="selectedRuntimeReconciliationResource.state.status === 'loading'"
              label="Loading Runtime Reconciliation detail."
            />
            <UiAlert
              v-if="selectedRuntimeReconciliationResource.state.status === 'error' || selectedRuntimeReconciliationResource.state.status === 'forbidden'"
              variant="error"
              title="Runtime Reconciliation detail unavailable"
            >
              {{ selectedRuntimeReconciliationResource.state.error }}
            </UiAlert>
            <section
              v-if="selectedRuntimeReconciliation"
              class="detail-section"
              aria-label="Selected Runtime Reconciliation detail"
            >
              <h3>{{ shortId(selectedRuntimeReconciliation.id) }}</h3>
              <dl class="definition-grid">
                <div>
                  <dt>Target</dt>
                  <dd>{{ targetLabel(selectedRuntimeReconciliation) }}</dd>
                </div>
                <div>
                  <dt>Status</dt>
                  <dd>{{ statusLabel(selectedRuntimeReconciliation.status) }}</dd>
                </div>
                <div>
                  <dt>Drift</dt>
                  <dd>{{ driftLabel(selectedRuntimeReconciliation.has_drift) }}</dd>
                </div>
                <div>
                  <dt>RuntimeNode</dt>
                  <dd>{{ runtimeNodeLabel(selectedRuntimeReconciliation) }}</dd>
                </div>
                <div>
                  <dt>Desired generation</dt>
                  <dd>{{ selectedRuntimeReconciliation.desired_generation }}</dd>
                </div>
                <div>
                  <dt>Observed generation</dt>
                  <dd>{{ generationLabel(selectedRuntimeReconciliation.observed_generation) }}</dd>
                </div>
                <div>
                  <dt>Attempt count</dt>
                  <dd>{{ selectedRuntimeReconciliation.attempt_count }}</dd>
                </div>
                <div>
                  <dt>Last attempted</dt>
                  <dd>{{ displayValue(selectedRuntimeReconciliation.last_checked_at) }}</dd>
                </div>
                <div>
                  <dt>Last successful</dt>
                  <dd>{{ selectedRuntimeReconciliation.status === 'converged' ? displayValue(selectedRuntimeReconciliation.last_checked_at) : 'None' }}</dd>
                </div>
                <div>
                  <dt>Next check</dt>
                  <dd>{{ displayValue(selectedRuntimeReconciliation.next_check_at) }}</dd>
                </div>
                <div>
                  <dt>Created</dt>
                  <dd>{{ displayValue(selectedRuntimeReconciliation.created_at) }}</dd>
                </div>
                <div>
                  <dt>Updated</dt>
                  <dd>{{ displayValue(selectedRuntimeReconciliation.updated_at) }}</dd>
                </div>
                <div>
                  <dt>Failure</dt>
                  <dd>{{ failureLabel(selectedRuntimeReconciliation.failure) }}</dd>
                </div>
                <div>
                  <dt>Last operation</dt>
                  <dd>{{ runtimeOperationLabel(selectedRuntimeReconciliation.runtime_operation, selectedRuntimeReconciliation.last_operation_id) }}</dd>
                </div>
              </dl>
            </section>
          </div>
        </div>
      </div>
    </UiDataList>

    <UiPagination
      v-if="runtimeReconciliationsResource.state.status === 'success' || runtimeReconciliationsResource.state.status === 'refreshing'"
      :page="runtimeReconciliationPagination.page"
      :per-page="runtimeReconciliationPagination.per_page"
      :total="runtimeReconciliationPagination.total"
      :has-more="runtimeReconciliationPagination.has_more"
      :page-size-options="[10, 20, 50]"
      @previous="setPage(runtimeReconciliationPagination.page - 1)"
      @next="setPage(runtimeReconciliationPagination.page + 1)"
      @update:per-page="setPerPage"
    />
  </section>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch, onBeforeUnmount, onMounted } from 'vue'
import {
  identityApi,
  type RuntimeReconciliation,
  type RuntimeReconciliationDetail,
  type RuntimeReconciliationFailure,
  type RuntimeReconciliationRuntimeOperationReference,
  type RuntimeReconciliationStatus,
  type RuntimeReconciliationTargetType,
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
import {
  leaveRuntimeReconciliationRealtimeTenant,
  resynchronizeRuntimeNodeRealtime,
  runtimeNodeRealtimeConnectionState,
  runtimeNodeRealtimeStatusText,
  subscribeRuntimeReconciliationRealtime,
} from '../realtime/runtimeNodeRealtime'
import { router } from '../router'
import { apiErrorMessage, can, displayValue, session, shortId, tenantContextVersion } from '../state/appState'

type RuntimeReconciliationFilters = {
  runtimeNodeId: string
  status: string
  targetType: string
  runtimeOperationId: string
  updatedFrom: string
  updatedTo: string
}

const runtimeReconciliationStatuses: RuntimeReconciliationStatus[] = [
  'waiting',
  'leased',
  'converged',
  'operation_required',
  'blocked',
  'unsupported',
  'retry_scheduled',
]
const runtimeReconciliationTargetTypes: RuntimeReconciliationTargetType[] = [
  'runtime_node',
  'conference',
  'conference_participant',
  'signaling_registration',
]
const filterDraft = reactive<RuntimeReconciliationFilters>({
  runtimeNodeId: '',
  status: '',
  targetType: '',
  runtimeOperationId: '',
  updatedFrom: '',
  updatedTo: '',
})
const runtimeReconciliationListQuery = useListQueryState<RuntimeReconciliationFilters>(router, {
  pagination: true,
  filters: {
    runtimeNodeId: { query: 'runtime_node_id' },
    status: { query: 'status', allowedValues: runtimeReconciliationStatuses },
    targetType: { query: 'target_type', allowedValues: runtimeReconciliationTargetTypes },
    runtimeOperationId: { query: 'runtime_operation_id' },
    updatedFrom: { query: 'updated_from' },
    updatedTo: { query: 'updated_to' },
  },
  defaultPerPage: 20,
  allowedPerPage: [10, 20, 50],
})
const runtimeReconciliations = ref<RuntimeReconciliation[]>([])
const runtimeReconciliationPagination = ref({ page: 1, per_page: 20, total: 0, has_more: false })
const selectedRuntimeReconciliationId = ref<string | null>(null)
const selectedRuntimeReconciliation = ref<RuntimeReconciliationDetail | null>(null)
let detailRequestGeneration = 0

const runtimeReconciliationsResource = useAsyncResource(async () => {
  const response = await identityApi.runtimeReconciliations({
    runtime_node_id: runtimeReconciliationListQuery.state.filters.runtimeNodeId,
    status: runtimeReconciliationListQuery.state.filters.status as RuntimeReconciliationStatus | '',
    target_type: runtimeReconciliationListQuery.state.filters.targetType as RuntimeReconciliationTargetType | '',
    runtime_operation_id: runtimeReconciliationListQuery.state.filters.runtimeOperationId,
    updated_from: runtimeReconciliationListQuery.state.filters.updatedFrom,
    updated_to: runtimeReconciliationListQuery.state.filters.updatedTo,
    page: runtimeReconciliationListQuery.state.page,
    per_page: runtimeReconciliationListQuery.state.perPage,
  })
  runtimeReconciliations.value = response.runtime_reconciliations
  runtimeReconciliationPagination.value = response.pagination

  return response
}, {
  isEmpty: (response) => response.runtime_reconciliations.length === 0,
  getErrorMessage: apiErrorMessage,
})

const selectedRuntimeReconciliationResource = useAsyncResource(async () => {
  const reconciliationId = selectedRuntimeReconciliationId.value
  if (reconciliationId === null) return null
  const generation = ++detailRequestGeneration
  const tenantGeneration = tenantContextVersion.value
  const response = await identityApi.runtimeReconciliation(reconciliationId)
  if (generation !== detailRequestGeneration || selectedRuntimeReconciliationId.value !== reconciliationId || tenantContextVersion.value !== tenantGeneration) {
    return selectedRuntimeReconciliation.value
  }
  selectedRuntimeReconciliation.value = response.runtime_reconciliation

  return selectedRuntimeReconciliation.value
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

function sessionCanViewRuntimeReconciliations(): boolean {
  return session.value !== null && activeTenantId() !== '' && can('runtime.nodes.view')
}

async function loadRuntimeReconciliations(): Promise<void> {
  const loaded = await runtimeReconciliationsResource.load()
  if (loaded !== null) subscribeAfterCanonicalSnapshot()
}

async function selectRuntimeReconciliation(runtimeReconciliationId: string): Promise<void> {
  if (selectedRuntimeReconciliationId.value !== runtimeReconciliationId) {
    selectedRuntimeReconciliation.value = null
    selectedRuntimeReconciliationResource.reset()
  }
  selectedRuntimeReconciliationId.value = runtimeReconciliationId
  await refreshSelectedRuntimeReconciliation(runtimeReconciliationId)
}

async function refreshSelectedRuntimeReconciliation(runtimeReconciliationId: string): Promise<void> {
  if (selectedRuntimeReconciliationId.value !== runtimeReconciliationId) return
  await selectedRuntimeReconciliationResource.load()
}

async function applyFilters(): Promise<void> {
  await runtimeReconciliationListQuery.applyFilters({
    search: '',
    filters: { ...filterDraft },
  })
}

async function clearFilters(): Promise<void> {
  filterDraft.runtimeNodeId = ''
  filterDraft.status = ''
  filterDraft.targetType = ''
  filterDraft.runtimeOperationId = ''
  filterDraft.updatedFrom = ''
  filterDraft.updatedTo = ''
  clearSelectedRuntimeReconciliation()
  await runtimeReconciliationListQuery.clear()
}

async function setPage(page: number): Promise<void> {
  if (await runtimeReconciliationListQuery.setPage(page)) return
  await loadRuntimeReconciliations()
}

async function setPerPage(perPage: number): Promise<void> {
  if (await runtimeReconciliationListQuery.setPerPage(perPage)) return
  await loadRuntimeReconciliations()
}

function subscribeAfterCanonicalSnapshot(): void {
  if (!sessionCanViewRuntimeReconciliations()) return
  subscribeRuntimeReconciliationRealtime({
    tenantId: activeTenantId(),
    refreshList: () => runtimeReconciliationsResource.load(),
    refreshSelectedRuntimeReconciliation,
    selectedRuntimeReconciliationId: () => selectedRuntimeReconciliationId.value,
    sessionActive: sessionCanViewRuntimeReconciliations,
  })
}

function clearSelectedRuntimeReconciliation(): void {
  selectedRuntimeReconciliationId.value = null
  selectedRuntimeReconciliation.value = null
  detailRequestGeneration++
  selectedRuntimeReconciliationResource.reset()
}

function syncFilterDraftFromQuery(): void {
  filterDraft.runtimeNodeId = runtimeReconciliationListQuery.state.filters.runtimeNodeId
  filterDraft.status = runtimeReconciliationListQuery.state.filters.status
  filterDraft.targetType = runtimeReconciliationListQuery.state.filters.targetType
  filterDraft.runtimeOperationId = runtimeReconciliationListQuery.state.filters.runtimeOperationId
  filterDraft.updatedFrom = runtimeReconciliationListQuery.state.filters.updatedFrom
  filterDraft.updatedTo = runtimeReconciliationListQuery.state.filters.updatedTo
}

function targetLabel(reconciliation: RuntimeReconciliation): string {
  return `${statusLabel(reconciliation.target.type)} ${shortId(reconciliation.target.id)}`
}

function runtimeNodeLabel(reconciliation: RuntimeReconciliation): string {
  if (reconciliation.runtime_node !== null) return `${reconciliation.runtime_node.name} (${shortId(reconciliation.runtime_node.id)})`

  return 'None'
}

function statusLabel(status: string): string {
  return status.replaceAll('_', ' ')
}

function reconciliationStatusCategory(status: string): 'neutral' | 'success' | 'warning' | 'danger' | 'information' {
  if (status === 'converged') return 'success'
  if (['waiting', 'leased', 'operation_required', 'retry_scheduled'].includes(status)) return 'information'
  if (['blocked', 'unsupported'].includes(status)) return 'danger'

  return 'neutral'
}

function driftLabel(hasDrift: boolean | null): string {
  if (hasDrift === null) return 'Drift unknown'

  return hasDrift ? 'Drift detected' : 'No drift'
}

function driftCategory(hasDrift: boolean | null): 'neutral' | 'success' | 'warning' | 'danger' | 'information' {
  if (hasDrift === null) return 'neutral'

  return hasDrift ? 'warning' : 'success'
}

function generationLabel(generation: number | null): string {
  return generation === null ? 'Unknown' : String(generation)
}

function failureLabel(failure: RuntimeReconciliationFailure | null): string {
  if (failure === null) return 'None'

  return `${failure.summary} · ${displayValue(failure.occurred_at)}`
}

function runtimeOperationLabel(operation: RuntimeReconciliationRuntimeOperationReference | null, fallbackId: string | null): string {
  if (operation !== null) return `${operation.operation_type} ${shortId(operation.id)} · ${operation.status}`
  if (fallbackId !== null) return shortId(fallbackId)

  return 'None'
}

function handleVisibilityChange(): void {
  if (globalThis.document?.visibilityState === 'visible') {
    void resynchronizeRuntimeNodeRealtime()
  }
}

watch(
  () => runtimeReconciliationListQuery.state,
  () => {
    syncFilterDraftFromQuery()
    void loadRuntimeReconciliations()
  },
  { deep: true, immediate: true },
)

watch(tenantContextVersion, () => {
  clearSelectedRuntimeReconciliation()
  leaveRuntimeReconciliationRealtimeTenant()
  void loadRuntimeReconciliations()
})

onMounted(() => {
  globalThis.document?.addEventListener('visibilitychange', handleVisibilityChange)
})

onBeforeUnmount(() => {
  globalThis.document?.removeEventListener('visibilitychange', handleVisibilityChange)
  leaveRuntimeReconciliationRealtimeTenant()
})
</script>
