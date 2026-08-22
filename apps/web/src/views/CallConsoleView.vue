<template>
  <section
    class="workspace call-console"
    aria-labelledby="call-console-title"
  >
    <div class="section-heading">
      <div>
        <h2 id="call-console-title">
          Calls
        </h2>
        <p class="meta">
          Minimal reference consumer for canonical generic calls.
        </p>
      </div>
      <UiButton
        type="button"
        variant="secondary"
        :loading="loading"
        loading-label="Refreshing"
        @click="void loadCalls()"
      >
        Refresh
      </UiButton>
    </div>

    <UiAlert
      v-if="errorMessage"
      variant="error"
      title="Call console unavailable"
    >
      {{ errorMessage }}
    </UiAlert>

    <div class="call-console-grid">
      <UiPanel
        title="Call list"
        label="Canonical calls"
      >
        <UiLoadingState
          v-if="loading && calls.length === 0"
          label="Loading calls."
        />
        <UiEmptyState
          v-else-if="calls.length === 0"
          title="No calls"
          message="No tenant-scoped generic Calls are available."
        />
        <div
          v-else
          class="data-table"
        >
          <button
            v-for="call in calls"
            :key="call.id"
            type="button"
            class="data-row call-list-row"
            :class="{ 'call-list-row--selected': selectedCallId === call.id }"
            @click="void selectCall(call.id)"
          >
            <span>
              <strong>{{ shortId(call.id) }}</strong>
              <small>{{ call.direction }} · {{ formatDate(call.created_at) }}</small>
            </span>
            <UiStatusBadge
              :label="call.state"
              :category="stateCategory(call.state)"
            />
          </button>
        </div>
      </UiPanel>

      <UiPanel
        title="New outbound Call"
        label="C6 reference consumer"
        description="Uses the pre-C7 normalized destination seam."
      >
        <form
          class="call-form"
          @submit.prevent="void createOutbound()"
        >
          <UiFormField
            id="call-destination"
            label="Destination"
          >
            <template #default="{ id }">
              <UiTextInput
                :id="id"
                v-model="newCall.destination"
                placeholder="opaque:destination-1"
                required
                :disabled="creating"
              />
            </template>
          </UiFormField>
          <UiFormField
            id="call-runtime-node"
            label="Runtime node ID (optional pre-C7 seam)"
          >
            <template #default="{ id }">
              <UiTextInput
                :id="id"
                v-model="newCall.runtimeNodeId"
                placeholder="Runtime node UUID"
                :disabled="creating"
              />
            </template>
          </UiFormField>
          <UiButton
            type="submit"
            :disabled="!canOriginate || newCall.destination.trim() === ''"
            :loading="creating"
            loading-label="Creating"
          >
            Create outbound Call
          </UiButton>
          <p
            v-if="!canOriginate"
            class="meta"
          >
            Origination permission is required.
          </p>
        </form>
      </UiPanel>
    </div>

    <UiPanel
      v-if="selectedCall !== null"
      title="Selected Call"
      label="Canonical state"
    >
      <div class="section-heading">
        <div>
          <h3>{{ shortId(selectedCall.id) }}</h3>
          <p class="meta">
            State is read from the canonical Call API after observation processing.
          </p>
        </div>
        <UiStatusBadge
          :label="selectedCall.state"
          :category="stateCategory(selectedCall.state)"
        />
      </div>
      <dl class="definition-grid">
        <div><dt>Call ID</dt><dd>{{ selectedCall.id }}</dd></div>
        <div><dt>Direction</dt><dd>{{ selectedCall.direction }}</dd></div>
        <div><dt>Created</dt><dd>{{ formatDate(selectedCall.created_at) }}</dd></div>
        <div><dt>Termination</dt><dd>{{ selectedCall.termination_reason ?? 'Not terminal' }}</dd></div>
        <div><dt>Destination seam</dt><dd>{{ selectedCall.destination_ref ?? 'Not present' }}</dd></div>
      </dl>

      <div class="call-console-grid call-console-grid--details">
        <section
          class="detail-section"
          aria-label="Call legs"
        >
          <div class="section-heading">
            <h3>CallLegs</h3>
            <UiButton
              type="button"
              variant="secondary"
              :loading="detailLoading"
              @click="void refreshSelected()"
            >
              Refresh detail
            </UiButton>
          </div>
          <UiEmptyState
            v-if="legs.length === 0"
            title="No CallLegs"
            message="No canonical legs are attached to this Call."
          />
          <div
            v-for="leg in legs"
            :key="leg.id"
            class="call-leg-card"
            :class="{ 'call-leg-card--selected': selectedLegId === leg.id }"
          >
            <div class="section-heading">
              <div>
                <strong>{{ shortId(leg.id) }}</strong>
                <p class="meta">
                  {{ leg.role }} · {{ leg.direction }}
                </p>
              </div>
              <UiStatusBadge
                :label="leg.state"
                :category="stateCategory(leg.state)"
              />
            </div>
            <dl class="definition-grid">
              <div><dt>Runtime node</dt><dd>{{ leg.runtime_node_id ?? 'Unbound' }}</dd></div>
              <div><dt>Runtime channel</dt><dd>{{ leg.runtime_channel_id ?? 'Unbound' }}</dd></div>
              <div><dt>Remote identity</dt><dd>{{ leg.remote_identity ?? 'Unavailable' }}</dd></div>
              <div><dt>Bridge</dt><dd>{{ leg.bridged_to_leg_id ? shortId(leg.bridged_to_leg_id) : 'Not bridged' }}</dd></div>
              <div><dt>Termination</dt><dd>{{ leg.termination_reason ?? 'Not terminal' }}</dd></div>
            </dl>
            <div class="call-controls">
              <UiButton
                v-if="normalizeCallLegState(leg.state) === 'offered'"
                type="button"
                :disabled="!canControl"
                :loading="activeOperation === 'call.leg.answer'"
                @click.stop="void submitOperation('call.leg.answer', leg.id)"
              >
                Answer
              </UiButton>
              <UiButton
                v-if="normalizeCallLegState(leg.state) === 'answered'"
                type="button"
                variant="secondary"
                :disabled="!canControl"
                :loading="activeOperation === 'call.leg.hold'"
                @click.stop="void submitOperation('call.leg.hold', leg.id)"
              >
                Hold
              </UiButton>
              <UiButton
                v-if="normalizeCallLegState(leg.state) === 'held'"
                type="button"
                variant="secondary"
                :disabled="!canControl"
                :loading="activeOperation === 'call.leg.resume'"
                @click.stop="void submitOperation('call.leg.resume', leg.id)"
              >
                Resume
              </UiButton>
              <UiButton
                v-if="!isTerminal(leg.state)"
                type="button"
                variant="danger"
                :disabled="!operationAllowed(pendingOperation(leg))"
                :loading="activeOperation === pendingOperation(leg)"
                @click.stop="void submitOperation(pendingOperation(leg), leg.id)"
              >
                {{ pendingOperation(leg) === 'call.leg.cancel_origination' ? 'Cancel origination' : 'Hang up' }}
              </UiButton>
            </div>
          </div>
          <div
            v-if="selectedLeg !== null && !isTerminal(selectedLeg.state)"
            class="call-controls"
          >
            <UiTextInput
              v-model="dtmfDigit"
              aria-label="DTMF digit"
              placeholder="DTMF digit"
              :disabled="!canControl || activeOperation !== null"
            />
            <UiButton
              type="button"
              variant="secondary"
              :disabled="!canControl || dtmfDigit.trim() === ''"
              :loading="activeOperation === 'call.leg.send_dtmf'"
              @click="void sendDtmf()"
            >
              Send DTMF
            </UiButton>
          </div>
          <p
            v-if="!canControl"
            class="meta"
          >
            Call control permission is required for operation buttons.
          </p>
        </section>

        <section
          class="detail-section"
          aria-label="Call operations"
        >
          <h3>Operations</h3>
          <UiEmptyState
            v-if="operations.length === 0"
            title="No operations"
            message="No normalized runtime operations are recorded for this Call."
          />
          <div
            v-for="operation in operations"
            :key="operation.id"
            class="timeline-entry"
          >
            <UiStatusBadge
              :label="operation.status"
              :category="stateCategory(operation.status)"
            />
            <span><strong>{{ operation.operation_type }}</strong><small>{{ formatDate(operation.created_at) }}</small></span>
          </div>
        </section>
      </div>

      <section
        class="detail-section"
        aria-label="Call timeline"
      >
        <h3>Timeline</h3>
        <p class="meta">
          COMMAND and OBSERVATION remain separate; this view never derives state locally.
        </p>
        <UiEmptyState
          v-if="timeline.length === 0"
          title="No timeline entries"
          message="No normalized history is available for this Call."
        />
        <div
          v-for="entry in timeline"
          :key="entry.id"
          class="timeline-entry"
        >
          <UiStatusBadge
            :label="entry.source.toUpperCase()"
            :category="entry.source === 'observation' ? 'success' : entry.source === 'command' ? 'information' : 'neutral'"
          />
          <span>
            <strong>{{ entry.type }}</strong>
            <small>{{ entry.summary }} · {{ formatDate(entry.occurred_at) }}</small>
          </span>
        </div>
      </section>
    </UiPanel>
  </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiEmptyState from '../components/ui/UiEmptyState.vue'
import UiFormField from '../components/ui/UiFormField.vue'
import UiLoadingState from '../components/ui/UiLoadingState.vue'
import UiPanel from '../components/ui/UiPanel.vue'
import UiStatusBadge from '../components/ui/UiStatusBadge.vue'
import UiTextInput from '../components/ui/UiTextInput.vue'
import { apiErrorMessage, can } from '../state/appState'
import { callApi, type Call, type CallLeg, type CallOperation, type CallTimelineEntry } from '../api/platform'

const calls = ref<Call[]>([])
const selectedCall = ref<Call | null>(null)
const legs = ref<CallLeg[]>([])
const operations = ref<CallOperation[]>([])
const timeline = ref<CallTimelineEntry[]>([])
const selectedCallId = ref<string | null>(null)
const selectedLegId = ref<string | null>(null)
const loading = ref(false)
const detailLoading = ref(false)
const creating = ref(false)
const errorMessage = ref('')
const activeOperation = ref<string | null>(null)
const dtmfDigit = ref('')
const newCall = ref({ destination: '', runtimeNodeId: '' })
let refreshTimer: ReturnType<typeof setInterval> | null = null

const canOriginate = computed(() => can('telephony.calls.originate'))
const canControl = computed(() => can('telephony.calls.control'))
const selectedLeg = computed(() => legs.value.find((leg) => leg.id === selectedLegId.value) ?? null)

function idempotencyKey(prefix: string): string {
  return `${prefix}-${globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`}`
}

function shortId(value: string): string {
  return value.length > 12 ? `${value.slice(0, 8)}…${value.slice(-4)}` : value
}

function formatDate(value: string | null): string {
  if (!value) return 'Unavailable'
  const date = new Date(value)

  return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
}

function normalizeCallLegState(value: string): string {
  return value.trim().toLowerCase()
}

function stateCategory(value: string): 'success' | 'danger' | 'warning' | 'information' | 'neutral' {
  const state = normalizeCallLegState(value)
  if (['answered', 'succeeded', 'completed', 'connected'].includes(state)) return 'success'
  if (['failed', 'cancelled', 'rejected', 'error'].includes(state)) return 'danger'
  if (['held', 'ringing', 'offered', 'running', 'requested'].includes(state)) return 'warning'
  if (['originating', 'selecting_route', 'pending'].includes(state)) return 'information'

  return 'neutral'
}

function isTerminal(value: string): boolean {
  return ['completed', 'failed', 'cancelled'].includes(normalizeCallLegState(value))
}

function pendingOperation(leg: CallLeg): 'call.leg.cancel_origination' | 'call.leg.hangup' {
  return leg.direction.toLowerCase() === 'outbound' && ['requested', 'selecting_route', 'originating', 'pending'].includes(normalizeCallLegState(leg.state))
    ? 'call.leg.cancel_origination'
    : 'call.leg.hangup'
}

function operationAllowed(operationType: string): boolean {
  return operationType === 'call.leg.cancel_origination' ? canOriginate.value : canControl.value
}

async function loadCalls(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    const response = await callApi.list({ per_page: 50 })
    calls.value = response.data
    if (selectedCallId.value !== null && !calls.value.some((call) => call.id === selectedCallId.value)) {
      selectedCallId.value = null
      selectedCall.value = null
    }
  } catch (error) {
    errorMessage.value = apiErrorMessage(error)
  } finally {
    loading.value = false
  }
}

async function selectCall(callId: string): Promise<void> {
  selectedCallId.value = callId
  selectedLegId.value = null
  await refreshSelected()
}

async function refreshSelected(): Promise<void> {
  if (selectedCallId.value === null) return
  detailLoading.value = true
  errorMessage.value = ''
  try {
    const [callResponse, legResponse, operationResponse, timelineResponse] = await Promise.all([
      callApi.get(selectedCallId.value),
      callApi.legs(selectedCallId.value, { per_page: 50 }),
      callApi.operations(selectedCallId.value, { per_page: 50 }),
      callApi.timeline(selectedCallId.value, { per_page: 50 }),
    ])
    selectedCall.value = callResponse.data
    legs.value = legResponse.data
    operations.value = operationResponse.data
    timeline.value = timelineResponse.data
    if (selectedLegId.value === null || !legs.value.some((leg) => leg.id === selectedLegId.value)) {
      selectedLegId.value = legs.value[0]?.id ?? null
    }
  } catch (error) {
    errorMessage.value = apiErrorMessage(error)
  } finally {
    detailLoading.value = false
  }
}

async function createOutbound(): Promise<void> {
  if (!canOriginate.value || newCall.value.destination.trim() === '' || creating.value) return
  creating.value = true
  errorMessage.value = ''
  try {
    const response = await callApi.createOutbound(newCall.value.destination.trim(), newCall.value.runtimeNodeId.trim(), idempotencyKey('call-create'))
    newCall.value.destination = ''
    newCall.value.runtimeNodeId = ''
    await loadCalls()
    await selectCall(response.data.id)
  } catch (error) {
    errorMessage.value = apiErrorMessage(error)
  } finally {
    creating.value = false
  }
}

async function submitOperation(operationType: string, legId: string | null): Promise<void> {
  if (!operationAllowed(operationType) || selectedCall.value === null || activeOperation.value !== null) return
  activeOperation.value = operationType
  errorMessage.value = ''
  try {
    await callApi.submitOperation(selectedCall.value.id, operationType, legId, {}, idempotencyKey('call-operation'))
    await refreshSelected()
  } catch (error) {
    errorMessage.value = apiErrorMessage(error)
  } finally {
    activeOperation.value = null
  }
}

async function sendDtmf(): Promise<void> {
  const digit = dtmfDigit.value.trim()
  if (digit === '' || selectedLeg.value === null) return
  activeOperation.value = 'call.leg.send_dtmf'
  errorMessage.value = ''
  try {
    await callApi.submitOperation(selectedCall.value?.id ?? '', 'call.leg.send_dtmf', selectedLeg.value.id, { digit }, idempotencyKey('call-operation'))
    dtmfDigit.value = ''
    await refreshSelected()
  } catch (error) {
    errorMessage.value = apiErrorMessage(error)
  } finally {
    activeOperation.value = null
  }
}

onMounted(async () => {
  await loadCalls()
  refreshTimer = setInterval(() => {
    void loadCalls()
    if (selectedCallId.value !== null && selectedCall.value !== null && !isTerminal(selectedCall.value.state)) void refreshSelected()
  }, 5000)
})

onBeforeUnmount(() => {
  if (refreshTimer !== null) clearInterval(refreshTimer)
})

</script>

<style scoped>
.call-console-grid {
  display: grid;
  gap: 1rem;
  grid-template-columns: minmax(0, 1.2fr) minmax(18rem, 0.8fr);
}

.call-console-grid--details {
  grid-template-columns: minmax(0, 1.4fr) minmax(18rem, 0.6fr);
}

.call-list-row {
  align-items: center;
  background: transparent;
  border: 0;
  border-bottom: 1px solid var(--color-border);
  color: inherit;
  cursor: pointer;
  display: flex;
  justify-content: space-between;
  padding: 0.75rem;
  text-align: left;
  width: 100%;
}

.call-list-row:hover,
.call-list-row--selected {
  background: var(--color-surface-muted);
}

.call-list-row small,
.timeline-entry small {
  display: block;
  margin-top: 0.25rem;
}

.call-form,
.call-controls {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
}

.call-leg-card {
  border: 1px solid var(--color-border);
  border-radius: 0.5rem;
  cursor: pointer;
  margin-top: 0.75rem;
  padding: 0.75rem;
}

.call-leg-card--selected {
  border-color: var(--color-accent);
}

.timeline-entry {
  align-items: center;
  border-bottom: 1px solid var(--color-border);
  display: flex;
  gap: 0.75rem;
  padding: 0.65rem 0;
}

@media (max-width: 900px) {
  .call-console-grid,
  .call-console-grid--details {
    grid-template-columns: 1fr;
  }
}
</style>
