<template>
  <section
    class="workspace"
    aria-labelledby="memberships-title"
  >
    <div class="section-heading">
      <div>
        <h2 id="memberships-title">
          Memberships
        </h2>
        <p class="meta">
          Assign users to tenants and manage tenant-scoped access.
        </p>
      </div>
      <UiButton
        type="button"
        variant="secondary"
        :loading="membershipsResource.state.status === 'refreshing'"
        loading-label="Refreshing"
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
        @submit.prevent="runMembership(membershipCreateActionKey, createMembership, 'Membership added.')"
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
          :loading="membershipActionSubmitting(membershipCreateActionKey)"
          loading-label="Adding"
        >
          Add membership
        </UiButton>
      </form>
    </UiPanel>

    <UiDataList
      :status="membershipsResource.state.status"
      :error="membershipsResource.state.error"
      :has-data="memberships.length > 0"
      title="Membership list"
      label="Tenant access"
      loading-label="Loading memberships."
      refreshing-label="Refreshing memberships."
      empty-title="No memberships"
      empty-message="No memberships were returned."
      error-title="Memberships unavailable"
      forbidden-title="Memberships forbidden"
    >
      <template #actions>
        <UiListSummary
          :count="memberships.length"
          item-label="memberships"
        />
      </template>
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
            :disabled="membershipActionSubmitting(membershipStatusActionKey(membership.id, membership.status === 'active' ? 'suspended' : 'active'))"
            :loading="membershipActionSubmitting(membershipStatusActionKey(membership.id, membership.status === 'active' ? 'suspended' : 'active'))"
            @click="runMembership(membershipStatusActionKey(membership.id, membership.status === 'active' ? 'suspended' : 'active'), () => setMembershipStatus(membership.id, membership.status === 'active' ? 'suspended' : 'active'), `Membership ${membership.status === 'active' ? 'suspended' : 'activated'}.`)"
          >
            {{ membership.status === 'active' ? 'Suspend' : 'Activate' }}
          </UiButton>
        </div>
      </div>
    </UiDataList>
    <UiAlert
      v-if="membershipActionError(membershipCreateActionKey)"
      variant="error"
      title="Membership action failed"
    >
      {{ membershipActionError(membershipCreateActionKey) }}
    </UiAlert>
  </section>
</template>

<script setup lang="ts">
import { watch } from 'vue'
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiDataList from '../components/ui/UiDataList.vue'
import UiFormField from '../components/ui/UiFormField.vue'
import UiListSummary from '../components/ui/UiListSummary.vue'
import UiPanel from '../components/ui/UiPanel.vue'
import UiSelect from '../components/ui/UiSelect.vue'
import UiStatusBadge from '../components/ui/UiStatusBadge.vue'
import { useAsyncActionMap, useAsyncResource } from '../composables/asyncState'
import { useListQueryState } from '../composables/listQueryState'
import { router } from '../router'
import { notify } from '../state/notifications'
import {
  apiErrorMessage,
  can,
  createMembership,
  membershipForm,
  memberships,
  refreshMemberships,
  setMembershipStatus,
  tenantContextVersion,
  tenantRoleOptions,
  users,
} from '../state/appState'

useListQueryState(router, {})
const membershipsResource = useAsyncResource(refreshMemberships, {
  isEmpty: () => memberships.value.length === 0,
  getErrorMessage: apiErrorMessage,
})
const membershipActions = useAsyncActionMap<void>({
  getErrorMessage: apiErrorMessage,
})
const membershipCreateActionKey = 'membership:create'

function membershipStatusCategory(status: string): 'success' | 'warning' | 'neutral' {
  if (status === 'active') return 'success'
  if (status === 'suspended') return 'warning'

  return 'neutral'
}

function membershipStatusActionKey(membershipId: string, status: string): string {
  return `membership:${membershipId}:status:${status}`
}

function membershipActionSubmitting(key: string): boolean {
  return membershipActions.isSubmitting(key)
}

function membershipActionError(key: string): string {
  const state = membershipActions.stateFor(key)

  return state.status === 'failed' ? state.error : ''
}

async function runMembership(key: string, action: () => Promise<void>, successMessage: string): Promise<void> {
  await membershipActions.run(key, action)
  const state = membershipActions.stateFor(key)
  if (state.status === 'succeeded') {
    notify({
      variant: 'success',
      title: 'Membership updated',
      message: successMessage,
    })

    return
  }

  if (state.status === 'failed') {
    notify({
      variant: 'error',
      title: 'Membership action failed',
      message: state.error,
    })
  }
}

async function load(): Promise<void> {
  await membershipsResource.load()
}

watch(tenantContextVersion, load, { immediate: true })
</script>
