import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import { ApiRequestTimeoutError, referenceDialerApi } from './platform'

describe('reference dialer recovery API requests', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.useRealTimers()
  })

  it('aborts a hung recovery request at its requested timeout', async () => {
    vi.useFakeTimers()
    const fetchMock = vi.mocked(fetch)
    fetchMock.mockImplementation((_input, init) => new Promise((_resolve, reject) => {
      init?.signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')), { once: true })
    }))

    const request = referenceDialerApi.bootstrap({ timeoutMs: 10_000 })
    const rejection = request.catch((error: unknown) => error)
    await vi.advanceTimersByTimeAsync(10_000)

    expect(await rejection).toBeInstanceOf(ApiRequestTimeoutError)
  })

  it('clears the timeout when the recovery request completes first', async () => {
    vi.useFakeTimers()
    const fetchMock = vi.mocked(fetch)
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ participation: null }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    }))

    await expect(referenceDialerApi.bootstrap({ timeoutMs: 10_000 })).resolves.toEqual({ participation: null })
    await vi.advanceTimersByTimeAsync(10_000)
    expect(fetchMock).toHaveBeenCalledOnce()
  })

  it('preserves the legacy request behavior when no timeout is supplied', async () => {
    const fetchMock = vi.mocked(fetch)
    let resolveResponse: ((response: Response) => void) | undefined
    fetchMock.mockImplementation(() => new Promise((resolve) => {
      resolveResponse = resolve
    }))

    const request = referenceDialerApi.bootstrap()
    expect(fetchMock).toHaveBeenCalledOnce()
    resolveResponse?.(new Response(JSON.stringify({ participation: null }), { status: 200 }))

    await expect(request).resolves.toEqual({ participation: null })
  })
})
