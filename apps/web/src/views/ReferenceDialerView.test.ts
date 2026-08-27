import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { ReferenceDialerBootstrap, OneTimeSignalingCredential } from '../api/platform'

const mocks = vi.hoisted(() => ({
  bootstrap: vi.fn(),
  createTelephonySession: vi.fn(),
  issueSignalingCredential: vi.fn(),
  joinConference: vi.fn(),
  leaveConference: vi.fn(),
  invite: vi.fn(),
  leave: vi.fn(),
  ensureRegistered: vi.fn(),
  stop: vi.fn(),
  client: null as null | {
    emitCallState: (state: string, message?: string, attemptId?: number) => void
    emitSignalingState: (state: string, message?: string) => void
  },
}))

vi.mock('../api/platform', async () => {
  const actual = await vi.importActual<typeof import('../api/platform')>('../api/platform')

  return {
    ...actual,
    referenceDialerApi: {
      bootstrap: mocks.bootstrap,
      createTelephonySession: mocks.createTelephonySession,
      issueSignalingCredential: mocks.issueSignalingCredential,
      joinConference: mocks.joinConference,
      leaveConference: mocks.leaveConference,
      isApiRequestError: (error: unknown): error is { status: number } =>
        typeof error === 'object' && error !== null && 'status' in error,
    },
  }
})

vi.mock('../signaling/referenceDialerSignaling', () => ({
  ReferenceDialerSignalingClient: class {
    private readonly onStateChange: (state: string) => void
    private established = false

    constructor(
      _credential: unknown,
      onStateChange: (state: string) => void,
      onCallStateChange: (state: string, message?: string, attemptId?: number) => void,
    ) {
      this.onStateChange = onStateChange
      this.onCallStateChange = onCallStateChange
      mocks.client = this
    }

    private readonly onCallStateChange: (state: string, message?: string, attemptId?: number) => void

    async start(): Promise<void> {
      // The mocked adapter emits the same state the real adapter receives from SIP.js.
      this.onStateChange('registered')
    }

    async invite(destination: string): Promise<number> {
      const attemptId = ++this.inviteAttempt
      this.onCallStateChange('inviting', undefined, attemptId)
      await mocks.invite(destination)
      this.established = true
      this.onCallStateChange('connected', undefined, attemptId)
      return attemptId
    }

    private inviteAttempt = 0

    hasEstablishedConference(): boolean {
      return this.established
    }

    async ensureRegistered(): Promise<void> {
      mocks.ensureRegistered()
    }

    emitCallState(state: string, message?: string, attemptId?: number): void {
      if (state === 'failed' || state === 'terminated') this.established = false
      this.onCallStateChange(state, message, attemptId)
    }

    emitSignalingState(state: string, message?: string): void {
      void message
      this.onStateChange(state)
    }

    async stop(): Promise<void> {
      mocks.stop()
    }

    async leave(): Promise<void> {
      this.established = false
      mocks.leave()
      this.onCallStateChange('terminated')
    }
  },
}))

import ReferenceDialerView from './ReferenceDialerView.vue'

const bootstrap: ReferenceDialerBootstrap = {
  application: 'reference-dialer',
  tenant_id: 'tenant-1',
  telephony_session: null,
  signaling: null,
  participation: null,
  conferences: [
    {
      id: 'conference-open',
      tenant_id: 'tenant-1',
      slug: 'support-room',
      display_name: 'Support Room',
      runtime_node_id: 'runtime-1',
      desired_state: 'open',
      observed_state: 'ready',
      configuration_generation: 1,
      observed_generation: 1,
      observed_at: '2026-08-08T00:00:00Z',
      opened_at: '2026-08-08T00:00:00Z',
      draining_at: null,
      closed_at: null,
      created_at: '2026-08-08T00:00:00Z',
      updated_at: '2026-08-08T00:00:00Z',
    },
    {
      id: 'conference-closed',
      tenant_id: 'tenant-1',
      slug: 'old-room',
      display_name: 'Old Room',
      runtime_node_id: null,
      desired_state: 'closed',
      observed_state: 'closed',
      configuration_generation: 1,
      observed_generation: 1,
      observed_at: '2026-08-08T00:00:00Z',
      opened_at: '2026-08-08T00:00:00Z',
      draining_at: null,
      closed_at: '2026-08-08T00:00:00Z',
      created_at: '2026-08-08T00:00:00Z',
      updated_at: '2026-08-08T00:00:00Z',
    },
  ],
}

const credential: OneTimeSignalingCredential = {
  username: 'ts-session',
  realm: 'sip.utcp.local.test',
  algorithm: 'MD5',
  sip_secret: 'test-only-secret',
  wss_uri: 'wss://sip.utcp.local.test/ws',
  issued_at: '2026-08-08T00:00:00Z',
  expires_at: '2026-08-08T00:02:00Z',
}

describe('ReferenceDialerView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.bootstrap.mockResolvedValue(bootstrap)
    mocks.createTelephonySession.mockResolvedValue({
      telephony_session: {
        id: 'session-1',
        status: 'active',
        issued_at: '2026-08-08T00:00:00Z',
        expires_at: '2026-08-08T00:02:00Z',
      },
    })
    mocks.issueSignalingCredential.mockResolvedValue({ credential })
    mocks.joinConference.mockResolvedValue({ signaling_destination: 'sip:conf-participant-abc@sip.utcp.local.test', participant: {} })
  })

  it('shows loading and then REGISTERED after the signaling adapter confirms registration', async () => {
    const wrapper = mount(ReferenceDialerView)
    expect(wrapper.text()).toContain('Loading reference-dialer bootstrap.')

    await flushPromises()

    expect(mocks.createTelephonySession).toHaveBeenCalledOnce()
    expect(mocks.issueSignalingCredential).toHaveBeenCalledWith('session-1')
    expect(wrapper.text()).toContain('REGISTERED')
  })

  it('keeps an idle dialer in a retryable signaling state during transport loss', async () => {
    const wrapper = mount(ReferenceDialerView)
    await flushPromises()

    mocks.client?.emitSignalingState('connecting', 'The WSS connection closed.')
    await flushPromises()

    expect(wrapper.text()).toContain('Registering')
    expect(wrapper.text()).not.toContain('SIP registration failed')
    expect(mocks.joinConference).not.toHaveBeenCalled()
    expect(mocks.invite).not.toHaveBeenCalled()

    mocks.client?.emitSignalingState('registered')
    await flushPromises()
    expect(wrapper.text()).toContain('REGISTERED')
    expect(mocks.leaveConference).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('shows bootstrap failures instead of silently falling back', async () => {
    mocks.bootstrap.mockRejectedValue(new Error('Active tenant context is required.'))
    const wrapper = mount(ReferenceDialerView)

    await flushPromises()

    expect(wrapper.text()).toContain('Reference Telephony Client unavailable')
    expect(wrapper.text()).toContain('Active tenant context is required.')
    expect(mocks.createTelephonySession).not.toHaveBeenCalled()
  })

  it('presents only open conferences with a runtime binding and admits before inviting', async () => {
    const wrapper = mount(ReferenceDialerView)
    await flushPromises()

    expect(wrapper.text()).toContain('Available conferences')
    expect(wrapper.text()).toContain('Support Room')
    expect(wrapper.text()).not.toContain('Old Room')

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(mocks.joinConference).toHaveBeenCalledWith('conference-open', expect.any(String))
    expect(mocks.invite).toHaveBeenCalledWith('sip:conf-participant-abc@sip.utcp.local.test')
  })

  it('does not invite when canonical admission rejects', async () => {
    mocks.joinConference.mockRejectedValueOnce(new Error('Conference is not available'))
    const wrapper = mount(ReferenceDialerView)
    await flushPromises()

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(mocks.invite).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Conference is not available')
  })

  it('shows Connected and uses the canonical leave path', async () => {
    const wrapper = mount(ReferenceDialerView)
    await flushPromises()

    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Connected')

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(mocks.leave).toHaveBeenCalled()
    expect(mocks.leaveConference).toHaveBeenCalledWith('conference-open')
    expect(wrapper.text()).toContain('Available conferences')
  })

  it('preserves admission and enters canonical recovery when the runtime terminates an established session', async () => {
    const wrapper = mount(ReferenceDialerView)
    await flushPromises()
    await wrapper.get('button').trigger('click')
    await flushPromises()

    mocks.client?.emitCallState('terminated', undefined, 1)
    await flushPromises()

    expect(mocks.leaveConference).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Available conferences')
    expect(wrapper.text()).not.toContain('Connected')
  })

  it('compensates admitted participation when INVITE establishment fails', async () => {
    mocks.invite.mockRejectedValueOnce(new Error('486 Busy Here'))
    const wrapper = mount(ReferenceDialerView)
    await flushPromises()
    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(mocks.leaveConference).toHaveBeenCalledTimes(1)
    expect(wrapper.text()).toContain('Needs attention')
    expect(wrapper.text()).not.toContain('Joining...')
    expect(wrapper.text()).not.toContain('Connected')
  })

  it('allows a failed conference attempt to be retried without remounting', async () => {
    mocks.invite.mockRejectedValueOnce(new Error('486 Busy Here'))
    const wrapper = mount(ReferenceDialerView)
    await flushPromises()

    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Needs attention')

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(mocks.joinConference).toHaveBeenCalledTimes(2)
    expect(mocks.invite).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('Connected')
  })

  it('ignores a stale callback from an earlier attempt after retry', async () => {
    mocks.invite.mockRejectedValueOnce(new Error('486 Busy Here'))
    const wrapper = mount(ReferenceDialerView)
    await flushPromises()

    await wrapper.get('button').trigger('click')
    await flushPromises()
    await wrapper.get('button').trigger('click')
    await flushPromises()

    mocks.client?.emitCallState('failed', 'stale failure', 1)
    await flushPromises()

    expect(wrapper.text()).toContain('Connected')
    expect(wrapper.text()).not.toContain('stale failure')
  })

  it('accepts the current SIP callback after non-inviting recovery orchestration drifts', async () => {
    const recoverableBootstrap: ReferenceDialerBootstrap = {
      ...bootstrap,
      participation: {
        participant_id: 'participant-1',
        conference_id: 'conference-open',
        state: 'recoverable',
        recoverable: true,
        recoverable_until: '2099-08-08T00:02:00Z',
      },
    }
    mocks.bootstrap.mockResolvedValueOnce(bootstrap).mockResolvedValue(recoverableBootstrap)
    mocks.joinConference.mockResolvedValue({
      signaling_destination: 'sip:conf-participant-1@sip.utcp.local.test',
      participant: { id: 'participant-1' },
    })

    const wrapper = mount(ReferenceDialerView)
    await flushPromises()
    mocks.client?.emitCallState('terminated')
    await flushPromises()
    expect(mocks.invite).toHaveBeenCalledTimes(1)

    // This rediscovery does not create a new SIP INVITE, but it does advance
    // the view orchestration generation while the current session is alive.
    window.dispatchEvent(new Event('online'))
    await flushPromises()
    mocks.client?.emitCallState('terminated', undefined, 1)
    await flushPromises()

    expect(mocks.leaveConference).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Recovering')
  })

  it('treats an already absent participant as converged cleanup', async () => {
    mocks.leaveConference.mockResolvedValueOnce({ participant: null })
    const wrapper = mount(ReferenceDialerView)
    await flushPromises()
    await wrapper.get('button').trigger('click')
    await flushPromises()

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(mocks.leaveConference).toHaveBeenCalledTimes(1)
    expect(wrapper.text()).toContain('Available conferences')
    expect(wrapper.text()).not.toContain('Needs attention')
  })

  it('coalesces repeated terminal callbacks into one replacement invite', async () => {
    const recoverableBootstrap: ReferenceDialerBootstrap = {
      ...bootstrap,
      participation: {
        participant_id: 'participant-1',
        conference_id: 'conference-open',
        state: 'recoverable',
        recoverable: true,
        recoverable_until: '2099-08-08T00:02:00Z',
      },
    }
    mocks.bootstrap.mockResolvedValueOnce(bootstrap).mockResolvedValue(recoverableBootstrap)
    mocks.joinConference.mockResolvedValue({
      signaling_destination: 'sip:conf-participant-1@sip.utcp.local.test',
      participant: { id: 'participant-1' },
    })
    mount(ReferenceDialerView)
    await flushPromises()

    mocks.client?.emitCallState('terminated')
    mocks.client?.emitCallState('terminated')
    await flushPromises()

    expect(mocks.leaveConference).not.toHaveBeenCalled()
    expect(mocks.joinConference).toHaveBeenCalledTimes(1)
    expect(mocks.invite).toHaveBeenCalledTimes(1)
  })

  it('stops the signaling client when the view is torn down', async () => {
    const wrapper = mount(ReferenceDialerView)
    await flushPromises()

    wrapper.unmount()
    await flushPromises()

    expect(mocks.stop).toHaveBeenCalledOnce()
    expect(mocks.leaveConference).not.toHaveBeenCalled()
  })

  it('recovers a canonical participation without another Join click', async () => {
    const recoverableBootstrap: ReferenceDialerBootstrap = {
      ...bootstrap,
      participation: {
        participant_id: 'participant-1',
        conference_id: 'conference-open',
        state: 'recoverable',
        recoverable: true,
        recoverable_until: '2099-08-08T00:02:00Z',
      },
    }
    const activeBootstrap: ReferenceDialerBootstrap = {
      ...bootstrap,
      participation: {
        ...recoverableBootstrap.participation!,
        state: 'active',
        recoverable: false,
        recoverable_until: null,
      },
    }
    mocks.bootstrap.mockResolvedValueOnce(bootstrap).mockResolvedValueOnce(recoverableBootstrap).mockResolvedValue(activeBootstrap)
    mocks.joinConference.mockResolvedValue({
      signaling_destination: 'sip:conf-participant-1@sip.utcp.local.test',
      participant: { id: 'participant-1' },
    })

    const wrapper = mount(ReferenceDialerView)
    await flushPromises()
    mocks.client?.emitCallState('terminated')
    await flushPromises()

    expect(mocks.joinConference).toHaveBeenCalledTimes(1)
    expect(mocks.invite).toHaveBeenCalledWith('sip:conf-participant-1@sip.utcp.local.test')
    expect(wrapper.text()).toContain('Connected')
    expect(wrapper.text()).not.toContain('Restoring the canonical conference participation.')
    expect(mocks.leaveConference).not.toHaveBeenCalled()
  })

  it('waits for the canonical old-channel loss before inviting a replacement', async () => {
    const waitingBootstrap: ReferenceDialerBootstrap = {
      ...bootstrap,
      participation: {
        participant_id: 'participant-1',
        conference_id: 'conference-open',
        state: 'active',
        recoverable: false,
        recoverable_until: null,
      },
    }
    mocks.bootstrap.mockResolvedValueOnce(bootstrap).mockResolvedValue(waitingBootstrap)
    const wrapper = mount(ReferenceDialerView)
    await flushPromises()

    mocks.client?.emitCallState('terminated')
    await flushPromises()

    expect(wrapper.text()).toContain('Recovering')
    expect(mocks.joinConference).not.toHaveBeenCalled()
    expect(mocks.invite).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('preserves participation when a recovery invite fails and retries through canonical discovery', async () => {
    vi.useFakeTimers()
    const recoverableBootstrap: ReferenceDialerBootstrap = {
      ...bootstrap,
      participation: {
        participant_id: 'participant-1',
        conference_id: 'conference-open',
        state: 'recoverable',
        recoverable: true,
        recoverable_until: '2099-08-08T00:02:00Z',
      },
    }
    const activeBootstrap: ReferenceDialerBootstrap = {
      ...bootstrap,
      participation: {
        ...recoverableBootstrap.participation!,
        state: 'active',
        recoverable: false,
        recoverable_until: null,
      },
    }
    mocks.bootstrap.mockResolvedValueOnce(bootstrap).mockResolvedValueOnce(recoverableBootstrap).mockResolvedValueOnce(recoverableBootstrap).mockResolvedValue(activeBootstrap)
    mocks.joinConference.mockResolvedValue({
      signaling_destination: 'sip:conf-participant-1@sip.utcp.local.test',
      participant: { id: 'participant-1' },
    })
    mocks.invite.mockRejectedValueOnce(new Error('temporary conference failure')).mockResolvedValue(undefined)
    const wrapper = mount(ReferenceDialerView)
    await flushPromises()

    mocks.client?.emitCallState('terminated')
    await flushPromises()
    expect(mocks.leaveConference).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Recovering')

    await vi.advanceTimersByTimeAsync(2_000)
    await flushPromises()

    expect(mocks.joinConference).toHaveBeenCalledTimes(2)
    expect(mocks.invite).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('Connected')
    wrapper.unmount()
    vi.useRealTimers()
  })

  it('preserves the recovery retry ladder across consecutive rejected replacement INVITEs', async () => {
    vi.useFakeTimers()
    vi.spyOn(Math, 'random').mockReturnValue(0.5)
    const recoverableBootstrap: ReferenceDialerBootstrap = {
      ...bootstrap,
      participation: {
        participant_id: 'participant-1',
        conference_id: 'conference-open',
        state: 'recoverable',
        recoverable: true,
        recoverable_until: '2099-08-08T00:02:00Z',
      },
    }
    mocks.bootstrap.mockResolvedValueOnce(bootstrap).mockResolvedValue(recoverableBootstrap)
    mocks.joinConference.mockResolvedValue({
      signaling_destination: 'sip:conf-participant-1@sip.utcp.local.test',
      participant: { id: 'participant-1' },
    })
    mocks.invite.mockResolvedValue(undefined)
    const wrapper = mount(ReferenceDialerView)
    await flushPromises()

    mocks.client?.emitCallState('terminated')
    await flushPromises()
    expect(mocks.invite).toHaveBeenCalledTimes(1)

    const retryDelays = [1_000, 2_000, 3_000, 5_000, 8_000, 10_000]
    for (const [index, delay] of retryDelays.entries()) {
      const attemptId = index + 1
      mocks.client?.emitCallState('failed', `488 attempt ${attemptId}`, attemptId)
      await flushPromises()

      await vi.advanceTimersByTimeAsync(delay)
      await flushPromises()

      expect(mocks.joinConference).toHaveBeenCalledTimes(index + 2)
      expect(mocks.invite).toHaveBeenCalledTimes(index + 2)
    }

    expect(wrapper.text()).toContain('Recovering')
    expect(mocks.leaveConference).not.toHaveBeenCalled()
    wrapper.unmount()
    vi.useRealTimers()
  })

  it('re-enters recovery after the current recovery INVITE receives a terminal SIP failure', async () => {
    vi.useFakeTimers()
    vi.spyOn(Math, 'random').mockReturnValue(0.5)
    const recoverableBootstrap: ReferenceDialerBootstrap = {
      ...bootstrap,
      participation: {
        participant_id: 'participant-1',
        conference_id: 'conference-open',
        state: 'recoverable',
        recoverable: true,
        recoverable_until: '2099-08-08T00:02:00Z',
      },
    }
    mocks.bootstrap.mockResolvedValueOnce(bootstrap).mockResolvedValue(recoverableBootstrap)
    mocks.joinConference.mockResolvedValue({
      signaling_destination: 'sip:conf-participant-1@sip.utcp.local.test',
      participant: { id: 'participant-1' },
    })
    const wrapper = mount(ReferenceDialerView)
    await flushPromises()

    mocks.client?.emitCallState('terminated')
    await flushPromises()
    expect(mocks.invite).toHaveBeenCalledTimes(1)
    expect(mocks.leaveConference).not.toHaveBeenCalled()

    mocks.client?.emitCallState('failed', '488 Media Relay Unavailable', 1)
    await flushPromises()
    expect(wrapper.text()).toContain('Recovering')
    expect(mocks.leaveConference).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(2_000)
    await flushPromises()

    expect(mocks.joinConference).toHaveBeenCalledTimes(2)
    expect(mocks.invite).toHaveBeenCalledTimes(2)
    expect(mocks.joinConference.mock.calls[1][1]).not.toBe(mocks.joinConference.mock.calls[0][1])
    wrapper.unmount()
    vi.useRealTimers()
  })

  it('does not release a newer recovery binding wait for a stale failed SIP attempt', async () => {
    vi.useFakeTimers()
    vi.spyOn(Math, 'random').mockReturnValue(0.5)
    const recoverableBootstrap: ReferenceDialerBootstrap = {
      ...bootstrap,
      participation: {
        participant_id: 'participant-1',
        conference_id: 'conference-open',
        state: 'recoverable',
        recoverable: true,
        recoverable_until: '2099-08-08T00:02:00Z',
      },
    }
    mocks.bootstrap.mockResolvedValueOnce(bootstrap).mockResolvedValue(recoverableBootstrap)
    mocks.joinConference.mockResolvedValue({
      signaling_destination: 'sip:conf-participant-1@sip.utcp.local.test',
      participant: { id: 'participant-1' },
    })
    const wrapper = mount(ReferenceDialerView)
    await flushPromises()
    mocks.client?.emitCallState('terminated')
    await flushPromises()
    mocks.client?.emitCallState('failed', '488 Media Relay Unavailable', 1)
    await flushPromises()
    await vi.advanceTimersByTimeAsync(2_000)
    await flushPromises()
    expect(mocks.invite).toHaveBeenCalledTimes(2)

    mocks.client?.emitCallState('failed', 'stale attempt', 1)
    await flushPromises()
    expect(mocks.invite).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('Recovering')
    wrapper.unmount()
    vi.useRealTimers()
  })

  it('does not replace a still-established dialog on a recovery trigger', async () => {
    const wrapper = mount(ReferenceDialerView)
    await flushPromises()
    await wrapper.get('button').trigger('click')
    await flushPromises()
    const inviteCount = mocks.invite.mock.calls.length

    window.dispatchEvent(new Event('online'))
    await flushPromises()

    expect(mocks.invite).toHaveBeenCalledTimes(inviteCount)
  })

  it('cancels recovery on explicit Leave and ignores late recovery work', async () => {
    const recoverableBootstrap: ReferenceDialerBootstrap = {
      ...bootstrap,
      participation: {
        participant_id: 'participant-1',
        conference_id: 'conference-open',
        state: 'recoverable',
        recoverable: true,
        recoverable_until: '2099-08-08T00:02:00Z',
      },
    }
    mocks.bootstrap.mockResolvedValueOnce(bootstrap).mockResolvedValue(recoverableBootstrap)
    mocks.joinConference.mockImplementationOnce(() => new Promise(() => undefined))
    const wrapper = mount(ReferenceDialerView)
    await flushPromises()
    mocks.client?.emitCallState('terminated')
    await flushPromises()
    expect(wrapper.text()).toContain('Recovering')

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(mocks.leaveConference).toHaveBeenCalledWith('conference-open')
    expect(wrapper.text()).not.toContain('Connected')
    expect(wrapper.text()).toContain('Available conferences')
  })
})
