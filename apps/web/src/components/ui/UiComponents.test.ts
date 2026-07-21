import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import UiAlert from './UiAlert.vue'
import UiButton from './UiButton.vue'
import UiEmptyState from './UiEmptyState.vue'
import UiFormField from './UiFormField.vue'
import UiLoadingState from './UiLoadingState.vue'
import UiNotificationRegion from './UiNotificationRegion.vue'
import UiPanel from './UiPanel.vue'
import UiSelect from './UiSelect.vue'
import UiStatusBadge from './UiStatusBadge.vue'
import UiTextInput from './UiTextInput.vue'
import { notify, resetNotificationsForTests } from '../../state/notifications'

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
    expect(wrapper.attributes('disabled')).toBeDefined()
    expect(wrapper.attributes('aria-busy')).toBe('true')
    expect(wrapper.text()).toBe('Saving user')
    expect(wrapper.element.tagName).toBe('BUTTON')
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
})
