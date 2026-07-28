<template>
  <section
    class="workspace"
    aria-labelledby="tenants-title"
  >
    <div class="section-heading">
      <div>
        <h2 id="tenants-title">
          Tenants
        </h2>
        <p class="meta">
          Manage tenant workspaces represented in the control plane.
        </p>
      </div>
      <UiButton
        type="button"
        variant="secondary"
        :loading="tenantsResource.state.status === 'refreshing'"
        loading-label="Refreshing"
        @click="load"
      >
        Refresh
      </UiButton>
    </div>

    <UiPanel
      v-if="can('platform.tenants.manage')"
      title="Create tenant"
      label="Management"
    >
      <form
        class="inline-form"
        @submit.prevent="runTenantAction(tenantCreateActionKey, createTenant, 'Tenant created.')"
      >
        <UiFormField
          id="tenant-slug"
          label="Tenant slug"
          required
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="tenantForm.slug"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="off"
              placeholder="tenant-slug"
              required
            />
          </template>
        </UiFormField>
        <UiFormField
          id="tenant-display-name"
          label="Display name"
          required
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="tenantForm.displayName"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="organization"
              placeholder="Tenant display name"
              required
            />
          </template>
        </UiFormField>
        <UiButton
          type="submit"
          :loading="tenantActionSubmitting(tenantCreateActionKey)"
          loading-label="Creating tenant"
        >
          Create tenant
        </UiButton>
      </form>
    </UiPanel>

    <UiAlert
      v-if="tenantActionError(tenantCreateActionKey)"
      variant="error"
      title="Tenant action failed"
    >
      {{ tenantActionError(tenantCreateActionKey) }}
    </UiAlert>

    <UiDataList
      :status="tenantsResource.state.status"
      :error="tenantsResource.state.error"
      :has-data="tenants.length > 0"
      title="Tenant list"
      label="Directory"
      loading-label="Loading tenants."
      refreshing-label="Refreshing tenants."
      empty-title="No tenants"
      empty-message="No tenants were returned."
      error-title="Tenants unavailable"
      forbidden-title="Tenants forbidden"
    >
      <template #actions>
        <UiListSummary
          :count="tenants.length"
          item-label="tenants"
        />
      </template>
      <div class="data-table">
        <div
          v-for="tenant in tenants"
          :key="tenant.id"
          class="data-row"
        >
          <span>
            <strong>{{ tenant.display_name }}</strong>
            <small>{{ tenant.slug }}</small>
            <UiStatusBadge
              :label="tenant.status"
              :category="tenantStatusCategory(tenant.status)"
            />
          </span>
          <UiButton
            v-if="can('platform.tenants.manage')"
            type="button"
            :variant="tenant.status === 'active' ? 'danger' : 'secondary'"
            :disabled="tenantActionSubmitting(tenantStatusActionKey(tenant.id, tenant.status === 'active' ? 'suspended' : 'active'))"
            :loading="tenantActionSubmitting(tenantStatusActionKey(tenant.id, tenant.status === 'active' ? 'suspended' : 'active'))"
            @click="runTenantAction(tenantStatusActionKey(tenant.id, tenant.status === 'active' ? 'suspended' : 'active'), () => setTenantStatus(tenant.id, tenant.status === 'active' ? 'suspended' : 'active'), 'Tenant status updated.')"
          >
            {{ tenant.status === 'active' ? 'Suspend' : 'Activate' }}
          </UiButton>
        </div>
      </div>
    </UiDataList>
  </section>
</template>

<script setup lang="ts">
import { watch } from 'vue'
import UiButton from '../components/ui/UiButton.vue'
import UiAlert from '../components/ui/UiAlert.vue'
import UiDataList from '../components/ui/UiDataList.vue'
import UiFormField from '../components/ui/UiFormField.vue'
import UiListSummary from '../components/ui/UiListSummary.vue'
import UiPanel from '../components/ui/UiPanel.vue'
import UiStatusBadge from '../components/ui/UiStatusBadge.vue'
import UiTextInput from '../components/ui/UiTextInput.vue'
import { useAsyncActionMap, useAsyncResource } from '../composables/asyncState'
import { useListQueryState } from '../composables/listQueryState'
import { router } from '../router'
import { apiErrorMessage, can, createTenant, refreshTenants, setTenantStatus, tenantContextVersion, tenantForm, tenants } from '../state/appState'
import { notify } from '../state/notifications'

useListQueryState(router, {})
const tenantsResource = useAsyncResource(refreshTenants, {
  isEmpty: () => tenants.value.length === 0,
  getErrorMessage: apiErrorMessage,
})
const tenantActions = useAsyncActionMap<void>({
  getErrorMessage: apiErrorMessage,
})
const tenantCreateActionKey = 'tenant:create'

function tenantStatusCategory(status: string): 'success' | 'warning' | 'neutral' {
  if (status === 'active') return 'success'
  if (status === 'suspended') return 'warning'

  return 'neutral'
}

function tenantStatusActionKey(tenantId: string, status: string): string {
  return `tenant:${tenantId}:status:${status}`
}

function tenantActionSubmitting(key: string): boolean {
  return tenantActions.isSubmitting(key)
}

function tenantActionError(key: string): string {
  const state = tenantActions.stateFor(key)

  return state.status === 'failed' ? state.error : ''
}

async function runTenantAction(key: string, action: () => Promise<void>, successMessage: string): Promise<void> {
  await tenantActions.run(key, action)
  const state = tenantActions.stateFor(key)
  if (state.status === 'succeeded') {
    notify({
      variant: 'success',
      title: 'Tenant updated',
      message: successMessage,
    })
    return
  }

  if (state.status === 'failed') {
    notify({
      variant: 'error',
      title: 'Tenant action failed',
      message: state.error,
    })
  }
}

async function load(): Promise<void> {
  await tenantsResource.load()
}

watch(tenantContextVersion, load, { immediate: true })
</script>
