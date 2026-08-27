<template>
  <section
    class="workspace conference-operations"
    aria-labelledby="conference-operations-title"
  >
    <div class="section-heading">
      <div>
        <h2 id="conference-operations-title">
          Conferences
        </h2>
        <p class="meta">
          Inspect conference lifecycle operations and their execution state.
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
        :loading="conferenceListResource.state.status === 'refreshing'"
        loading-label="Refreshing"
        @click="loadConferences"
      >
        Refresh
      </UiButton>
    </div>

    <UiDataList
      :status="conferenceListResource.state.status"
      :error="conferenceListResource.state.error"
      :has-data="conferences.length > 0"
      title="Conference operation list"
      label="Operations"
      loading-label="Loading conference operations."
      refreshing-label="Refreshing conference operations."
      empty-title="No conference operations"
      empty-message="No conference operations were returned."
      error-title="Conference operations unavailable"
      forbidden-title="Conference operations forbidden"
    >
      <template #actions>
        <UiListSummary
          :count="conferences.length"
          item-label="conference operations"
        />
      </template>
      <div class="data-table">
        <div
          v-for="conference in conferences"
          :key="conference.id"
          class="data-row conference-row"
        >
          <span>
            <strong>{{ conference.display_name }}</strong>
            <small>{{ conference.slug }} · {{ shortId(conference.id) }}</small>
            <span class="badge-row">
              <UiStatusBadge
                :label="`desired ${conference.desired_state}`"
                :category="stateCategory(conference.desired_state)"
              />
              <UiStatusBadge
                :label="`observed ${conference.observed_state}`"
                :category="stateCategory(conference.observed_state)"
              />
              <UiStatusBadge
                v-if="conference.failover_state"
                :label="`failover ${conference.failover_state}`"
                :category="stateCategory(conference.failover_state)"
              />
            </span>
          </span>
          <span class="row-actions">
            <UiButton
              type="button"
              variant="secondary"
              :loading="selectedConferenceId === conference.id && selectedConferenceResource.state.status === 'loading'"
              loading-label="Loading details"
              @click="selectConference(conference.id)"
            >
              {{ selectedConferenceId === conference.id ? 'Selected' : 'Details' }}
            </UiButton>
          </span>
          <div
            v-if="selectedConferenceId === conference.id"
            class="subgrid conference-detail-grid"
          >
            <UiLoadingState
              v-if="selectedConferenceResource.state.status === 'loading'"
              label="Loading conference operation detail."
            />
            <UiAlert
              v-if="selectedConferenceResource.state.status === 'error' || selectedConferenceResource.state.status === 'forbidden'"
              variant="error"
              title="Conference operation detail unavailable"
            >
              {{ selectedConferenceResource.state.error }}
            </UiAlert>
            <section
              v-if="selectedConference"
              class="detail-section"
              aria-label="Selected conference operation detail"
            >
              <h3>{{ selectedConference.display_name }}</h3>
              <dl class="definition-grid">
                <div>
                  <dt>Runtime node</dt>
                  <dd>{{ selectedConference.runtime_node_id ?? 'Unassigned' }}</dd>
                </div>
                <div>
                  <dt>Binding</dt>
                  <dd>{{ selectedConference.runtime_binding_lifecycle_status ?? 'Unavailable' }}</dd>
                </div>
                <div>
                  <dt>Observed</dt>
                  <dd>{{ selectedConference.observed_at ?? 'Not observed' }}</dd>
                </div>
                <div>
                  <dt>Generation</dt>
                  <dd>{{ selectedConference.configuration_generation }}</dd>
                </div>
              </dl>
            </section>
            <section
              class="detail-section"
              aria-label="Conference participants"
            >
              <div class="section-heading">
                <h3>Participants</h3>
                <UiListSummary
                  :count="participants.length"
                  item-label="participants"
                />
              </div>
              <UiLoadingState
                v-if="participantsResource.state.status === 'loading'"
                label="Loading conference participants."
              />
              <UiAlert
                v-if="participantsResource.state.status === 'error' || participantsResource.state.status === 'forbidden'"
                variant="error"
                title="Conference participants unavailable"
              >
                {{ participantsResource.state.error }}
              </UiAlert>
              <UiEmptyState
                v-if="participantsResource.state.status === 'empty'"
                title="No participants"
                message="No participants were returned for this conference operation."
              />
              <div
                v-if="participants.length > 0"
                class="data-table"
              >
                <div
                  v-for="participant in participants"
                  :key="participant.id"
                  class="data-row participant-row"
                >
                  <span>
                    <strong>{{ participant.role }}</strong>
                    <small>{{ shortId(participant.id) }} · session {{ shortId(participant.telephony_session_id) }}</small>
                    <span class="badge-row">
                      <UiStatusBadge
                        :label="`desired ${participant.desired_state}`"
                        :category="stateCategory(participant.desired_state)"
                      />
                      <UiStatusBadge
                        :label="`observed ${participant.observed_state}`"
                        :category="stateCategory(participant.observed_state)"
                      />
                    </span>
                  </span>
                  <span>
                    <small>{{ participant.joined_at ?? 'Not joined' }}</small>
                    <small v-if="participant.failure_class">{{ participant.failure_class }} · {{ participant.failure_code ?? 'failure' }}</small>
                  </span>
                </div>
              </div>
            </section>
          </div>
        </div>
      </div>
    </UiDataList>
  </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { identityApi, type Conference, type ConferenceParticipant } from '../api/platform'
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiDataList from '../components/ui/UiDataList.vue'
import UiEmptyState from '../components/ui/UiEmptyState.vue'
import UiListSummary from '../components/ui/UiListSummary.vue'
import UiLoadingState from '../components/ui/UiLoadingState.vue'
import UiStatusBadge from '../components/ui/UiStatusBadge.vue'
import { useAsyncResource } from '../composables/asyncState'
import {
  leaveConferenceRealtimeTenant,
  resynchronizeRuntimeNodeRealtime,
  runtimeNodeRealtimeConnectionState,
  runtimeNodeRealtimeStatusText,
  subscribeConferenceRealtime,
} from '../realtime/runtimeNodeRealtime'
import { apiErrorMessage, can, session, shortId, tenantContextVersion } from '../state/appState'

const conferences = ref<Conference[]>([])
const selectedConferenceId = ref<string | null>(null)
const selectedConference = ref<Conference | null>(null)
const participants = ref<ConferenceParticipant[]>([])

const conferenceListResource = useAsyncResource(async () => {
  conferences.value = (await identityApi.conferences()).conferences

  return conferences.value
}, {
  isEmpty: (rows) => rows.length === 0,
  getErrorMessage: apiErrorMessage,
})

const selectedConferenceResource = useAsyncResource(async () => {
  if (selectedConferenceId.value === null) return null
  selectedConference.value = (await identityApi.conference(selectedConferenceId.value)).conference

  return selectedConference.value
}, {
  isEmpty: (row) => row === null,
  getErrorMessage: apiErrorMessage,
})

const participantsResource = useAsyncResource(async () => {
  if (selectedConferenceId.value === null) {
    participants.value = []

    return participants.value
  }
  participants.value = (await identityApi.conferenceParticipants(selectedConferenceId.value)).participants

  return participants.value
}, {
  isEmpty: (rows) => rows.length === 0,
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

function sessionCanViewConferences(): boolean {
  return session.value !== null && activeTenantId() !== '' && can('telephony.conferences.view')
}

async function loadConferences(): Promise<void> {
  const loaded = await conferenceListResource.load()
  if (loaded !== null) subscribeAfterCanonicalSnapshot()
}

async function selectConference(conferenceId: string): Promise<void> {
  selectedConferenceId.value = conferenceId
  await refreshSelectedConference(conferenceId)
}

async function refreshSelectedConference(conferenceId: string): Promise<void> {
  if (selectedConferenceId.value !== conferenceId) return
  await selectedConferenceResource.load()
  if (selectedConferenceId.value !== conferenceId) return
  await participantsResource.load()
}

function subscribeAfterCanonicalSnapshot(): void {
  if (!sessionCanViewConferences()) return
  subscribeConferenceRealtime({
    tenantId: activeTenantId(),
    refreshList: () => conferenceListResource.load(),
    refreshSelectedConference,
    selectedConferenceId: () => selectedConferenceId.value,
    sessionActive: sessionCanViewConferences,
  })
}

function clearSelectedConference(): void {
  selectedConferenceId.value = null
  selectedConference.value = null
  participants.value = []
  selectedConferenceResource.reset()
  participantsResource.reset()
}

function stateCategory(state: string): 'neutral' | 'success' | 'warning' | 'danger' | 'information' {
  if (['active', 'open', 'ready', 'joined', 'present'].includes(state)) return 'success'
  if (['draining', 'pending', 'joining', 'removing', 'failover_in_progress'].includes(state)) return 'warning'
  if (['failed', 'degraded', 'removed', 'closed', 'absent'].includes(state)) return 'danger'

  return 'neutral'
}

function handleVisibilityChange(): void {
  if (globalThis.document?.visibilityState === 'visible') {
    void resynchronizeRuntimeNodeRealtime()
  }
}

watch(tenantContextVersion, () => {
  clearSelectedConference()
  void loadConferences()
}, { immediate: true })

onMounted(() => {
  globalThis.document?.addEventListener('visibilitychange', handleVisibilityChange)
})

onBeforeUnmount(() => {
  globalThis.document?.removeEventListener('visibilitychange', handleVisibilityChange)
  leaveConferenceRealtimeTenant()
})
</script>
