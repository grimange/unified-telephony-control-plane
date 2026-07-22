<template>
  <section
    class="workspace"
    aria-labelledby="tenants-title"
  >
    <div class="section-heading">
      <h2 id="tenants-title">
        Tenants
      </h2>
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
        @submit.prevent="run(createTenant)"
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
        <UiButton type="submit">
          Create tenant
        </UiButton>
      </form>
    </UiPanel>

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
            @click="run(() => setTenantStatus(tenant.id, tenant.status === 'active' ? 'suspended' : 'active'))"
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
import UiDataList from '../components/ui/UiDataList.vue'
import UiFormField from '../components/ui/UiFormField.vue'
import UiListSummary from '../components/ui/UiListSummary.vue'
import UiPanel from '../components/ui/UiPanel.vue'
import UiStatusBadge from '../components/ui/UiStatusBadge.vue'
import UiTextInput from '../components/ui/UiTextInput.vue'
import { useAsyncResource } from '../composables/asyncState'
import { useListQueryState } from '../composables/listQueryState'
import { router } from '../router'
import { apiErrorMessage, can, createTenant, fail, refreshTenants, setTenantStatus, tenantContextVersion, tenantForm, tenants } from '../state/appState'

useListQueryState(router, {})
const tenantsResource = useAsyncResource(refreshTenants, {
  isEmpty: () => tenants.value.length === 0,
  getErrorMessage: apiErrorMessage,
})

function tenantStatusCategory(status: string): 'success' | 'warning' | 'neutral' {
  if (status === 'active') return 'success'
  if (status === 'suspended') return 'warning'

  return 'neutral'
}

async function run(action: () => Promise<void>): Promise<void> {
  try {
    await action()
  } catch (errorValue) {
    fail(errorValue)
  }
}

async function load(): Promise<void> {
  await tenantsResource.load()
}

watch(tenantContextVersion, load, { immediate: true })
</script>
