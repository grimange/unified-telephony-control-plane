import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PlatformStatusPage from './PlatformStatusPage.vue'
import type { PlatformApiClient, ReadinessResponse, VersionResponse } from '../api/platform'

const live = { status: 'ok', service: 'utcp-api' } as const
const version: VersionResponse = {
  service: 'utcp-api',
  version: '0.1.0-test',
  commit: 'abc1234',
  built_at: '2026-07-13T09:00:00Z',
}

const ready = (status: ReadinessResponse['status']): ReadinessResponse => ({
  status,
  service: 'utcp-api',
  dependencies: {
    postgres: status === 'ready' ? 'ok' : 'unavailable',
    redis: 'ok',
  },
})

function client(overrides: Partial<PlatformApiClient> = {}): PlatformApiClient {
  return {
    getLiveness: () => Promise.resolve(live),
    getReadiness: () => Promise.resolve(ready('ready')),
    getVersion: () => Promise.resolve(version),
    ...overrides,
  }
}

describe('PlatformStatusPage', () => {
  it('renders the operator system status surface', () => {
    const wrapper = mount(PlatformStatusPage, {
      props: { client: client() },
    })

    expect(wrapper.text()).toContain('System status')
    expect(wrapper.text()).toContain('Health semantics')
  })

  it('renders the loading state', () => {
    const wrapper = mount(PlatformStatusPage, {
      props: { client: client() },
    })

    expect(wrapper.text()).toContain('Loading')
    expect(wrapper.text()).toContain('Checking API liveness')
    expect(wrapper.text()).toContain('Checking API readiness')
  })

  it('renders a healthy API state', async () => {
    const wrapper = mount(PlatformStatusPage, {
      props: { client: client() },
    })

    await flushPromises()

    expect(wrapper.text()).toContain('API live')
    expect(wrapper.text()).toContain('API ready')
    expect(wrapper.text()).toContain('ready')
    expect(wrapper.text()).toContain('postgres')
    expect(wrapper.text()).toContain('ok')
  })

  it('renders a degraded readiness state', async () => {
    const wrapper = mount(PlatformStatusPage, {
      props: {
        client: client({
          getReadiness: () => Promise.resolve(ready('not_ready')),
        }),
      },
    })

    await flushPromises()

    expect(wrapper.text()).toContain('API not ready')
    expect(wrapper.text()).toContain('not_ready')
    expect(wrapper.text()).toContain('unavailable')
  })

  it('renders an unreachable API state', async () => {
    const wrapper = mount(PlatformStatusPage, {
      props: {
        client: client({
          getLiveness: () => Promise.reject(new Error('network failed')),
        }),
      },
    })

    await flushPromises()

    expect(wrapper.text()).toContain('Liveness unavailable')
    expect(wrapper.text()).toContain('canonical liveness endpoint could not be read')
  })

  it('renders backend version metadata', async () => {
    const wrapper = mount(PlatformStatusPage, {
      props: { client: client() },
    })

    await flushPromises()

    expect(wrapper.text()).toContain('0.1.0-test')
    expect(wrapper.text()).toContain('abc1234')
    expect(wrapper.text()).toContain('2026-07-13T09:00:00Z')
  })

  it('does not crash when API requests fail', async () => {
    const wrapper = mount(PlatformStatusPage, {
      props: {
        client: client({
          getVersion: () => Promise.reject(new Error('stack trace should not render')),
        }),
      },
    })

    await flushPromises()

    expect(wrapper.text()).toContain('Version unavailable')
    expect(wrapper.text()).not.toContain('stack trace should not render')
  })

  it('keeps successful health facts visible when another read fails', async () => {
    const wrapper = mount(PlatformStatusPage, {
      props: {
        client: client({
          getReadiness: () => Promise.reject(new Error('readiness unavailable')),
        }),
      },
    })

    await flushPromises()

    expect(wrapper.text()).toContain('API live')
    expect(wrapper.text()).toContain('Version')
    expect(wrapper.text()).toContain('Readiness unavailable')
  })

  it('refreshes canonical reads without exposing mutation controls', async () => {
    const calls = { live: 0, ready: 0, version: 0 }
    const wrapper = mount(PlatformStatusPage, {
      props: {
        client: client({
          getLiveness: () => { calls.live += 1; return Promise.resolve(live) },
          getReadiness: () => { calls.ready += 1; return Promise.resolve(ready('ready')) },
          getVersion: () => { calls.version += 1; return Promise.resolve(version) },
        }),
      },
    })

    await flushPromises()
    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(calls).toEqual({ live: 2, ready: 2, version: 2 })
    expect(wrapper.text()).not.toContain('Repair')
    expect(wrapper.text()).not.toContain('Reconcile now')
    expect(wrapper.text()).not.toContain('Restart')
  })
})
