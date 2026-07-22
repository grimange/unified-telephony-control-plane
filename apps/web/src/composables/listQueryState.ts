import { computed, reactive, watch } from 'vue'
import type { LocationQueryRaw, Router } from 'vue-router'

type QueryValue = string | null
type FilterDefinition = {
  query: string
  allowedValues: readonly string[]
  defaultValue?: string
}

export type ListQuerySnapshot<TFilters extends Record<string, string> = Record<string, string>> = {
  search: string
  filters: TFilters
  page: number
  perPage: number
}

export type ListQueryConfig<TFilters extends Record<string, string> = Record<string, string>> = {
  search?: boolean
  pagination?: boolean
  filters?: {
    [K in keyof TFilters]: FilterDefinition
  }
  defaultPage?: number
  defaultPerPage?: number
  allowedPerPage?: readonly number[]
}

function firstQueryValue(value: unknown): QueryValue {
  if (Array.isArray(value)) return typeof value[0] === 'string' ? value[0] : null
  if (typeof value === 'string') return value

  return null
}

function parsePositiveInteger(value: QueryValue, fallback: number): number {
  if (value === null || value.trim() === '') return fallback
  const parsed = Number(value)
  if (!Number.isInteger(parsed) || parsed < 1) return fallback

  return parsed
}

function normalizePerPage(value: QueryValue, allowed: readonly number[], fallback: number): number {
  const parsed = parsePositiveInteger(value, fallback)

  return allowed.includes(parsed) ? parsed : fallback
}

function queryValue(value: string | number | undefined, defaultValue: string | number | undefined): string | undefined {
  if (value === undefined || value === '' || value === defaultValue) return undefined

  return String(value)
}

function sameQuery(left: LocationQueryRaw, right: LocationQueryRaw): boolean {
  const leftParams = new URLSearchParams()
  const rightParams = new URLSearchParams()
  for (const [key, value] of Object.entries(left)) {
    if (value !== undefined && value !== null) leftParams.set(key, String(value))
  }
  for (const [key, value] of Object.entries(right)) {
    if (value !== undefined && value !== null) rightParams.set(key, String(value))
  }

  return leftParams.toString() === rightParams.toString()
}

export function useListQueryState<TFilters extends Record<string, string> = Record<string, string>>(
  router: Router,
  config: ListQueryConfig<TFilters>,
) {
  const defaultPage = config.defaultPage ?? 1
  const defaultPerPage = config.defaultPerPage ?? 20
  const allowedPerPage = config.allowedPerPage ?? [defaultPerPage]
  const filterConfig = (config.filters ?? {}) as Record<string, FilterDefinition>
  const state = reactive({
    search: '',
    filters: Object.fromEntries(Object.keys(filterConfig).map((key) => [key, ''])) as Record<string, string>,
    page: defaultPage,
    perPage: defaultPerPage,
  })

  function parseFromRoute(): { next: ListQuerySnapshot<TFilters>; normalizedQuery: LocationQueryRaw; changed: boolean } {
    const routeQuery = router.currentRoute.value.query
    const search = config.search ? (firstQueryValue(routeQuery.search)?.trim() ?? '') : ''
    const page = config.pagination ? parsePositiveInteger(firstQueryValue(routeQuery.page), defaultPage) : defaultPage
    const perPage = config.pagination ? normalizePerPage(firstQueryValue(routeQuery.per_page), allowedPerPage, defaultPerPage) : defaultPerPage
    const filters: Record<string, string> = {}

    for (const [filterKey, filter] of Object.entries(filterConfig)) {
      const raw = firstQueryValue(routeQuery[filter.query])
      const defaultValue = filter.defaultValue ?? ''
      const value = raw !== null && filter.allowedValues.includes(raw) ? raw : defaultValue
      filters[filterKey] = value
    }

    const next = { search, filters: filters as TFilters, page, perPage }
    const normalizedQuery = toQuery(next)

    return {
      next,
      normalizedQuery,
      changed: !sameQuery(routeQuery, normalizedQuery),
    }
  }

  function applySnapshot(snapshot: ListQuerySnapshot<TFilters>): void {
    state.search = snapshot.search
    state.page = snapshot.page
    state.perPage = snapshot.perPage
    Object.assign(state.filters, snapshot.filters)
  }

  function toQuery(snapshot: ListQuerySnapshot<TFilters>): LocationQueryRaw {
    const query: LocationQueryRaw = {
      search: config.search ? queryValue(snapshot.search.trim(), '') : undefined,
      page: config.pagination ? queryValue(snapshot.page, defaultPage) : undefined,
      per_page: config.pagination ? queryValue(snapshot.perPage, defaultPerPage) : undefined,
    }

    for (const [filterKey, filter] of Object.entries(filterConfig)) {
      const value = snapshot.filters[filterKey as keyof TFilters]
      query[filter.query] = queryValue(value, filter.defaultValue ?? '')
    }

    return query
  }

  async function syncFromRoute(): Promise<void> {
    const parsed = parseFromRoute()
    applySnapshot(parsed.next)
    if (parsed.changed) {
      await router.replace({ query: parsed.normalizedQuery })
    }
  }

  async function apply(next: Partial<ListQuerySnapshot<TFilters>>, mode: 'push' | 'replace' = 'push'): Promise<boolean> {
    const snapshot: ListQuerySnapshot<TFilters> = {
      search: next.search ?? state.search,
      filters: { ...state.filters, ...(next.filters ?? {}) } as TFilters,
      page: next.page ?? state.page,
      perPage: next.perPage ?? state.perPage,
    }
    const normalized = toQuery(snapshot)
    if (sameQuery(router.currentRoute.value.query, normalized)) return false
    await router[mode]({ query: normalized })

    return true
  }

  async function applyFilters(next: Pick<ListQuerySnapshot<TFilters>, 'search' | 'filters'>): Promise<boolean> {
    const normalizedSearch = next.search.trim()
    const filtersChanged = Object.keys(filterConfig).some((key) => state.filters[key] !== next.filters[key as keyof TFilters])
    const searchChanged = normalizedSearch !== state.search

    return apply({
      search: normalizedSearch,
      filters: next.filters,
      page: searchChanged || filtersChanged ? 1 : state.page,
    }, 'push')
  }

  async function clear(): Promise<boolean> {
    return apply({
      search: '',
      filters: Object.fromEntries(Object.keys(filterConfig).map((key) => [key, ''])) as TFilters,
      page: defaultPage,
      perPage: defaultPerPage,
    }, 'push')
  }

  async function setPage(page: number): Promise<boolean> {
    return apply({ page: Math.max(1, page) }, 'push')
  }

  async function setPerPage(perPage: number): Promise<boolean> {
    return apply({ perPage, page: 1 }, 'push')
  }

  watch(
    () => router.currentRoute.value.fullPath,
    () => {
      void syncFromRoute()
    },
    { immediate: true },
  )

  return {
    state: state as ListQuerySnapshot<TFilters>,
    query: computed(() => toQuery(state as ListQuerySnapshot<TFilters>)),
    syncFromRoute,
    apply,
    applyFilters,
    clear,
    setPage,
    setPerPage,
  }
}
