import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  disconnectRuntimeNodeRealtime,
  resetRuntimeNodeRealtimeClientFactory,
  runtimeNodeRealtimeConnectionState,
  runtimeNodeRealtimeMayBeStale,
  runtimeNodeRealtimeStatusText,
  setRuntimeNodeRealtimeClientFactory,
  subscribeRuntimeNodeRealtime,
  type EchoChannel,
  type EchoClient,
  type RuntimeNodeRealtimeConfig,
  type RuntimeNodeRealtimeSubscription,
} from './runtimeNodeRealtime'

type ConnectionCallback = (payload?: unknown) => void

function flushAsync(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, 0))
}

function createMockEcho() {
  const connectionCallbacks: Record<string, ConnectionCallback[]> = {}
  const notificationCallbacks: Record<string, (payload: unknown) => void> = {}
  const channelErrorCallbacks: Array<(error: unknown) => void> = []
  const channelBindCallbacks: Record<string, ConnectionCallback[]> = {}
  const privateChannels: string[] = []
  const leftChannels: string[] = []
  let disconnected = false

  const channel: EchoChannel = {
    listen(event, callback) {
      notificationCallbacks[event] = callback

      return channel
    },
    stopListening(event) {
      delete notificationCallbacks[event]

      return channel
    },
    error(callback) {
      channelErrorCallbacks.push(callback)

      return channel
    },
    bind(event, callback) {
      channelBindCallbacks[event] = [...(channelBindCallbacks[event] ?? []), callback]

      return channel
    },
  }

  const client: EchoClient = {
    private(channelName) {
      privateChannels.push(channelName)

      return channel
    },
    leave(channelName) {
      leftChannels.push(channelName)
    },
    disconnect() {
      disconnected = true
    },
    connector: {
      pusher: {
        connection: {
          bind(event, callback) {
            connectionCallbacks[event] = [...(connectionCallbacks[event] ?? []), callback]
          },
        },
      },
    },
  }

  return {
    client,
    privateChannels,
    leftChannels,
    get disconnected() {
      return disconnected
    },
    emitConnection(event: string, payload?: unknown) {
      for (const callback of connectionCallbacks[event] ?? []) callback(payload)
    },
    emitNotification(payload: unknown) {
      notificationCallbacks['.runtime-node.operational-state.changed']?.(payload)
    },
    emitChannelError(error: unknown) {
      for (const callback of channelErrorCallbacks) callback(error)
      for (const callback of channelBindCallbacks['pusher:subscription_error'] ?? []) callback(error)
    },
  }
}

function notification(overrides: Record<string, unknown> = {}) {
  return {
    event_type: 'runtime_node.observed_state_changed',
    aggregate_type: 'runtime_node',
    runtime_node_id: 'runtime-1',
    tenant_id: 'tenant-1',
    occurred_at: '2026-07-23T01:02:03.000000Z',
    desired_state: 'disabled',
    observed_state: 'ready',
    secret: 'must-not-be-used',
    ...overrides,
  }
}

function subscription(overrides: Partial<RuntimeNodeRealtimeSubscription> = {}) {
  return {
    tenantId: 'tenant-1',
    refreshList: vi.fn().mockResolvedValue({ runtime_nodes: [{ id: 'runtime-1', observed_state: 'canonical-ready' }] }),
    refreshNodeDetails: vi.fn().mockResolvedValue({ runtime_evidence: { observed_state: 'canonical-ready' } }),
    openRuntimeNodeIds: vi.fn(() => []),
    sessionActive: vi.fn(() => true),
    ...overrides,
  } satisfies RuntimeNodeRealtimeSubscription
}

describe('runtimeNodeRealtime', () => {
  beforeEach(() => {
    vi.stubEnv('VITE_UTCP_REVERB_APP_KEY', 'public-reverb-key')
    vi.stubEnv('VITE_UTCP_WS_HOST', 'app.utcp.local.test')
    vi.stubEnv('VITE_UTCP_WS_PORT', '443')
    vi.stubEnv('VITE_UTCP_WS_SCHEME', 'wss')
    vi.stubEnv('VITE_UTCP_WS_PATH', '/app')
    window.localStorage.clear()
    window.sessionStorage.clear()
    disconnectRuntimeNodeRealtime()
  })

  afterEach(() => {
    disconnectRuntimeNodeRealtime()
    resetRuntimeNodeRealtimeClientFactory()
    vi.unstubAllEnvs()
    vi.restoreAllMocks()
    window.localStorage.clear()
    window.sessionStorage.clear()
  })

  it('creates one Echo client after a valid session and subscribes to the active tenant private channel', () => {
    const echo = createMockEcho()
    const createdConfigs: RuntimeNodeRealtimeConfig[] = []
    const factory = vi.fn((config: RuntimeNodeRealtimeConfig) => {
      createdConfigs.push(config)

      return echo.client
    })
    setRuntimeNodeRealtimeClientFactory(factory)

    subscribeRuntimeNodeRealtime(subscription({ tenantId: '', sessionActive: vi.fn(() => false) }))

    expect(factory).not.toHaveBeenCalled()
    expect(runtimeNodeRealtimeConnectionState.value).toBe('idle')

    subscribeRuntimeNodeRealtime(subscription())
    subscribeRuntimeNodeRealtime(subscription())

    expect(factory).toHaveBeenCalledTimes(1)
    expect(createdConfigs).toEqual([{
      appKey: 'public-reverb-key',
      wsHost: 'app.utcp.local.test',
      wsPort: 443,
      wsScheme: 'wss',
      wsPath: '/app',
      authEndpoint: '/api/broadcasting/auth',
    }])
    expect(echo.privateChannels).toEqual(['tenant.tenant-1.runtime-nodes'])
    expect(runtimeNodeRealtimeStatusText()).toBe('Live updates connecting')
  })

  it('treats RuntimeNode notifications as invalidation hints and never applies payload state', async () => {
    const echo = createMockEcho()
    setRuntimeNodeRealtimeClientFactory(() => echo.client)
    const activeSubscription = subscription({
      openRuntimeNodeIds: vi.fn(() => ['runtime-1']),
    })

    subscribeRuntimeNodeRealtime(activeSubscription)
    echo.emitNotification(notification())
    await flushAsync()

    expect(activeSubscription.refreshList).toHaveBeenCalledTimes(1)
    expect(activeSubscription.refreshNodeDetails).toHaveBeenCalledTimes(1)
    expect(activeSubscription.refreshNodeDetails).toHaveBeenCalledWith('runtime-1')
    expect(JSON.stringify(window.localStorage)).not.toContain('disabled')
    expect(JSON.stringify(window.sessionStorage)).not.toContain('must-not-be-used')

    echo.emitNotification(notification({ runtime_node_id: 'runtime-2' }))
    await flushAsync()

    expect(activeSubscription.refreshList).toHaveBeenCalledTimes(2)
    expect(activeSubscription.refreshNodeDetails).toHaveBeenCalledTimes(1)

    echo.emitNotification(notification({ tenant_id: 'tenant-2' }))
    echo.emitNotification(notification({ aggregate_type: 'conference' }))
    echo.emitNotification(notification({ runtime_node_id: '../runtime-1' }))
    await flushAsync()

    expect(activeSubscription.refreshList).toHaveBeenCalledTimes(2)

    echo.emitNotification(notification({ occurred_at: '2026-07-23T01:02:02.000000Z' }))
    echo.emitNotification(notification({ occurred_at: '2026-07-23T01:02:01.000000Z' }))
    await flushAsync()

    expect(activeSubscription.refreshList).toHaveBeenCalledTimes(4)
    expect(activeSubscription.refreshNodeDetails).toHaveBeenCalledTimes(3)
  })

  it('marks stale while disconnected and clears stale only after canonical resynchronization succeeds', async () => {
    const echo = createMockEcho()
    setRuntimeNodeRealtimeClientFactory(() => echo.client)
    const activeSubscription = subscription({
      openRuntimeNodeIds: vi.fn(() => ['runtime-1']),
    })

    subscribeRuntimeNodeRealtime(activeSubscription)
    echo.emitConnection('state_change', { current: 'connected' })
    await flushAsync()

    expect(runtimeNodeRealtimeConnectionState.value).toBe('connected')
    expect(runtimeNodeRealtimeMayBeStale.value).toBe(false)

    echo.emitConnection('state_change', { current: 'disconnected' })

    expect(runtimeNodeRealtimeConnectionState.value).toBe('reconnecting')
    expect(runtimeNodeRealtimeMayBeStale.value).toBe(true)

    echo.emitConnection('state_change', { current: 'connected' })
    await flushAsync()

    expect(activeSubscription.refreshList).toHaveBeenCalledTimes(1)
    expect(activeSubscription.refreshNodeDetails).toHaveBeenCalledWith('runtime-1')
    expect(runtimeNodeRealtimeConnectionState.value).toBe('connected')
    expect(runtimeNodeRealtimeMayBeStale.value).toBe(false)

    vi.mocked(activeSubscription.refreshList).mockRejectedValueOnce(new Error('snapshot unavailable'))
    echo.emitConnection('state_change', { current: 'disconnected' })
    echo.emitConnection('state_change', { current: 'connected' })
    await flushAsync()

    expect(runtimeNodeRealtimeConnectionState.value).toBe('disconnected')
    expect(runtimeNodeRealtimeMayBeStale.value).toBe(true)
    expect(runtimeNodeRealtimeStatusText()).toBe('Live updates disconnected — displayed data may be stale')
  })

  it('leaves old tenant channels and disconnects cleanly on logout or session rejection', () => {
    const echo = createMockEcho()
    setRuntimeNodeRealtimeClientFactory(() => echo.client)

    subscribeRuntimeNodeRealtime(subscription({ tenantId: 'tenant-a' }))
    subscribeRuntimeNodeRealtime(subscription({ tenantId: 'tenant-b' }))

    expect(echo.leftChannels).toContain('tenant.tenant-a.runtime-nodes')
    expect(echo.privateChannels).toEqual(['tenant.tenant-a.runtime-nodes', 'tenant.tenant-b.runtime-nodes'])

    disconnectRuntimeNodeRealtime()

    expect(echo.leftChannels).toContain('tenant.tenant-b.runtime-nodes')
    expect(echo.disconnected).toBe(true)
    expect(runtimeNodeRealtimeConnectionState.value).toBe('idle')
  })

  it('keeps channel authorization denial observable without retrying the private subscription', () => {
    const echo = createMockEcho()
    setRuntimeNodeRealtimeClientFactory(() => echo.client)

    subscribeRuntimeNodeRealtime(subscription())
    echo.emitChannelError({ status: 403 })

    expect(runtimeNodeRealtimeConnectionState.value).toBe('unauthorized')
    expect(runtimeNodeRealtimeMayBeStale.value).toBe(true)
    expect(runtimeNodeRealtimeStatusText()).toBe('Live updates unavailable for this session')
    expect(echo.leftChannels).toEqual(['tenant.tenant-1.runtime-nodes'])

    echo.emitConnection('state_change', { current: 'connecting' })
    echo.emitConnection('state_change', { current: 'connected' })

    expect(runtimeNodeRealtimeConnectionState.value).toBe('unauthorized')
    expect(echo.privateChannels).toEqual(['tenant.tenant-1.runtime-nodes'])
  })
})
