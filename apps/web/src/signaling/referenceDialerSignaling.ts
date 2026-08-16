import { Inviter, Registerer, RegistererState, SessionState, TransportState, UserAgent } from 'sip.js'
import type { OneTimeSignalingCredential } from '../api/platform'
import { RECOVERY_RETRY_MAX_INDEX, recoveryRetryDelay } from '../views/recoveryResilience'

export type ReferenceDialerSignalingState = 'connecting' | 'registered' | 'failed' | 'stopped'

export type ReferenceDialerSignalingStateListener = (state: ReferenceDialerSignalingState, error?: string) => void
export type ReferenceDialerCallState = 'inviting' | 'connected' | 'terminating' | 'terminated' | 'failed'
export type ReferenceDialerCallStateListener = (state: ReferenceDialerCallState, error?: string, attemptId?: number) => void
export type ReferenceDialerCredentialRenewal = () => Promise<OneTimeSignalingCredential>

const RENEWAL_SAFETY_WINDOW_MS = 30_000

class RegistrationRejectedError extends Error {
  public readonly status: number

  public constructor(status: number) {
    super(`The SIP registrar rejected registration (${status}).`)
    this.name = 'RegistrationRejectedError'
    this.status = status
  }
}

export class ReferenceDialerSignalingClient {
  private credential: OneTimeSignalingCredential
  private readonly onStateChange: ReferenceDialerSignalingStateListener
  private readonly onCallStateChange?: ReferenceDialerCallStateListener
  private readonly renewCredential?: ReferenceDialerCredentialRenewal
  private userAgent: UserAgent | null = null
  private registerer: Registerer | null = null
  private registered = false
  private transportConnected = false
  private inviter: Inviter | null = null
  private inviteAttempt = 0
  private inviteEstablished = false
  private localTermination = false
  private renewalTimer: ReturnType<typeof setTimeout> | null = null
  private renewalInFlight = false
  private registrationPromise: Promise<void> | null = null
  private reconnectPromise: Promise<void> | null = null
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null
  private reconnectTimerReject: (() => void) | null = null
  private reconnectRetryIndex = 0
  private pendingRegistrationReject: ((error: Error) => void) | null = null
  private shouldRemainRegistered = false
  private authRetryUsed = false
  private stopping = false

  public constructor(
    credential: OneTimeSignalingCredential,
    onStateChange: ReferenceDialerSignalingStateListener,
    onCallStateChange?: ReferenceDialerCallStateListener,
    renewCredential?: ReferenceDialerCredentialRenewal,
  ) {
    this.credential = credential
    this.onStateChange = onStateChange
    this.onCallStateChange = onCallStateChange
    this.renewCredential = renewCredential
  }

  public async invite(destination: string): Promise<number> {
    if (this.userAgent === null || !this.isRegistered()) throw new Error('The SIP client is not registered.')
    const target = UserAgent.makeURI(destination)
    if (target === undefined) throw new Error('The conference signaling destination is invalid.')

    const inviter = new Inviter(this.userAgent, target)
    const attemptId = ++this.inviteAttempt
    this.inviter = inviter
    this.inviteEstablished = false
    this.localTermination = false
    this.onCallStateChange?.('inviting', undefined, attemptId)
    inviter.stateChange.addListener((state) => {
      if (this.inviter !== inviter) return
      if (state === SessionState.Established) {
        this.inviteEstablished = true
        this.onCallStateChange?.('connected', undefined, attemptId)
      }
      if (state === SessionState.Terminating) this.onCallStateChange?.('terminating', undefined, attemptId)
      if (state === SessionState.Terminated) {
        const wasEstablished = this.inviteEstablished
        this.inviter = null
        this.inviteEstablished = false
        if (wasEstablished || this.localTermination) {
          this.onCallStateChange?.('terminated', undefined, attemptId)
        } else {
          this.onCallStateChange?.('failed', 'The conference call could not be established.', attemptId)
        }
      }
    })

    try {
      await inviter.invite()
      return attemptId
    } catch (errorValue) {
      this.inviter = null
      this.inviteEstablished = false
      const message = errorValue instanceof Error ? errorValue.message : 'The conference INVITE failed.'
      if (!this.inviteEstablished) this.onCallStateChange?.('failed', message, attemptId)
      throw new Error(message)
    }
  }

  public hasEstablishedConference(): boolean {
    return this.inviter?.state === SessionState.Established || this.inviteEstablished
  }

  public isRegistered(): boolean {
    return this.transportConnected && this.registered && this.registerer?.state === RegistererState.Registered
  }

  public async ensureRegistered(force = false): Promise<void> {
    this.shouldRemainRegistered = true
    if (!force && this.isRegistered()) return
    if (this.registrationPromise !== null) return this.registrationPromise

    this.registrationPromise = this.ensureRegisteredOnce(force)
    try {
      await this.registrationPromise
    } finally {
      this.registrationPromise = null
    }
  }

  public async leave(): Promise<void> {
    const inviter = this.inviter
    if (inviter === null) return
    this.localTermination = true
    this.onCallStateChange?.('terminating')
    try {
      if (inviter.state === SessionState.Established) await inviter.bye()
      else if (inviter.state === SessionState.Initial || inviter.state === SessionState.Establishing) await inviter.cancel()
    } finally {
      this.inviter = null
      this.inviteEstablished = false
      this.onCallStateChange?.('terminated')
    }
  }

  public async start(): Promise<void> {
    this.stopping = false
    this.shouldRemainRegistered = true
    this.authRetryUsed = false
    const uri = UserAgent.makeURI(`sip:${this.credential.username}@${this.credential.realm}`)
    if (uri === undefined) {
      throw new Error('The signaling identity is invalid.')
    }

    this.onStateChange('connecting')
    this.userAgent = new UserAgent({
      uri,
      authorizationUsername: this.credential.username,
      authorizationPassword: this.credential.sip_secret,
      transportOptions: { server: this.credential.wss_uri },
      reconnectionAttempts: 0,
      logBuiltinEnabled: false,
      logConfiguration: false,
      delegate: {
        onConnect: () => {
          this.transportConnected = true
          this.reconnectRetryIndex = 0
        },
        onDisconnect: (error?: Error) => {
          this.handleTransportDisconnect(error)
        },
      },
    })
    this.userAgent.transport.stateChange.addListener((state) => {
      if (state === TransportState.Connected) {
        this.transportConnected = true
        this.reconnectRetryIndex = 0
      } else if (state === TransportState.Disconnected) {
        this.handleTransportDisconnect(new Error('The WSS connection closed.'))
      }
    })
    this.createRegisterer()

    try {
      await this.userAgent.start()
      await this.ensureRegistered(true)
    } catch (errorValue) {
      const message = errorValue instanceof Error ? errorValue.message : 'The SIP registration failed.'
      this.onStateChange('failed', message)
      await this.stop()
      throw new Error(message)
    }
  }

  public async stop(): Promise<void> {
    this.stopping = true
    this.shouldRemainRegistered = false
    this.clearCredentialRenewal()
    this.reconnectTimerReject?.()
    this.reconnectTimerReject = null
    if (this.reconnectTimer !== null) clearTimeout(this.reconnectTimer)
    this.reconnectTimer = null
    this.pendingRegistrationReject?.(new Error('The SIP client is stopped.'))
    this.pendingRegistrationReject = null
    await this.leave()
    const registerer = this.registerer
    const userAgent = this.userAgent
    this.registerer = null
    this.userAgent = null
    this.transportConnected = false
    this.registered = false

    if (registerer !== null) {
      try {
        await registerer.unregister()
      } catch {
        await registerer.dispose()
      }
    }
    if (userAgent !== null) await userAgent.stop()
    this.onStateChange('stopped')
  }

  private scheduleCredentialRenewal(): void {
    this.clearCredentialRenewal()
    if (this.renewCredential === undefined) return

    const expiresAt = Date.parse(this.credential.expires_at)
    const delay = expiresAt - Date.now() - RENEWAL_SAFETY_WINDOW_MS
    if (!Number.isFinite(expiresAt) || delay <= 0) {
      this.registered = false
      this.onStateChange('failed', 'The SIP credential expiry is invalid or too close to renew safely.')
      return
    }

    this.renewalTimer = setTimeout(() => void this.renewSignalingCredential(), delay)
  }

  private clearCredentialRenewal(): void {
    if (this.renewalTimer !== null) clearTimeout(this.renewalTimer)
    this.renewalTimer = null
  }

  private async renewSignalingCredential(): Promise<void> {
    if (this.stopping || this.renewCredential === undefined || this.registerer === null || this.userAgent === null) return
    if (this.renewalInFlight) return
    this.renewalInFlight = true
    try {
      const credential = await this.renewCredential()
      const previousExpiry = Date.parse(this.credential.expires_at)
      const replacementExpiry = Date.parse(credential.expires_at)
      if (!Number.isFinite(replacementExpiry) || replacementExpiry <= previousExpiry) {
        throw new Error('The SIP credential expiry did not advance.')
      }

      this.credential = credential
      this.userAgent.configuration.authorizationUsername = credential.username
      this.userAgent.configuration.authorizationPassword = credential.sip_secret
      await this.ensureRegistered(true)
      this.scheduleCredentialRenewal()
    } catch (errorValue) {
      this.registered = false
      this.onStateChange('failed', errorValue instanceof Error ? errorValue.message : 'The SIP credential renewal failed.')
    } finally {
      this.renewalInFlight = false
    }
  }

  private createRegisterer(): void {
    if (this.userAgent === null) throw new Error('The SIP client is not initialized.')
    const registerer = new Registerer(this.userAgent)
    registerer.stateChange.addListener((state) => {
      if (this.registerer !== registerer) return
      if (state === RegistererState.Registered) {
        this.registered = true
        this.onStateChange('registered')
      } else if (state === RegistererState.Unregistered || state === RegistererState.Terminated) {
        this.registered = false
        if (!this.stopping && this.transportConnected) this.onStateChange('failed', 'The SIP registrar rejected registration.')
      }
    })
    this.registerer = registerer
  }

  private handleTransportDisconnect(error?: Error): void {
    if (this.stopping) return
    const wasUsable = this.transportConnected || this.registered
    this.transportConnected = false
    this.registered = false
    this.authRetryUsed = false
    this.clearCredentialRenewal()
    this.pendingRegistrationReject?.(error ?? new Error('The WSS connection closed.'))
    this.pendingRegistrationReject = null
    if (!wasUsable) return
    this.onStateChange('connecting', error?.message ?? 'The WSS connection closed.')
    const reconnect = this.scheduleReconnect()
    void reconnect?.catch(() => undefined)
  }

  private scheduleReconnect(): Promise<void> | null {
    if (this.stopping || this.transportConnected || this.reconnectPromise !== null) return this.reconnectPromise
    if (typeof navigator !== 'undefined' && !navigator.onLine) return null
    this.reconnectPromise = this.reconnectLoop()
      .finally(() => {
        this.reconnectPromise = null
      })
    return this.reconnectPromise
  }

  private async reconnectLoop(): Promise<void> {
    while (!this.stopping && !this.transportConnected) {
      if (typeof navigator !== 'undefined' && !navigator.onLine) throw new Error('The browser is offline.')
      const delay = recoveryRetryDelay(this.reconnectRetryIndex)
      this.reconnectRetryIndex = Math.min(this.reconnectRetryIndex + 1, RECOVERY_RETRY_MAX_INDEX)
      await new Promise<void>((resolve, reject) => {
        this.reconnectTimerReject = () => {
          this.reconnectTimerReject = null
          reject(new Error('The SIP client is stopped.'))
        }
        this.reconnectTimer = setTimeout(() => {
          this.reconnectTimer = null
          this.reconnectTimerReject = null
          resolve()
        }, delay)
      })
      if (this.stopping) throw new Error('The SIP client is stopped.')
      if (typeof navigator !== 'undefined' && !navigator.onLine) throw new Error('The browser is offline.')
      try {
        if (this.userAgent === null) throw new Error('The SIP client is not initialized.')
        await this.userAgent.reconnect()
        if (this.userAgent.isConnected()) {
          this.transportConnected = true
          this.reconnectRetryIndex = 0
          if (this.shouldRemainRegistered) queueMicrotask(() => void this.ensureRegistered().catch(() => undefined))
          return
        }
      } catch {
        // Continue with the bounded/capped ladder while the browser remains online.
      }
    }
    throw new Error('The WSS transport could not be restored.')
  }

  private async ensureRegisteredOnce(force = false): Promise<void> {
    if (this.userAgent === null || this.registerer === null) throw new Error('The SIP client is not initialized.')
    if (!this.transportConnected || !this.userAgent.isConnected()) {
      const reconnect = this.scheduleReconnect()
      if (reconnect === null) throw new Error('The WSS transport is unavailable.')
      await reconnect
    }
    if (!force && this.isRegistered()) return

    try {
      await this.registerCurrentRegisterer()
    } catch (errorValue) {
      if (!(errorValue instanceof RegistrationRejectedError) || ![401, 403].includes(errorValue.status) || this.renewCredential === undefined || this.authRetryUsed) {
        this.onStateChange('failed', errorValue instanceof Error ? errorValue.message : 'The SIP registration failed.')
        throw errorValue
      }

      this.authRetryUsed = true
      const credential = await this.renewCredential()
      this.credential = credential
      this.userAgent.configuration.authorizationUsername = credential.username
      this.userAgent.configuration.authorizationPassword = credential.sip_secret
      await this.registerer?.dispose()
      this.createRegisterer()
      await this.registerCurrentRegisterer()
    }
    this.scheduleCredentialRenewal()
  }

  private async registerCurrentRegisterer(): Promise<void> {
    const registerer = this.registerer
    if (registerer === null) throw new Error('The SIP client is not initialized.')

    const generationRegisterer = registerer
    let rejection: RegistrationRejectedError | null = null
    let removeStateListener: () => void = () => undefined
    const waitForState = new Promise<void>((resolve, reject) => {
      const onStateChange = (state: RegistererState): void => {
        if (this.registerer !== generationRegisterer) {
          generationRegisterer.stateChange.removeListener(onStateChange)
          reject(new Error('The SIP registration attempt was superseded.'))
        } else if (state === RegistererState.Registered) {
          generationRegisterer.stateChange.removeListener(onStateChange)
          resolve()
        } else if (state === RegistererState.Unregistered || state === RegistererState.Terminated) {
          generationRegisterer.stateChange.removeListener(onStateChange)
          reject(new Error('The SIP registrar rejected registration.'))
        }
      }
      generationRegisterer.stateChange.addListener(onStateChange)
      removeStateListener = () => generationRegisterer.stateChange.removeListener(onStateChange)
      this.pendingRegistrationReject = (error) => {
        removeStateListener()
        reject(error)
      }
    })
    const finalResponse = new Promise<void>((resolve, reject) => {
      void registerer.register({
        requestDelegate: {
          onAccept: () => {
            if (this.registerer === generationRegisterer) this.registered = true
            resolve()
          },
          onReject: (response) => {
            rejection = new RegistrationRejectedError(response.message.statusCode ?? 0)
            reject(rejection)
          },
        },
      }).catch(reject)
    })
    try {
      await Promise.race([waitForState, finalResponse])
    } catch (errorValue) {
      if (rejection !== null) throw rejection
      throw errorValue
    } finally {
      removeStateListener()
      if (this.pendingRegistrationReject !== null) this.pendingRegistrationReject = null
    }
  }
}
