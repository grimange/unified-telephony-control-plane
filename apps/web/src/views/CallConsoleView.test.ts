import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Call, CallLeg, CallOperation, CallTimelineEntry } from '../api/platform'
import { tenantContextVersion } from '../state/appState'

const mocks = vi.hoisted(() => ({
  can: vi.fn(), list: vi.fn(), get: vi.fn(), legs: vi.fn(), operations: vi.fn(), timeline: vi.fn(), runtimeNodes: vi.fn(),
}))

vi.mock('../api/platform', async () => {
  const actual = await vi.importActual<typeof import('../api/platform')>('../api/platform')
  return { ...actual, identityApi: { ...actual.identityApi, runtimeNodes: mocks.runtimeNodes }, callApi: { ...actual.callApi, list: mocks.list, get: mocks.get, legs: mocks.legs, operations: mocks.operations, timeline: mocks.timeline } }
})
vi.mock('../state/appState', async () => { const actual = await vi.importActual<typeof import('../state/appState')>('../state/appState'); return { ...actual, can: mocks.can } })
import CallConsoleView from './CallConsoleView.vue'

const call: Call = { id: 'call-1', tenant_id: 'tenant-1', direction: 'outbound', state: 'answered', termination_reason: null, terminated_at: null, correlation_id: 'corr-1', created_at: '2026-08-16T00:00:00Z', updated_at: '2026-08-16T00:00:00Z', destination_ref: '+15550001' }
const leg: CallLeg = { id: 'leg-1', call_id: 'call-1', direction: 'outbound', role: 'originator', state: 'answered', runtime_node_id: 'runtime-1', runtime_channel_id: 'channel-1', remote_identity: '+15550001', bridged_to_leg_id: null, bridged_at: null, termination_reason: null, terminated_at: null, telephony_session_id: null }
const operation: CallOperation = { id: 'operation-1', operation_type: 'call.leg.hangup', target: { type: 'call_leg', id: 'leg-1' }, status: 'terminal_failed', attempts: 1, failure_class: 'runtime', failure_code: 'channel_missing', correlation_id: 'corr-op', request_id: 'request-op', created_at: '2026-08-16T00:00:00Z', started_at: '2026-08-16T00:00:00Z', completed_at: '2026-08-16T00:00:01Z' }
const observation: CallTimelineEntry = { id: 'timeline-1', type: 'call.leg.answered', source: 'observation', occurred_at: '2026-08-16T00:00:01Z', recorded_at: '2026-08-16T00:00:01Z', call_id: 'call-1', leg_id: 'leg-1', summary: 'Answered observed', metadata: {} }

function responses(): void {
  mocks.list.mockResolvedValue({ data: [call], pagination: {} }); mocks.get.mockResolvedValue({ data: call }); mocks.legs.mockResolvedValue({ data: [leg], pagination: {} }); mocks.operations.mockResolvedValue({ data: [operation], pagination: {} }); mocks.timeline.mockResolvedValue({ data: [observation], pagination: {} }); mocks.runtimeNodes.mockResolvedValue({ runtime_nodes: [{ id: 'runtime-1', name: 'Asterisk 01', runtime_family: 'asterisk' }] })
}
async function selected() { const wrapper = mount(CallConsoleView); await flushPromises(); await wrapper.get('button.call-list-row').trigger('click'); await flushPromises(); return wrapper }

describe('CallConsoleView', () => {
  beforeEach(() => { vi.clearAllMocks(); tenantContextVersion.value = 0; mocks.can.mockImplementation((capability: string) => capability === 'telephony.calls.view' || capability === 'runtime.nodes.view'); responses() })

  it('renders canonical calls as an operations list and removes the origination surface', async () => {
    const wrapper = await selected()
    expect(wrapper.text()).toContain('Outbound · +15550001'); expect(wrapper.text()).toContain('Answered observed'); expect(wrapper.text()).toContain('Asterisk 01')
    expect(wrapper.text()).not.toContain('New outbound Call'); expect(wrapper.text()).not.toContain('Create outbound Call'); expect(wrapper.find('input').exists()).toBe(false); wrapper.unmount()
  })

  it('does not expose endpoint-style controls or arbitrary operation submission', async () => {
    const wrapper = await selected(); const buttons = wrapper.findAll('button').map((button) => button.text())
    for (const control of ['Answer', 'Hold', 'Resume', 'DTMF', 'Send DTMF', 'Terminate', 'Cancel']) expect(buttons).not.toContain(control)
    expect(wrapper.find('textarea').exists()).toBe(false); expect(wrapper.find('input').exists()).toBe(false); wrapper.unmount()
  })

  it('presents a professional overview, Call Legs, operation history and command/observation evidence', async () => {
    const wrapper = await selected(); expect(wrapper.text()).toContain('Call overview'); expect(wrapper.text()).toContain('Call Legs'); expect(wrapper.text()).toContain('Operations history'); expect(wrapper.text()).toContain('Terminal failed'); expect(wrapper.text()).toContain('Failure: channel_missing'); expect(wrapper.text()).toContain('Observation'); expect(wrapper.text()).toContain('Duration'); expect(wrapper.text()).toContain('Unavailable without canonical answered timestamp'); wrapper.unmount()
  })

  it('keeps successful detail sections visible when one subordinate read fails', async () => {
    mocks.operations.mockRejectedValue(new Error('Operations unavailable.')); const wrapper = await selected()
    expect(wrapper.text()).toContain('Call overview'); expect(wrapper.text()).toContain('Call Legs'); expect(wrapper.text()).toContain('Operations unavailable.'); expect(wrapper.text()).toContain('Timeline'); expect(wrapper.text()).toContain('Answered observed'); wrapper.unmount()
  })

  it('keeps call overview and timeline visible when Call Legs fail', async () => {
    mocks.legs.mockRejectedValue(new Error('Legs unavailable.')); const wrapper = await selected()
    expect(wrapper.text()).toContain('Call overview'); expect(wrapper.text()).toContain('Legs unavailable.'); expect(wrapper.text()).toContain('Operations history'); expect(wrapper.text()).toContain('Answered observed'); wrapper.unmount()
  })

  it('uses a safe technical fallback when runtime reference resolution is forbidden', async () => {
    mocks.runtimeNodes.mockRejectedValue(new Error('Forbidden')); const wrapper = await selected(); expect(wrapper.text()).toContain('Reference unavailable (runtime-1)'); expect(wrapper.text()).toContain('Call overview'); wrapper.unmount()
  })

  it('clears the selected tenant call immediately when tenant context changes', async () => {
    const wrapper = await selected(); expect(wrapper.text()).toContain('call-1'); tenantContextVersion.value += 1; await flushPromises(); expect(wrapper.text()).not.toContain('Answered observed'); expect(wrapper.text()).not.toContain('Call overview'); wrapper.unmount()
  })

  it('renders the empty state without a dialer action', async () => {
    mocks.list.mockResolvedValue({ data: [], pagination: {} }); const wrapper = mount(CallConsoleView); await flushPromises(); expect(wrapper.text()).toContain('No calls were returned for the active tenant.'); expect(wrapper.text()).not.toContain('Make a Call'); wrapper.unmount()
  })
})
