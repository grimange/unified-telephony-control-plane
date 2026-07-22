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

const eventName = '.runtime-node.operational-state.changed'
const publicRuntimeNodeIdPattern = /^[A-Za-z0-9._:-]{1,128}$/

export const runtimeNodeRealtimeConnectionState = ref<RuntimeNodeRealtimeConnectionState>('idle')
export const runtimeNodeRealtimeLastConnectedAt = ref<string | null>(null)
export const runtimeNodeRealtimeError = ref('')
export const runtimeNodeRealtimeMayBeStale = computed(() =>
  ['disconnected', 'reconnecting', 'unauthorized'].includes(runtimeNodeRealtimeConnectionState.value),
)

let echoClient: EchoClient | null = null
let activeChannelName: string | null = null
let activeSubscription: RuntimeNodeRealtimeSubscription | null = null
let activeChannel: EchoChannel | null = null
let echoClientFactory: EchoClientFactory = createEchoClient
let connectedOnce = false
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

export function subscribeRuntimeNodeRealtime(subscription: RuntimeNodeRealtimeSubscription): void {
  if (!subscription.sessionActive() || subscription.tenantId === '') {
    disconnectRuntimeNodeRealtime()

    return
  }

  const nextChannelName = `tenant.${subscription.tenantId}.runtime-nodes`
  activeSubscription = subscription

  if (activeChannelName === nextChannelName && echoClient !== null) return

  leaveActiveChannel()
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

  activeChannelName = nextChannelName
  activeChannel = echoClient.private(nextChannelName)
  activeChannel.listen(eventName, handleRuntimeNodeNotification)
  activeChannel.error?.(handleAuthorizationFailure)
  activeChannel.bind?.('pusher:subscription_error', handleAuthorizationFailure)
}

export function leaveRuntimeNodeRealtimeTenant(): void {
  leaveActiveChannel()
  activeSubscription = null
  runtimeNodeRealtimeConnectionState.value = echoClient === null ? 'idle' : 'disconnected'
}

export function disconnectRuntimeNodeRealtime(): void {
  leaveActiveChannel()
  activeSubscription = null
  echoClient?.disconnect()
  echoClient = null
  connectedOnce = false
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
      runtimeNodeRealtimeConnectionState.value = connectedOnce ? 'reconnecting' : 'disconnected'
    }
  })
  connection?.bind?.('connected', () => {
    if (runtimeNodeRealtimeConnectionState.value === 'unauthorized') return

    void handleConnected()
  })
  connection?.bind?.('disconnected', () => {
    if (runtimeNodeRealtimeConnectionState.value === 'unauthorized') return

    runtimeNodeRealtimeConnectionState.value = connectedOnce ? 'reconnecting' : 'disconnected'
  })
  connection?.bind?.('unavailable', () => {
    if (runtimeNodeRealtimeConnectionState.value === 'unauthorized') return

    runtimeNodeRealtimeConnectionState.value = 'disconnected'
  })
  connection?.bind?.('failed', () => {
    if (runtimeNodeRealtimeConnectionState.value === 'unauthorized') return

    runtimeNodeRealtimeConnectionState.value = 'disconnected'
  })
}

async function handleConnected(): Promise<void> {
  if (runtimeNodeRealtimeConnectionState.value === 'unauthorized') return

  runtimeNodeRealtimeConnectionState.value = 'connected'
  runtimeNodeRealtimeLastConnectedAt.value = new Date().toISOString()
  runtimeNodeRealtimeError.value = ''
  const needsResync = connectedOnce
  connectedOnce = true
  if (needsResync && !(await resynchronizeCanonicalSnapshots())) {
    runtimeNodeRealtimeConnectionState.value = 'disconnected'
  }
}

function handleAuthorizationFailure(error: unknown): void {
  const status = Number((error as { status?: unknown })?.status ?? (error as { statusCode?: unknown })?.statusCode)
  if (status === 403 || status === 401 || Number.isNaN(status)) {
    runtimeNodeRealtimeConnectionState.value = 'unauthorized'
    runtimeNodeRealtimeError.value = 'Live updates are unavailable for this session.'
    leaveActiveChannel()
  }
}

function handleRuntimeNodeNotification(payload: unknown): void {
  const subscription = activeSubscription
  if (subscription === null || !subscription.sessionActive()) return
  if (!isRuntimeNodeNotification(payload, subscription.tenantId)) return

  void refreshCanonicalSnapshotsForNotification(String(payload.runtime_node_id))
}

async function refreshCanonicalSnapshotsForNotification(runtimeNodeId: string): Promise<void> {
  const subscription = activeSubscription
  if (subscription === null) return

  await subscription.refreshList()
  if (subscription.openRuntimeNodeIds().includes(runtimeNodeId)) {
    await subscription.refreshNodeDetails(runtimeNodeId)
  }
}

async function resynchronizeCanonicalSnapshots(): Promise<boolean> {
  if (resynchronizing) return false
  const subscription = activeSubscription
  if (subscription === null || !subscription.sessionActive()) return false

  resynchronizing = true
  try {
    await subscription.refreshList()
    await Promise.all(subscription.openRuntimeNodeIds().map((runtimeNodeId) => subscription.refreshNodeDetails(runtimeNodeId)))
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

function leaveActiveChannel(): void {
  if (echoClient !== null && activeChannelName !== null) {
    activeChannel?.stopListening?.(eventName)
    echoClient.leave(activeChannelName)
  }
  activeChannelName = null
  activeChannel = null
}
