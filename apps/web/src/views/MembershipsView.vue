<template>
  <section
    class="workspace"
    aria-labelledby="memberships-title"
  >
    <div class="section-heading">
      <h2 id="memberships-title">
        Memberships
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
      v-if="can('tenant.memberships.manage')"
      title="Add membership"
      label="Tenant access"
    >
      <form
        class="inline-form"
        @submit.prevent="run(createMembership)"
      >
        <UiFormField
          id="membership-user"
          label="User"
          required
        >
          <template #default="{ id, describedBy, invalid }">
            <UiSelect
              :id="id"
              v-model="membershipForm.userId"
              :aria-describedby="describedBy"
              :invalid="invalid"
              required
            >
              <option value="">
                Select user
              </option>
              <option
                v-for="user in users"
                :key="user.id"
                :value="user.id"
              >
                {{ user.display_name }}
              </option>
            </UiSelect>
          </template>
        </UiFormField>
        <UiFormField
          id="membership-role"
          label="Tenant role"
          help="Role options are loaded from the server role catalog."
          required
        >
          <template #default="{ id, describedBy, invalid }">
            <UiSelect
              :id="id"
              v-model="membershipForm.roleKey"
              :aria-describedby="describedBy"
              :invalid="invalid"
              :disabled="tenantRoleOptions.length === 0"
              required
            >
              <option value="">
                Select role
              </option>
              <option
                v-for="role in tenantRoleOptions"
                :key="role.key"
                :value="role.key"
              >
                {{ role.label }}
              </option>
            </UiSelect>
          </template>
        </UiFormField>
        <UiButton
          type="submit"
          :disabled="tenantRoleOptions.length === 0"
        >
          Add membership
        </UiButton>
      </form>
    </UiPanel>

    <UiLoadingState
      v-if="loading"
      label="Loading memberships."
    />
    <UiEmptyState
      v-else-if="memberships.length === 0"
      title="No memberships"
      message="No memberships were returned."
    />
    <UiPanel
      v-else
      title="Membership list"
      label="Tenant access"
    >
      <div class="data-table">
        <div
          v-for="membership in memberships"
          :key="membership.id"
          class="data-row"
        >
          <span>
            <strong>{{ membership.display_name }}</strong>
            <small>{{ membership.email }}</small>
            <UiStatusBadge
              :label="membership.status"
              :category="membershipStatusCategory(membership.status)"
            />
          </span>
          <UiButton
            v-if="can('tenant.memberships.manage')"
            type="button"
            :variant="membership.status === 'active' ? 'danger' : 'secondary'"
            @click="run(() => setMembershipStatus(membership.id, membership.status === 'active' ? 'suspended' : 'active'))"
          >
            {{ membership.status === 'active' ? 'Suspend' : 'Activate' }}
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
import UiSelect from '../components/ui/UiSelect.vue'
import UiStatusBadge from '../components/ui/UiStatusBadge.vue'
import {
  can,
  createMembership,
  fail,
  membershipForm,
  memberships,
  refreshMemberships,
  setMembershipStatus,
  tenantContextVersion,
  tenantRoleOptions,
  users,
} from '../state/appState'

const loading = ref(false)

function membershipStatusCategory(status: string): 'success' | 'warning' | 'neutral' {
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
  await run(refreshMemberships)
  loading.value = false
}

onMounted(load)
watch(tenantContextVersion, load)
</script>
