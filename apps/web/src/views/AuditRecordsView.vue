<template>
  <section
    class="workspace audit-records"
    aria-labelledby="audit-records-title"
  >
    <div class="section-heading">
      <h2 id="audit-records-title">
        Audit records
      </h2>
      <UiButton
        type="button"
        variant="secondary"
        :loading="auditRecordsResource.state.status === 'refreshing'"
        loading-label="Refreshing"
        @click="loadAuditRecords"
      >
        Refresh
      </UiButton>
    </div>

    <UiPanel
      title="Filter audit records"
      label="Audit"
    >
      <UiFilterBar
        @apply="applyFilters"
        @clear="clearFilters"
      >
        <UiFormField
          id="audit-actor-type-filter"
          label="Actor type"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="filterDraft.actorType"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="user"
            />
          </template>
        </UiFormField>
        <UiFormField
          id="audit-actor-id-filter"
          label="Actor ID"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="filterDraft.actorId"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="Actor identifier"
            />
          </template>
        </UiFormField>
        <UiFormField
          id="audit-action-filter"
          label="Action"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="filterDraft.action"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="runtime_node.created"
            />
          </template>
        </UiFormField>
        <UiFormField
          id="audit-subject-type-filter"
          label="Subject type"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="filterDraft.subjectType"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="runtime_node"
            />
          </template>
        </UiFormField>
        <UiFormField
          id="audit-subject-id-filter"
          label="Subject ID"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="filterDraft.subjectId"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="Subject identifier"
            />
          </template>
        </UiFormField>
        <UiFormField
          id="audit-correlation-filter"
          label="Correlation ID"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="filterDraft.correlationId"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="32 hex characters"
            />
          </template>
        </UiFormField>
        <UiFormField
          id="audit-request-filter"
          label="Request ID"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="filterDraft.requestId"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="32 hex characters"
            />
          </template>
        </UiFormField>
        <UiFormField
          id="audit-occurred-from-filter"
          label="Occurred from"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="filterDraft.occurredFrom"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="2026-07-24T10:00:00Z"
            />
          </template>
        </UiFormField>
        <UiFormField
          id="audit-occurred-to-filter"
          label="Occurred to"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="filterDraft.occurredTo"
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
      :status="auditRecordsResource.state.status"
      :error="auditRecordsResource.state.error"
      :has-data="auditRecords.length > 0"
      title="Audit record list"
      label="Audit records"
      loading-label="Loading Audit records."
      refreshing-label="Refreshing Audit records."
      empty-title="No Audit records"
      empty-message="No Audit records matched the current filters."
      error-title="Audit records unavailable"
      forbidden-title="Audit records forbidden"
    >
      <template #actions>
        <UiListSummary
          :page="auditRecordPagination.page"
          :total="auditRecordPagination.total"
          :count="auditRecords.length"
          item-label="Audit records"
        />
      </template>
      <div class="data-table">
        <div
          v-for="record in auditRecords"
          :key="record.id"
          class="data-row audit-record-row"
        >
          <span>
            <strong>{{ displayValue(record.occurred_at) }}</strong>
            <small>{{ record.action }}</small>
            <span class="badge-row">
              <UiStatusBadge
                :label="outcomeLabel(record.outcome)"
                :category="outcomeCategory(record.outcome)"
              />
            </span>
          </span>
          <span>
            <small>Actor {{ referenceLabel(record.actor) }}</small>
            <small>Subject {{ referenceLabel(record.subject) }}</small>
            <small v-if="record.correlation_id">Correlation {{ shortId(record.correlation_id) }}</small>
            <small v-if="record.request_id">Request {{ shortId(record.request_id) }}</small>
          </span>
          <span class="row-actions">
            <UiButton
              type="button"
              variant="secondary"
              :loading="selectedAuditRecordId === record.id && selectedAuditRecordResource.state.status === 'loading'"
              loading-label="Loading details"
              @click="selectAuditRecord(record.id, $event)"
            >
              {{ selectedAuditRecordId === record.id ? 'Selected' : 'Details' }}
            </UiButton>
          </span>
          <div
            v-if="selectedAuditRecordId === record.id"
            class="subgrid audit-record-detail-grid"
          >
            <UiLoadingState
              v-if="selectedAuditRecordResource.state.status === 'loading'"
              label="Loading Audit record detail."
            />
            <UiAlert
              v-if="selectedAuditRecordResource.state.status === 'error' || selectedAuditRecordResource.state.status === 'forbidden'"
              variant="error"
              title="Audit record detail unavailable"
            >
              {{ selectedAuditRecordResource.state.error }}
            </UiAlert>
            <section
              v-if="selectedAuditRecord"
              class="detail-section"
              aria-label="Selected Audit record detail"
            >
              <div class="section-heading">
                <h3>{{ shortId(selectedAuditRecord.id) }}</h3>
                <UiButton
                  type="button"
                  variant="ghost"
                  @click="clearSelectedAuditRecord({ restoreFocus: true })"
                >
                  Close
                </UiButton>
              </div>
              <dl class="definition-grid">
                <div>
                  <dt>Action</dt>
                  <dd>{{ selectedAuditRecord.action }}</dd>
                </div>
                <div>
                  <dt>Outcome</dt>
                  <dd>{{ outcomeLabel(selectedAuditRecord.outcome) }}</dd>
                </div>
                <div>
                  <dt>Actor</dt>
                  <dd>{{ referenceLabel(selectedAuditRecord.actor) }}</dd>
                </div>
                <div>
                  <dt>Subject</dt>
                  <dd>{{ referenceLabel(selectedAuditRecord.subject) }}</dd>
                </div>
                <div>
                  <dt>Correlation</dt>
                  <dd>{{ selectedAuditRecord.correlation_id ? shortId(selectedAuditRecord.correlation_id) : 'None' }}</dd>
                </div>
                <div>
                  <dt>Request</dt>
                  <dd>{{ selectedAuditRecord.request_id ? shortId(selectedAuditRecord.request_id) : 'None' }}</dd>
                </div>
                <div>
                  <dt>Occurred</dt>
                  <dd>{{ displayValue(selectedAuditRecord.occurred_at) }}</dd>
                </div>
                <div>
                  <dt>Created</dt>
                  <dd>{{ displayValue(selectedAuditRecord.created_at) }}</dd>
                </div>
                <div>
                  <dt>Reason</dt>
                  <dd>{{ selectedAuditRecord.reason ?? 'None' }}</dd>
                </div>
              </dl>
              <section
                class="detail-block nested"
                aria-label="Safe Audit metadata"
              >
                <h3>Safe metadata</h3>
                <p
                  v-if="metadataEntries.length === 0"
                  class="meta"
                >
                  None.
                </p>
                <dl
                  v-else
                  class="definition-grid metadata-grid"
                >
                  <div
                    v-for="entry in metadataEntries"
                    :key="entry.key"
                  >
                    <dt>{{ entry.key }}</dt>
                    <dd>{{ entry.value }}</dd>
                  </div>
                </dl>
              </section>
            </section>
          </div>
        </div>
      </div>
    </UiDataList>

    <UiPagination
      v-if="auditRecordsResource.state.status === 'success' || auditRecordsResource.state.status === 'refreshing'"
      :page="auditRecordPagination.page"
      :per-page="auditRecordPagination.per_page"
      :total="auditRecordPagination.total"
      :has-more="auditRecordPagination.has_more"
      :page-size-options="[10, 20, 50]"
      @previous="setPage(auditRecordPagination.page - 1)"
      @next="setPage(auditRecordPagination.page + 1)"
      @update:per-page="setPerPage"
    />
  </section>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue'
import {
  identityApi,
  type AuditActorReference,
  type AuditOutcome,
  type AuditRecord,
  type AuditRecordDetail,
  type AuditSafeMetadata,
  type AuditSafeMetadataValue,
  type AuditSubjectReference,
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
import UiStatusBadge from '../components/ui/UiStatusBadge.vue'
import UiTextInput from '../components/ui/UiTextInput.vue'
import { useAsyncResource } from '../composables/asyncState'
import { useListQueryState } from '../composables/listQueryState'
import { router } from '../router'
import { apiErrorMessage, displayValue, shortId, tenantContextVersion } from '../state/appState'

type AuditRecordFilters = {
  actorType: string
  actorId: string
  action: string
  subjectType: string
  subjectId: string
  correlationId: string
  requestId: string
  occurredFrom: string
  occurredTo: string
}

type MetadataEntry = {
  key: string
  value: string
}

const metadataEntryLimit = 20
const metadataValueLimit = 240
const filterDraft = reactive<AuditRecordFilters>({
  actorType: '',
  actorId: '',
  action: '',
  subjectType: '',
  subjectId: '',
  correlationId: '',
  requestId: '',
  occurredFrom: '',
  occurredTo: '',
})
const auditRecordListQuery = useListQueryState<AuditRecordFilters>(router, {
  pagination: true,
  filters: {
    actorType: { query: 'actor_type' },
    actorId: { query: 'actor_id' },
    action: { query: 'action' },
    subjectType: { query: 'subject_type' },
    subjectId: { query: 'subject_id' },
    correlationId: { query: 'correlation_id' },
    requestId: { query: 'request_id' },
    occurredFrom: { query: 'occurred_from' },
    occurredTo: { query: 'occurred_to' },
  },
  defaultPerPage: 20,
  allowedPerPage: [10, 20, 50],
})
const auditRecords = ref<AuditRecord[]>([])
const auditRecordPagination = ref({ page: 1, per_page: 20, total: 0, has_more: false })
const selectedAuditRecordId = ref<string | null>(null)
const selectedAuditRecord = ref<AuditRecordDetail | null>(null)
const selectedAuditRecordTrigger = ref<HTMLElement | null>(null)
let detailRequestGeneration = 0

const auditRecordsResource = useAsyncResource(async () => identityApi.listAuditRecords({
  actor_type: auditRecordListQuery.state.filters.actorType,
  actor_id: auditRecordListQuery.state.filters.actorId,
  action: auditRecordListQuery.state.filters.action,
  subject_type: auditRecordListQuery.state.filters.subjectType,
  subject_id: auditRecordListQuery.state.filters.subjectId,
  correlation_id: auditRecordListQuery.state.filters.correlationId,
  request_id: auditRecordListQuery.state.filters.requestId,
  occurred_from: auditRecordListQuery.state.filters.occurredFrom,
  occurred_to: auditRecordListQuery.state.filters.occurredTo,
  page: auditRecordListQuery.state.page,
  per_page: auditRecordListQuery.state.perPage,
}), {
  isEmpty: (response) => response.audit_records.length === 0,
  getErrorMessage: apiErrorMessage,
})

const selectedAuditRecordResource = useAsyncResource(async () => {
  const auditRecordId = selectedAuditRecordId.value
  if (auditRecordId === null) return null
  const generation = ++detailRequestGeneration
  const tenantGeneration = tenantContextVersion.value
  const response = await identityApi.getAuditRecord(auditRecordId)
  if (generation !== detailRequestGeneration || selectedAuditRecordId.value !== auditRecordId || tenantContextVersion.value !== tenantGeneration) {
    return selectedAuditRecord.value
  }

  return response.audit_record
}, {
  isEmpty: (row) => row === null,
  getErrorMessage: apiErrorMessage,
})

const metadataEntries = computed(() => safeMetadataEntries(selectedAuditRecord.value?.metadata ?? {}))

async function loadAuditRecords(): Promise<void> {
  const loaded = await auditRecordsResource.load()
  if (loaded === null) return
  auditRecords.value = loaded.audit_records
  auditRecordPagination.value = loaded.pagination
}

async function selectAuditRecord(auditRecordId: string, event?: MouseEvent): Promise<void> {
  rememberAuditRecordTrigger(event)
  if (selectedAuditRecordId.value !== auditRecordId) {
    selectedAuditRecord.value = null
    selectedAuditRecordResource.reset()
  }
  selectedAuditRecordId.value = auditRecordId
  const loaded = await selectedAuditRecordResource.load()
  if (loaded !== null) selectedAuditRecord.value = loaded
}

async function applyFilters(): Promise<void> {
  await auditRecordListQuery.applyFilters({
    search: '',
    filters: { ...filterDraft },
  })
}

async function clearFilters(): Promise<void> {
  filterDraft.actorType = ''
  filterDraft.actorId = ''
  filterDraft.action = ''
  filterDraft.subjectType = ''
  filterDraft.subjectId = ''
  filterDraft.correlationId = ''
  filterDraft.requestId = ''
  filterDraft.occurredFrom = ''
  filterDraft.occurredTo = ''
  clearSelectedAuditRecord()
  await auditRecordListQuery.clear()
}

async function setPage(page: number): Promise<void> {
  if (await auditRecordListQuery.setPage(page)) return
  await loadAuditRecords()
}

async function setPerPage(perPage: number): Promise<void> {
  if (await auditRecordListQuery.setPerPage(perPage)) return
  await loadAuditRecords()
}

function clearSelectedAuditRecord(options: { restoreFocus?: boolean } = {}): void {
  const restoreTarget = selectedAuditRecordTrigger.value
  selectedAuditRecordId.value = null
  selectedAuditRecord.value = null
  selectedAuditRecordTrigger.value = null
  detailRequestGeneration++
  selectedAuditRecordResource.reset()
  if (options.restoreFocus) {
    void nextTick(() => {
      if (isFocusableConnectedElement(restoreTarget)) restoreTarget.focus()
    })
  }
}

function rememberAuditRecordTrigger(event?: MouseEvent): void {
  const trigger = event?.currentTarget
  selectedAuditRecordTrigger.value = trigger instanceof HTMLElement ? trigger : null
}

function isFocusableConnectedElement(element: HTMLElement | null): element is HTMLElement {
  if (element === null || !element.isConnected) return false
  if (element instanceof HTMLButtonElement && element.disabled) return false

  return element.tabIndex >= 0
}

function syncFilterDraftFromQuery(): void {
  filterDraft.actorType = auditRecordListQuery.state.filters.actorType
  filterDraft.actorId = auditRecordListQuery.state.filters.actorId
  filterDraft.action = auditRecordListQuery.state.filters.action
  filterDraft.subjectType = auditRecordListQuery.state.filters.subjectType
  filterDraft.subjectId = auditRecordListQuery.state.filters.subjectId
  filterDraft.correlationId = auditRecordListQuery.state.filters.correlationId
  filterDraft.requestId = auditRecordListQuery.state.filters.requestId
  filterDraft.occurredFrom = auditRecordListQuery.state.filters.occurredFrom
  filterDraft.occurredTo = auditRecordListQuery.state.filters.occurredTo
}

function referenceLabel(reference: AuditActorReference | AuditSubjectReference): string {
  const type = reference.type ?? 'unknown'
  const id = reference.id ?? 'unknown'

  return `${type} ${id}`
}

function outcomeLabel(outcome: AuditOutcome): string {
  return outcome?.summary ?? outcome?.status ?? outcome?.code ?? 'No outcome'
}

function outcomeCategory(outcome: AuditOutcome): 'neutral' | 'success' | 'warning' | 'danger' | 'information' {
  const summary = outcomeLabel(outcome).toLowerCase()
  if (summary.includes('success') || summary.includes('succeed') || summary.includes('accepted')) return 'success'
  if (summary.includes('fail') || summary.includes('error') || summary.includes('blocked')) return 'danger'
  if (summary === 'no outcome') return 'neutral'

  return 'information'
}

function safeMetadataEntries(metadata: AuditSafeMetadata): MetadataEntry[] {
  return Object.entries(metadata)
    .filter(([, value]) => value !== '[redacted]')
    .slice(0, metadataEntryLimit)
    .map(([key, value]) => ({ key, value: metadataValueLabel(value) }))
}

function metadataValueLabel(value: AuditSafeMetadataValue): string {
  if (value === null) return 'null'
  if (Array.isArray(value)) return `[${value.map((item) => metadataValueLabel(item)).join(', ')}]`.slice(0, metadataValueLimit)
  if (typeof value === 'object') {
    return Object.entries(value)
      .filter(([, nestedValue]) => nestedValue !== '[redacted]')
      .slice(0, 6)
      .map(([key, nestedValue]) => `${key}: ${metadataValueLabel(nestedValue)}`)
      .join(', ')
      .slice(0, metadataValueLimit)
  }

  return String(value).slice(0, metadataValueLimit)
}

watch(
  () => auditRecordListQuery.state,
  () => {
    syncFilterDraftFromQuery()
    void loadAuditRecords()
  },
  { deep: true, immediate: true },
)

watch(tenantContextVersion, () => {
  clearSelectedAuditRecord()
  void loadAuditRecords()
})

onBeforeUnmount(() => {
  selectedAuditRecordTrigger.value = null
})
</script>
