<template>
  <section
    class="workspace call-console"
    aria-labelledby="call-console-title"
  >
    <div class="section-heading">
      <div>
        <h2 id="call-console-title">
          Calls
        </h2><p class="meta">
          Observe tenant-scoped call lifecycle, execution and control-plane evidence.
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
      v-if="listError"
      variant="error"
      title="Calls could not be loaded"
    >
      {{ listError }}
    </UiAlert>
    <div class="call-console-grid">
      <UiPanel
        title="Calls"
        label="Call lifecycle"
      >
        <UiLoadingState
          v-if="loading && calls.length === 0"
          label="Loading calls."
        />
        <UiEmptyState
          v-else-if="calls.length === 0"
          title="No calls"
          message="No calls were returned for the active tenant."
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
            :aria-label="`${directionLabel(call.direction)} call ${destinationLabel(call)}`"
            @click="void selectCall(call.id)"
          >
            <span><strong>{{ directionLabel(call.direction) }} · {{ destinationLabel(call) }}</strong><small>{{ formatDate(call.created_at) }} · Call {{ shortId(call.id) }}</small></span>
            <UiStatusBadge
              :label="stateLabel(call.state)"
              :category="stateCategory(call.state)"
            />
          </button>
        </div>
      </UiPanel>
      <UiPanel
        title="Operational context"
        label="Control-plane evidence"
      >
        <p class="meta">
          Calls are observed through canonical lifecycle, leg, operation and timeline records. Origination and endpoint interaction remain application concerns.
        </p>
        <dl class="definition-grid">
          <div><dt>Call list</dt><dd>Tenant-scoped canonical calls</dd></div><div><dt>Detail evidence</dt><dd>Call Legs, operations and timeline</dd></div><div><dt>Runtime identity</dt><dd>Resolved from authorized Telephony Nodes where available</dd></div>
        </dl>
      </UiPanel>
    </div>

    <UiPanel
      v-if="selectedCall !== null"
      title="Call overview"
      label="Canonical call facts"
    >
      <UiAlert
        v-if="detailCallError"
        variant="error"
        title="Call overview unavailable"
      >
        {{ detailCallError }}
      </UiAlert>
      <div class="section-heading">
        <div>
          <h3>{{ directionLabel(selectedCall.direction) }} → {{ destinationLabel(selectedCall) }}</h3><p class="meta">
            The state below is read from the canonical Call API.
          </p>
        </div><UiStatusBadge
          :label="stateLabel(selectedCall.state)"
          :category="stateCategory(selectedCall.state)"
        />
      </div>
      <dl class="definition-grid">
        <div><dt>Direction</dt><dd>{{ directionLabel(selectedCall.direction) }}</dd></div><div><dt>Destination / remote party</dt><dd>{{ destinationLabel(selectedCall) }}</dd></div><div><dt>Telephony Node</dt><dd>{{ selectedRuntimeLabel }}</dd></div><div><dt>Created</dt><dd>{{ formatDate(selectedCall.created_at) }}</dd></div><div><dt>Answered</dt><dd>Unavailable in canonical Call data</dd></div><div><dt>Ended</dt><dd>{{ formatDate(selectedCall.terminated_at) }}</dd></div><div><dt>Duration</dt><dd>{{ durationLabel }}</dd></div><div><dt>Termination reason</dt><dd>{{ selectedCall.termination_reason ?? 'Not terminal' }}</dd></div>
      </dl>
      <details class="technical-details">
        <summary>Technical details</summary><dl class="definition-grid">
          <div><dt>Call ID</dt><dd>{{ selectedCall.id }}</dd></div><div><dt>Correlation ID</dt><dd>{{ selectedCall.correlation_id ?? 'Unavailable' }}</dd></div><div><dt>Destination reference</dt><dd>{{ selectedCall.destination_ref ?? 'Unavailable' }}</dd></div>
        </dl>
      </details>

      <div class="call-console-grid call-console-grid--details">
        <section
          class="detail-section"
          aria-labelledby="call-legs-title"
        >
          <div class="section-heading">
            <h3 id="call-legs-title">
              Call Legs
            </h3><UiButton
              type="button"
              variant="secondary"
              :loading="detailLoading"
              @click="void refreshSelected()"
            >
              Refresh detail
            </UiButton>
          </div>
          <UiAlert
            v-if="legsError"
            variant="error"
            title="Call Legs unavailable"
          >
            {{ legsError }}
          </UiAlert><UiLoadingState
            v-else-if="legsLoading"
            label="Loading Call Legs."
          /><UiEmptyState
            v-else-if="legs.length === 0"
            title="No Call Legs"
            message="No canonical legs are attached to this Call."
          />
          <div
            v-for="leg in legs"
            v-else
            :key="leg.id"
            class="call-leg-card"
          >
            <div class="section-heading">
              <div>
                <strong>{{ directionLabel(leg.direction) }} · {{ leg.role }}</strong><p class="meta">
                  Remote identity: {{ leg.remote_identity ?? 'Unavailable' }}
                </p>
              </div><UiStatusBadge
                :label="stateLabel(leg.state)"
                :category="stateCategory(leg.state)"
              />
            </div><dl class="definition-grid">
              <div><dt>Telephony Node</dt><dd>{{ runtimeLabel(leg.runtime_node_id) }}</dd></div><div><dt>Started</dt><dd>Unavailable in canonical Call Leg data</dd></div><div><dt>Answered</dt><dd>Unavailable in canonical Call Leg data</dd></div><div><dt>Ended</dt><dd>{{ formatDate(leg.terminated_at) }}</dd></div><div><dt>Bridge</dt><dd>{{ leg.bridged_to_leg_id ? `Call Leg ${shortId(leg.bridged_to_leg_id)}` : 'Not bridged' }}</dd></div><div><dt>Termination reason</dt><dd>{{ leg.termination_reason ?? 'Not terminal' }}</dd></div>
            </dl><details class="technical-details">
              <summary>Technical details</summary><dl class="definition-grid">
                <div><dt>Call Leg ID</dt><dd>{{ leg.id }}</dd></div><div><dt>Runtime channel ID</dt><dd>{{ leg.runtime_channel_id ?? 'Unavailable' }}</dd></div><div><dt>Telephony session ID</dt><dd>{{ leg.telephony_session_id ?? 'Unavailable' }}</dd></div>
              </dl>
            </details>
          </div>
        </section>
        <section
          class="detail-section"
          aria-labelledby="call-operations-title"
        >
          <h3 id="call-operations-title">
            Operations history
          </h3><UiAlert
            v-if="operationsError"
            variant="error"
            title="Operations unavailable"
          >
            {{ operationsError }}
          </UiAlert><UiLoadingState
            v-else-if="operationsLoading"
            label="Loading operations."
          /><UiEmptyState
            v-else-if="operations.length === 0"
            title="No operations"
            message="No normalized operations are recorded for this Call."
          /><div
            v-for="operation in operations"
            v-else
            :key="operation.id"
            class="timeline-entry"
          >
            <UiStatusBadge
              :label="stateLabel(operation.status)"
              :category="stateCategory(operation.status)"
            /><span><strong>{{ operationLabel(operation.operation_type) }}</strong><small>{{ formatDate(operation.created_at) }} · {{ operation.target.type }} {{ shortId(operation.target.id) }}</small><small v-if="operation.completed_at">Completed {{ formatDate(operation.completed_at) }}</small><small
              v-if="operation.failure_code"
              class="failure-text"
            >Failure: {{ operation.failure_code }}</small></span>
          </div>
        </section>
      </div>
      <section
        class="detail-section"
        aria-labelledby="call-timeline-title"
      >
        <h3 id="call-timeline-title">
          Timeline
        </h3><p class="meta">
          Commands and observations remain separate; the UI does not derive Call state from timeline events.
        </p><UiAlert
          v-if="timelineError"
          variant="error"
          title="Timeline unavailable"
        >
          {{ timelineError }}
        </UiAlert><UiLoadingState
          v-else-if="timelineLoading"
          label="Loading timeline."
        /><UiEmptyState
          v-else-if="timeline.length === 0"
          title="No timeline entries"
          message="No normalized history is available for this Call."
        /><div
          v-for="entry in timeline"
          v-else
          :key="entry.id"
          class="timeline-entry"
        >
          <UiStatusBadge
            :label="sourceLabel(entry.source)"
            :category="sourceCategory(entry.source)"
          /><span><strong>{{ entry.type }}</strong><small>{{ entry.summary }} · {{ formatDate(entry.occurred_at) }}</small><small v-if="entry.leg_id">Call Leg {{ shortId(entry.leg_id) }}</small></span>
        </div>
      </section>
    </UiPanel>
  </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import UiAlert from '../components/ui/UiAlert.vue'; import UiButton from '../components/ui/UiButton.vue'; import UiEmptyState from '../components/ui/UiEmptyState.vue'; import UiLoadingState from '../components/ui/UiLoadingState.vue'; import UiPanel from '../components/ui/UiPanel.vue'; import UiStatusBadge from '../components/ui/UiStatusBadge.vue'
import { callApi, identityApi, type Call, type CallLeg, type CallOperation, type CallTimelineEntry, type RuntimeNode } from '../api/platform'
import { apiErrorMessage, can, tenantContextVersion } from '../state/appState'
const calls = ref<Call[]>([]); const selectedCall = ref<Call | null>(null); const legs = ref<CallLeg[]>([]); const operations = ref<CallOperation[]>([]); const timeline = ref<CallTimelineEntry[]>([]); const runtimeNodes = ref<RuntimeNode[]>([]); const selectedCallId = ref<string | null>(null)
const loading = ref(false); const detailLoading = ref(false); const legsLoading = ref(false); const operationsLoading = ref(false); const timelineLoading = ref(false); const listError = ref(''); const detailCallError = ref(''); const legsError = ref(''); const operationsError = ref(''); const timelineError = ref('')
let refreshTimer: ReturnType<typeof setInterval> | null = null; let detailGeneration = 0
function shortId(value: string): string { return value.length > 12 ? `${value.slice(0, 8)}…${value.slice(-4)}` : value }
function formatDate(value: string | null): string { if (!value) return 'Unavailable'; const date = new Date(value); return Number.isNaN(date.getTime()) ? value : date.toLocaleString() }
function normalize(value: string): string { return value.trim().toLowerCase() }
function stateLabel(value: string): string {
  const label = value.replaceAll('_', ' ')

  return label.charAt(0).toUpperCase() + label.slice(1)
}
function directionLabel(value: string): string { return value.charAt(0).toUpperCase() + value.slice(1) }
function stateCategory(value: string): 'success' | 'danger' | 'warning' | 'information' | 'neutral' { const state = normalize(value); if (['answered', 'connected', 'completed', 'succeeded'].includes(state)) return 'success'; if (['failed', 'cancelled', 'rejected', 'error', 'terminal_failed'].includes(state)) return 'danger'; if (['offered', 'ringing', 'running', 'requested', 'held'].includes(state)) return 'warning'; if (['originating', 'selecting_route', 'pending', 'leased', 'retry_scheduled'].includes(state)) return 'information'; return 'neutral' }
function destinationLabel(call: Call): string { return call.destination_ref ?? 'Destination unavailable' }
function runtimeLabel(id: string | null): string { if (!id) return 'Reference unavailable'; const node = runtimeNodes.value.find((candidate) => candidate.id === id); return node ? `${node.name} (${node.runtime_family})` : `Reference unavailable (${shortId(id)})` }
function operationLabel(value: string): string { return ({ 'call.leg.hangup': 'Terminate leg', 'call.leg.cancel_origination': 'Cancel origination' } as Record<string, string>)[value] ?? value }
function sourceLabel(value: string): string { return value.charAt(0).toUpperCase() + value.slice(1) }
function sourceCategory(value: string): 'success' | 'information' | 'neutral' { return value === 'observation' ? 'success' : value === 'command' ? 'information' : 'neutral' }
const selectedRuntimeLabel = computed(() => runtimeLabel(legs.value.find((leg) => leg.runtime_node_id)?.runtime_node_id ?? null)); const durationLabel = computed(() => 'Unavailable without canonical answered timestamp')
async function loadRuntimeNodes(): Promise<void> { if (!can('runtime.nodes.view')) { runtimeNodes.value = []; return } try { runtimeNodes.value = (await identityApi.runtimeNodes()).runtime_nodes } catch { runtimeNodes.value = [] } }
async function loadCalls(): Promise<void> { loading.value = true; listError.value = ''; try { const response = await callApi.list({ per_page: 50 }); calls.value = response.data; if (selectedCallId.value && !calls.value.some((call) => call.id === selectedCallId.value)) clearSelection() } catch (error) { listError.value = apiErrorMessage(error) } finally { loading.value = false } }
function clearSelection(): void { selectedCallId.value = null; selectedCall.value = null; legs.value = []; operations.value = []; timeline.value = []; detailCallError.value = ''; legsError.value = ''; operationsError.value = ''; timelineError.value = ''; detailGeneration += 1 }
async function selectCall(callId: string): Promise<void> { clearSelection(); selectedCallId.value = callId; await refreshSelected() }
async function refreshSelected(): Promise<void> { const callId = selectedCallId.value; if (!callId) return; const generation = ++detailGeneration; const tenantGeneration = tenantContextVersion.value; detailLoading.value = true; detailCallError.value = ''; legsError.value = ''; operationsError.value = ''; timelineError.value = ''; legsLoading.value = true; operationsLoading.value = true; timelineLoading.value = true
  const loadPart = async <T>(load: () => Promise<{ data: T }>, assign: (value: T) => void, fail: (message: string) => void, done: () => void): Promise<void> => { try { const response = await load(); if (generation === detailGeneration && selectedCallId.value === callId && tenantContextVersion.value === tenantGeneration) assign(response.data) } catch (error) { if (generation === detailGeneration && selectedCallId.value === callId && tenantContextVersion.value === tenantGeneration) fail(apiErrorMessage(error)) } finally { done() } }
  await Promise.all([loadPart(() => callApi.get(callId), (value) => { selectedCall.value = value }, (message) => { detailCallError.value = message }, () => { detailLoading.value = false }), loadPart(() => callApi.legs(callId, { per_page: 50 }), (value) => { legs.value = value }, (message) => { legsError.value = message }, () => { legsLoading.value = false }), loadPart(() => callApi.operations(callId, { per_page: 50 }), (value) => { operations.value = value }, (message) => { operationsError.value = message }, () => { operationsLoading.value = false }), loadPart(() => callApi.timeline(callId, { per_page: 50 }), (value) => { timeline.value = value }, (message) => { timelineError.value = message }, () => { timelineLoading.value = false })]); if (generation === detailGeneration) detailLoading.value = false }
watch(tenantContextVersion, () => { clearSelection(); calls.value = []; void loadCalls(); void loadRuntimeNodes() })
onMounted(() => { void loadCalls(); void loadRuntimeNodes(); refreshTimer = setInterval(() => { void loadCalls(); if (selectedCallId.value && selectedCall.value && !['completed', 'failed', 'cancelled'].includes(normalize(selectedCall.value.state))) void refreshSelected() }, 5000) })
onBeforeUnmount(() => { if (refreshTimer !== null) clearInterval(refreshTimer) })
</script>

<style scoped>
.call-console-grid { display: grid; gap: 1rem; grid-template-columns: minmax(0, 1.2fr) minmax(18rem, 0.8fr); }
.call-console-grid--details { grid-template-columns: minmax(0, 1.4fr) minmax(18rem, 0.6fr); }
.call-list-row { align-items: center; background: transparent; border: 0; border-bottom: 1px solid var(--color-border); color: inherit; cursor: pointer; display: flex; justify-content: space-between; padding: 0.75rem; text-align: left; width: 100%; }
.call-list-row:hover, .call-list-row--selected { background: var(--color-surface-muted); }
.call-list-row small, .timeline-entry small { display: block; margin-top: 0.25rem; }
.call-leg-card { border: 1px solid var(--color-border); border-radius: 0.5rem; margin-top: 0.75rem; padding: 0.75rem; }
.timeline-entry { align-items: center; border-bottom: 1px solid var(--color-border); display: flex; gap: 0.75rem; padding: 0.65rem 0; }
.failure-text { color: var(--color-danger); }
.technical-details { margin-top: 1rem; }
.technical-details summary { cursor: pointer; font-weight: 600; }
@media (max-width: 900px) { .call-console-grid, .call-console-grid--details { grid-template-columns: 1fr; } }
</style>
