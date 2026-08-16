import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { OneTimeSignalingCredential } from '../api/platform'

const fakes = vi.hoisted(() => {
  class Emitter<T> {
    listeners: Array<(value: T) => void> = []

    addListener(listener: (value: T) => void): void {
      this.listeners.push(listener)
    }

    removeListener(listener: (value: T) => void): void {
      this.listeners = this.listeners.filter((candidate) => candidate !== listener)
    }

    emit(value: T): void {
      this.listeners.forEach((listener) => listener(value))
    }
  }

  class UserAgent {
    static makeURI(uri: string): string {
      return uri
    }

    public readonly options: Record<string, unknown>
    public readonly stateChange = new Emitter<string>()
    public readonly transport = {
      state: 'Connected',
      stateChange: new Emitter<string>(),
    }
    public reconnectCalls = 0
    public connected = true

    constructor(options: Record<string, unknown>) {
      this.options = options
    }

    get configuration(): Record<string, unknown> {
      return this.options
    }

    async start(): Promise<void> {
      this.transport.stateChange.emit('Connected')
    }

    isConnected(): boolean {
      return this.connected
    }

    async reconnect(): Promise<void> {
      this.reconnectCalls += 1
      this.connected = true
      this.transport.state = 'Connected'
      this.transport.stateChange.emit('Connected')
    }

    async stop(): Promise<void> {}
  }

  class Registerer {
    public readonly stateChange = new Emitter<string>()
    public unregisterCalls = 0
    public registererCalls = 0
    public state = 'Initial'
    public static autoRegister = true
    public static rejectNext = false
    public static rejectStatuses: number[] = []

    async register(options?: {
      requestDelegate?: {
        onAccept?: () => void
        onReject?: (response: { message: { statusCode: number } }) => void
      }
    }): Promise<void> {
      this.registererCalls += 1
      const rejectionStatus = Registerer.rejectStatuses.shift() ?? (Registerer.rejectNext ? 401 : null)
      if (rejectionStatus !== null) {
        Registerer.rejectNext = false
        if (this.state !== 'Unregistered') {
          this.state = 'Unregistered'
          this.stateChange.emit('Unregistered')
        }
        options?.requestDelegate?.onReject?.({ message: { statusCode: rejectionStatus } })
        return
      }
      if (!Registerer.autoRegister) return
      if (this.state !== 'Registered') {
        this.state = 'Registered'
        this.stateChange.emit('Registered')
      }
      options?.requestDelegate?.onAccept?.()
    }

    async unregister(): Promise<void> {
      this.unregisterCalls += 1
      this.state = 'Unregistered'
    }

    async dispose(): Promise<void> {}
  }

  class Inviter {
    public readonly stateChange = new Emitter<string>()
    public readonly target: string
    public inviteCalls = 0
    public byeCalls = 0
    public cancelCalls = 0
    public state = 'Initial'
    public static failInvite = false
    public static terminateInvite = false

    constructor(_userAgent: unknown, target: string) {
      this.target = target
    }

    async invite(): Promise<void> {
      this.inviteCalls += 1
      if (Inviter.failInvite) throw new Error('486 Busy Here')
      if (Inviter.terminateInvite) {
        this.state = 'Establishing'
        this.stateChange.emit('Establishing')
        this.state = 'Terminated'
        this.stateChange.emit('Terminated')
        return
      }
      this.state = 'Established'
      this.stateChange.emit('Established')
    }

    async bye(): Promise<void> {
      this.byeCalls += 1
      this.state = 'Terminating'
      this.stateChange.emit('Terminating')
      this.state = 'Terminated'
      this.stateChange.emit('Terminated')
    }

    async cancel(): Promise<void> {
      this.cancelCalls += 1
      this.state = 'Terminated'
      this.stateChange.emit('Terminated')
    }
  }

  return { Inviter, Registerer, UserAgent }
})

vi.mock('sip.js', () => ({
  Inviter: fakes.Inviter,
  Registerer: fakes.Registerer,
  RegistererState: {
    Initial: 'Initial',
    Registered: 'Registered',
    Unregistered: 'Unregistered',
    Terminated: 'Terminated',
  },
  TransportState: {
    Connected: 'Connected',
    Disconnected: 'Disconnected',
  },
  SessionState: {
    Initial: 'Initial',
    Establishing: 'Establishing',
    Established: 'Established',
    Terminating: 'Terminating',
    Terminated: 'Terminated',
  },
  UserAgent: fakes.UserAgent,
}))

import { RegistererState } from 'sip.js'
import { ReferenceDialerSignalingClient } from './referenceDialerSignaling'

const credential: OneTimeSignalingCredential = {
  username: 'ts-session',
  realm: 'sip.utcp.local.test',
  algorithm: 'MD5',
  sip_secret: 'test-only-secret',
  wss_uri: 'wss://sip.utcp.local.test/ws',
  issued_at: '2026-08-08T00:00:00Z',
  expires_at: '2026-08-08T00:02:00Z',
}

describe('ReferenceDialerSignalingClient', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.restoreAllMocks()
    fakes.Inviter.failInvite = false
    fakes.Inviter.terminateInvite = false
    fakes.Registerer.autoRegister = true
    fakes.Registerer.rejectNext = false
    fakes.Registerer.rejectStatuses = []
  })

  it('reports REGISTERED only after the SIP registerer enters Registered state', async () => {
    const states: string[] = []
    const client = new ReferenceDialerSignalingClient(credential, (state) => states.push(state))

    await client.start()

    expect(states).toEqual(['connecting', 'registered'])
    expect(RegistererState.Registered).toBe('Registered')
    await client.stop()
    expect(states.at(-1)).toBe('stopped')
  })

  it('does not resolve ensureRegistered when REGISTER was sent but confirmation is pending', async () => {
    vi.useFakeTimers()
    fakes.Registerer.autoRegister = false
    const client = new ReferenceDialerSignalingClient(credential, () => undefined)
    const started = client.start()
    await vi.runAllTicks()

    let settled = false
    void started.then(() => { settled = true })
    await vi.runAllTicks()
    expect(settled).toBe(false)

    const registerer = (client as unknown as { registerer: InstanceType<typeof fakes.Registerer> }).registerer
    registerer.state = RegistererState.Registered
    registerer.stateChange.emit(RegistererState.Registered)
    await started
    expect(client.isRegistered()).toBe(true)
    await client.stop()
    vi.useRealTimers()
  })

  it('invalidates registration on transport loss and reconnects through one application-owned attempt', async () => {
    vi.useFakeTimers()
    vi.stubGlobal('navigator', { onLine: true })
    vi.spyOn(Math, 'random').mockReturnValue(0.5)
    const client = new ReferenceDialerSignalingClient(credential, () => undefined)
    await client.start()
    const userAgent = (client as unknown as { userAgent: InstanceType<typeof fakes.UserAgent> }).userAgent

    userAgent.transport.stateChange.emit('Disconnected')
    expect(client.isRegistered()).toBe(false)
    expect(userAgent.reconnectCalls).toBe(0)

    await vi.advanceTimersByTimeAsync(1_000)
    await vi.runAllTicks()
    expect(userAgent.reconnectCalls).toBe(1)
    expect(client.isRegistered()).toBe(true)
    await client.stop()
    vi.unstubAllGlobals()
    vi.useRealTimers()
  })

  it('refreshes credentials once after an authentication rejection before confirming registration', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-08-08T00:00:00Z'))
    const refreshed = { ...credential, sip_secret: 'fresh-secret', expires_at: '2026-08-08T00:04:00Z' }
    const renewal = vi.fn().mockResolvedValue(refreshed)
    fakes.Registerer.rejectNext = true
    const client = new ReferenceDialerSignalingClient(credential, () => undefined, undefined, renewal)

    await client.start()

    expect(renewal).toHaveBeenCalledOnce()
    expect(client.isRegistered()).toBe(true)
    await client.stop()
    vi.useRealTimers()
  })

  it('allows one fresh credential retry per independent registration episode', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-08-08T00:00:00Z'))
    vi.stubGlobal('navigator', { onLine: true })
    vi.spyOn(Math, 'random').mockReturnValue(0.5)
    const refreshed = { ...credential, sip_secret: 'fresh-secret', expires_at: '2026-08-08T00:04:00Z' }
    const renewal = vi.fn()
      .mockResolvedValueOnce(refreshed)
      .mockResolvedValueOnce({ ...refreshed, sip_secret: 'episode-two-secret', expires_at: '2026-08-08T00:06:00Z' })
    fakes.Registerer.rejectStatuses = [401]
    const client = new ReferenceDialerSignalingClient(credential, () => undefined, undefined, renewal)

    await client.start()
    expect(renewal).toHaveBeenCalledOnce()

    const userAgent = (client as unknown as { userAgent: InstanceType<typeof fakes.UserAgent> }).userAgent
    userAgent.transport.stateChange.emit('Disconnected')
    const registrationAfterReconnect = client.ensureRegistered()
    await vi.advanceTimersByTimeAsync(1_000)
    await registrationAfterReconnect
    expect(userAgent.reconnectCalls).toBe(1)
    expect(client.isRegistered()).toBe(true)

    fakes.Registerer.rejectStatuses = [401]
    await client.ensureRegistered(true)
    expect(renewal).toHaveBeenCalledTimes(2)

    await client.stop()
    vi.unstubAllGlobals()
    vi.useRealTimers()
  })

  it('settles an accepted renewal while already Registered without a state transition', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-08-08T00:00:00Z'))
    const renewal = vi.fn().mockResolvedValue({ ...credential, expires_at: '2026-08-08T00:04:00Z' })
    const client = new ReferenceDialerSignalingClient(credential, () => undefined, undefined, renewal)

    await client.start()
    const registerer = (client as unknown as { registerer: InstanceType<typeof fakes.Registerer> }).registerer
    await vi.advanceTimersByTimeAsync(90_000)

    expect(registerer.state).toBe(RegistererState.Registered)
    expect(registerer.registererCalls).toBe(2)
    expect(renewal).toHaveBeenCalledOnce()
    expect((client as unknown as { registrationPromise: Promise<void> | null }).registrationPromise).toBeNull()
    expect((client as unknown as { renewalInFlight: boolean }).renewalInFlight).toBe(false)
    expect(client.isRegistered()).toBe(true)
    await client.stop()
    vi.useRealTimers()
  })

  it('settles a rejected REGISTER while already Unregistered and reaches the existing auth retry', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-08-08T00:00:00Z'))
    const refreshed = { ...credential, sip_secret: 'fresh-secret', expires_at: '2026-08-08T00:04:00Z' }
    const renewal = vi.fn().mockResolvedValue(refreshed)
    const client = new ReferenceDialerSignalingClient(credential, () => undefined, undefined, renewal)

    await client.start()
    const registerer = (client as unknown as { registerer: InstanceType<typeof fakes.Registerer> }).registerer
    registerer.state = RegistererState.Unregistered
    fakes.Registerer.rejectStatuses = [401]

    await client.ensureRegistered(true)

    expect(renewal).toHaveBeenCalledOnce()
    expect(registerer.registererCalls).toBe(2)
    expect(client.isRegistered()).toBe(true)
    expect((client as unknown as { registrationPromise: Promise<void> | null }).registrationPromise).toBeNull()
    await client.stop()
    vi.useRealTimers()
  })

  it('keeps the second authentication failure terminal without a third credential', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-08-08T00:00:00Z'))
    const refreshed = { ...credential, sip_secret: 'fresh-secret', expires_at: '2026-08-08T00:04:00Z' }
    const renewal = vi.fn().mockResolvedValue(refreshed)
    const client = new ReferenceDialerSignalingClient(credential, () => undefined, undefined, renewal)

    await client.start()
    const registerer = (client as unknown as { registerer: InstanceType<typeof fakes.Registerer> }).registerer
    registerer.state = RegistererState.Unregistered
    fakes.Registerer.rejectStatuses = [401, 401]

    await expect(client.ensureRegistered(true)).rejects.toThrow('401')

    expect(renewal).toHaveBeenCalledOnce()
    expect((client as unknown as { registrationPromise: Promise<void> | null }).registrationPromise).toBeNull()
    await client.stop()
    vi.useRealTimers()
  })

  it('does not reset the auth retry allowance during the same registration episode', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-08-08T00:00:00Z'))
    const renewal = vi.fn().mockResolvedValue({ ...credential, sip_secret: 'fresh-secret', expires_at: '2026-08-08T00:04:00Z' })
    fakes.Registerer.rejectStatuses = [401]
    const client = new ReferenceDialerSignalingClient(credential, () => undefined, undefined, renewal)

    await client.start()
    expect(renewal).toHaveBeenCalledOnce()

    const registerer = (client as unknown as { registerer: InstanceType<typeof fakes.Registerer> }).registerer
    fakes.Registerer.rejectStatuses = [401]
    registerer.state = 'Unregistered'
    await expect(client.ensureRegistered(true)).rejects.toThrow('401')
    expect(renewal).toHaveBeenCalledOnce()
    await client.stop()
    vi.useRealTimers()
  })

  it('reports registration loss when the active registerer becomes Unregistered', async () => {
    const states: string[] = []
    const client = new ReferenceDialerSignalingClient(credential, (state, error) => states.push(`${state}:${error ?? ''}`))

    await client.start()
    const registerer = (client as unknown as { registerer: InstanceType<typeof fakes.Registerer> }).registerer
    registerer.stateChange.emit(RegistererState.Unregistered)

    expect(states.at(-1)).toBe('failed:The SIP registrar rejected registration.')
    await client.stop()
  })

  it('re-registers the existing UserAgent and Registerer without creating a second client', async () => {
    const client = new ReferenceDialerSignalingClient(credential, () => undefined)

    await client.start()
    const userAgent = (client as unknown as { userAgent: InstanceType<typeof fakes.UserAgent> }).userAgent
    const registerer = (client as unknown as { registerer: InstanceType<typeof fakes.Registerer> }).registerer
    registerer.state = RegistererState.Unregistered
    registerer.stateChange.emit(RegistererState.Unregistered)

    await client.ensureRegistered()

    expect((client as unknown as { userAgent: InstanceType<typeof fakes.UserAgent> }).userAgent).toBe(userAgent)
    expect((client as unknown as { registerer: InstanceType<typeof fakes.Registerer> }).registerer).toBe(registerer)
    expect(registerer.registererCalls).toBe(2)
    await client.stop()
  })

  it('passes the canonical WSS and session credential to the SIP user agent', async () => {
    const client = new ReferenceDialerSignalingClient(credential, () => undefined)

    await client.start()

    const userAgent = (client as unknown as { userAgent: InstanceType<typeof fakes.UserAgent> }).userAgent
    expect(userAgent?.options).toMatchObject({
      authorizationUsername: credential.username,
      authorizationPassword: credential.sip_secret,
      transportOptions: { server: credential.wss_uri },
    })
    await client.stop()
  })

  it('creates an Inviter from the registered UserAgent and terminates the established dialog', async () => {
    const callStates: Array<{ state: string; attemptId?: number }> = []
    const client = new ReferenceDialerSignalingClient(credential, () => undefined, (state, _message, attemptId) => callStates.push({ state, attemptId }))

    await client.start()
    const attemptId = await client.invite('sip:conf-participant-abc@sip.utcp.local.test')

    const inviter = (client as unknown as { inviter: InstanceType<typeof fakes.Inviter> }).inviter
    expect(inviter?.target).toBe('sip:conf-participant-abc@sip.utcp.local.test')
    expect(inviter?.inviteCalls).toBe(1)
    expect(attemptId).toBe(1)
    expect(callStates).toEqual([
      { state: 'inviting', attemptId: 1 },
      { state: 'connected', attemptId: 1 },
    ])

    await client.leave()
    expect(inviter?.byeCalls).toBe(1)
    expect(callStates).toContainEqual({ state: 'terminating', attemptId: 1 })
    expect(callStates.at(-1)).toEqual({ state: 'terminated', attemptId: undefined })
    await client.stop()
  })

  it('owns one callback attempt identity per INVITE and advances it only for a new INVITE', async () => {
    const callStates: Array<{ state: string; attemptId?: number }> = []
    const client = new ReferenceDialerSignalingClient(credential, () => undefined, (state, _message, attemptId) => callStates.push({ state, attemptId }))

    await client.start()
    const firstAttempt = await client.invite('sip:conf-first@sip.utcp.local.test')
    await client.leave()
    const secondAttempt = await client.invite('sip:conf-second@sip.utcp.local.test')

    expect(firstAttempt).toBe(1)
    expect(secondAttempt).toBe(2)
    expect(callStates.filter(({ state }) => state === 'inviting')).toEqual([
      { state: 'inviting', attemptId: 1 },
      { state: 'inviting', attemptId: 2 },
    ])
    expect(callStates.filter(({ state }) => state === 'connected')).toEqual([
      { state: 'connected', attemptId: 1 },
      { state: 'connected', attemptId: 2 },
    ])
    await client.stop()
  })

  it('reports INVITE failure without presenting a connected dialog', async () => {
    const callStates: string[] = []
    fakes.Inviter.failInvite = true
    const client = new ReferenceDialerSignalingClient(credential, () => undefined, (state) => callStates.push(state))

    await client.start()
    await expect(client.invite('sip:conf-participant-abc@sip.utcp.local.test')).rejects.toThrow('486 Busy Here')

    expect(callStates).toEqual(['inviting', 'failed'])
    await client.stop()
  })

  it('clears a stale established latch when the current INVITE fails', async () => {
    const client = new ReferenceDialerSignalingClient(credential, () => undefined)

    await client.start()
    const internals = client as unknown as { inviteEstablished: boolean }
    internals.inviteEstablished = true
    fakes.Inviter.failInvite = true

    await expect(client.invite('sip:conf-participant-abc@sip.utcp.local.test')).rejects.toThrow('486 Busy Here')

    expect(internals.inviteEstablished).toBe(false)
    expect(client.hasEstablishedConference()).toBe(false)
    await client.stop()
  })

  it('reports a terminal SIP failure when the INVITE reaches Terminated before Established', async () => {
    const callStates: string[] = []
    fakes.Inviter.terminateInvite = true
    const client = new ReferenceDialerSignalingClient(credential, () => undefined, (state) => callStates.push(state))

    await client.start()
    await client.invite('sip:conf-participant-abc@sip.utcp.local.test')

    expect(callStates).toEqual(['inviting', 'failed'])
    await client.stop()
  })

  it('reports remote termination after an established dialog without requiring local leave', async () => {
    const callStates: string[] = []
    const client = new ReferenceDialerSignalingClient(credential, () => undefined, (state) => callStates.push(state))

    await client.start()
    await client.invite('sip:conf-participant-abc@sip.utcp.local.test')
    const inviter = (client as unknown as { inviter: InstanceType<typeof fakes.Inviter> }).inviter
    inviter.state = 'Terminating'
    inviter.stateChange.emit('Terminating')
    inviter.state = 'Terminated'
    inviter.stateChange.emit('Terminated')

    expect(callStates).toEqual(['inviting', 'connected', 'terminating', 'terminated'])
    expect(inviter.byeCalls).toBe(0)
    expect(client.hasEstablishedConference()).toBe(false)
    await client.stop()
  })

  it('renews a finite credential before expiry on the existing UserAgent', async () => {
    vi.useFakeTimers()
    const refreshed = { ...credential, sip_secret: 'refreshed-secret', expires_at: '2026-08-08T00:04:00Z' }
    const renewal = vi.fn().mockResolvedValue(refreshed)
    const states: string[] = []
    const client = new ReferenceDialerSignalingClient(credential, (state) => states.push(state), undefined, renewal)

    vi.setSystemTime(new Date('2026-08-08T00:00:00Z'))
    await client.start()
    const userAgent = (client as unknown as { userAgent: InstanceType<typeof fakes.UserAgent> }).userAgent
    const registerer = (client as unknown as { registerer: InstanceType<typeof fakes.Registerer> }).registerer

    await vi.advanceTimersByTimeAsync(90_000)

    expect(renewal).toHaveBeenCalledOnce()
    expect(registerer?.registererCalls).toBe(2)
    expect(userAgent?.options.authorizationPassword).toBe('refreshed-secret')
    expect(states).toEqual(['connecting', 'registered'])
    await client.stop()
    vi.useRealTimers()
  })

  it('re-arms consecutive renewal cycles without stranding registration or renewal state', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-08-08T00:00:00Z'))
    const renewal = vi.fn()
      .mockResolvedValueOnce({ ...credential, sip_secret: 'first-renewed-secret', expires_at: '2026-08-08T00:04:00Z' })
      .mockResolvedValueOnce({ ...credential, sip_secret: 'second-renewed-secret', expires_at: '2026-08-08T00:06:00Z' })
    const client = new ReferenceDialerSignalingClient(credential, () => undefined, undefined, renewal)

    await client.start()
    const registerer = (client as unknown as { registerer: InstanceType<typeof fakes.Registerer> }).registerer
    await vi.advanceTimersByTimeAsync(90_000)
    await vi.advanceTimersByTimeAsync(120_000)

    expect(renewal).toHaveBeenCalledTimes(2)
    expect(registerer.registererCalls).toBe(3)
    expect((client as unknown as { registrationPromise: Promise<void> | null }).registrationPromise).toBeNull()
    expect((client as unknown as { renewalInFlight: boolean }).renewalInFlight).toBe(false)
    expect(client.isRegistered()).toBe(true)
    await client.stop()
    vi.useRealTimers()
  })

  it('schedules one future renewal for an explicit-offset expiry', async () => {
    vi.useFakeTimers()
    const renewal = vi.fn().mockResolvedValue({ ...credential, expires_at: '2026-08-08T00:04:00Z' })
    const client = new ReferenceDialerSignalingClient(credential, () => undefined, undefined, renewal)

    vi.setSystemTime(new Date('2026-08-08T00:00:00Z'))
    await client.start()
    await vi.advanceTimersByTimeAsync(89_999)

    expect(renewal).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1)
    expect(renewal).toHaveBeenCalledOnce()
    await client.stop()
    vi.useRealTimers()
  })

  it('fails closed for an invalid or already expired credential timestamp', async () => {
    vi.useFakeTimers()
    const renewal = vi.fn()
    const states: string[] = []
    const invalidCredential = { ...credential, expires_at: '2026-08-08 00:02:00' }
    const client = new ReferenceDialerSignalingClient(invalidCredential, (state, error) => states.push(`${state}:${error ?? ''}`), undefined, renewal)

    vi.setSystemTime(new Date('2026-08-08T08:00:00Z'))
    await client.start()
    await vi.advanceTimersByTimeAsync(60_000)

    expect(renewal).not.toHaveBeenCalled()
    expect(states.at(-1)).toBe('failed:The SIP credential expiry is invalid or too close to renew safely.')
    await client.stop()
    vi.useRealTimers()
  })

  it('stops renewal after a non-advancing replacement expiry', async () => {
    vi.useFakeTimers()
    const renewal = vi.fn().mockResolvedValue({ ...credential, sip_secret: 'same-window-secret' })
    const states: string[] = []
    const client = new ReferenceDialerSignalingClient(credential, (state, error) => states.push(`${state}:${error ?? ''}`), undefined, renewal)

    vi.setSystemTime(new Date('2026-08-08T00:00:00Z'))
    await client.start()
    await vi.advanceTimersByTimeAsync(90_000)
    await vi.advanceTimersByTimeAsync(300_000)

    expect(renewal).toHaveBeenCalledOnce()
    expect(states.at(-1)).toBe('failed:The SIP credential expiry did not advance.')
    await client.stop()
    vi.useRealTimers()
  })

  it('reports renewal failure instead of keeping registration truth indefinitely', async () => {
    vi.useFakeTimers()
    const renewal = vi.fn().mockRejectedValue(new Error('credential renewal unavailable'))
    const states: string[] = []
    const client = new ReferenceDialerSignalingClient(credential, (state, error) => states.push(`${state}:${error ?? ''}`), undefined, renewal)

    vi.setSystemTime(new Date('2026-08-08T00:00:00Z'))
    await client.start()
    await vi.advanceTimersByTimeAsync(90_000)

    expect(states.at(-1)).toBe('failed:credential renewal unavailable')
    await client.stop()
    vi.useRealTimers()
  })
})
