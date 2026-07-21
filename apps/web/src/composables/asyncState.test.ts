import { describe, expect, it } from 'vitest'
import { useAsyncAction, useAsyncResource } from './asyncState'

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (error: unknown) => void
  const promise = new Promise<T>((nextResolve, nextReject) => {
    resolve = nextResolve
    reject = nextReject
  })

  return { promise, resolve, reject }
}

describe('async state contracts', () => {
  it('tracks initial loading, success, empty, refresh, error, and forbidden resource states', async () => {
    let records = ['runtime-1']
    const resource = useAsyncResource(async () => records, {
      isEmpty: (data) => data.length === 0,
      getErrorMessage: (error) => error instanceof Error ? error.message : 'Request failed.',
      isForbidden: (error) => typeof error === 'object' && error !== null && 'status' in error,
    })

    const firstLoad = resource.load()
    expect(resource.state.status).toBe('loading')
    await firstLoad
    expect(resource.state.status).toBe('success')
    expect(resource.state.data).toEqual(['runtime-1'])

    records = ['runtime-1', 'runtime-2']
    const refresh = resource.load()
    expect(resource.state.status).toBe('refreshing')
    expect(resource.state.data).toEqual(['runtime-1'])
    await refresh
    expect(resource.state.status).toBe('success')

    records = []
    await resource.load()
    expect(resource.state.status).toBe('empty')

    const failing = useAsyncResource(async () => {
      throw new Error('Runtime catalog unavailable.')
    })
    await failing.load()
    expect(failing.state.status).toBe('error')
    expect(failing.state.error).toBe('Runtime catalog unavailable.')

    const forbidden = useAsyncResource(async () => {
      throw { status: 403 }
    })
    await forbidden.load()
    expect(forbidden.state.status).toBe('forbidden')
  })

  it('keeps stale resource responses from overwriting newer results', async () => {
    const slow = deferred<string[]>()
    const fast = deferred<string[]>()
    const responses = [slow.promise, fast.promise]
    const resource = useAsyncResource(async () => responses.shift() ?? Promise.resolve([]), {
      isEmpty: (data) => data.length === 0,
    })

    const slowLoad = resource.load()
    const fastLoad = resource.load()
    fast.resolve(['new'])
    await fastLoad
    slow.resolve(['old'])
    await slowLoad

    expect(resource.state.status).toBe('success')
    expect(resource.state.data).toEqual(['new'])
  })

  it('prevents duplicate actions and keeps success, failure, and reset distinct', async () => {
    const pending = deferred<string>()
    let submitCount = 0
    const action = useAsyncAction(async () => {
      submitCount += 1

      return pending.promise
    })

    const firstSubmit = action.run()
    const duplicateSubmit = action.run()
    expect(action.state.status).toBe('submitting')
    expect(submitCount).toBe(1)
    expect(await duplicateSubmit).toBeNull()

    pending.resolve('saved')
    await firstSubmit
    expect(action.state.status).toBe('succeeded')
    expect(action.state.result).toBe('saved')

    const failedAction = useAsyncAction(async () => {
      throw new Error('Backend validation failed.')
    })
    await failedAction.run()
    expect(failedAction.state.status).toBe('failed')
    expect(failedAction.state.error).toBe('Backend validation failed.')
    expect(failedAction.state.errorDetails).toBeInstanceOf(Error)

    failedAction.reset()
    expect(failedAction.state.status).toBe('idle')
    expect(failedAction.state.error).toBe('')
  })
})
