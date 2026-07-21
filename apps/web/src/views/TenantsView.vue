<template>
  <section
    class="workspace"
    aria-labelledby="tenants-title"
  >
    <div class="section-heading">
      <h2 id="tenants-title">
        Tenants
      </h2>
      <button
        type="button"
        @click="load"
      >
        Refresh
      </button>
    </div>
    <form
      v-if="can('platform.tenants.manage')"
      class="inline-form"
      @submit.prevent="run(createTenant)"
    >
      <input
        v-model="tenantForm.slug"
        placeholder="tenant-slug"
        required
      >
      <input
        v-model="tenantForm.displayName"
        placeholder="Tenant display name"
        required
      >
      <button type="submit">
        Create tenant
      </button>
    </form>
    <p
      v-if="loading"
      class="meta"
      role="status"
      aria-live="polite"
    >
      Loading tenants.
    </p>
    <p
      v-else-if="tenants.length === 0"
      class="meta"
    >
      No tenants were returned.
    </p>
    <div
      v-else
      class="data-table"
    >
      <div
        v-for="tenant in tenants"
        :key="tenant.id"
        class="data-row"
      >
        <span>
          <strong>{{ tenant.display_name }}</strong>
          <small>{{ tenant.slug }} · {{ tenant.status }}</small>
        </span>
        <button
          v-if="can('platform.tenants.manage')"
          type="button"
          @click="run(() => setTenantStatus(tenant.id, tenant.status === 'active' ? 'suspended' : 'active'))"
        >
          {{ tenant.status === 'active' ? 'Suspend' : 'Activate' }}
        </button>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { can, createTenant, fail, refreshTenants, setTenantStatus, tenantContextVersion, tenantForm, tenants } from '../state/appState'

const loading = ref(false)

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
