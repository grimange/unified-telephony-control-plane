<template>
  <div class="view-stack">
    <UiPanel
      label="V0 reference dialer"
      title="Reference dialer"
      description="Session-scoped SIP registration through the canonical UTCP WSS path."
    >
      <UiLoadingState
        v-if="state === 'loading'"
        label="Loading reference-dialer bootstrap."
      />
      <UiAlert
        v-else-if="state === 'error'"
        variant="error"
        title="Reference dialer unavailable"
      >
        {{ errorMessage }}
      </UiAlert>
      <UiAlert
        v-else-if="state === 'connecting'"
        variant="info"
        title="Registering"
      >
        Connecting to the canonical SIP-over-WSS registrar.
      </UiAlert>
      <UiAlert
        v-else-if="state === 'registered'"
        variant="success"
        title="REGISTERED"
      >
        The browser received a successful SIP registration response.
      </UiAlert>
      <UiAlert
        v-else-if="state === 'recovering'"
        variant="info"
        title="Recovering"
      >
        Restoring the canonical conference participation.
      </UiAlert>
      <UiAlert
        v-else-if="state === 'failed'"
        variant="error"
        title="SIP registration failed"
      >
        {{ errorMessage }}
      </UiAlert>
      <div
        v-if="bootstrap !== null"
        class="detail-grid"
        aria-label="Reference dialer session details"
      >
        <div>
          <span class="panel-label">Tenant</span>
          <strong>{{ bootstrap.tenant_id }}</strong>
        </div>
        <div>
          <span class="panel-label">Telephony session</span>
          <strong>{{ bootstrap.telephony_session?.status ?? 'unavailable' }}</strong>
        </div>
        <div>
          <span class="panel-label">Reference client status</span>
          <strong>{{ conferenceState === 'ready' ? 'Ready' : conferenceState === 'joining' ? 'Joining...' : conferenceState === 'connected' ? 'Connected' : conferenceState === 'recovering' ? 'Recovering...' : conferenceState === 'leaving' ? 'Leaving...' : 'Needs attention' }}</strong>
        </div>
      </div>
      <UiAlert
        v-if="conferenceState === 'attention'"
        variant="error"
        title="Conference unavailable"
      >
        {{ conferenceError }}
      </UiAlert>
      <div
        v-if="(conferenceState === 'connected' || conferenceState === 'recovering') && selectedConference !== null"
        class="reference-call-state"
      >
        <p class="panel-label">
          {{ conferenceState === 'connected' ? 'Connected' : 'Recovering...' }}
        </p>
        <strong>{{ selectedConference.display_name }}</strong>
        <button
          type="button"
          @click="void leave()"
        >
          Leave
        </button>
      </div>
      <div
        v-else-if="bootstrap !== null && state === 'registered'"
        class="reference-conferences"
      >
        <h3>Available conferences</h3>
        <p v-if="joinableConferences.length === 0">
          No conferences are currently available.
        </p>
        <ul v-else>
          <li
            v-for="conference in joinableConferences"
            :key="conference.id"
          >
            <span>{{ conference.display_name }}</span>
            <button
              type="button"
              :disabled="conferenceState !== 'ready' && conferenceState !== 'attention'"
              @click="void join(conference)"
            >
              Join
            </button>
          </li>
        </ul>
      </div>
    </UiPanel>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import UiAlert from '../components/ui/UiAlert.vue'
import UiLoadingState from '../components/ui/UiLoadingState.vue'
import UiPanel from '../components/ui/UiPanel.vue'
import { apiErrorMessage, fail } from '../state/appState'
import { referenceDialerApi, type Conference, type ReferenceDialerBootstrap, type ReferenceDialerParticipation } from '../api/platform'
import {
  ReferenceDialerSignalingClient,
  type ReferenceDialerCallState,
  type ReferenceDialerSignalingState,
} from '../signaling/referenceDialerSignaling'
import {
  CONNECTIVITY_DEBOUNCE_MS,
  RECOVERY_REQUEST_TIMEOUT_MS,
  RECOVERY_RETRY_MAX_INDEX,
  recoveryRetryDelay,
} from './recoveryResilience'

type ViewState = 'loading' | 'connecting' | 'registered' | 'recovering' | 'failed' | 'error'
type ConferenceState = 'ready' | 'joining' | 'connected' | 'recovering' | 'leaving' | 'attention'

const state = ref<ViewState>('loading')
const errorMessage = ref('')
const bootstrap = ref<ReferenceDialerBootstrap | null>(null)
const conferenceState = ref<ConferenceState>('ready')
const selectedConference = ref<Conference | null>(null)
const conferenceError = ref('')
let signalingClient: ReferenceDialerSignalingClient | null = null
let conferenceAttempt = 0
let cleanupPromise: Promise<void> | null = null
let recoveryPromise: Promise<void> | null = null
let recoveryTimer: ReturnType<typeof setTimeout> | null = null
let connectivityDebounceTimer: ReturnType<typeof setTimeout> | null = null
let recoveryRequestController: AbortController | null = null
let recoveryRetryIndex = 0
let destroyed = false
let explicitLeaveInFlight = false
let attemptKind: 'new' | 'recovery' | null = null
let awaitingRecoveryBinding = false
let awaitingRecoveryBindingAttemptId: number | null = null
let recoveryParticipantId: string | null = null
let activeConferenceInviteAttemptId: number | null = null

const joinableConferences = computed(() =>
  (bootstrap.value?.conferences ?? []).filter(
    (conference) => conference.desired_state === 'open' && conference.runtime_node_id !== null,
  ),
)

function updateSignalingState(nextState: ReferenceDialerSignalingState, message?: string): void {
  if (nextState === 'registered') {
    state.value = 'registered'
    errorMessage.value = ''
    if (bootstrap.value?.participation !== null && bootstrap.value?.participation !== undefined && conferenceState.value === 'recovering') {
      void beginRecovery()
    }
  } else if (nextState === 'failed') {
    state.value = bootstrap.value?.participation !== null && bootstrap.value?.participation !== undefined
      ? 'recovering'
      : 'failed'
    errorMessage.value = message ?? 'The SIP registrar rejected registration.'
    if (state.value === 'recovering') void beginRecovery()
  } else if (nextState === 'connecting') {
    if (bootstrap.value?.participation !== null && bootstrap.value?.participation !== undefined) {
      state.value = 'recovering'
      void beginRecovery(message)
    } else {
      state.value = 'connecting'
    }
  }
}

function markConferenceConnected(): void {
  clearRecoveryTimer()
  recoveryRetryIndex = 0
  state.value = 'registered'
  conferenceState.value = 'connected'
  conferenceError.value = ''
}

function clearRecoveryBindingWait(): void {
  awaitingRecoveryBinding = false
  awaitingRecoveryBindingAttemptId = null
  recoveryParticipantId = null
}

function transitionConferenceReady(): void {
  state.value = 'registered'
  conferenceState.value = 'ready'
}

function updateCallState(nextState: ReferenceDialerCallState, message?: string, attemptId?: number): void {
  if (destroyed) return
  if (attemptId !== undefined) {
    if (nextState === 'inviting') {
      activeConferenceInviteAttemptId = attemptId
      if (attemptKind === 'recovery' && awaitingRecoveryBinding) {
        awaitingRecoveryBindingAttemptId = attemptId
      }
    } else if (attemptId !== activeConferenceInviteAttemptId) {
      return
    }
  }
  if (nextState === 'connected') {
    if (explicitLeaveInFlight) return
    if (attemptKind === 'recovery' && awaitingRecoveryBinding) {
      state.value = 'recovering'
      conferenceState.value = 'recovering'
      return
    }
    markConferenceConnected()
  } else if (nextState === 'failed') {
    if (attemptKind === 'recovery') {
      if (attemptId !== undefined && awaitingRecoveryBindingAttemptId === attemptId) {
        clearRecoveryBindingWait()
        scheduleRecovery()
      } else {
        void beginRecovery(message)
      }
    } else {
      void finalizeConferenceSession('failed', message ?? 'The conference call could not be established.')
    }
  } else if (nextState === 'terminating') {
    if (explicitLeaveInFlight) conferenceState.value = 'leaving'
  } else if (nextState === 'terminated') {
    if (!explicitLeaveInFlight) {
      if (attemptId !== undefined && awaitingRecoveryBindingAttemptId === attemptId) {
        clearRecoveryBindingWait()
      }
      void beginRecovery()
    }
  }

  if (
    attemptId !== undefined
    && (nextState === 'failed' || nextState === 'terminated')
    && activeConferenceInviteAttemptId === attemptId
  ) {
    activeConferenceInviteAttemptId = null
  }
}

async function finalizeConferenceSession(reason: 'failed' | 'terminated' | 'local-leave', message?: string): Promise<void> {
  if (cleanupPromise !== null) return cleanupPromise
  const conference = selectedConference.value
  if (conference === null) {
    conferenceState.value = reason === 'failed' ? 'attention' : 'ready'
    if (message !== undefined) conferenceError.value = message
    return
  }

  cleanupPromise = (async () => {
    try {
      await referenceDialerApi.leaveConference(conference.id)
      selectedConference.value = null
      conferenceState.value = reason === 'failed' ? 'attention' : 'ready'
      conferenceError.value = reason === 'failed' ? (message ?? 'The conference call could not be established.') : ''
    } catch (errorValue) {
      conferenceState.value = 'attention'
      conferenceError.value = apiErrorMessage(errorValue)
      fail(errorValue, { notify: false })
    } finally {
      cleanupPromise = null
    }
  })()

  return cleanupPromise
}

function clearRecoveryTimer(): void {
  if (recoveryTimer !== null) clearTimeout(recoveryTimer)
  recoveryTimer = null
}

function clearConnectivityDebounce(): void {
  if (connectivityDebounceTimer !== null) clearTimeout(connectivityDebounceTimer)
  connectivityDebounceTimer = null
}

function resetRecoveryRetry(): void {
  recoveryRetryIndex = 0
}

function cancelRecovery(): void {
  clearRecoveryTimer()
  clearConnectivityDebounce()
  recoveryRequestController?.abort()
  recoveryRequestController = null
  resetRecoveryRetry()
  conferenceAttempt += 1
  explicitLeaveInFlight = true
  clearRecoveryBindingWait()
}

async function stopUnboundRecovery(): Promise<void> {
  clearRecoveryTimer()
  resetRecoveryRetry()
  clearRecoveryBindingWait()
  const wasExplicitLeave = explicitLeaveInFlight
  explicitLeaveInFlight = true
  try {
    await signalingClient?.leave()
  } finally {
    activeConferenceInviteAttemptId = null
    explicitLeaveInFlight = wasExplicitLeave
    selectedConference.value = null
    transitionConferenceReady()
    conferenceError.value = ''
  }
}

function conferenceForParticipation(participation: ReferenceDialerParticipation): Conference | null {
  return (bootstrap.value?.conferences ?? []).find((conference) => conference.id === participation.conference_id) ?? null
}

function scheduleRecovery(advanceRetryIndex = true): void {
  clearRecoveryTimer()
  if (destroyed || explicitLeaveInFlight || typeof navigator !== 'undefined' && !navigator.onLine) return

  const delay = recoveryRetryDelay(recoveryRetryIndex)
  if (advanceRetryIndex) recoveryRetryIndex = Math.min(recoveryRetryIndex + 1, RECOVERY_RETRY_MAX_INDEX)
  recoveryTimer = setTimeout(() => void beginRecovery(), delay)
}

async function beginRecovery(message?: string): Promise<void> {
  if (destroyed || explicitLeaveInFlight || recoveryPromise !== null || signalingClient === null) return
  if (typeof navigator !== 'undefined' && !navigator.onLine) return
  if (signalingClient.hasEstablishedConference() && !awaitingRecoveryBinding) {
    markConferenceConnected()
    return
  }

  if (state.value !== 'recovering') resetRecoveryRetry()
  const attemptId = ++conferenceAttempt
  attemptKind = 'recovery'
  state.value = 'recovering'
  conferenceState.value = 'recovering'
  if (message !== undefined) conferenceError.value = message

  const requestController = new AbortController()
  recoveryRequestController = requestController
  recoveryPromise = (async () => {
    const requestOptions = { timeoutMs: RECOVERY_REQUEST_TIMEOUT_MS, signal: requestController.signal }
    try {
      const currentBootstrap = await referenceDialerApi.bootstrap(requestOptions)
      if (destroyed || explicitLeaveInFlight || attemptId !== conferenceAttempt) return
      bootstrap.value = currentBootstrap
      const participation = currentBootstrap.participation
      if (participation === null) {
        if (awaitingRecoveryBinding) {
          await stopUnboundRecovery()
          return
        }
        selectedConference.value = null
        transitionConferenceReady()
        return
      }

      selectedConference.value = conferenceForParticipation(participation)
      if (awaitingRecoveryBinding) {
        if (participation.participant_id === recoveryParticipantId && participation.state === 'active') {
          clearRecoveryBindingWait()
          markConferenceConnected()
          return
        }
        if (!['active', 'awaiting_runtime', 'recoverable'].includes(participation.state)) {
          await stopUnboundRecovery()
          return
        }
        scheduleRecovery(false)
        return
      }
      if (selectedConference.value === null || !participation.recoverable) {
        if (participation.state === 'active' || participation.state === 'awaiting_runtime') {
          scheduleRecovery(false)
        } else {
          selectedConference.value = null
          transitionConferenceReady()
        }
        return
      }

      if (signalingClient.hasEstablishedConference()) {
        markConferenceConnected()
        return
      }
      await signalingClient.ensureRegistered()
      if (destroyed || explicitLeaveInFlight || attemptId !== conferenceAttempt) return
      if (signalingClient.hasEstablishedConference()) {
        markConferenceConnected()
        return
      }

      const admission = await referenceDialerApi.joinConference(participation.conference_id, crypto.randomUUID(), requestOptions)
      if (admission.participant.id !== participation.participant_id) {
        throw new Error('Canonical recovery returned a different participant.')
      }
      if (destroyed || explicitLeaveInFlight || attemptId !== conferenceAttempt) return
      awaitingRecoveryBinding = true
      awaitingRecoveryBindingAttemptId = null
      recoveryParticipantId = admission.participant.id
      const inviteAttemptId = await signalingClient.invite(admission.signaling_destination)
      awaitingRecoveryBindingAttemptId = inviteAttemptId
      if (destroyed || explicitLeaveInFlight || attemptId !== conferenceAttempt) return
      const confirmation = await referenceDialerApi.bootstrap(requestOptions)
      if (destroyed || explicitLeaveInFlight || attemptId !== conferenceAttempt) return
      bootstrap.value = confirmation
      const confirmed = confirmation.participation
      if (confirmed?.participant_id === admission.participant.id && confirmed.state === 'active') {
        clearRecoveryBindingWait()
        markConferenceConnected()
      } else if (confirmed !== null && ['active', 'awaiting_runtime', 'recoverable'].includes(confirmed.state)) {
        scheduleRecovery(false)
      } else {
        await stopUnboundRecovery()
      }
    } catch (errorValue) {
      if (destroyed || explicitLeaveInFlight || attemptId !== conferenceAttempt) return
      if (requestController.signal.aborted && explicitLeaveInFlight) return
      if (attemptKind === 'recovery' && !signalingClient?.hasEstablishedConference()) {
        clearRecoveryBindingWait()
      }
      conferenceError.value = apiErrorMessage(errorValue)
      const status = referenceDialerApi.isApiRequestError(errorValue) ? errorValue.status : null
      if (status === 401) {
        state.value = 'failed'
        conferenceState.value = 'attention'
        return
      }
      if (status === 403) {
        state.value = 'registered'
        conferenceState.value = 'attention'
        return
      }
      if (status === 404) {
        selectedConference.value = null
        transitionConferenceReady()
        return
      }
      if (status === 409) {
        try {
          const rediscovered = await referenceDialerApi.bootstrap(requestOptions)
          if (destroyed || explicitLeaveInFlight || attemptId !== conferenceAttempt) return
          bootstrap.value = rediscovered
          if (rediscovered.participation === null) {
            selectedConference.value = null
            transitionConferenceReady()
          } else {
            scheduleRecovery()
          }
        } catch {
          scheduleRecovery()
        }
        return
      }
      scheduleRecovery()
    } finally {
      recoveryPromise = null
      if (recoveryRequestController === requestController) recoveryRequestController = null
    }
  })()

  await recoveryPromise
}

async function join(conference: Conference): Promise<void> {
  if (signalingClient === null || state.value !== 'registered') return
  clearRecoveryTimer()
  explicitLeaveInFlight = false
  attemptKind = 'new'
  conferenceAttempt += 1
  cleanupPromise = null
  selectedConference.value = conference
  conferenceState.value = 'joining'
  conferenceError.value = ''

  try {
    await signalingClient.ensureRegistered()
    const admission = await referenceDialerApi.joinConference(conference.id, crypto.randomUUID())
    await signalingClient.invite(admission.signaling_destination)
  } catch (errorValue) {
    await finalizeConferenceSession('failed', apiErrorMessage(errorValue))
    fail(errorValue, { notify: false })
  }
}

async function leave(): Promise<void> {
  if (selectedConference.value === null) {
    cancelRecovery()
    transitionConferenceReady()
    return
  }
  cancelRecovery()
  conferenceState.value = 'leaving'
  conferenceError.value = ''
  try {
    await signalingClient?.leave()
    await finalizeConferenceSession('local-leave')
    activeConferenceInviteAttemptId = null
    attemptKind = null
    clearRecoveryBindingWait()
    transitionConferenceReady()
  } catch (errorValue) {
    conferenceState.value = 'attention'
    conferenceError.value = apiErrorMessage(errorValue)
    fail(errorValue, { notify: false })
  }
}

async function initialize(): Promise<void> {
  try {
    const initialBootstrap = await referenceDialerApi.bootstrap()
    let telephonySession = initialBootstrap.telephony_session
    if (telephonySession === null) {
      const created = await referenceDialerApi.createTelephonySession(`reference-dialer:${crypto.randomUUID()}`)
      telephonySession = created.telephony_session
    }
    bootstrap.value = { ...initialBootstrap, telephony_session: telephonySession }

    const telephonySessionId = telephonySession.id
    const credential = await referenceDialerApi.issueSignalingCredential(telephonySessionId)
    signalingClient = new ReferenceDialerSignalingClient(
      credential.credential,
      updateSignalingState,
      updateCallState,
      async () => (await referenceDialerApi.issueSignalingCredential(telephonySessionId)).credential,
    )
    await signalingClient.start()
    if (initialBootstrap.participation !== null) void beginRecovery()
  } catch (errorValue) {
    state.value = state.value === 'loading' ? 'error' : 'failed'
    errorMessage.value = apiErrorMessage(errorValue)
    fail(errorValue, { notify: false })
  }
}

function handleOnline(): void {
  clearConnectivityDebounce()
  connectivityDebounceTimer = setTimeout(() => {
    connectivityDebounceTimer = null
    void beginRecovery()
  }, CONNECTIVITY_DEBOUNCE_MS)
}

function handleOffline(): void {
  clearConnectivityDebounce()
  clearRecoveryTimer()
  if (selectedConference.value !== null && conferenceState.value === 'connected') conferenceState.value = 'recovering'
}

onMounted(() => {
  void initialize()
  window.addEventListener('online', handleOnline)
  window.addEventListener('offline', handleOffline)
})

onBeforeUnmount(() => {
  destroyed = true
  cancelRecovery()
  clearConnectivityDebounce()
  activeConferenceInviteAttemptId = null
  window.removeEventListener('online', handleOnline)
  window.removeEventListener('offline', handleOffline)
  void (async () => {
    await signalingClient?.stop()
  })()
})
</script>
