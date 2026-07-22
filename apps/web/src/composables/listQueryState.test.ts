import { flushPromises } from '@vue/test-utils'
import { effectScope } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { useListQueryState } from './listQueryState'

function createTestRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/admin/users', component: { template: '<div />' } },
      { path: '/admin/tenants', component: { template: '<div />' } },
    ],
  })
}

async function settle(): Promise<void> {
  await flushPromises()
  await new Promise((resolve) => setTimeout(resolve, 0))
  await flushPromises()
}

describe('list query state', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('normalizes invalid query values through router replacement without retaining unsupported dimensions', async () => {
    const router = createTestRouter()
    await router.push('/admin/users?page=bad&per_page=99&search=%20alice%20&status=unknown&sort=email&direction=desc')
    await router.isReady()
    const scope = effectScope()
    const listQuery = scope.run(() => useListQueryState<{ status: string }>(router, {
      search: true,
      pagination: true,
      filters: {
        status: { query: 'status', allowedValues: ['active', 'suspended'] },
      },
      defaultPerPage: 20,
      allowedPerPage: [10, 20, 50],
    }))

    await settle()

    expect(listQuery?.state.search).toBe('alice')
    expect(listQuery?.state.page).toBe(1)
    expect(listQuery?.state.perPage).toBe(20)
    expect(listQuery?.state.filters.status).toBe('')
    expect(router.currentRoute.value.query).toEqual({ search: 'alice' })

    scope.stop()
  })

  it('removes page and filter query parameters when an endpoint does not support them', async () => {
    const router = createTestRouter()
    await router.push('/admin/tenants?page=3&per_page=50&search=tenant&status=active')
    await router.isReady()
    const scope = effectScope()
    scope.run(() => useListQueryState(router, {}))

    await settle()

    expect(router.currentRoute.value.query).toEqual({})
    scope.stop()
  })

  it('pushes URL state for filters, pages, and page size while resetting result-set changes to page one', async () => {
    const router = createTestRouter()
    await router.push('/admin/users')
    await router.isReady()
    const scope = effectScope()
    const listQuery = scope.run(() => useListQueryState<{ status: string }>(router, {
      search: true,
      pagination: true,
      filters: {
        status: { query: 'status', allowedValues: ['active', 'suspended'] },
      },
      defaultPerPage: 20,
      allowedPerPage: [10, 20, 50],
    }))

    await settle()
    await listQuery?.setPage(2)
    await settle()
    expect(router.currentRoute.value.query).toEqual({ page: '2' })

    await listQuery?.applyFilters({ search: 'alice', filters: { status: 'active' } })
    await settle()
    expect(router.currentRoute.value.query).toEqual({ search: 'alice', status: 'active' })

    await listQuery?.setPerPage(10)
    await settle()
    expect(router.currentRoute.value.query).toEqual({ search: 'alice', status: 'active', per_page: '10' })

    await listQuery?.clear()
    await settle()
    expect(router.currentRoute.value.query).toEqual({})

    scope.stop()
  })

  it('restores prior and later query state through router back and forward', async () => {
    const router = createTestRouter()
    await router.push('/admin/users')
    await router.isReady()
    const scope = effectScope()
    const listQuery = scope.run(() => useListQueryState<{ status: string }>(router, {
      search: true,
      pagination: true,
      filters: {
        status: { query: 'status', allowedValues: ['active', 'suspended'] },
      },
      defaultPerPage: 20,
      allowedPerPage: [10, 20, 50],
    }))

    await settle()
    await listQuery?.applyFilters({ search: 'alice', filters: { status: 'active' } })
    await settle()
    await listQuery?.setPage(2)
    await settle()
    expect(listQuery?.state.page).toBe(2)

    router.back()
    await settle()
    expect(listQuery?.state.search).toBe('alice')
    expect(listQuery?.state.filters.status).toBe('active')
    expect(listQuery?.state.page).toBe(1)

    router.forward()
    await settle()
    expect(listQuery?.state.page).toBe(2)

    scope.stop()
  })

  it('does not push duplicate history entries when applying unchanged state', async () => {
    const router = createTestRouter()
    await router.push('/admin/users?search=alice&status=active')
    await router.isReady()
    const scope = effectScope()
    const listQuery = scope.run(() => useListQueryState<{ status: string }>(router, {
      search: true,
      pagination: true,
      filters: {
        status: { query: 'status', allowedValues: ['active', 'suspended'] },
      },
      defaultPerPage: 20,
      allowedPerPage: [10, 20, 50],
    }))
    await settle()
    const pushSpy = vi.spyOn(router, 'push')

    const changed = await listQuery?.applyFilters({ search: 'alice', filters: { status: 'active' } })

    expect(changed).toBe(false)
    expect(pushSpy).not.toHaveBeenCalled()
    scope.stop()
  })
})
