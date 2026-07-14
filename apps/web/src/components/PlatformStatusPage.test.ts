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
  it('renders the application title', () => {
    const wrapper = mount(PlatformStatusPage, {
      props: { client: client() },
    })

    expect(wrapper.text()).toContain('Unified Telephony Control Plane')
  })

  it('renders the loading state', () => {
    const wrapper = mount(PlatformStatusPage, {
      props: { client: client() },
    })

    expect(wrapper.text()).toContain('Loading')
    expect(wrapper.text()).toContain('checking')
  })

  it('renders a healthy API state', async () => {
    const wrapper = mount(PlatformStatusPage, {
      props: { client: client() },
    })

    await flushPromises()

    expect(wrapper.text()).toContain('Healthy')
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

    expect(wrapper.text()).toContain('Degraded')
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

    expect(wrapper.text()).toContain('Unreachable')
    expect(wrapper.text()).toContain('unreachable')
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

    expect(wrapper.text()).toContain('Unreachable')
    expect(wrapper.text()).not.toContain('stack trace should not render')
  })
})
