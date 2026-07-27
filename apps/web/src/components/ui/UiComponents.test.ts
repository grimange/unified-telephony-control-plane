import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import UiAlert from './UiAlert.vue'
import UiButton from './UiButton.vue'
import UiDataList from './UiDataList.vue'
import UiEmptyState from './UiEmptyState.vue'
import UiFilterBar from './UiFilterBar.vue'
import UiFormField from './UiFormField.vue'
import UiListSummary from './UiListSummary.vue'
import UiLoadingState from './UiLoadingState.vue'
import UiNotificationRegion from './UiNotificationRegion.vue'
import UiPagination from './UiPagination.vue'
import UiPanel from './UiPanel.vue'
import UiSelect from './UiSelect.vue'
import UiStatusBadge from './UiStatusBadge.vue'
import UiTextInput from './UiTextInput.vue'
import { notify, resetNotificationsForTests } from '../../state/notifications'
import { assertNoSeriousAxeViolations } from '../../test/accessibility'

describe('core UI components', () => {
  afterEach(() => {
    resetNotificationsForTests()
    vi.useRealTimers()
  })

  it('keeps button variants, disabled, and loading states semantic', () => {
    const wrapper = mount(UiButton, {
      props: { variant: 'danger', loading: true, loadingLabel: 'Saving user' },
      slots: { default: 'Delete' },
    })

    expect(wrapper.classes()).toContain('ui-button--danger')
    expect(wrapper.attributes('disabled')).toBeUndefined()
    expect(wrapper.attributes('aria-disabled')).toBe('true')
    expect(wrapper.attributes('aria-busy')).toBe('true')
    expect(wrapper.text()).toContain('Delete')
    expect(wrapper.text()).toContain('Saving user')
    expect(wrapper.element.tagName).toBe('BUTTON')
  })

  it('emits stable variant hooks and accessible names for every button variant', () => {
    for (const variant of ['primary', 'secondary', 'ghost', 'danger'] as const) {
      const wrapper = mount(UiButton, {
        props: { variant },
        slots: { default: `${variant} action` },
      })

      expect(wrapper.classes()).toContain('ui-button')
      expect(wrapper.classes()).toContain(`ui-button--${variant}`)
      expect(wrapper.find('button').text()).toBe(`${variant} action`)
      expect(wrapper.attributes('disabled')).toBeUndefined()
      expect(wrapper.attributes('aria-disabled')).toBeUndefined()
      expect(wrapper.attributes('aria-busy')).toBeUndefined()
    }
  })

  it('preserves loading names and busy semantics on every variant without native disabling', () => {
    for (const variant of ['primary', 'secondary', 'ghost', 'danger'] as const) {
      const wrapper = mount(UiButton, {
        props: { variant, loading: true, loadingLabel: 'Saving' },
        slots: { default: `${variant} action` },
      })

      expect(wrapper.classes()).toContain(`ui-button--${variant}`)
      expect(wrapper.find('button').text()).toContain(`${variant} action`)
      expect(wrapper.find('button').text()).toContain('Saving')
      expect(wrapper.attributes('disabled')).toBeUndefined()
      expect(wrapper.attributes('aria-disabled')).toBe('true')
      expect(wrapper.attributes('aria-busy')).toBe('true')
    }
  })

  it('keeps a loading button focusable and exposes busy semantics without native disabling', async () => {
    const wrapper = mount(UiButton, {
      props: { loading: false, loadingLabel: 'Saving user' },
      slots: { default: 'Delete' },
      attachTo: document.body,
    })
    const button = wrapper.find('button').element

    button.focus()
    expect(document.activeElement).toBe(button)

    await wrapper.setProps({ loading: true })
    await nextTick()

    expect(document.activeElement).toBe(button)
    expect(wrapper.attributes('disabled')).toBeUndefined()
    expect(wrapper.attributes('aria-disabled')).toBe('true')
    expect(wrapper.attributes('aria-busy')).toBe('true')
    expect(wrapper.text()).toContain('Delete')
    expect(wrapper.text()).toContain('Saving user')

    await wrapper.setProps({ loading: false })
    expect(wrapper.attributes('aria-disabled')).toBeUndefined()
    expect(wrapper.attributes('aria-busy')).toBeUndefined()

    wrapper.unmount()
  })

  it('blocks duplicate click and keyboard activation while loading without blocking the first activation', async () => {
    const wrapper = mount({
      components: { UiButton },
      data: () => ({ loading: false, activations: 0 }),
      template: `
        <UiButton
          type="button"
          :loading="loading"
          loading-label="Saving user"
          @click="activations += 1; loading = true"
        >
          Save
        </UiButton>
      `,
    })
    const button = wrapper.find('button')

    await button.trigger('click')
    await nextTick()

    expect((wrapper.vm as unknown as { activations: number }).activations).toBe(1)
    expect(button.attributes('disabled')).toBeUndefined()
    expect(button.attributes('aria-disabled')).toBe('true')

    await button.trigger('click')
    await button.trigger('keydown.enter')
    await button.trigger('click')
    await button.trigger('keydown.space')
    await button.trigger('click')

    expect((wrapper.vm as unknown as { activations: number }).activations).toBe(1)
  })

  it('keeps explicit disabled behavior native and blocks activation', async () => {
    const wrapper = mount(UiButton, {
      props: { disabled: true },
      slots: { default: 'Delete' },
    })

    await wrapper.find('button').trigger('click')

    expect(wrapper.attributes('disabled')).toBeDefined()
    expect(wrapper.emitted('click')).toBeUndefined()
  })

  it('prevents a loading submit button from submitting its form again', async () => {
    const wrapper = mount({
      components: { UiButton },
      template: `
        <form @submit="submissions += 1">
          <UiButton type="submit" loading loading-label="Saving">
            Save
          </UiButton>
        </form>
      `,
      data: () => ({ submissions: 0 }),
      attachTo: document.body,
    })

    ;(wrapper.find('button').element as HTMLButtonElement).click()
    await nextTick()

    expect((wrapper.vm as unknown as { submissions: number }).submissions).toBe(0)

    wrapper.unmount()
  })

  it('associates form labels, help, errors, and native input attributes', async () => {
    const wrapper = mount({
      components: { UiFormField, UiTextInput },
      data: () => ({ value: '' }),
      template: `
        <UiFormField id="email-field" label="Email" help="Use work email" error="Email is required" required>
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="value"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="email"
              type="email"
              required
            />
          </template>
        </UiFormField>
      `,
    })

    const input = wrapper.find('input')
    expect(wrapper.find('label').attributes('for')).toBe('email-field')
    expect(input.attributes('id')).toBe('email-field')
    expect(input.attributes('aria-describedby')).toContain('email-field-help')
    expect(input.attributes('aria-describedby')).toContain('email-field-error')
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(input.attributes('autocomplete')).toBe('email')
    expect(input.attributes('required')).toBeDefined()

    await input.setValue('operator@utcp.local.test')
    expect((wrapper.vm as unknown as { value: string }).value).toBe('operator@utcp.local.test')
  })

  it('preserves native select model behavior', async () => {
    const wrapper = mount({
      components: { UiSelect },
      data: () => ({ value: 'system' }),
      template: `
        <UiSelect v-model="value" aria-label="Appearance">
          <option value="system">System</option>
          <option value="light">Light</option>
          <option value="dark">Dark</option>
        </UiSelect>
      `,
    })

    await wrapper.find('select').setValue('dark')

    expect((wrapper.vm as unknown as { value: string }).value).toBe('dark')
    expect(wrapper.find('select').attributes('aria-label')).toBe('Appearance')
  })

  it('renders panels, badges, alerts, loading, and empty states distinctly', () => {
    const panel = mount(UiPanel, {
      props: { title: 'Runtime nodes', label: 'Operations' },
      slots: { default: 'Canonical data' },
    })
    const badge = mount(UiStatusBadge, { props: { label: 'active', category: 'success' } })
    const alert = mount(UiAlert, { props: { title: 'Request failed', variant: 'error' }, slots: { default: 'Try again.' } })
    const loading = mount(UiLoadingState, { props: { label: 'Loading users.' } })
    const empty = mount(UiEmptyState, { props: { title: 'No users', message: 'No users were returned.' } })

    expect(panel.find('section').attributes('aria-labelledby')).toBeDefined()
    expect(panel.text()).toContain('Runtime nodes')
    expect(badge.text()).toBe('active')
    expect(badge.classes()).toContain('ui-status-badge--success')
    expect(alert.attributes('role')).toBe('alert')
    expect(loading.attributes('role')).toBe('status')
    expect(empty.text()).toContain('No users were returned.')
  })

  it('keeps filter bars keyboard-submittable and clearable without domain filter knowledge', async () => {
    const wrapper = mount(UiFilterBar, {
      slots: {
        default: '<label>Search <input name="search" value="alice"></label>',
      },
    })

    await wrapper.find('form').trigger('submit.prevent')
    await wrapper.find('form').trigger('reset.prevent')

    expect(wrapper.emitted('apply')).toHaveLength(1)
    expect(wrapper.emitted('clear')).toHaveLength(1)
    expect(wrapper.text()).toContain('Apply')
    expect(wrapper.text()).toContain('Clear')
  })

  it('renders accessible pagination controls without inferring missing totals', async () => {
    const wrapper = mount(UiPagination, {
      props: { page: 2, perPage: 20, total: 45, hasMore: true, pageSizeOptions: [10, 20, 50] },
    })

    expect(wrapper.find('nav').attributes('aria-label')).toBe('Pagination')
    expect(wrapper.text()).toContain('Page 2 of 3')
    expect(wrapper.find('button[aria-label="Go to previous page"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('button[aria-label="Go to next page"]').attributes('disabled')).toBeUndefined()

    await wrapper.find('button[aria-label="Go to previous page"]').trigger('click')
    await wrapper.find('select').setValue('50')

    expect(wrapper.emitted('previous')).toHaveLength(1)
    expect(wrapper.emitted('update:perPage')?.[0]).toEqual([50])

    const bounded = mount(UiPagination, {
      props: { page: 1, perPage: 20, hasMore: false },
    })
    expect(bounded.text()).toContain('Page 1')
    expect(bounded.text()).not.toContain('of')
    expect(bounded.find('button[aria-label="Go to previous page"]').attributes('disabled')).toBeDefined()
    expect(bounded.find('button[aria-label="Go to next page"]').attributes('disabled')).toBeDefined()
  })

  it('forwards loading semantics to available pagination controls without native disabling', () => {
    const wrapper = mount(UiPagination, {
      props: { page: 2, perPage: 20, total: 60, hasMore: true, loading: true },
    })
    const previous = wrapper.find('button[aria-label="Go to previous page"]')
    const next = wrapper.find('button[aria-label="Go to next page"]')

    expect(previous.attributes('disabled')).toBeUndefined()
    expect(previous.attributes('aria-disabled')).toBe('true')
    expect(previous.attributes('aria-busy')).toBe('true')
    expect(next.attributes('disabled')).toBeUndefined()
    expect(next.attributes('aria-disabled')).toBe('true')
    expect(next.attributes('aria-busy')).toBe('true')
    expect(previous.text()).toContain('Previous')
    expect(next.text()).toContain('Next')
  })

  it('blocks duplicate previous activation while pagination is loading', async () => {
    const wrapper = mount(UiPagination, {
      props: { page: 2, perPage: 20, total: 60, hasMore: true },
    })
    const previous = wrapper.find('button[aria-label="Go to previous page"]')

    await previous.trigger('click')
    expect(wrapper.emitted('previous')).toHaveLength(1)

    await wrapper.setProps({ loading: true })
    await previous.trigger('click')
    await previous.trigger('keydown.enter')
    await previous.trigger('click')
    await previous.trigger('keydown.space')
    await previous.trigger('click')

    expect(wrapper.emitted('previous')).toHaveLength(1)
  })

  it('blocks duplicate next activation while pagination is loading', async () => {
    const wrapper = mount(UiPagination, {
      props: { page: 1, perPage: 20, total: 60, hasMore: true },
    })
    const next = wrapper.find('button[aria-label="Go to next page"]')

    await next.trigger('click')
    expect(wrapper.emitted('next')).toHaveLength(1)

    await wrapper.setProps({ loading: true })
    await next.trigger('click')
    await next.trigger('keydown.enter')
    await next.trigger('click')
    await next.trigger('keydown.space')
    await next.trigger('click')

    expect(wrapper.emitted('next')).toHaveLength(1)
  })

  it('keeps an available pagination control focused when loading starts', async () => {
    const wrapper = mount(UiPagination, {
      props: { page: 1, perPage: 20, total: 60, hasMore: true },
      attachTo: document.body,
    })
    const next = wrapper.find('button[aria-label="Go to next page"]').element as HTMLButtonElement

    next.focus()
    expect(document.activeElement).toBe(next)

    await wrapper.setProps({ loading: true })
    await nextTick()

    expect(document.activeElement).toBe(next)
    expect(wrapper.find('button[aria-label="Go to next page"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('button[aria-label="Go to next page"]').attributes('aria-disabled')).toBe('true')
    expect(wrapper.find('button[aria-label="Go to next page"]').attributes('aria-busy')).toBe('true')

    wrapper.unmount()
  })

  it('keeps unavailable pagination boundaries natively disabled and non-emitting', async () => {
    const firstPage = mount(UiPagination, {
      props: { page: 1, perPage: 20, total: 60, hasMore: true, loading: true },
    })
    const previous = firstPage.find('button[aria-label="Go to previous page"]')

    await previous.trigger('click')
    await previous.trigger('keydown.enter')
    await previous.trigger('click')
    await previous.trigger('keydown.space')
    await previous.trigger('click')

    expect(previous.attributes('disabled')).toBeDefined()
    expect(firstPage.emitted('previous')).toBeUndefined()

    const lastPage = mount(UiPagination, {
      props: { page: 3, perPage: 20, total: 60, hasMore: false, loading: true },
    })
    const next = lastPage.find('button[aria-label="Go to next page"]')

    await next.trigger('click')
    await next.trigger('keydown.enter')
    await next.trigger('click')
    await next.trigger('keydown.space')
    await next.trigger('click')

    expect(next.attributes('disabled')).toBeDefined()
    expect(lastPage.emitted('next')).toBeUndefined()
  })

  it('renders list summaries and data-list states from supplied metadata only', () => {
    const summary = mount(UiListSummary, {
      props: { page: 2, total: 45, count: 20, itemLabel: 'users' },
    })
    const countOnly = mount(UiListSummary, {
      props: { count: 3, itemLabel: 'tenants' },
    })
    const refreshing = mount(UiDataList, {
      props: {
        status: 'refreshing',
        hasData: true,
        title: 'User list',
        label: 'Directory',
        loadingLabel: 'Loading users.',
        refreshingLabel: 'Refreshing users.',
        emptyTitle: 'No users',
        emptyMessage: 'No users were returned.',
        errorTitle: 'Users unavailable',
        forbiddenTitle: 'Users forbidden',
      },
      slots: { default: 'Existing user rows' },
    })
    const empty = mount(UiDataList, {
      props: {
        status: 'empty',
        title: 'User list',
        loadingLabel: 'Loading users.',
        refreshingLabel: 'Refreshing users.',
        emptyTitle: 'No users',
        emptyMessage: 'No users were returned.',
        errorTitle: 'Users unavailable',
        forbiddenTitle: 'Users forbidden',
      },
    })
    const failedWithData = mount(UiDataList, {
      props: {
        status: 'error',
        error: 'Refresh failed.',
        hasData: true,
        title: 'User list',
        loadingLabel: 'Loading users.',
        refreshingLabel: 'Refreshing users.',
        emptyTitle: 'No users',
        emptyMessage: 'No users were returned.',
        errorTitle: 'Users unavailable',
        forbiddenTitle: 'Users forbidden',
      },
      slots: { default: 'Existing user rows' },
    })

    expect(summary.text()).toBe('Page 2 · 45 users')
    expect(countOnly.text()).toBe('3 tenants')
    expect(refreshing.text()).toContain('Refreshing users.')
    expect(refreshing.text()).toContain('Existing user rows')
    expect(empty.text()).toContain('No users were returned.')
    expect(failedWithData.text()).toContain('Refresh failed.')
    expect(failedWithData.text()).toContain('Existing user rows')
  })

  it('renders one notification region with variants, unique IDs, dismissal, and secret redaction', async () => {
    vi.useFakeTimers()
    const successId = notify({ variant: 'success', title: 'Saved', message: 'RuntimeNode updated.', autoExpireMs: 1000 })
    const infoId = notify({ variant: 'information', title: 'Information', message: 'Sign in to continue.', autoExpireMs: 0 })
    const warningId = notify({ variant: 'warning', title: 'Warning', message: 'Catalog is stale.' })
    const errorId = notify({
      variant: 'error',
      title: 'Credential failed',
      message: 'Rejected super-secret-fixture.',
      sensitiveValues: ['super-secret-fixture'],
    })
    const wrapper = mount(UiNotificationRegion)

    expect(new Set([successId, infoId, warningId, errorId]).size).toBe(4)
    expect(wrapper.findAll('.notification-region')).toHaveLength(1)
    expect(wrapper.text()).toContain('Saved')
    expect(wrapper.text()).toContain('Information')
    expect(wrapper.text()).toContain('Warning')
    expect(wrapper.text()).toContain('Credential failed')
    expect(wrapper.text()).not.toContain('super-secret-fixture')
    expect(wrapper.find(`#${errorId}`).attributes('role')).toBe('alert')
    expect(wrapper.find(`#${infoId}`).attributes('role')).toBe('status')

    vi.advanceTimersByTime(1000)
    await wrapper.vm.$nextTick()
    expect(wrapper.text()).not.toContain('RuntimeNode updated.')
    expect(wrapper.text()).toContain('Credential failed')

    await wrapper.find(`button[aria-label="Dismiss Credential failed"]`).trigger('click')
    expect(wrapper.text()).not.toContain('Credential failed')

    wrapper.unmount()
  })

  it('has no serious or critical axe violations across shared primitives', async () => {
    notify({ variant: 'information', title: 'Saved', message: 'Settings changed.', autoExpireMs: 0 })

    const wrapper = mount({
      components: {
        UiAlert,
        UiButton,
        UiDataList,
        UiEmptyState,
        UiFilterBar,
        UiFormField,
        UiLoadingState,
        UiNotificationRegion,
        UiPagination,
        UiPanel,
        UiSelect,
        UiStatusBadge,
        UiTextInput,
      },
      data: () => ({
        email: '',
        enabled: true,
        mode: 'system',
      }),
      template: `
        <main>
          <UiPanel title="Primitive accessibility" label="Design system">
            <template #actions>
              <UiButton type="button" variant="secondary">Refresh</UiButton>
            </template>

            <UiAlert title="Request failed" variant="error">Try again.</UiAlert>
            <UiLoadingState label="Loading records." />
            <UiEmptyState title="No records" message="No records were returned." />
            <UiStatusBadge label="active" category="success" />

            <UiFormField id="primitive-email" label="Email" help="Use a work address." error="Email is required" required>
              <template #default="{ id, describedBy, invalid }">
                <UiTextInput
                  :id="id"
                  v-model="email"
                  :aria-describedby="describedBy"
                  :invalid="invalid"
                  autocomplete="email"
                  required
                />
              </template>
            </UiFormField>

            <UiFormField id="primitive-mode" label="Mode">
              <template #default="{ id, describedBy }">
                <UiSelect
                  :id="id"
                  v-model="mode"
                  :aria-describedby="describedBy"
                >
                  <option value="system">System</option>
                  <option value="light">Light</option>
                  <option value="dark">Dark</option>
                </UiSelect>
              </template>
            </UiFormField>

            <UiFormField id="primitive-enabled" label="Enabled">
              <template #default="{ id, describedBy }">
                <input
                  :id="id"
                  v-model="enabled"
                  type="checkbox"
                  :aria-describedby="describedBy"
                >
              </template>
            </UiFormField>

            <UiFilterBar>
              <label>Search <input name="search" value="operator"></label>
            </UiFilterBar>

            <UiDataList
              status="success"
              has-data
              title="User list"
              label="Directory"
              loading-label="Loading users."
              refreshing-label="Refreshing users."
              empty-title="No users"
              empty-message="No users were returned."
              error-title="Users unavailable"
              forbidden-title="Users forbidden"
            >
              <div class="data-table" role="table" aria-label="Users">
                <div class="data-table__head" role="row">
                  <span role="columnheader">Name</span>
                  <span role="columnheader">Status</span>
                </div>
                <div class="data-row" role="row">
                  <span role="cell">Operator User</span>
                  <span role="cell">active</span>
                </div>
              </div>
            </UiDataList>

            <UiPagination
              :page="1"
              :per-page="20"
              :total="40"
              :has-more="true"
              :page-size-options="[10, 20]"
            />
            <UiNotificationRegion />
          </UiPanel>
        </main>
      `,
    })

    expect(wrapper.find('main').exists()).toBe(true)
    await assertNoSeriousAxeViolations(wrapper.element)

    wrapper.unmount()
  })
})
