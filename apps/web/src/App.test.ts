import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from './App.vue'

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
    'runtime.nodes.view',
    'runtime.nodes.manage',
    'runtime.credentials.rotate',
  ],
  catalog_version: 'c2.test',
  expires_at: '2026-07-14T10:00:00Z',
}

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

describe('C1 App shell', () => {
  beforeEach(() => {
    window.history.replaceState({}, '', '/login')
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('renders the natural login form without client-side tokens', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ message: 'Unauthenticated.' }, 401))

    const wrapper = mount(App)
    await flushPromises()

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
    window.history.replaceState({}, '', '/admin/tenants')

    const wrapper = mount(App)
    await flushPromises()

    expect(wrapper.text()).toContain('Local Admin')
    expect(wrapper.text()).toContain('Tenants')
    expect(wrapper.text()).toContain('Users')
    expect(wrapper.text()).toContain('Memberships')
    expect(wrapper.text()).toContain('Runtime nodes')
    expect(wrapper.text()).toContain('Local Tenant')
  })

  it('renders runtime-node administration without exposing credential secrets', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) {
        return Promise.resolve(jsonResponse(session))
      }
      if (url.endsWith('/api/v1/admin/runtime-nodes')) {
        return Promise.resolve(jsonResponse({
          runtime_nodes: [{
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
            credentials: [{ id: 'credential-1', type: 'control-api', identifier: 'proof', fingerprint: '1234567890abcdef', version: 1, status: 'active', rotated_at: '2026-07-14T10:00:00Z', expires_at: null }],
            capabilities: ['conference.execution'],
          }],
        }))
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })
    window.history.replaceState({}, '', '/admin/runtime-nodes')

    const wrapper = mount(App)
    await flushPromises()

    expect(wrapper.text()).toContain('Proof Runtime')
    expect(wrapper.text()).toContain('observed unobserved')
    expect(wrapper.text()).toContain('Secrets are write-only')
    expect(wrapper.text()).not.toContain('super-secret')
  })

  it('redirects a protected page to login when the session endpoint rejects it', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ message: 'Unauthenticated.' }, 401))
    window.history.replaceState({}, '', '/admin/users')

    const wrapper = mount(App)
    await flushPromises()

    expect(window.location.pathname).toBe('/login')
    expect(wrapper.text()).toContain('Sign in to continue.')
  })
})
