import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import App from './App.vue'
import { createUtcpRouter, router } from './router'
import { resetAppStateForTests } from './state/appState'

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

describe('C1 App shell', () => {
  const mountedWrappers: VueWrapper[] = []

  beforeEach(() => {
    resetAppStateForTests()
    window.history.replaceState({}, '', '/login')
  })

  afterEach(() => {
    for (const wrapper of mountedWrappers.splice(0)) wrapper.unmount()
    vi.restoreAllMocks()
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

  it('renders runtime-node administration without exposing credential secrets', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeAdminFetch(calls)
    const wrapper = await mountApp('/admin/runtime-nodes')

    expect(wrapper.text()).toContain('Proof Runtime')
    expect(wrapper.text()).toContain('observed unobserved')
    expect(wrapper.text()).toContain('Secrets are write-only')
    expect(wrapper.text()).toContain('Simulator')
    expect(wrapper.text()).toContain('Asterisk ARI')
    expect(wrapper.text()).toContain('Event stream')
    expect(wrapper.text()).toContain('Runtime observation')
    expect(wrapper.text()).not.toContain('Conference execution')
    expect(wrapper.find('input[placeholder="ARI application name"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Desired state: draft')
    expect(wrapper.text()).toContain('Observed state: unobserved')
    expect(wrapper.text()).toContain('runtime_node.created')
    expect(wrapper.text()).toContain('Retire')
    expect(wrapper.text()).not.toContain('super-secret')
    expect(wrapper.text()).not.toContain('Start Listener')
    expect(wrapper.text()).not.toContain('Connect')
    expect(wrapper.text()).not.toContain('Retry')
    expect(wrapper.text()).not.toContain('Reconcile')
    expect(wrapper.text()).not.toContain('Mark Ready')
  })

  it('preserves current capabilities and submits adapter configuration through canonical APIs', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeAdminFetch(calls)
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = await mountApp('/admin/runtime-nodes')

    await wrapper.find('form.inline-form input[type="checkbox"]').setValue(false)
    await wrapper.findAll('form.inline-form').find((form) => form.text().includes('Set capabilities'))?.trigger('submit.prevent')
    await flushPromises()

    expect(calls.some((call) =>
      call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/capabilities') &&
      JSON.stringify(call.body) === JSON.stringify({ capabilities: ['runtime.observation'] }),
    )).toBe(true)

    await wrapper.findAll('form.inline-form').find((form) => form.text().includes('Save adapter configuration'))?.trigger('submit.prevent')
    await flushPromises()

    expect(calls.some((call) =>
      call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration') &&
      (call.body as { application_name?: string })?.application_name === 'utcp',
    )).toBe(true)

    await wrapper.findAll('button').find((button) => button.text() === 'Retire')?.trigger('click')
    await flushPromises()

    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/credentials/credential-1/retire'))).toBe(true)
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

    expect(calls.some((call) => call.url.includes('/api/v1/admin/users?') && new URL(call.url, 'http://localhost').searchParams.get('page') === '2')).toBe(true)
    expect(wrapper.text()).toContain('Second Page User')
    expect(wrapper.text()).toContain('Page 2 · 21 users')
    expect(wrapper.findAll('button').find((button) => button.text() === 'Next')?.attributes('disabled')).toBeDefined()

    await wrapper.findAll('button').find((button) => button.text() === 'Previous')?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Operator User')
    expect(wrapper.text()).toContain('Page 1 · 21 users')
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
})
