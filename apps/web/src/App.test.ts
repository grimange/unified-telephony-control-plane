import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createMemoryHistory } from 'vue-router'
import App from './App.vue'
import type { RuntimeManagementCatalog } from './api/platform'
import {
  buildRuntimeNodeEchoOptions,
  disconnectRuntimeNodeRealtime,
  resetRuntimeNodeRealtimeClientFactory,
  setRuntimeNodeRealtimeClientFactory,
  type EchoChannel,
  type EchoClient,
  type RuntimeNodeRealtimeConfig,
} from './realtime/runtimeNodeRealtime'
import { createUtcpRouter, router } from './router'
import { resetAppStateForTests } from './state/appState'
import { appearanceStorageKey, resetAppearanceForTests } from './state/theme'
import changePasswordViewSource from './views/ChangePasswordView.vue?raw'
import loginViewSource from './views/LoginView.vue?raw'
import membershipsViewSource from './views/MembershipsView.vue?raw'
import appStateSource from './state/appState.ts?raw'
import runtimeNodesViewSource from './views/RuntimeNodesView.vue?raw'
import tenantsViewSource from './views/TenantsView.vue?raw'
import userDetailViewSource from './views/UserDetailView.vue?raw'
import usersViewSource from './views/UsersView.vue?raw'

const session = {
  user: {
    id: 'user-1',
    email: 'admin@utcp.local.test',
    display_name: 'Local Admin',
    status: 'active',
    password_change_required: false,
  },
  active_tenant: {
    tenant_id: 'tenant-1',
    slug: 'local',
    display_name: 'Local Tenant',
  },
  memberships: [
    {
      membership_id: 'membership-1',
      tenant_id: 'tenant-1',
      slug: 'local',
      display_name: 'Local Tenant',
      status: 'active',
      membership_status: 'active',
    },
  ],
  capabilities: [
    'platform.tenants.view',
    'platform.users.view',
    'tenant.memberships.view',
    'tenant.memberships.manage',
    'telephony.sessions.manage',
    'telephony.signaling.manage',
    'runtime.nodes.view',
    'runtime.nodes.manage',
    'runtime.credentials.rotate',
  ],
  catalog_version: 'c2.test',
  expires_at: '2026-07-14T10:00:00Z',
}

const limitedSession = {
  ...session,
  capabilities: [],
}

const adminUser = {
  id: 'user-2',
  email: 'operator@utcp.local.test',
  display_name: 'Operator User',
  status: 'active',
  password_change_required: false,
  updated_at: '2026-07-16T10:00:00Z',
  membership_summary: { total: 1, active: 1, suspended: 0 },
  role_summary: { platform: [], tenant: ['tenant-member'] },
  active_telephony_session: {
    id: '11111111-2222-3333-4444-555555555555',
    tenant_id: 'tenant-1',
    status: 'active',
    issued_at: '2026-07-16T10:00:00Z',
    expires_at: '2026-07-16T11:00:00Z',
    ended_at: null,
  },
  signaling_registration_summary: {
    desired_state: 'eligible',
    observed_state: 'registered',
    observed_at: '2026-07-16T10:01:00Z',
    observed_expires_at: '2026-07-16T10:03:00Z',
    pending_removal: false,
  },
}

const adminUserDetail = {
  user: {
    ...adminUser,
    created_at: '2026-07-16T09:00:00Z',
    last_login_at: null,
    password_changed_at: null,
  },
  memberships: [
    {
      id: 'membership-2',
      tenant_id: 'tenant-1',
      tenant_slug: 'local',
      tenant_display_name: 'Local Tenant',
      status: 'active',
      roles: ['tenant-member'],
      created_at: '2026-07-16T09:00:00Z',
      updated_at: '2026-07-16T09:00:00Z',
    },
  ],
  platform_roles: [],
  effective_capabilities: {
    platform: [],
    tenant: ['telephony.signaling.issue_own', 'telephony.signaling.view_own'],
  },
  active_telephony_session: adminUser.active_telephony_session,
  signaling: {
    signaling_identity: 'ts-11111111222233334444555555555555',
    credential: {
      username: 'ts-11111111222233334444555555555555',
      realm: 'sip.utcp.local.test',
      algorithm: 'MD5',
      issued_at: '2026-07-16T10:00:00Z',
      expires_at: '2026-07-16T10:02:00Z',
      revoked_at: null,
      wss_uri: 'wss://sip.utcp.local.test/ws',
    },
    registration: {
      desired_state: 'eligible',
      observed_state: 'registered',
      observed_at: '2026-07-16T10:01:00Z',
      observed_expires_at: '2026-07-16T10:03:00Z',
      last_event_type: 'registration.accepted',
      failure_class: null,
      pending_removal: false,
      reconciliation_status: 'converged',
      reconciliation_reason: null,
    },
  },
}

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

function deferredResponse(): { promise: Promise<Response>; resolve: (response: Response) => void } {
  let resolve!: (response: Response) => void
  const promise = new Promise<Response>((next) => {
    resolve = next
  })

  return { promise, resolve }
}

const runtimeCatalog = {
  catalog_version: 'runtime-management.v1',
  runtime_families: {
    asterisk: { display_name: 'Asterisk', description: null, adapters: ['asterisk-ari'] },
    freeswitch: { display_name: 'FreeSWITCH', description: null, adapters: ['freeswitch-esl'] },
    simulator: { display_name: 'Simulator', description: null, adapters: ['simulator-deterministic'] },
  },
  adapter_keys: {
    'asterisk-ari': {
      runtime_family: 'asterisk',
      display_name: 'Asterisk ARI',
      description: null,
      supported_capabilities: ['event.stream', 'runtime.observation'],
      required_capabilities: ['event.stream', 'runtime.observation'],
      endpoint_requirements: [],
      credentials_required: true,
      adapter_configuration_available: true,
      adapter_configuration: {
        fields: [
          {
            key: 'application_name',
            label: 'ARI application name',
            help: 'Stasis application name subscribed by the Asterisk ARI listener.',
            input_type: 'text',
            required: true,
            read_only: false,
            write_only: false,
            default: 'utcp-t0-observation',
            order: 10,
            validation: { min_length: 3, max_length: 80 },
          },
          {
            key: 'connect_timeout_ms',
            label: 'Connect timeout',
            help: 'HTTP connection timeout for Asterisk ARI requests, in milliseconds.',
            input_type: 'integer',
            required: true,
            read_only: false,
            write_only: false,
            default: 2000,
            order: 20,
            validation: { min: 250, max: 30000, step: 1 },
          },
          {
            key: 'request_timeout_ms',
            label: 'Request timeout',
            help: 'Total timeout for Asterisk ARI HTTP requests, in milliseconds.',
            input_type: 'integer',
            required: true,
            read_only: false,
            write_only: false,
            default: 4000,
            order: 30,
            validation: { min: 250, max: 60000, step: 1 },
          },
          {
            key: 'websocket_handshake_timeout_ms',
            label: 'WebSocket handshake timeout',
            help: 'Timeout for establishing the Asterisk ARI event WebSocket, in milliseconds.',
            input_type: 'integer',
            required: true,
            read_only: false,
            write_only: false,
            default: 4000,
            order: 40,
            validation: { min: 250, max: 60000, step: 1 },
          },
          {
            key: 'heartbeat_interval_ms',
            label: 'Heartbeat interval',
            help: 'Interval for ARI event connection heartbeat checks, in milliseconds.',
            input_type: 'integer',
            required: true,
            read_only: false,
            write_only: false,
            default: 15000,
            order: 50,
            validation: { min: 1000, max: 120000, step: 1 },
          },
          {
            key: 'reconnect_min_delay_ms',
            label: 'Minimum reconnect delay',
            help: 'Minimum backoff delay before reconnecting the ARI event stream, in milliseconds.',
            input_type: 'integer',
            required: true,
            read_only: false,
            write_only: false,
            default: 1000,
            order: 60,
            validation: { min: 100, max: 120000, step: 1 },
          },
          {
            key: 'reconnect_max_delay_ms',
            label: 'Maximum reconnect delay',
            help: 'Maximum backoff delay before reconnecting the ARI event stream, in milliseconds.',
            input_type: 'integer',
            required: true,
            read_only: false,
            write_only: false,
            default: 30000,
            order: 70,
            validation: { min: 100, max: 300000, step: 1 },
          },
        ],
      },
    },
    'freeswitch-esl': {
      runtime_family: 'freeswitch',
      display_name: 'FreeSWITCH ESL',
      description: null,
      supported_capabilities: [],
      required_capabilities: [],
      endpoint_requirements: [],
      credentials_required: true,
      adapter_configuration_available: false,
    },
    'simulator-deterministic': {
      runtime_family: 'simulator',
      display_name: 'Deterministic simulator',
      description: null,
      supported_capabilities: ['event.stream', 'runtime.observation', 'runtime.configuration'],
      required_capabilities: ['event.stream', 'runtime.observation', 'runtime.configuration'],
      endpoint_requirements: [],
      credentials_required: false,
      adapter_configuration_available: true,
      adapter_configuration: {
        fields: [
          {
            key: 'scenario_key',
            label: 'Scenario key',
            help: 'Deterministic simulator scenario key from the server simulator catalog.',
            input_type: 'text',
            required: true,
            read_only: false,
            write_only: false,
            default: null,
            order: 10,
            validation: { min_length: 1, max_length: 32 },
          },
          {
            key: 'scenario_version',
            label: 'Scenario version',
            help: 'Deterministic simulator scenario contract version.',
            input_type: 'integer',
            required: true,
            read_only: false,
            write_only: false,
            default: 1,
            order: 20,
            validation: { min: 1, max: 1, step: 1 },
          },
          {
            key: 'seed',
            label: 'Seed',
            help: 'Stable deterministic seed used by the simulator profile.',
            input_type: 'text',
            required: true,
            read_only: false,
            write_only: false,
            default: 'local',
            order: 30,
            validation: { min_length: 1, max_length: 120 },
          },
          {
            key: 'parameters',
            label: 'Parameters',
            help: 'Optional scalar simulator parameters keyed by the selected scenario.',
            input_type: 'json',
            required: true,
            read_only: false,
            write_only: false,
            default: [],
            order: 40,
          },
        ],
      },
    },
  },
  runtime_capabilities: {
    'event.stream': { display_name: 'Event stream', description: null },
    'runtime.observation': { display_name: 'Runtime observation', description: null },
    'runtime.configuration': { display_name: 'Runtime configuration', description: null },
    'conference.execution': { display_name: 'Conference execution', description: null },
  },
  desired_states: {},
  endpoint_purposes: {},
  endpoint_transports: {},
  endpoint_tls_modes: {},
} satisfies RuntimeManagementCatalog

const roleCatalog = {
  catalog_version: 'roles.v1',
  roles: {
    'tenant-member': { scope: 'tenant', display_name: 'Tenant member', capabilities: [] },
    'tenant-admin': { scope: 'tenant', display_name: 'Tenant admin', capabilities: [] },
    'platform-admin': { scope: 'platform', display_name: 'Platform admin', capabilities: [] },
  },
  capabilities: [],
}

const runtimeNode = {
  id: 'runtime-1',
  tenant_id: 'tenant-1',
  name: 'Proof Runtime',
  slug: 'proof-runtime',
  runtime_family: 'asterisk',
  adapter_key: 'asterisk-ari',
  desired_state: 'draft',
  observed_state: 'unobserved',
  configuration_version: 1,
  placement: { region: null, zone: null, priority: 100, capacity_weight: 10, labels: {} },
  endpoints: [{ id: 'endpoint-1', purpose: 'control', transport: 'https', host: 'runtime.local.test', port: 8089, path: '/ari', tls_mode: 'verify', priority: 100, enabled: true }],
  credentials: [
    { id: 'credential-1', type: 'control-api', identifier: 'old', fingerprint: '1234567890abcdef', version: 1, status: 'active', rotated_at: '2026-07-14T10:00:00Z', expires_at: null },
    { id: 'credential-2', type: 'control-api', identifier: 'new', fingerprint: 'abcdef1234567890', version: 2, status: 'active', rotated_at: '2026-07-14T11:00:00Z', expires_at: null },
  ],
  capabilities: ['event.stream', 'runtime.observation'],
}

function mockRuntimeAdminFetch(calls: Array<{ url: string; body?: unknown }>): void {
  vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
    const url = input.toString()
    calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
    if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
    if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
    if (url.endsWith('/api/v1/admin/runtime-node-catalog')) return Promise.resolve(jsonResponse({ catalog: runtimeCatalog }))
    if (url.endsWith('/api/v1/admin/runtime-nodes')) return Promise.resolve(jsonResponse({ runtime_nodes: [runtimeNode] }))
    if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration')) {
      if (init?.method === 'PUT') {
        return Promise.resolve(jsonResponse({ adapter_configuration: { configured: true, profile: JSON.parse(String(init.body)) } }))
      }

      return Promise.resolve(jsonResponse({
        adapter_configuration: {
          configured: true,
          profile: {
            configuration_version: 1,
            application_name: 'utcp',
            connect_timeout_ms: 1000,
            request_timeout_ms: 7000,
            websocket_handshake_timeout_ms: 8000,
            heartbeat_interval_ms: 15000,
            reconnect_min_delay_ms: 500,
            reconnect_max_delay_ms: 10000,
          },
        },
      }))
    }
    if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/runtime-evidence')) {
      return Promise.resolve(jsonResponse({
        runtime_evidence: {
          desired_state: 'draft',
          observed_state: 'unobserved',
          observed_at: null,
          desired_configuration_generation: 1,
          observed_configuration_generation: null,
          listener: { status: null, lease_freshness: null, last_claimed_at: null, last_renewed_at: null },
          connection: { state: 'closed', latest_epoch_opened_at: '2026-07-14T10:00:00Z', latest_epoch_closed_at: null, latest_event_at: null, latest_disconnect_class: null },
          reconciliation: { state: 'blocked', last_evaluated_at: null, next_retry_at: '2026-07-14T10:05:00Z', sanitized_failure_class: 'runtime_unavailable', sanitized_failure_code: 'profile_missing', sanitized_message: 'runtime_unavailable:profile_missing' },
          inspection: { last_success_at: null, last_failure_at: null, failure_class: null },
        },
      }))
    }
    if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/history?limit=10')) {
      return Promise.resolve(jsonResponse({
        history: [{ id: 'audit-1', timestamp: '2026-07-14T10:00:00Z', action: 'runtime_node.created', actor: 'user', summary: 'Node created for asterisk-ari.' }],
        pagination: { limit: 10, has_more: false, next_before: null },
      }))
    }
    if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/capabilities')) {
      return Promise.resolve(jsonResponse({ runtime_node: runtimeNode }))
    }
    if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/credentials/credential-1/retire')) {
      return Promise.resolve(jsonResponse({ credential: runtimeNode.credentials[0], runtime_node: runtimeNode }))
    }

    return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
  })
}

function mockUserAdminFetch(calls: Array<{ url: string; body?: unknown }>): void {
  vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
    const url = input.toString()
    calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
    if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
    if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
    if (url.endsWith('/api/v1/auth/tenant-context')) return Promise.resolve(jsonResponse(session))
    if (url.includes('/api/v1/admin/users?')) {
      return Promise.resolve(jsonResponse({
        users: [adminUser],
        pagination: { page: 1, per_page: 20, total: 1, has_more: false },
      }))
    }
    if (url.endsWith('/api/v1/admin/users/user-2')) {
      return Promise.resolve(jsonResponse(adminUserDetail))
    }
    if (url.endsWith('/api/v1/admin/users/user-2/telephony-sessions/11111111-2222-3333-4444-555555555555/signaling-credential')) {
      return Promise.resolve(jsonResponse({
        credential: {
          username: 'ts-11111111222233334444555555555555',
          realm: 'sip.utcp.local.test',
          algorithm: 'MD5',
          sip_secret: 'temporary-sip-secret-test-value',
          wss_uri: 'wss://sip.utcp.local.test/ws',
          issued_at: '2026-07-16T10:05:00Z',
          expires_at: '2026-07-16T10:07:00Z',
        },
      }))
    }
    if (url.endsWith('/api/v1/admin/users/user-2/telephony-sessions/11111111-2222-3333-4444-555555555555/end')) {
      return Promise.resolve(jsonResponse({ telephony_session: { ...adminUser.active_telephony_session, status: 'ended', ended_at: '2026-07-16T10:06:00Z' } }))
    }

    return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
  })
}

function createMockRealtimeEcho() {
  const connectionCallbacks: Record<string, Array<(payload?: unknown) => void>> = {}
  const channelErrorCallbacks: Array<(error: unknown) => void> = []
  const privateChannels: string[] = []
  const leftChannels: string[] = []
  const createdConfigs: RuntimeNodeRealtimeConfig[] = []
  let disconnected = false

  const channel: EchoChannel = {
    listen() {
      return channel
    },
    stopListening() {
      return channel
    },
    error(callback) {
      channelErrorCallbacks.push(callback)

      return channel
    },
    bind(event, callback) {
      if (event === 'pusher:subscription_error') channelErrorCallbacks.push(callback)

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

  setRuntimeNodeRealtimeClientFactory((config) => {
    createdConfigs.push(config)

    return client
  })

  return {
    createdConfigs,
    privateChannels,
    leftChannels,
    get disconnected() {
      return disconnected
    },
    emitConnection(event: string, payload?: unknown) {
      for (const callback of connectionCallbacks[event] ?? []) callback(payload)
    },
    emitAuthError(error: unknown) {
      for (const callback of channelErrorCallbacks) callback(error)
    },
  }
}

describe('C1 App shell', () => {
  const mountedWrappers: VueWrapper[] = []

  beforeEach(() => {
    resetAppStateForTests()
    resetAppearanceForTests()
    window.localStorage.clear()
    window.history.replaceState({}, '', '/login')
  })

  afterEach(() => {
    for (const wrapper of mountedWrappers.splice(0)) wrapper.unmount()
    vi.restoreAllMocks()
    disconnectRuntimeNodeRealtime()
    resetRuntimeNodeRealtimeClientFactory()
    resetAppearanceForTests()
    vi.unstubAllEnvs()
    window.localStorage.clear()
  })

  async function mountApp(path = '/login') {
    await router.push(path)
    await router.isReady()
    const wrapper = mount(App, {
      global: {
        plugins: [router],
      },
    })
    mountedWrappers.push(wrapper)
    await flushPromises()
    await flushPromises()
    await flushPromises()

    return wrapper
  }

  it('renders the natural login form without client-side tokens', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ message: 'Unauthenticated.' }, 401))

    const wrapper = await mountApp('/login')

    expect(wrapper.text()).toContain('Sign in')
    expect(wrapper.find('input[type="email"]').exists()).toBe(true)
    expect(wrapper.html()).not.toContain('localStorage')
  })

  it('renders capability-gated administration from the server session', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) {
        return Promise.resolve(jsonResponse(session))
      }
      if (url.endsWith('/api/v1/admin/tenants')) {
        return Promise.resolve(jsonResponse({ tenants: [{ id: 'tenant-1', slug: 'local', display_name: 'Local Tenant', status: 'active' }] }))
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })
    const wrapper = await mountApp('/admin/tenants')

    expect(wrapper.text()).toContain('Local Admin')
    expect(wrapper.text()).toContain('Tenants')
    expect(wrapper.text()).toContain('Users')
    expect(wrapper.text()).toContain('Memberships')
    expect(wrapper.text()).toContain('Runtime nodes')
    expect(wrapper.text()).toContain('Local Tenant')
  })

  it('builds production RuntimeNode Reverb options for the canonical WSS route', () => {
    const appKey = 'test-public-key'
    const options = buildRuntimeNodeEchoOptions({
      appKey,
      wsHost: 'app.utcp.local.test',
      wsPort: 443,
      wsScheme: 'wss',
      wsPath: '/app',
      authEndpoint: '/api/broadcasting/auth',
    })

    expect(options.broadcaster).toBe('reverb')
    expect(options.key).toBe(appKey)
    expect(options.wsHost).toBe('app.utcp.local.test')
    expect(options.wsPort).toBe(443)
    expect(options.wssPort).toBe(443)
    expect(options.forceTLS).toBe(true)
    expect(options.enabledTransports).toEqual(['ws', 'wss'])
    const enabledTransports = options.enabledTransports.map(String)
    expect(enabledTransports).not.toContain('xhr_polling')
    expect(enabledTransports).not.toContain('xhr_streaming')
    expect(options.authEndpoint).toBe('/api/broadcasting/auth')
    expect(options.auth.headers['X-Requested-With']).toBe('XMLHttpRequest')
    expect(options).not.toHaveProperty('wsPath')
    expect(Object.keys(options)).not.toContain('secret')
    expect(Object.values(options)).not.toContain(6001)

    const pusherRouteTemplate = `${String((options as { wsPath?: string }).wsPath ?? '')}/app/{key}`
    expect(pusherRouteTemplate).toBe('/app/{key}')
    expect(pusherRouteTemplate.match(/\/app\//g)).toHaveLength(1)
  })

  it('keeps RuntimeNode realtime disconnected when required browser transport coordinates are missing', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeAdminFetch(calls)
    vi.stubEnv('VITE_UTCP_REVERB_APP_KEY', 'test-public-key')
    vi.stubEnv('VITE_UTCP_WS_HOST', '')
    vi.stubEnv('VITE_UTCP_WS_PORT', '443')
    vi.stubEnv('VITE_UTCP_WS_SCHEME', 'wss')
    vi.stubEnv('VITE_UTCP_WS_PATH', '/app')
    const realtime = createMockRealtimeEcho()
    const wrapper = await mountApp('/admin/runtime-nodes')

    expect(wrapper.text()).toContain('Proof Runtime')
    expect(wrapper.text()).toContain('Live updates disconnected — displayed data may be stale')
    expect(realtime.createdConfigs).toEqual([])
    expect(realtime.privateChannels).toEqual([])
    expect(calls.filter((call) => call.url.endsWith('/api/v1/admin/runtime-node-catalog'))).toHaveLength(1)
    expect(calls.filter((call) => call.url.endsWith('/api/v1/admin/runtime-nodes'))).toHaveLength(1)
    expect(calls.some((call) => call.url.includes('/api/broadcasting/auth'))).toBe(false)
  })

  it('renders runtime-node administration without exposing credential secrets', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeAdminFetch(calls)
    vi.stubEnv('VITE_UTCP_REVERB_APP_KEY', 'public-reverb-key')
    vi.stubEnv('VITE_UTCP_WS_HOST', 'app.utcp.local.test')
    vi.stubEnv('VITE_UTCP_WS_PORT', '443')
    vi.stubEnv('VITE_UTCP_WS_SCHEME', 'wss')
    vi.stubEnv('VITE_UTCP_WS_PATH', '/app')
    const realtime = createMockRealtimeEcho()
    const wrapper = await mountApp('/admin/runtime-nodes')

    expect(wrapper.text()).toContain('Proof Runtime')
    expect(wrapper.text()).toContain('observed unobserved')
    expect(wrapper.text()).toContain('Live updates connecting')
    const runtimeHeading = wrapper.find('.section-heading')
    expect(runtimeHeading.exists()).toBe(true)
    expect(runtimeHeading.find('.live-updates-badge').text()).toContain('Live updates connecting')
    expect(runtimeHeading.findAll('button').some((button) => button.text() === 'Refresh')).toBe(true)
    expect(realtime.createdConfigs).toEqual([{
      appKey: 'public-reverb-key',
      wsHost: 'app.utcp.local.test',
      wsPort: 443,
      wsScheme: 'wss',
      wsPath: '/app',
      authEndpoint: '/api/broadcasting/auth',
    }])
    expect(realtime.privateChannels).toEqual(['tenant.tenant-1.runtime-nodes'])
    expect(calls.filter((call) => call.url.endsWith('/api/v1/admin/runtime-node-catalog'))).toHaveLength(1)
    expect(calls.filter((call) => call.url.endsWith('/api/v1/admin/runtime-nodes'))).toHaveLength(1)
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration'))).toBe(false)
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/runtime-evidence'))).toBe(false)
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/history?limit=10'))).toBe(false)

    realtime.emitConnection('state_change', { current: 'connected' })
    await nextTick()
    expect(wrapper.text()).toContain('Live updates connected')

    realtime.emitConnection('state_change', { current: 'disconnected' })
    await nextTick()
    expect(wrapper.text()).toContain('Live updates reconnecting')

    realtime.emitConnection('unavailable')
    await nextTick()
    expect(wrapper.text()).toContain('Live updates disconnected — displayed data may be stale')
    expect(wrapper.text()).toContain('Details')

    realtime.emitAuthError({ status: 403 })
    await nextTick()
    expect(wrapper.text()).toContain('Live updates unavailable for this session')

    await wrapper.findAll('button').find((button) => button.text() === 'Details')?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Secrets are write-only')
    expect(wrapper.text()).toContain('Simulator')
    expect(wrapper.text()).toContain('Asterisk ARI')
    expect(wrapper.text()).toContain('Event stream')
    expect(wrapper.text()).toContain('Runtime observation')
    expect(wrapper.text()).not.toContain('Conference execution')
    const adapterFieldLabels = wrapper.findAll('label')
      .map((label) => label.text().replace(/\s*required\s*/g, '').trim())
      .filter((label) => [
        'ARI application name',
        'Connect timeout',
        'Request timeout',
        'WebSocket handshake timeout',
        'Heartbeat interval',
        'Minimum reconnect delay',
        'Maximum reconnect delay',
      ].includes(label))
    expect(adapterFieldLabels).toEqual([
      'ARI application name',
      'Connect timeout',
      'Request timeout',
      'WebSocket handshake timeout',
      'Heartbeat interval',
      'Minimum reconnect delay',
      'Maximum reconnect delay',
    ])
    expect(wrapper.find('#runtime-node-runtime-1-adapter-field-application_name').exists()).toBe(true)
    expect(wrapper.find('#runtime-node-runtime-1-adapter-field-connect_timeout_ms').attributes('type')).toBe('number')
    expect(wrapper.text()).toContain('Desired state: draft')
    expect(wrapper.text()).toContain('Observed state: unobserved')
    expect(wrapper.text()).toContain('runtime_node.created')
    expect(wrapper.text()).toContain('Retire')
    expect(wrapper.text()).not.toContain('super-secret')
    expect(wrapper.text()).not.toContain('Start Listener')
    expect(wrapper.findAll('button').some((button) => button.text() === 'Connect')).toBe(false)
    expect(wrapper.text()).not.toContain('Retry')
    expect(wrapper.text()).not.toContain('Reconcile')
    expect(wrapper.text()).not.toContain('Mark Ready')

    const detailCallCountAfterFirstOpen = calls.filter((call) =>
      call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration') ||
      call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/runtime-evidence') ||
      call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/history?limit=10'),
    ).length
    await wrapper.findAll('button').find((button) => button.text() === 'Hide details')?.trigger('click')
    await wrapper.findAll('button').find((button) => button.text() === 'Details')?.trigger('click')
    await flushPromises()
    expect(calls.filter((call) =>
      call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration') ||
      call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/runtime-evidence') ||
      call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/history?limit=10'),
    )).toHaveLength(detailCallCountAfterFirstOpen)
  })

  it('preserves current capabilities and submits adapter configuration through canonical APIs', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeAdminFetch(calls)
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = await mountApp('/admin/runtime-nodes')
    await wrapper.findAll('button').find((button) => button.text() === 'Details')?.trigger('click')
    await flushPromises()

    await wrapper.find('form.inline-form input[type="checkbox"]').setValue(false)
    await wrapper.findAll('form.inline-form').find((form) => form.text().includes('Set capabilities'))?.trigger('submit.prevent')
    await flushPromises()

    expect(calls.some((call) =>
      call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/capabilities') &&
      JSON.stringify(call.body) === JSON.stringify({ capabilities: ['runtime.observation'] }),
    )).toBe(true)

    await wrapper.findAll('form.inline-form').find((form) => form.text().includes('Save adapter configuration'))?.trigger('submit.prevent')
    await flushPromises()

    const configurationSave = calls.find((call) =>
      call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration') &&
      (call.body as { application_name?: string })?.application_name === 'utcp')
    expect(configurationSave?.body).toEqual({
      application_name: 'utcp',
      connect_timeout_ms: 1000,
      request_timeout_ms: 7000,
      websocket_handshake_timeout_ms: 8000,
      heartbeat_interval_ms: 15000,
      reconnect_min_delay_ms: 500,
      reconnect_max_delay_ms: 10000,
    })

    await wrapper.findAll('button').find((button) => button.text() === 'Retire')?.trigger('click')
    await flushPromises()

    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/credentials/credential-1/retire'))).toBe(true)
  })

  it('renders simulator JSON configuration from catalog descriptors and blocks invalid JSON', async () => {
    const simulatorNode = {
      ...runtimeNode,
      id: 'runtime-sim',
      name: 'Simulator Runtime',
      slug: 'simulator-runtime',
      runtime_family: 'simulator',
      adapter_key: 'simulator-deterministic',
      credentials: [],
      capabilities: ['event.stream', 'runtime.observation', 'runtime.configuration'],
    }
    const calls: Array<{ url: string; body?: unknown }> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.endsWith('/api/v1/admin/runtime-node-catalog')) return Promise.resolve(jsonResponse({ catalog: runtimeCatalog }))
      if (url.endsWith('/api/v1/admin/runtime-nodes')) return Promise.resolve(jsonResponse({ runtime_nodes: [runtimeNode, simulatorNode] }))
      if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-sim/adapter-configuration')) {
        if (init?.method === 'PUT') {
          return Promise.resolve(jsonResponse({ adapter_configuration: { configured: true, profile: JSON.parse(String(init.body)) } }))
        }

        return Promise.resolve(jsonResponse({
          adapter_configuration: {
            configured: true,
            profile: {
              scenario_key: 'happy_path',
              scenario_version: 1,
              seed: 'fixture',
              parameters: { calls: 2, enabled: true },
            },
          },
        }))
      }
      if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-sim/runtime-evidence')) {
        return Promise.resolve(jsonResponse({ runtime_evidence: { desired_state: 'draft', observed_state: 'unobserved', observed_at: null, desired_configuration_generation: 1, observed_configuration_generation: null, listener: { status: null, lease_freshness: null, last_claimed_at: null, last_renewed_at: null }, connection: { state: 'closed', latest_epoch_opened_at: null, latest_epoch_closed_at: null, latest_event_at: null, latest_disconnect_class: null }, reconciliation: { state: 'blocked', last_evaluated_at: null, next_retry_at: null, sanitized_failure_class: null, sanitized_failure_code: null, sanitized_message: null }, inspection: { last_success_at: null, last_failure_at: null, failure_class: null } } }))
      }
      if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-sim/history?limit=10')) return Promise.resolve(jsonResponse({ history: [], pagination: { limit: 10, has_more: false, next_before: null } }))
      if (url.includes('/api/v1/admin/runtime-nodes/runtime-1/')) return Promise.resolve(jsonResponse({ message: 'not opened' }, 500))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/admin/runtime-nodes')
    await wrapper.findAll('button').filter((button) => button.text() === 'Details')[1]?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Scenario key')
    expect(wrapper.text()).toContain('Parameters')
    const jsonField = wrapper.find('#runtime-node-runtime-sim-adapter-field-parameters')
    expect(jsonField.element.tagName).toBe('TEXTAREA')
    expect((jsonField.element as HTMLTextAreaElement).value).toContain('"calls": 2')

    await jsonField.setValue('{bad json')
    await wrapper.findAll('form.inline-form').find((form) => form.text().includes('Save adapter configuration'))?.trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.text()).toContain('Parameters must contain valid JSON.')
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-sim/adapter-configuration') && call.body !== undefined)).toBe(false)

    await jsonField.setValue('{"calls":0,"enabled":false,"tags":["a"],"nothing":null}')
    await wrapper.findAll('form.inline-form').find((form) => form.text().includes('Save adapter configuration'))?.trigger('submit.prevent')
    await flushPromises()

    const saveCall = calls.find((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-sim/adapter-configuration') && call.body !== undefined)
    expect(saveCall?.body).toMatchObject({
      scenario_key: 'happy_path',
      scenario_version: 1,
      seed: 'fixture',
      parameters: { calls: 0, enabled: false, tags: ['a'], nothing: null },
    })
  })

  it('omits read-only and blank write-only descriptor values while preserving entered replacements', async () => {
    const writeOnlyCatalog = structuredClone(runtimeCatalog) as RuntimeManagementCatalog
    writeOnlyCatalog.adapter_keys['asterisk-ari'].adapter_configuration = {
      fields: [
        {
          key: 'application_name',
          label: 'ARI application name',
          help: 'Synthetic writable text fixture.',
          input_type: 'text',
          required: true,
          read_only: false,
          write_only: false,
          default: 'catalog-default',
          order: 10,
        },
        {
          key: 'connect_timeout_ms',
          label: 'Connect timeout',
          help: 'Synthetic writable integer fixture.',
          input_type: 'integer',
          required: true,
          read_only: false,
          write_only: false,
          default: 0,
          order: 20,
        },
        {
          key: 'read_only_note',
          label: 'Read-only note',
          help: 'Synthetic read-only fixture.',
          input_type: 'text',
          required: false,
          read_only: true,
          write_only: false,
          default: null,
          order: 30,
        },
        {
          key: 'replacement_secret',
          label: 'Replacement secret',
          help: 'Synthetic write-only fixture.',
          input_type: 'text',
          required: false,
          read_only: false,
          write_only: true,
          default: null,
          order: 40,
        },
      ],
    }
    const calls: Array<{ url: string; body?: unknown }> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.endsWith('/api/v1/admin/runtime-node-catalog')) return Promise.resolve(jsonResponse({ catalog: writeOnlyCatalog }))
      if (url.endsWith('/api/v1/admin/runtime-nodes')) return Promise.resolve(jsonResponse({ runtime_nodes: [runtimeNode] }))
      if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration')) {
        if (init?.method === 'PUT') return Promise.resolve(jsonResponse({ adapter_configuration: { configured: true, profile: JSON.parse(String(init.body)) } }))

        return Promise.resolve(jsonResponse({
          adapter_configuration: {
            configured: true,
            profile: {
              application_name: 'utcp',
              connect_timeout_ms: 0,
              read_only_note: 'server-owned',
              replacement_secret: 'synthetic-readback-secret',
            },
          },
        }))
      }
      if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/runtime-evidence')) {
        return Promise.resolve(jsonResponse({ runtime_evidence: { desired_state: 'draft', observed_state: 'unobserved', observed_at: null, desired_configuration_generation: 1, observed_configuration_generation: null, listener: { status: null, lease_freshness: null, last_claimed_at: null, last_renewed_at: null }, connection: { state: 'closed', latest_epoch_opened_at: null, latest_epoch_closed_at: null, latest_event_at: null, latest_disconnect_class: null }, reconciliation: { state: 'blocked', last_evaluated_at: null, next_retry_at: null, sanitized_failure_class: null, sanitized_failure_code: null, sanitized_message: null }, inspection: { last_success_at: null, last_failure_at: null, failure_class: null } } }))
      }
      if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/history?limit=10')) return Promise.resolve(jsonResponse({ history: [], pagination: { limit: 10, has_more: false, next_before: null } }))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/admin/runtime-nodes')
    await wrapper.findAll('button').find((button) => button.text() === 'Details')?.trigger('click')
    await flushPromises()

    const writeOnlyInput = wrapper.find('#runtime-node-runtime-1-adapter-field-replacement_secret')
    expect(writeOnlyInput.attributes('type')).toBe('password')
    expect((writeOnlyInput.element as HTMLInputElement).value).toBe('')
    expect(wrapper.text()).not.toContain('synthetic-readback-secret')

    await wrapper.findAll('form.inline-form').find((form) => form.text().includes('Save adapter configuration'))?.trigger('submit.prevent')
    await flushPromises()

    const firstSave = calls.find((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration') && call.body !== undefined)
    expect(firstSave?.body).toEqual({
      application_name: 'utcp',
      connect_timeout_ms: 0,
    })

    await wrapper.find('#runtime-node-runtime-1-adapter-field-replacement_secret').setValue('synthetic-replacement')
    await wrapper.findAll('form.inline-form').find((form) => form.text().includes('Save adapter configuration'))?.trigger('submit.prevent')
    await flushPromises()

    const saveBodies = calls
      .filter((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration') && call.body !== undefined)
      .map((call) => call.body)
    expect(saveBodies[1]).toEqual({
      application_name: 'utcp',
      connect_timeout_ms: 0,
      replacement_secret: 'synthetic-replacement',
    })
    expect((wrapper.find('#runtime-node-runtime-1-adapter-field-replacement_secret').element as HTMLInputElement).value).toBe('')
    expect(wrapper.text()).not.toContain('synthetic-replacement')
    expect(wrapper.text()).not.toContain('synthetic-readback-secret')
  })

  it('blocks required unsupported RuntimeNode descriptors without affecting the list', async () => {
    const unsupportedCatalog = structuredClone(runtimeCatalog) as RuntimeManagementCatalog
    unsupportedCatalog.adapter_keys['asterisk-ari'].adapter_configuration = {
      fields: [
        {
          key: 'application_name',
          label: 'ARI application name',
          help: 'Unsupported fixture.',
          input_type: 'unsupported' as 'text',
          required: true,
          read_only: false,
          write_only: false,
          default: null,
          order: 10,
        },
      ],
    }
    const calls: Array<{ url: string; body?: unknown }> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.endsWith('/api/v1/admin/runtime-node-catalog')) return Promise.resolve(jsonResponse({ catalog: unsupportedCatalog }))
      if (url.endsWith('/api/v1/admin/runtime-nodes')) return Promise.resolve(jsonResponse({ runtime_nodes: [runtimeNode] }))
      if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration')) {
        if (init?.method === 'PUT') return Promise.resolve(jsonResponse({ message: 'should not save' }, 500))

        return Promise.resolve(jsonResponse({ adapter_configuration: { configured: true, profile: { application_name: 'utcp' } } }))
      }
      if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/runtime-evidence')) {
        return Promise.resolve(jsonResponse({ runtime_evidence: { desired_state: 'draft', observed_state: 'unobserved', observed_at: null, desired_configuration_generation: 1, observed_configuration_generation: null, listener: { status: null, lease_freshness: null, last_claimed_at: null, last_renewed_at: null }, connection: { state: 'closed', latest_epoch_opened_at: null, latest_epoch_closed_at: null, latest_event_at: null, latest_disconnect_class: null }, reconciliation: { state: 'blocked', last_evaluated_at: null, next_retry_at: null, sanitized_failure_class: null, sanitized_failure_code: null, sanitized_message: null }, inspection: { last_success_at: null, last_failure_at: null, failure_class: null } } }))
      }
      if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/history?limit=10')) return Promise.resolve(jsonResponse({ history: [], pagination: { limit: 10, has_more: false, next_before: null } }))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/admin/runtime-nodes')
    await wrapper.findAll('button').find((button) => button.text() === 'Details')?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Proof Runtime')
    expect(wrapper.text()).toContain('Required field application_name uses unsupported type unsupported.')
    expect(wrapper.find('#runtime-node-runtime-1-adapter-field-application_name').exists()).toBe(false)
    const saveButton = wrapper.findAll('button').find((button) => button.text() === 'Save adapter configuration')
    expect(saveButton?.attributes('disabled')).toBeDefined()
    await wrapper.findAll('form.inline-form').find((form) => form.text().includes('Save adapter configuration'))?.trigger('submit.prevent')
    await flushPromises()

    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration') && call.body !== undefined)).toBe(false)
  })

  it('keeps repeated RuntimeNode credential field IDs unique and scoped to labels', async () => {
    const secondRuntimeNode = { ...runtimeNode, id: 'runtime-2', name: 'Second Runtime', slug: 'second-runtime' }
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/admin/runtime-node-catalog')) return Promise.resolve(jsonResponse({ catalog: runtimeCatalog }))
      if (url.endsWith('/api/v1/admin/runtime-nodes')) return Promise.resolve(jsonResponse({ runtime_nodes: [runtimeNode, secondRuntimeNode] }))
      if (url.includes('/api/v1/admin/runtime-nodes/runtime-')) return Promise.resolve(jsonResponse({ message: 'Detail unavailable.' }, 500))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/admin/runtime-nodes')
    for (const button of wrapper.findAll('button').filter((candidate) => candidate.text() === 'Details')) {
      await button.trigger('click')
    }
    await flushPromises()

    const credentialInputs = wrapper.findAll('input[type="password"][placeholder="Write-only secret"]')
    const credentialIds = credentialInputs.map((input) => input.attributes('id'))
    expect(credentialIds).toEqual(['credential-secret-runtime-1', 'credential-secret-runtime-2'])
    expect(new Set(credentialIds).size).toBe(credentialIds.length)
    for (const id of credentialIds) {
      expect(wrapper.find(`label[for="${id}"]`).exists()).toBe(true)
      expect(id).not.toContain('super-secret')
    }
  })

  it('redirects a protected page to login when the session endpoint rejects it', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ message: 'Unauthenticated.' }, 401))
    const wrapper = await mountApp('/admin/users')

    expect(router.currentRoute.value.path).toBe('/login')
    expect(wrapper.text()).toContain('Sign in to continue.')
  })

  it('renders canonical user detail and keeps signaling secrets transient', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockUserAdminFetch(calls)
    const wrapper = await mountApp('/admin/users')

    expect(wrapper.text()).toContain('Operator User')
    expect(wrapper.text()).toContain('TelephonySession: active')
    expect(wrapper.text()).toContain('Signaling: eligible / registered')
    expect(wrapper.find('label[for="user-search"]').text()).toContain('Search')
    expect(wrapper.find('.ui-status-badge--success').text()).toBe('active')

    await wrapper.findAll('a').find((link) => link.text() === 'Details')?.trigger('click')
    await flushPromises()
    await flushPromises()

    expect(wrapper.text()).toContain('User detail')
    expect(wrapper.text()).toContain('Tenant memberships')
    expect(wrapper.text()).toContain('Active TelephonySession')
    expect(wrapper.text()).toContain('Signaling registration')
    expect(wrapper.text()).toContain('Desired registration state')
    expect(wrapper.text()).toContain('Observed runtime state')
    expect(wrapper.text()).toContain('Reconciliation state')
    expect(wrapper.text()).toContain('Currently registered')
    expect(wrapper.text()).not.toContain('ha1')
    expect(wrapper.text()).not.toContain('Contact:')
    expect(wrapper.text()).not.toContain('ruid')
    expect(wrapper.text()).not.toContain('Assign provider node')
    expect(wrapper.text()).not.toContain('Assign PBX')
    expect(wrapper.text()).not.toContain('Register now')
    expect(wrapper.text()).not.toContain('Remove Contact')
    expect(wrapper.text()).not.toContain('Run observer')
    expect(wrapper.text()).not.toContain('Run projection')
    expect(wrapper.text()).not.toContain('Retry reconciliation now')

    const focusSpy = vi.spyOn(HTMLElement.prototype, 'focus')

    await wrapper.findAll('button').find((button) => button.text() === 'Reissue signaling credential')?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Temporary SIP credential issued')
    expect(wrapper.text()).toContain('hidden')
    expect(wrapper.text()).not.toContain('temporary-sip-secret-test-value')
    expect(wrapper.find('.one-time-secret').attributes('tabindex')).toBe('-1')
    expect(focusSpy).toHaveBeenCalled()

    await wrapper.findAll('button').find((button) => button.text() === 'Reveal secret')?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('temporary-sip-secret-test-value')

    focusSpy.mockClear()
    await wrapper.findAll('button').find((button) => button.text() === 'Close credential')?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).not.toContain('temporary-sip-secret-test-value')
    expect(focusSpy).toHaveBeenCalled()
    focusSpy.mockRestore()

    await wrapper.find('select').setValue('tenant-1')
    await flushPromises()

    expect(wrapper.text()).not.toContain('temporary-sip-secret-test-value')
    expect(calls.some((call) => call.url.endsWith('/signaling-credential'))).toBe(true)
  })

  it('navigates the user list to the next and previous page through canonical pagination controls', async () => {
    const pageTwoUser = { ...adminUser, id: 'user-3', email: 'second-page@utcp.local.test', display_name: 'Second Page User' }
    const calls: Array<{ url: string; body?: unknown }> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.includes('/api/v1/admin/users?') && new URL(url, 'http://localhost').searchParams.get('page') === '2') {
        return Promise.resolve(jsonResponse({
          users: [pageTwoUser],
          pagination: { page: 2, per_page: 20, total: 21, has_more: false },
        }))
      }
      if (url.includes('/api/v1/admin/users?')) {
        return Promise.resolve(jsonResponse({
          users: [adminUser],
          pagination: { page: 1, per_page: 20, total: 21, has_more: true },
        }))
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })
    const wrapper = await mountApp('/admin/users')

    expect(wrapper.text()).toContain('Operator User')
    expect(wrapper.text()).toContain('Page 1 · 21 users')
    const nextButton = wrapper.findAll('button').find((button) => button.text() === 'Next')
    const previousButton = wrapper.findAll('button').find((button) => button.text() === 'Previous')
    expect(previousButton?.attributes('disabled')).toBeDefined()

    await nextButton?.trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({ page: '2' })
    expect(calls.some((call) => call.url.includes('/api/v1/admin/users?') && new URL(call.url, 'http://localhost').searchParams.get('page') === '2')).toBe(true)
    expect(wrapper.text()).toContain('Second Page User')
    expect(wrapper.text()).toContain('Page 2 · 21 users')
    expect(wrapper.findAll('button').find((button) => button.text() === 'Next')?.attributes('disabled')).toBeDefined()

    await wrapper.findAll('button').find((button) => button.text() === 'Previous')?.trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({})
    expect(wrapper.text()).toContain('Operator User')
    expect(wrapper.text()).toContain('Page 1 · 21 users')
  })

  it('restores Users search, status, page, and page size from the URL-backed query state', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.includes('/api/v1/admin/users')) {
        const params = new URL(url, 'http://localhost').searchParams
        const email = params.get('search') === 'bob' ? 'bob@utcp.local.test' : 'alice@utcp.local.test'

        return Promise.resolve(jsonResponse({
          users: [{ ...adminUser, email, display_name: params.get('search') === 'bob' ? 'Bob User' : 'Alice User', status: params.get('status') ?? 'active' }],
          pagination: {
            page: Number(params.get('page') ?? '1'),
            per_page: Number(params.get('per_page') ?? '20'),
            total: 37,
            has_more: true,
          },
        }))
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/admin/users?page=2&per_page=10&search=alice&status=active')
    const initialUserCall = calls.find((call) => call.url.includes('/api/v1/admin/users'))
    const initialParams = new URL(initialUserCall?.url ?? '', 'http://localhost').searchParams

    expect(initialParams.get('search')).toBe('alice')
    expect(initialParams.get('status')).toBe('active')
    expect(initialParams.get('page')).toBe('2')
    expect(initialParams.get('per_page')).toBe('10')
    expect((wrapper.find('#user-search').element as HTMLInputElement).value).toBe('alice')
    expect((wrapper.find('#user-status-filter').element as HTMLSelectElement).value).toBe('active')
    expect(wrapper.text()).toContain('Alice User')

    const callCountBeforeUnchangedApply = calls.length
    await wrapper.find('form[role="search"]').trigger('submit')
    await flushPromises()
    expect(calls).toHaveLength(callCountBeforeUnchangedApply)

    await wrapper.find('#user-search').setValue('bob')
    await wrapper.find('form[role="search"]').trigger('submit')
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({ search: 'bob', status: 'active', per_page: '10' })
    const latestUserCall = [...calls].reverse().find((call) => call.url.includes('/api/v1/admin/users'))
    const latestParams = new URL(latestUserCall?.url ?? '', 'http://localhost').searchParams
    expect(latestParams.get('search')).toBe('bob')
    expect(latestParams.get('page')).toBe('1')
    expect(wrapper.text()).toContain('Bob User')
  })

  it('keeps rendered Users rows and pagination bound to the newer query when an older response resolves last', async () => {
    const activeUser = { ...adminUser, id: 'active-user', email: 'active@utcp.local.test', display_name: 'Active Query User', status: 'active' }
    const suspendedUser = { ...adminUser, id: 'suspended-user', email: 'suspended@utcp.local.test', display_name: 'Suspended Query User', status: 'suspended' }
    const activeRequest = deferredResponse()
    const suspendedRequest = deferredResponse()
    const calls: string[] = []

    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      calls.push(url)
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.includes('/api/v1/admin/users')) {
        const params = new URL(url, 'http://localhost').searchParams
        if (params.get('status') === 'active') return activeRequest.promise
        if (params.get('status') === 'suspended') return suspendedRequest.promise
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/admin/users?status=active')

    expect(calls.some((url) => url.includes('/api/v1/admin/users') && url.includes('status=active'))).toBe(true)

    await wrapper.find('#user-status-filter').setValue('suspended')
    await wrapper.find('form[role="search"]').trigger('submit')
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({ status: 'suspended' })
    expect(calls.some((url) => url.includes('/api/v1/admin/users') && url.includes('status=suspended'))).toBe(true)

    suspendedRequest.resolve(jsonResponse({
      users: [suspendedUser],
      pagination: { page: 1, per_page: 20, total: 1, has_more: false },
    }))
    await flushPromises()
    await flushPromises()

    expect(wrapper.text()).toContain('Suspended Query User')
    expect(wrapper.text()).toContain('Page 1 · 1 users')
    expect(wrapper.text()).not.toContain('Active Query User')
    expect(wrapper.findAll('button').find((button) => button.text() === 'Next')?.attributes('disabled')).toBeDefined()

    activeRequest.resolve(jsonResponse({
      users: [activeUser],
      pagination: { page: 1, per_page: 20, total: 206, has_more: true },
    }))
    await flushPromises()
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({ status: 'suspended' })
    expect(wrapper.text()).toContain('Suspended Query User')
    expect(wrapper.text()).toContain('Page 1 · 1 users')
    expect(wrapper.text()).not.toContain('Active Query User')
    expect(wrapper.text()).not.toContain('Page 1 · 206 users')
    expect(wrapper.findAll('button').find((button) => button.text() === 'Next')?.attributes('disabled')).toBeDefined()
  })

  it('keeps rendered Users rows tenant-scoped when a prior-tenant response resolves after tenant switch', async () => {
    const tenantAUser = { ...adminUser, id: 'tenant-a-user', email: 'tenant-a@utcp.local.test', display_name: 'Tenant A User' }
    const tenantBUser = { ...adminUser, id: 'tenant-b-user', email: 'tenant-b@utcp.local.test', display_name: 'Tenant B User' }
    const twoTenantSession = {
      ...session,
      active_tenant: { tenant_id: 'tenant-a', slug: 'tenant-a', display_name: 'Tenant A' },
      memberships: [
        {
          membership_id: 'membership-a',
          tenant_id: 'tenant-a',
          slug: 'tenant-a',
          display_name: 'Tenant A',
          status: 'active',
          membership_status: 'active',
        },
        {
          membership_id: 'membership-b',
          tenant_id: 'tenant-b',
          slug: 'tenant-b',
          display_name: 'Tenant B',
          status: 'active',
          membership_status: 'active',
        },
      ],
    }
    const tenantBSession = {
      ...twoTenantSession,
      active_tenant: { tenant_id: 'tenant-b', slug: 'tenant-b', display_name: 'Tenant B' },
    }
    const tenantARequest = deferredResponse()
    const tenantBRequest = deferredResponse()
    let currentTenant = 'tenant-a'

    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(twoTenantSession))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.endsWith('/api/v1/auth/tenant-context')) {
        currentTenant = String(JSON.parse(String(init?.body)).tenant_id)

        return Promise.resolve(jsonResponse(tenantBSession))
      }
      if (url.includes('/api/v1/admin/users')) {
        return currentTenant === 'tenant-a' ? tenantARequest.promise : tenantBRequest.promise
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/admin/users')
    await wrapper.find('#active-tenant').setValue('tenant-b')
    await flushPromises()

    tenantBRequest.resolve(jsonResponse({
      users: [tenantBUser],
      pagination: { page: 1, per_page: 20, total: 1, has_more: false },
    }))
    await flushPromises()
    await flushPromises()

    expect(wrapper.text()).toContain('Tenant B User')
    expect(wrapper.text()).toContain('Page 1 · 1 users')
    expect(wrapper.text()).not.toContain('Tenant A User')

    tenantARequest.resolve(jsonResponse({
      users: [tenantAUser],
      pagination: { page: 1, per_page: 20, total: 44, has_more: true },
    }))
    await flushPromises()
    await flushPromises()

    expect(wrapper.text()).toContain('Tenant B User')
    expect(wrapper.text()).toContain('Page 1 · 1 users')
    expect(wrapper.text()).not.toContain('Tenant A User')
    expect(wrapper.text()).not.toContain('Page 1 · 44 users')
  })

  it('shows pending-removal wording for an ended session and hides mutation actions', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.endsWith('/api/v1/admin/users/user-2')) {
        return Promise.resolve(jsonResponse({
          ...adminUserDetail,
          active_telephony_session: { ...adminUser.active_telephony_session, status: 'ended', ended_at: '2026-07-16T10:10:00Z' },
          signaling: {
            ...adminUserDetail.signaling,
            credential: null,
            registration: { ...adminUserDetail.signaling.registration, desired_state: 'removed', observed_state: 'registered', pending_removal: true },
          },
        }))
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })
    const wrapper = await mountApp('/admin/users/user-2')

    expect(wrapper.text()).toContain('Registration removed. Contact pending expiration. New registrations and refreshes are blocked.')
    expect(wrapper.findAll('button').find((button) => button.text() === 'End TelephonySession')).toBeUndefined()
    expect(wrapper.findAll('button').find((button) => /issue|reissue/i.test(button.text()))).toBeUndefined()
  })

  it('shows converged-removal wording once the Contact has fully expired, not the not-issued fallback', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.endsWith('/api/v1/admin/users/user-2')) {
        return Promise.resolve(jsonResponse({
          ...adminUserDetail,
          active_telephony_session: { ...adminUser.active_telephony_session, status: 'ended', ended_at: '2026-07-16T10:10:00Z' },
          signaling: {
            ...adminUserDetail.signaling,
            credential: null,
            registration: { ...adminUserDetail.signaling.registration, desired_state: 'removed', observed_state: 'expired', pending_removal: false },
          },
        }))
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })
    const wrapper = await mountApp('/admin/users/user-2')

    expect(wrapper.text()).toContain('Registration removed. No active Contact. Reconciliation is converged when reported by the backend.')
    expect(wrapper.text()).not.toContain('No signaling credential has been issued.')
  })

  it('uses the dashboard as the authenticated root and login destination', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/admin/runtime-nodes')) return Promise.resolve(jsonResponse({ runtime_nodes: [] }))
      if (url.includes('/api/v1/admin/users')) return Promise.resolve(jsonResponse({ users: [], pagination: { page: 1, per_page: 5, total: 0, has_more: false } }))
      if (url.endsWith('/api/v1/admin/memberships')) return Promise.resolve(jsonResponse({ memberships: [] }))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const rootWrapper = await mountApp('/')
    expect(router.currentRoute.value.path).toBe('/dashboard')
    expect(rootWrapper.text()).toContain('Dashboard')

    const loginWrapper = await mountApp('/login')
    expect(router.currentRoute.value.path).toBe('/dashboard')
    expect(loginWrapper.text()).toContain('Local Admin')
  })

  it('renders explicit forbidden and not-found routes through Vue Router', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(limitedSession))
      if (url.endsWith('/api/v1/admin/runtime-nodes')) return Promise.resolve(jsonResponse({ runtime_nodes: [] }))
      if (url.includes('/api/v1/admin/users')) return Promise.resolve(jsonResponse({ users: [] }, 403))
      if (url.endsWith('/api/v1/admin/memberships')) return Promise.resolve(jsonResponse({ memberships: [] }, 403))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const forbiddenWrapper = await mountApp('/admin/runtime-nodes')
    expect(router.currentRoute.value.path).toBe('/forbidden')
    expect(forbiddenWrapper.text()).toContain('Forbidden')
    expect(forbiddenWrapper.text()).toContain('Back to dashboard')

    const notFoundWrapper = await mountApp('/missing-route')
    expect(router.currentRoute.value.name).toBe('not-found')
    expect(notFoundWrapper.text()).toContain('Not found')
  })

  it('keeps capability navigation useful for a limited normal user without role-name authority', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(limitedSession))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/dashboard')

    expect(wrapper.text()).toContain('Dashboard')
    expect(wrapper.text()).toContain('Available management')
    expect(wrapper.text()).not.toContain('Tenants')
    expect(wrapper.text()).not.toContain('Runtime nodes')
    expect(calls.every((call) => !String(call.body ?? '').includes('role'))).toBe(true)
  })

  it('renders the local theme control for a limited user without API persistence', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(limitedSession))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/dashboard')
    const callCountBeforeThemeChange = calls.length
    const appearanceControl = wrapper.find('select[aria-label="Appearance"]')

    expect(appearanceControl.exists()).toBe(true)
    expect(appearanceControl.text()).toContain('System')
    expect(appearanceControl.text()).toContain('Light')
    expect(appearanceControl.text()).toContain('Dark')

    await appearanceControl.setValue('dark')
    await flushPromises()

    expect(document.documentElement.dataset.theme).toBe('dark')
    expect(window.localStorage.getItem(appearanceStorageKey)).toBe('dark')
    expect(calls).toHaveLength(callCountBeforeThemeChange)
  })

  it('loads dashboard summaries from existing APIs and preserves partial failures', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/admin/runtime-nodes')) return Promise.resolve(jsonResponse({ message: 'Runtime summary unavailable.' }, 500))
      if (url.includes('/api/v1/admin/users')) {
        return Promise.resolve(jsonResponse({
          users: [adminUser],
          pagination: { page: 1, per_page: 5, total: 1, has_more: false },
        }))
      }
      if (url.endsWith('/api/v1/admin/memberships')) return Promise.resolve(jsonResponse({ memberships: [] }))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/dashboard')

    expect(wrapper.text()).toContain('Runtime summary unavailable.')
    expect(wrapper.text()).toContain('Users and TelephonySessions')
    expect(wrapper.text()).toContain('Operator User')
    expect(wrapper.text()).toContain('No memberships were returned.')
    expect(wrapper.text()).not.toContain('Runtime nodes 0')

    await wrapper.findAll('button').find((button) => button.text() === 'Refresh')?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Runtime summary unavailable.')
  })

  it('preserves router-level browser history across current direct URLs', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.includes('/api/v1/admin/users')) {
        return Promise.resolve(jsonResponse({
          users: [adminUser],
          pagination: { page: 1, per_page: 20, total: 1, has_more: false },
        }))
      }
      if (url.endsWith('/api/v1/admin/runtime-nodes')) return Promise.resolve(jsonResponse({ runtime_nodes: [] }))
      if (url.endsWith('/api/v1/admin/memberships')) return Promise.resolve(jsonResponse({ memberships: [] }))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/admin/users')
    expect(router.currentRoute.value.path).toBe('/admin/users')
    expect(wrapper.text()).toContain('Operator User')

    const historyRouter = createUtcpRouter(createMemoryHistory())
    await historyRouter.push('/admin/users')
    await historyRouter.isReady()
    expect(historyRouter.currentRoute.value.path).toBe('/admin/users')

    await historyRouter.push('/dashboard')
    await flushPromises()
    expect(historyRouter.currentRoute.value.path).toBe('/dashboard')

    historyRouter.back()
    await new Promise((resolve) => setTimeout(resolve, 0))
    await flushPromises()
    await flushPromises()
    expect(historyRouter.currentRoute.value.path).toBe('/admin/users')
  })

  it('keeps login errors associated and preserves intended redirects without credential persistence', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse({ message: 'Unauthenticated.' }, 401))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.endsWith('/api/v1/auth/login')) return Promise.resolve(jsonResponse({ message: 'Invalid credentials.' }, 422))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/login?redirect=/admin/users')
    expect(wrapper.text()).toContain('Sign in to continue.')
    expect(wrapper.text()).not.toContain('Authentication failed')
    expect(wrapper.find('#login-password').attributes('aria-invalid')).toBeUndefined()

    await wrapper.find('#login-email').setValue('admin@utcp.local.test')
    await wrapper.find('#login-password').setValue('wrong-password')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.find('#login-password').attributes('aria-describedby')).toContain('login-password-error')
    expect(wrapper.find('#login-password').attributes('aria-invalid')).toBe('true')
    expect(wrapper.find('[role="alert"]').text()).toContain('Invalid credentials.')
    expect(window.localStorage.getItem('wrong-password')).toBeNull()
    expect(window.sessionStorage.getItem('wrong-password')).toBeNull()
    expect(calls.some((call) => call.url.endsWith('/api/v1/auth/login') && JSON.stringify(call.body).includes('wrong-password'))).toBe(true)

    wrapper.unmount()
    resetAppStateForTests()
    vi.restoreAllMocks()
    let authenticated = false
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) {
        return authenticated
          ? Promise.resolve(jsonResponse(session))
          : Promise.resolve(jsonResponse({ message: 'Unauthenticated.' }, 401))
      }
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.endsWith('/api/v1/auth/login')) {
        authenticated = true

        return Promise.resolve(jsonResponse({ message: 'Authenticated.' }))
      }
      if (url.includes('/api/v1/admin/users?')) {
        return Promise.resolve(jsonResponse({ users: [], pagination: { page: 1, per_page: 20, total: 0, has_more: false } }))
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const successWrapper = await mountApp('/login?redirect=/admin/users')
    await successWrapper.find('#login-email').setValue('admin@utcp.local.test')
    await successWrapper.find('#login-password').setValue('correct-password')
    await successWrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/admin/users')
    expect(window.localStorage.getItem('correct-password')).toBeNull()
    expect(window.sessionStorage.getItem('correct-password')).toBeNull()
  })

  it('keeps change-password validation and redirect behavior component-backed', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.endsWith('/api/v1/auth/change-password')) {
        const body = JSON.parse(String(init?.body))
        if (body.new_password === 'too-short') {
          return Promise.resolve(jsonResponse({ message: 'The new password must be at least 12 characters.' }, 422))
        }

        return Promise.resolve(jsonResponse({ message: 'Password changed.' }))
      }
      if (url.includes('/api/v1/admin/users?')) {
        return Promise.resolve(jsonResponse({ users: [], pagination: { page: 1, per_page: 20, total: 0, has_more: false } }))
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/change-password?redirect=/admin/users')
    await wrapper.find('#current-password').setValue('current-password')
    await wrapper.find('#new-password').setValue('new-valid-password')
    await wrapper.find('#confirm-password').setValue('different-password')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.find('#confirm-password').attributes('aria-describedby')).toContain('confirm-password-error')
    expect(wrapper.text()).toContain('New password and confirmation must match.')

    await wrapper.find('#new-password').setValue('too-short')
    await wrapper.find('#confirm-password').setValue('too-short')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.text()).toContain('The new password must be at least 12 characters.')

    await wrapper.find('#new-password').setValue('new-valid-password')
    await wrapper.find('#confirm-password').setValue('new-valid-password')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/admin/users')
  })

  it('renders tenants, memberships, and server role catalog controls through shared components', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    const managementSession = {
      ...session,
      capabilities: [...session.capabilities, 'platform.tenants.manage'],
    }
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(managementSession))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.endsWith('/api/v1/admin/tenants') && init?.method === 'POST') {
        return Promise.resolve(jsonResponse({ tenant: { id: 'tenant-2', slug: 'proof', display_name: 'Proof Tenant', status: 'active' } }, 201))
      }
      if (url.endsWith('/api/v1/admin/tenants')) return Promise.resolve(jsonResponse({ tenants: [] }))
      if (url.endsWith('/api/v1/admin/roles')) return Promise.resolve(jsonResponse(roleCatalog))
      if (url.endsWith('/api/v1/admin/memberships') && init?.method === 'POST') {
        return Promise.resolve(jsonResponse({ membership_id: 'membership-3' }, 201))
      }
      if (url.endsWith('/api/v1/admin/memberships')) {
        return Promise.resolve(jsonResponse({ memberships: [{ id: 'membership-2', user_id: 'user-2', email: adminUser.email, display_name: adminUser.display_name, status: 'active' }] }))
      }
      if (url.includes('/api/v1/admin/users?')) {
        return Promise.resolve(jsonResponse({ users: [adminUser], pagination: { page: 1, per_page: 20, total: 1, has_more: false } }))
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const tenantsWrapper = await mountApp('/admin/tenants')
    expect(tenantsWrapper.find('#tenant-slug').exists()).toBe(true)
    expect(tenantsWrapper.findComponent({ name: 'UiEmptyState' }).exists()).toBe(true)
    await tenantsWrapper.find('#tenant-slug').setValue('proof')
    await tenantsWrapper.find('#tenant-display-name').setValue('Proof Tenant')
    await tenantsWrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/tenants') && JSON.stringify(call.body) === JSON.stringify({ slug: 'proof', display_name: 'Proof Tenant' }))).toBe(true)

    const membershipsWrapper = await mountApp('/admin/memberships')
    expect(membershipsWrapper.find('#membership-role').text()).toContain('Tenant member')
    expect(membershipsWrapper.find('#membership-role').text()).toContain('Tenant admin')
    expect(membershipsWrapper.find('#membership-role').text()).not.toContain('Platform admin')
    await membershipsWrapper.find('#membership-user').setValue('user-2')
    await membershipsWrapper.find('#membership-role').setValue('tenant-admin')
    await membershipsWrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/memberships') && JSON.stringify(call.body) === JSON.stringify({ user_id: 'user-2', role_key: 'tenant-admin' }))).toBe(true)
  })

  it('keeps UI-B2 static adoption boundaries and the Users narrow metadata layout contract', () => {
    const viewSources = {
      'LoginView.vue': loginViewSource,
      'ChangePasswordView.vue': changePasswordViewSource,
      'TenantsView.vue': tenantsViewSource,
      'MembershipsView.vue': membershipsViewSource,
      'RuntimeNodesView.vue': runtimeNodesViewSource,
      'UserDetailView.vue': userDetailViewSource,
    }

    for (const source of Object.values(viewSources)) {
      expect(source).toContain("components/ui/Ui")
      expect(source).not.toMatch(/<button[\s>]/)
      expect(source).not.toMatch(/<select[\s>]/)
    }
    expect(viewSources['RuntimeNodesView.vue'].match(/<input[\s>]/g)).toHaveLength(1)
    expect(viewSources['RuntimeNodesView.vue']).toContain('type="checkbox"')
    for (const source of Object.entries(viewSources).filter(([viewName]) => viewName !== 'RuntimeNodesView.vue').map(([, source]) => source)) {
      expect(source).not.toMatch(/<input[\s>]/)
    }

    const membershipSource = viewSources['MembershipsView.vue']
    expect(membershipSource).not.toContain('<option value="tenant-member">')
    expect(membershipSource).not.toContain('<option value="tenant-admin">')
    expect(membershipSource).toContain('tenantRoleOptions')

    const runtimeSource = viewSources['RuntimeNodesView.vue']
    expect(runtimeSource).not.toContain("adapter_key === 'asterisk-ari'")
    expect(runtimeSource).not.toContain('saveAsteriskAdapterConfiguration')
    expect(runtimeSource).not.toContain('asteriskConfigurationForm')
    expect(appStateSource).not.toContain('saveAsteriskAdapterConfiguration')
    expect(appStateSource).not.toContain('asteriskConfigurationForm')
    expect(appStateSource).toContain('saveRuntimeAdapterConfiguration')
    expect(runtimeSource).toContain('RuntimeNodeCatalogField')
    expect(runtimeSource).not.toContain('feature gate')

    expect(usersViewSource).toContain('class="subgrid"')
    expect(usersViewSource).toContain('Memberships:')
    expect(usersViewSource).toContain('TelephonySession:')
  })

  it('accepts runtime adapter configuration descriptors in the catalog contract and cuts over rendering authority', () => {
    const asteriskFields = runtimeCatalog.adapter_keys['asterisk-ari'].adapter_configuration?.fields ?? []
    expect(asteriskFields.map((field) => field.key)).toEqual([
      'application_name',
      'connect_timeout_ms',
      'request_timeout_ms',
      'websocket_handshake_timeout_ms',
      'heartbeat_interval_ms',
      'reconnect_min_delay_ms',
      'reconnect_max_delay_ms',
    ])
    expect(asteriskFields.map((field) => field.order)).toEqual([10, 20, 30, 40, 50, 60, 70])
    expect(asteriskFields.map((field) => field.input_type)).toEqual([
      'text',
      'integer',
      'integer',
      'integer',
      'integer',
      'integer',
      'integer',
    ])

    const simulatorFields = runtimeCatalog.adapter_keys['simulator-deterministic'].adapter_configuration?.fields ?? []
    expect(simulatorFields.map((field) => field.key)).toEqual(['scenario_key', 'scenario_version', 'seed', 'parameters'])
    expect(simulatorFields.map((field) => field.input_type)).toEqual(['text', 'integer', 'text', 'json'])
    expect('adapter_configuration' in runtimeCatalog.adapter_keys['freeswitch-esl']).toBe(false)

    const serializedCatalog = JSON.stringify(runtimeCatalog)
    expect(serializedCatalog).not.toContain('credential-secret')
    expect(serializedCatalog).not.toContain('encrypted_secret')
    expect(serializedCatalog).not.toContain('fencing_token')
    expect(runtimeNodesViewSource).not.toContain("adapter_key === 'asterisk-ari'")
    expect(runtimeNodesViewSource).not.toContain('saveAsteriskAdapterConfiguration')
    expect(runtimeNodesViewSource).not.toContain('asteriskNumberFields')
    expect(appStateSource).not.toContain('saveAsteriskAdapterConfiguration')
    expect(appStateSource).not.toContain('asteriskConfigurationForm')
  })
})
