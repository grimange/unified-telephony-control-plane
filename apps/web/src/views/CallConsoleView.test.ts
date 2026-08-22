import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Call, CallLeg, CallOperation, CallTimelineEntry } from '../api/platform'

const mocks = vi.hoisted(() => ({
  can: vi.fn(),
  list: vi.fn(),
  get: vi.fn(),
  legs: vi.fn(),
  operations: vi.fn(),
  timeline: vi.fn(),
  createOutbound: vi.fn(),
  submitOperation: vi.fn(),
}))

vi.mock('../api/platform', async () => {
  const actual = await vi.importActual<typeof import('../api/platform')>('../api/platform')

  return {
    ...actual,
    callApi: {
      list: mocks.list,
      get: mocks.get,
      legs: mocks.legs,
      operations: mocks.operations,
      timeline: mocks.timeline,
      createOutbound: mocks.createOutbound,
      submitOperation: mocks.submitOperation,
    },
  }
})

vi.mock('../state/appState', async () => {
  const actual = await vi.importActual<typeof import('../state/appState')>('../state/appState')

  return {
    ...actual,
    can: mocks.can,
  }
})

import CallConsoleView from './CallConsoleView.vue'

const outboundCall: Call = {
  id: 'call-1',
  tenant_id: 'tenant-1',
  direction: 'outbound',
  state: 'answered',
  termination_reason: null,
  terminated_at: null,
  correlation_id: 'corr-1',
  created_at: '2026-08-16T00:00:00Z',
  updated_at: '2026-08-16T00:00:00Z',
  destination_ref: 'opaque:destination-1',
}

const answeredLeg: CallLeg = {
  id: 'leg-1',
  call_id: 'call-1',
  direction: 'outbound',
  role: 'originator',
  state: 'answered',
  runtime_node_id: 'runtime-1',
  runtime_channel_id: 'channel-1',
  remote_identity: 'opaque:destination-1',
  bridged_to_leg_id: null,
  bridged_at: null,
  termination_reason: null,
  terminated_at: null,
  telephony_session_id: null,
}

const heldLeg = { ...answeredLeg, state: 'held' }

const operation: CallOperation = {
  id: 'operation-1',
  operation_type: 'call.leg.hold',
  target: { type: 'call_leg', id: 'leg-1' },
  status: 'succeeded',
  attempts: 1,
  failure_class: null,
  failure_code: null,
  correlation_id: 'corr-op',
  request_id: 'request-op',
  created_at: '2026-08-16T00:00:00Z',
  started_at: '2026-08-16T00:00:00Z',
  completed_at: '2026-08-16T00:00:01Z',
}

const timelineEntry: CallTimelineEntry = {
  id: 'timeline-1',
  type: 'call.leg.answered',
  source: 'observation',
  occurred_at: '2026-08-16T00:00:01Z',
  recorded_at: '2026-08-16T00:00:01Z',
  call_id: 'call-1',
  leg_id: 'leg-1',
  summary: 'Answered observed',
  metadata: {},
}

function setDefaultResponses(): void {
  mocks.list.mockResolvedValue({ data: [outboundCall], pagination: { page: 1, per_page: 50, total: 1, has_more: false } })
  mocks.get.mockResolvedValue({ data: outboundCall })
  mocks.legs.mockResolvedValue({ data: [answeredLeg], pagination: { page: 1, per_page: 50, total: 1, has_more: false } })
  mocks.operations.mockResolvedValue({ data: [operation], pagination: { page: 1, per_page: 50, total: 1, has_more: false } })
  mocks.timeline.mockResolvedValue({ data: [timelineEntry], pagination: { page: 1, per_page: 50, total: 1, has_more: false } })
  mocks.submitOperation.mockResolvedValue({ data: operation })
}

describe('CallConsoleView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.can.mockImplementation((capability: string) =>
      ['telephony.calls.view', 'telephony.calls.originate', 'telephony.calls.control'].includes(capability),
    )
    setDefaultResponses()
  })

  it('reads calls, legs, operations, and timeline through the C6 API', async () => {
    const wrapper = mount(CallConsoleView)
    await flushPromises()

    expect(mocks.list).toHaveBeenCalledWith({ per_page: 50 })
    await wrapper.get('button.call-list-row').trigger('click')
    await flushPromises()

    expect(mocks.get).toHaveBeenCalledWith('call-1')
    expect(mocks.legs).toHaveBeenCalledWith('call-1', { per_page: 50 })
    expect(mocks.operations).toHaveBeenCalledWith('call-1', { per_page: 50 })
    expect(mocks.timeline).toHaveBeenCalledWith('call-1', { per_page: 50 })
    expect(wrapper.text()).toContain('answered')
    expect(wrapper.text()).toContain('Answered observed')
    expect(wrapper.text()).toContain('channel-1')

    wrapper.unmount()
  })

  it('never fabricates held before the canonical API reports the observation', async () => {
    mocks.legs
      .mockResolvedValueOnce({ data: [answeredLeg], pagination: { page: 1, per_page: 50, total: 1, has_more: false } })
      .mockResolvedValueOnce({ data: [answeredLeg], pagination: { page: 1, per_page: 50, total: 1, has_more: false } })
      .mockResolvedValueOnce({ data: [heldLeg], pagination: { page: 1, per_page: 50, total: 1, has_more: false } })

    const wrapper = mount(CallConsoleView)
    await flushPromises()
    await wrapper.get('button.call-list-row').trigger('click')
    await flushPromises()

    const holdButton = wrapper.findAll('button').find((button) => button.text() === 'Hold')
    expect(holdButton).toBeDefined()
    await holdButton!.trigger('click')
    await flushPromises()

    expect(mocks.submitOperation).toHaveBeenCalledWith(
      'call-1',
      'call.leg.hold',
      'leg-1',
      {},
      expect.stringContaining('call-operation-'),
    )
    expect(wrapper.text()).toContain('answered')
    expect(wrapper.text()).not.toContain('held')

    const refreshButton = wrapper.findAll('button').find((button) => button.text() === 'Refresh detail')
    expect(refreshButton).toBeDefined()
    await refreshButton!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('held')

    wrapper.unmount()
  })

  it('respects view and control capabilities and renders inbound offered calls', async () => {
    const inboundCall = { ...outboundCall, id: 'call-inbound', direction: 'inbound', state: 'offered' }
    const inboundLeg = { ...answeredLeg, id: 'leg-inbound', call_id: 'call-inbound', direction: 'inbound', role: 'callee', state: 'offered' }
    mocks.list.mockResolvedValue({ data: [inboundCall], pagination: { page: 1, per_page: 50, total: 1, has_more: false } })
    mocks.get.mockResolvedValue({ data: inboundCall })
    mocks.legs.mockResolvedValue({ data: [inboundLeg], pagination: { page: 1, per_page: 50, total: 1, has_more: false } })
    mocks.can.mockImplementation((capability: string) => capability === 'telephony.calls.view')

    const wrapper = mount(CallConsoleView)
    await flushPromises()
    expect(wrapper.text()).toContain('inbound')
    await wrapper.get('button.call-list-row').trigger('click')
    await flushPromises()

    const answerButton = wrapper.findAll('button').find((button) => button.text() === 'Answer')
    expect(answerButton).toBeDefined()
    expect(answerButton!.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).not.toContain('manual inbound')

    wrapper.unmount()
  })

  it('shows operation submission failures instead of claiming success', async () => {
    mocks.submitOperation.mockRejectedValue(new Error('Operation was rejected.'))
    const wrapper = mount(CallConsoleView)
    await flushPromises()
    await wrapper.get('button.call-list-row').trigger('click')
    await flushPromises()
    const holdButton = wrapper.findAll('button').find((button) => button.text() === 'Hold')
    expect(holdButton).toBeDefined()
    await holdButton!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Operation was rejected.')
    expect(wrapper.text()).toContain('answered')

    wrapper.unmount()
  })

  it.each([
    ['offered', 'Answer', ['Hold', 'Resume', 'Cancel origination']],
    ['answered', 'Hold', ['Answer', 'Resume', 'Cancel origination']],
    ['held', 'Resume', ['Answer', 'Hold', 'Cancel origination']],
  ])('renders the canonical %s active control', async (state, visibleControl, hiddenControls) => {
    const leg = { ...answeredLeg, state }
    mocks.legs.mockResolvedValue({ data: [leg], pagination: { page: 1, per_page: 50, total: 1, has_more: false } })

    const wrapper = mount(CallConsoleView)
    await flushPromises()
    await wrapper.get('button.call-list-row').trigger('click')
    await flushPromises()

    expect(wrapper.findAll('button').some((button) => button.text() === visibleControl)).toBe(true)
    for (const hiddenControl of hiddenControls) {
      expect(wrapper.findAll('button').some((button) => button.text() === hiddenControl)).toBe(false)
    }

    wrapper.unmount()
  })

  it.each(['completed', 'failed', 'cancelled'])('treats canonical terminal state %s as inactive', async (state) => {
    const leg = { ...answeredLeg, state }
    mocks.legs.mockResolvedValue({ data: [leg], pagination: { page: 1, per_page: 50, total: 1, has_more: false } })

    const wrapper = mount(CallConsoleView)
    await flushPromises()
    await wrapper.get('button.call-list-row').trigger('click')
    await flushPromises()

    for (const control of ['Answer', 'Hold', 'Resume', 'Hang up', 'Cancel origination']) {
      expect(wrapper.findAll('button').some((button) => button.text() === control)).toBe(false)
    }

    wrapper.unmount()
  })

  it('keeps cancellation for an originating outbound leg', async () => {
    const leg = { ...answeredLeg, state: 'originating' }
    mocks.legs.mockResolvedValue({ data: [leg], pagination: { page: 1, per_page: 50, total: 1, has_more: false } })

    const wrapper = mount(CallConsoleView)
    await flushPromises()
    await wrapper.get('button.call-list-row').trigger('click')
    await flushPromises()

    const cancelButton = wrapper.findAll('button').find((button) => button.text() === 'Cancel origination')
    expect(cancelButton).toBeDefined()
    await cancelButton!.trigger('click')
    await flushPromises()
    expect(mocks.submitOperation).toHaveBeenCalledWith(
      'call-1',
      'call.leg.cancel_origination',
      'leg-1',
      {},
      expect.stringContaining('call-operation-'),
    )

    wrapper.unmount()
  })
})
