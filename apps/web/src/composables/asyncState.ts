import { computed, reactive } from 'vue'

export type AsyncResourceStatus = 'idle' | 'loading' | 'success' | 'empty' | 'refreshing' | 'error' | 'forbidden'
export type AsyncActionStatus = 'idle' | 'submitting' | 'succeeded' | 'failed'

type ErrorMessageResolver = (error: unknown) => string

function defaultErrorMessage(error: unknown): string {
  if (error instanceof Error) return error.message

  return 'Request failed.'
}

function defaultForbiddenCheck(error: unknown): boolean {
  return typeof error === 'object' && error !== null && 'status' in error && Number((error as { status?: unknown }).status) === 403
}

export function useAsyncResource<T>(
  loader: () => Promise<T>,
  options: {
    isEmpty?: (data: T) => boolean
    getErrorMessage?: ErrorMessageResolver
    isForbidden?: (error: unknown) => boolean
  } = {},
) {
  let requestId = 0
  const state = reactive({
    status: 'idle' as AsyncResourceStatus,
    data: null as T | null,
    error: '',
    errorDetails: null as unknown,
  })

  async function load(): Promise<T | null> {
    const currentRequestId = requestId + 1
    requestId = currentRequestId
    state.status = state.data === null ? 'loading' : 'refreshing'
    state.error = ''
    state.errorDetails = null

    try {
      const data = await loader()
      if (currentRequestId !== requestId) return null

      state.data = data as typeof state.data
      state.status = options.isEmpty?.(data) ? 'empty' : 'success'

      return data
    } catch (error) {
      if (currentRequestId !== requestId) return null

      state.error = options.getErrorMessage?.(error) ?? defaultErrorMessage(error)
      state.errorDetails = error
      state.status = (options.isForbidden?.(error) ?? defaultForbiddenCheck(error)) ? 'forbidden' : 'error'

      return null
    }
  }

  function reset(): void {
    requestId += 1
    state.status = 'idle'
    state.data = null
    state.error = ''
    state.errorDetails = null
  }

  return {
    state,
    load,
    reset,
    hasData: computed(() => state.data !== null),
    isLoading: computed(() => state.status === 'loading'),
    isRefreshing: computed(() => state.status === 'refreshing'),
  }
}

export function useAsyncAction<TArgs extends unknown[], TResult>(
  action: (...args: TArgs) => Promise<TResult>,
  options: {
    getErrorMessage?: ErrorMessageResolver
  } = {},
) {
  const state = reactive({
    status: 'idle' as AsyncActionStatus,
    error: '',
    errorDetails: null as unknown,
    result: null as TResult | null,
  })

  async function run(...args: TArgs): Promise<TResult | null> {
    if (state.status === 'submitting') return null

    state.status = 'submitting'
    state.error = ''
    state.errorDetails = null

    try {
      const result = await action(...args)
      state.result = result as typeof state.result
      state.status = 'succeeded'

      return result
    } catch (error) {
      state.error = options.getErrorMessage?.(error) ?? defaultErrorMessage(error)
      state.errorDetails = error
      state.status = 'failed'

      return null
    }
  }

  function reset(): void {
    state.status = 'idle'
    state.error = ''
    state.errorDetails = null
    state.result = null
  }

  return {
    state,
    run,
    reset,
    isSubmitting: computed(() => state.status === 'submitting'),
  }
}

export function useAsyncActionMap<TResult = unknown>(
  options: {
    getErrorMessage?: ErrorMessageResolver
  } = {},
) {
  const states = reactive<Record<string, {
    status: AsyncActionStatus
    error: string
    errorDetails: unknown
    result: TResult | null
  }>>({})

  function stateFor(key: string) {
    if (!states[key]) {
      states[key] = {
        status: 'idle',
        error: '',
        errorDetails: null,
        result: null,
      }
    }

    return states[key]
  }

  async function run(key: string, action: () => Promise<TResult>): Promise<TResult | null> {
    const state = stateFor(key)
    if (state.status === 'submitting') return null

    state.status = 'submitting'
    state.error = ''
    state.errorDetails = null

    try {
      const result = await action()
      state.result = result
      state.status = 'succeeded'

      return result
    } catch (error) {
      state.error = options.getErrorMessage?.(error) ?? defaultErrorMessage(error)
      state.errorDetails = error
      state.status = 'failed'

      return null
    }
  }

  function reset(key?: string): void {
    if (key) {
      delete states[key]
      return
    }

    Object.keys(states).forEach((stateKey) => {
      delete states[stateKey]
    })
  }

  function isSubmitting(key: string): boolean {
    return stateFor(key).status === 'submitting'
  }

  return {
    states,
    stateFor,
    run,
    reset,
    isSubmitting,
  }
}
