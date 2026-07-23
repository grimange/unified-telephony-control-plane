import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { computed, ref } from 'vue'

export type RuntimeNodeRealtimeConnectionState =
  | 'idle'
  | 'connecting'
  | 'connected'
  | 'reconnecting'
  | 'disconnected'
  | 'unauthorized'

export type RuntimeNodeOperationalNotification = {
  event_type: unknown
  aggregate_type: unknown
  runtime_node_id: unknown
  tenant_id: unknown
  occurred_at: unknown
}

export type RuntimeNodeRealtimeSubscription = {
  tenantId: string
  refreshList: () => Promise<unknown>
  refreshNodeDetails: (runtimeNodeId: string) => Promise<unknown>
  openRuntimeNodeIds: () => string[]
  sessionActive: () => boolean
}

export type ConferenceOperationalNotification = {
  event_type: unknown
  aggregate_type: unknown
  aggregate_id: unknown
  conference_id: unknown
  tenant_id: unknown
  occurred_at: unknown
}

export type ConferenceRealtimeSubscription = {
  tenantId: string
  refreshList: () => Promise<unknown>
  refreshSelectedConference: (conferenceId: string) => Promise<unknown>
  selectedConferenceId: () => string | null
  sessionActive: () => boolean
}

type EchoConnection = {
  bind?: (event: string, callback: (payload?: unknown) => void) => void
  unbind?: (event: string, callback?: (payload?: unknown) => void) => void
}

export type EchoChannel = {
  listen: (event: string, callback: (payload: unknown) => void) => EchoChannel
  stopListening?: (event: string, callback?: (payload: unknown) => void) => EchoChannel
  error?: (callback: (error: unknown) => void) => EchoChannel
  bind?: (event: string, callback: (payload?: unknown) => void) => EchoChannel
}

export type EchoClient = {
  private: (channel: string) => EchoChannel
  leave: (channel: string) => void
  disconnect: () => void
  connector?: {
    pusher?: {
      connection?: EchoConnection
    }
  }
}

export type EchoClientFactory = (config: RuntimeNodeRealtimeConfig) => EchoClient

export type RuntimeNodeRealtimeConfig = {
  appKey: string
  wsHost: string
  wsPort: number
  wsScheme: 'ws' | 'wss'
  wsPath: string
  authEndpoint: string
}

export type RuntimeNodeEchoOptions = {
  broadcaster: 'reverb'
  key: string
  wsHost: string
  wsPort: number
  wssPort: number
  forceTLS: boolean
  enabledTransports: Array<'ws' | 'wss'>
  authEndpoint: string
  auth: {
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    }
  }
  Pusher: typeof Pusher
}

const runtimeNodeEventName = '.runtime-node.operational-state.changed'
const conferenceEventName = '.conference.operational-state.changed'
const publicRuntimeNodeIdPattern = /^[A-Za-z0-9._:-]{1,128}$/
const publicConferenceIdPattern = /^[A-Za-z0-9._:-]{1,128}$/

export const runtimeNodeRealtimeConnectionState = ref<RuntimeNodeRealtimeConnectionState>('idle')
export const runtimeNodeRealtimeLastConnectedAt = ref<string | null>(null)
export const runtimeNodeRealtimeError = ref('')
export const runtimeNodeRealtimeMayBeStale = computed(() =>
  ['disconnected', 'reconnecting', 'unauthorized'].includes(runtimeNodeRealtimeConnectionState.value),
)

let echoClient: EchoClient | null = null
let activeRuntimeNodeChannelName: string | null = null
let activeRuntimeNodeSubscription: RuntimeNodeRealtimeSubscription | null = null
let activeRuntimeNodeChannel: EchoChannel | null = null
let activeRuntimeNodeToken = 0
let activeRuntimeNodeSnapshotReady = false
let activeRuntimeNodeSubscriptionReady = false
let activeConferenceChannelName: string | null = null
let activeConferenceSubscription: ConferenceRealtimeSubscription | null = null
let activeConferenceChannel: EchoChannel | null = null
let activeConferenceToken = 0
let activeConferenceSnapshotReady = false
let activeConferenceSubscriptionReady = false
let echoClientFactory: EchoClientFactory = createEchoClient
let connectedOnce = false
let socketConnected = false
let requiresReconnectResync = false
let resynchronizing = false

export function setRuntimeNodeRealtimeClientFactory(factory: EchoClientFactory): void {
  echoClientFactory = factory
}

export function resetRuntimeNodeRealtimeClientFactory(): void {
  echoClientFactory = createEchoClient
}

export function runtimeNodeRealtimeStatusText(): string {
  if (runtimeNodeRealtimeConnectionState.value === 'connected') return 'Live updates connected'
  if (runtimeNodeRealtimeConnectionState.value === 'connecting') return 'Live updates connecting'
  if (runtimeNodeRealtimeConnectionState.value === 'reconnecting') return 'Live updates reconnecting'
  if (runtimeNodeRealtimeConnectionState.value === 'unauthorized') return 'Live updates unavailable for this session'
  if (runtimeNodeRealtimeConnectionState.value === 'disconnected') return 'Live updates disconnected — displayed data may be stale'

  return 'Live updates connecting'
}

export function isPermittedPusherTransportCache(value: string | null): boolean {
  if (value === null) return true

  let parsed: unknown
  try {
    parsed = JSON.parse(value)
  } catch {
    return false
  }

  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return false
  const record = parsed as Record<string, unknown>
  const keys = Object.keys(record)
  if (!keys.every((key) => ['timestamp', 'transport'].includes(key))) return false
  if (!['ws', 'wss'].includes(String(record.transport))) return false
  if (!['number', 'string'].includes(typeof record.timestamp)) return false

  return true
}

export function subscribeRuntimeNodeRealtime(subscription: RuntimeNodeRealtimeSubscription): void {
  if (!subscription.sessionActive() || subscription.tenantId === '') {
    disconnectRuntimeNodeRealtime()

    return
  }

  const nextChannelName = `tenant.${subscription.tenantId}.runtime-nodes`
  activeRuntimeNodeSubscription = subscription

  if (activeRuntimeNodeChannelName === nextChannelName && echoClient !== null) {
    activeRuntimeNodeSnapshotReady = true
    void maybeCompleteLiveConnection()

    return
  }

  leaveRuntimeNodeChannel()
  const config = readRuntimeNodeRealtimeConfig()
  if (config === null) {
    runtimeNodeRealtimeConnectionState.value = 'disconnected'
    runtimeNodeRealtimeError.value = 'Live update transport configuration is incomplete.'

    return
  }

  if (echoClient === null) {
    runtimeNodeRealtimeConnectionState.value = 'connecting'
    echoClient = echoClientFactory(config)
    bindConnectionEvents(echoClient)
  }

  runtimeNodeRealtimeConnectionState.value = requiresReconnectResync ? 'reconnecting' : 'connecting'
  activeRuntimeNodeChannelName = nextChannelName
  activeRuntimeNodeSnapshotReady = true
  activeRuntimeNodeSubscriptionReady = false
  const generation = ++activeRuntimeNodeToken
  activeRuntimeNodeChannel = echoClient.private(nextChannelName)
  activeRuntimeNodeChannel.listen(runtimeNodeEventName, handleRuntimeNodeNotification)
  activeRuntimeNodeChannel.error?.(handleAuthorizationFailure)
  activeRuntimeNodeChannel.bind?.('pusher:subscription_error', handleAuthorizationFailure)
  activeRuntimeNodeChannel.bind?.('pusher:subscription_succeeded', () => {
    if (activeRuntimeNodeToken !== generation) return
    activeRuntimeNodeSubscriptionReady = true
    void maybeCompleteLiveConnection()
  })
}

export function leaveRuntimeNodeRealtimeTenant(): void {
  leaveRuntimeNodeChannel()
  leaveConferenceChannel()
  activeRuntimeNodeSubscription = null
  activeConferenceSubscription = null
  runtimeNodeRealtimeConnectionState.value = echoClient === null ? 'idle' : 'connecting'
}

export function subscribeConferenceRealtime(subscription: ConferenceRealtimeSubscription): void {
  if (!subscription.sessionActive() || subscription.tenantId === '') {
    leaveConferenceChannel()
    activeConferenceSubscription = null

    return
  }

  const nextChannelName = `tenant.${subscription.tenantId}.conferences`
  activeConferenceSubscription = subscription

  if (activeConferenceChannelName === nextChannelName && echoClient !== null) {
    activeConferenceSnapshotReady = true
    void maybeCompleteLiveConnection()

    return
  }

  leaveConferenceChannel()
  const config = readRuntimeNodeRealtimeConfig()
  if (config === null) {
    runtimeNodeRealtimeConnectionState.value = 'disconnected'
    runtimeNodeRealtimeError.value = 'Live update transport configuration is incomplete.'

    return
  }

  if (echoClient === null) {
    runtimeNodeRealtimeConnectionState.value = 'connecting'
    echoClient = echoClientFactory(config)
    bindConnectionEvents(echoClient)
  }

  runtimeNodeRealtimeConnectionState.value = requiresReconnectResync ? 'reconnecting' : 'connecting'
  activeConferenceChannelName = nextChannelName
  activeConferenceSnapshotReady = true
  activeConferenceSubscriptionReady = false
  const generation = ++activeConferenceToken
  activeConferenceChannel = echoClient.private(nextChannelName)
  activeConferenceChannel.listen(conferenceEventName, handleConferenceNotification)
  activeConferenceChannel.error?.(handleAuthorizationFailure)
  activeConferenceChannel.bind?.('pusher:subscription_error', handleAuthorizationFailure)
  activeConferenceChannel.bind?.('pusher:subscription_succeeded', () => {
    if (activeConferenceToken !== generation) return
    activeConferenceSubscriptionReady = true
    void maybeCompleteLiveConnection()
  })
}

export function leaveConferenceRealtimeTenant(): void {
  leaveConferenceChannel()
  activeConferenceSubscription = null
  runtimeNodeRealtimeConnectionState.value = echoClient === null ? 'idle' : 'connecting'
  void maybeCompleteLiveConnection()
}

export function disconnectRuntimeNodeRealtime(): void {
  leaveRuntimeNodeChannel()
  leaveConferenceChannel()
  activeRuntimeNodeSubscription = null
  activeConferenceSubscription = null
  echoClient?.disconnect()
  echoClient = null
  connectedOnce = false
  socketConnected = false
  requiresReconnectResync = false
  resynchronizing = false
  runtimeNodeRealtimeConnectionState.value = 'idle'
  runtimeNodeRealtimeLastConnectedAt.value = null
  runtimeNodeRealtimeError.value = ''
}

export async function resynchronizeRuntimeNodeRealtime(): Promise<boolean> {
  return resynchronizeCanonicalSnapshots()
}

export function buildRuntimeNodeEchoOptions(config: RuntimeNodeRealtimeConfig): RuntimeNodeEchoOptions {
  return {
    broadcaster: 'reverb',
    key: config.appKey,
    wsHost: config.wsHost,
    wsPort: config.wsPort,
    wssPort: config.wsPort,
    forceTLS: config.wsScheme === 'wss',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: config.authEndpoint,
    auth: {
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
      },
    },
    Pusher,
  }
}

function createEchoClient(config: RuntimeNodeRealtimeConfig): EchoClient {
  return new Echo(buildRuntimeNodeEchoOptions(config)) as EchoClient
}

function readRuntimeNodeRealtimeConfig(): RuntimeNodeRealtimeConfig | null {
  const appKey = String(import.meta.env.VITE_UTCP_REVERB_APP_KEY ?? '')
  const wsHost = String(import.meta.env.VITE_UTCP_WS_HOST ?? '')
  const rawPort = String(import.meta.env.VITE_UTCP_WS_PORT ?? '')
  const wsScheme = String(import.meta.env.VITE_UTCP_WS_SCHEME ?? '')
  const wsPath = String(import.meta.env.VITE_UTCP_WS_PATH ?? '/app')
  const wsPort = Number(rawPort)

  if (
    appKey === ''
    || wsHost === ''
    || !Number.isInteger(wsPort)
    || wsPort < 1
    || wsPort > 65535
    || !['ws', 'wss'].includes(wsScheme)
    || !wsPath.startsWith('/')
  ) {
    return null
  }

  return {
    appKey,
    wsHost,
    wsPort,
    wsScheme: wsScheme as 'ws' | 'wss',
    wsPath,
    authEndpoint: '/api/broadcasting/auth',
  }
}

function bindConnectionEvents(client: EchoClient): void {
  const connection = client.connector?.pusher?.connection
  connection?.bind?.('state_change', (payload) => {
    if (runtimeNodeRealtimeConnectionState.value === 'unauthorized') return

    const current = (payload as { current?: unknown } | undefined)?.current
    if (current === 'connecting') {
      runtimeNodeRealtimeConnectionState.value = connectedOnce ? 'reconnecting' : 'connecting'
    } else if (current === 'connected') {
      void handleConnected()
    } else if (['disconnected', 'unavailable', 'failed'].includes(String(current))) {
      markSocketDisconnected(current === 'disconnected' ? 'reconnecting' : 'disconnected')
    }
  })
  connection?.bind?.('connected', () => {
    if (runtimeNodeRealtimeConnectionState.value === 'unauthorized') return

    void handleConnected()
  })
  connection?.bind?.('disconnected', () => {
    if (runtimeNodeRealtimeConnectionState.value === 'unauthorized') return

    markSocketDisconnected('reconnecting')
  })
  connection?.bind?.('unavailable', () => {
    if (runtimeNodeRealtimeConnectionState.value === 'unauthorized') return

    markSocketDisconnected('disconnected')
  })
  connection?.bind?.('failed', () => {
    if (runtimeNodeRealtimeConnectionState.value === 'unauthorized') return

    markSocketDisconnected('disconnected')
  })
}

async function handleConnected(): Promise<void> {
  if (runtimeNodeRealtimeConnectionState.value === 'unauthorized') return

  socketConnected = true
  connectedOnce = true
  await maybeCompleteLiveConnection()
}

function markSocketDisconnected(state: Extract<RuntimeNodeRealtimeConnectionState, 'reconnecting' | 'disconnected'>): void {
  socketConnected = false
  if (connectedOnce) {
    requiresReconnectResync = true
    activeRuntimeNodeSubscriptionReady = false
    activeConferenceSubscriptionReady = false
  }
  runtimeNodeRealtimeConnectionState.value = connectedOnce ? state : 'disconnected'
}

function handleAuthorizationFailure(error: unknown): void {
  const status = Number((error as { status?: unknown })?.status ?? (error as { statusCode?: unknown })?.statusCode)
  if (status === 403 || status === 401 || Number.isNaN(status)) {
    runtimeNodeRealtimeConnectionState.value = 'unauthorized'
    runtimeNodeRealtimeError.value = 'Live updates are unavailable for this session.'
    leaveRuntimeNodeChannel()
    leaveConferenceChannel()
  }
}

function handleRuntimeNodeNotification(payload: unknown): void {
  const subscription = activeRuntimeNodeSubscription
  if (subscription === null || !subscription.sessionActive()) return
  if (!isRuntimeNodeNotification(payload, subscription.tenantId)) return

  void refreshCanonicalSnapshotsForNotification(String(payload.runtime_node_id))
}

async function refreshCanonicalSnapshotsForNotification(runtimeNodeId: string): Promise<void> {
  const subscription = activeRuntimeNodeSubscription
  if (subscription === null) return

  await subscription.refreshList()
  if (subscription.openRuntimeNodeIds().includes(runtimeNodeId)) {
    await subscription.refreshNodeDetails(runtimeNodeId)
  }
}

async function resynchronizeCanonicalSnapshots(): Promise<boolean> {
  if (resynchronizing) return false
  const runtimeNodeSubscription = activeRuntimeNodeSubscription
  const conferenceSubscription = activeConferenceSubscription
  if (
    (runtimeNodeSubscription === null || !runtimeNodeSubscription.sessionActive())
    && (conferenceSubscription === null || !conferenceSubscription.sessionActive())
  ) return false

  resynchronizing = true
  try {
    if (runtimeNodeSubscription !== null && runtimeNodeSubscription.sessionActive()) {
      await runtimeNodeSubscription.refreshList()
      await Promise.all(runtimeNodeSubscription.openRuntimeNodeIds().map((runtimeNodeId) => runtimeNodeSubscription.refreshNodeDetails(runtimeNodeId)))
      activeRuntimeNodeSnapshotReady = true
    }
    if (conferenceSubscription !== null && conferenceSubscription.sessionActive()) {
      await conferenceSubscription.refreshList()
      const selectedConferenceId = conferenceSubscription.selectedConferenceId()
      if (selectedConferenceId !== null) await conferenceSubscription.refreshSelectedConference(selectedConferenceId)
      activeConferenceSnapshotReady = true
    }
    if (runtimeNodeRealtimeConnectionState.value !== 'unauthorized') {
      runtimeNodeRealtimeConnectionState.value = 'connected'
    }

    return true
  } catch (error) {
    runtimeNodeRealtimeError.value = error instanceof Error ? error.message : 'RuntimeNode resynchronization failed.'

    return false
  } finally {
    resynchronizing = false
  }
}

function handleConferenceNotification(payload: unknown): void {
  const subscription = activeConferenceSubscription
  if (subscription === null || !subscription.sessionActive()) return
  if (!isConferenceNotification(payload, subscription.tenantId)) return

  void refreshConferenceSnapshotsForNotification(String(payload.conference_id))
}

async function refreshConferenceSnapshotsForNotification(conferenceId: string): Promise<void> {
  const subscription = activeConferenceSubscription
  if (subscription === null) return

  await subscription.refreshList()
  if (subscription.selectedConferenceId() === conferenceId) {
    await subscription.refreshSelectedConference(conferenceId)
  }
}

async function maybeCompleteLiveConnection(): Promise<void> {
  if (runtimeNodeRealtimeConnectionState.value === 'unauthorized' || !socketConnected) return
  if (!activeSubscriptionsReady()) {
    runtimeNodeRealtimeConnectionState.value = requiresReconnectResync ? 'reconnecting' : 'connecting'

    return
  }

  if (requiresReconnectResync) {
    if (!(await resynchronizeCanonicalSnapshots())) {
      runtimeNodeRealtimeConnectionState.value = 'disconnected'

      return
    }
    requiresReconnectResync = false
  }

  runtimeNodeRealtimeConnectionState.value = 'connected'
  runtimeNodeRealtimeLastConnectedAt.value = new Date().toISOString()
  runtimeNodeRealtimeError.value = ''
}

function activeSubscriptionsReady(): boolean {
  const runtimeReady = activeRuntimeNodeSubscription === null
    || (activeRuntimeNodeSnapshotReady && activeRuntimeNodeSubscriptionReady && activeRuntimeNodeSubscription.sessionActive())
  const conferenceReady = activeConferenceSubscription === null
    || (activeConferenceSnapshotReady && activeConferenceSubscriptionReady && activeConferenceSubscription.sessionActive())

  return runtimeReady && conferenceReady && (activeRuntimeNodeSubscription !== null || activeConferenceSubscription !== null)
}

function isRuntimeNodeNotification(payload: unknown, activeTenantId: string): payload is RuntimeNodeOperationalNotification {
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) return false

  const candidate = payload as RuntimeNodeOperationalNotification

  return candidate.aggregate_type === 'runtime_node'
    && candidate.tenant_id === activeTenantId
    && typeof candidate.event_type === 'string'
    && candidate.event_type.startsWith('runtime_node.')
    && typeof candidate.runtime_node_id === 'string'
    && publicRuntimeNodeIdPattern.test(candidate.runtime_node_id)
    && typeof candidate.occurred_at === 'string'
}

function isConferenceNotification(payload: unknown, activeTenantId: string): payload is ConferenceOperationalNotification {
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) return false

  const candidate = payload as ConferenceOperationalNotification

  return ['conference', 'conference_participant'].includes(String(candidate.aggregate_type))
    && candidate.tenant_id === activeTenantId
    && typeof candidate.event_type === 'string'
    && (candidate.event_type.startsWith('conference.') || candidate.event_type.startsWith('conference_participant.'))
    && typeof candidate.aggregate_id === 'string'
    && typeof candidate.conference_id === 'string'
    && publicConferenceIdPattern.test(candidate.conference_id)
    && typeof candidate.occurred_at === 'string'
}

function leaveRuntimeNodeChannel(): void {
  if (echoClient !== null && activeRuntimeNodeChannelName !== null) {
    activeRuntimeNodeChannel?.stopListening?.(runtimeNodeEventName)
    echoClient.leave(activeRuntimeNodeChannelName)
  }
  activeRuntimeNodeChannelName = null
  activeRuntimeNodeChannel = null
  activeRuntimeNodeSnapshotReady = false
  activeRuntimeNodeSubscriptionReady = false
  activeRuntimeNodeToken++
}

function leaveConferenceChannel(): void {
  if (echoClient !== null && activeConferenceChannelName !== null) {
    activeConferenceChannel?.stopListening?.(conferenceEventName)
    echoClient.leave(activeConferenceChannelName)
  }
  activeConferenceChannelName = null
  activeConferenceChannel = null
  activeConferenceSnapshotReady = false
  activeConferenceSubscriptionReady = false
  activeConferenceToken++
}
