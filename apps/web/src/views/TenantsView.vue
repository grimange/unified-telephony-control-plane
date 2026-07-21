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

    <UiLoadingState
      v-if="loading"
      label="Loading tenants."
    />
    <UiEmptyState
      v-else-if="tenants.length === 0"
      title="No tenants"
      message="No tenants were returned."
    />
    <UiPanel
      v-else
      title="Tenant list"
      label="Directory"
    >
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
    </UiPanel>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import UiButton from '../components/ui/UiButton.vue'
import UiEmptyState from '../components/ui/UiEmptyState.vue'
import UiFormField from '../components/ui/UiFormField.vue'
import UiLoadingState from '../components/ui/UiLoadingState.vue'
import UiPanel from '../components/ui/UiPanel.vue'
import UiStatusBadge from '../components/ui/UiStatusBadge.vue'
import UiTextInput from '../components/ui/UiTextInput.vue'
import { can, createTenant, fail, refreshTenants, setTenantStatus, tenantContextVersion, tenantForm, tenants } from '../state/appState'

const loading = ref(false)

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
  loading.value = true
  await run(refreshTenants)
  loading.value = false
}

onMounted(load)
watch(tenantContextVersion, load)
</script>
