<template>
  <section
    class="workspace"
    aria-labelledby="users-title"
  >
    <div class="section-heading">
      <h2 id="users-title">
        Users
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
      title="Filter users"
      label="Search"
    >
      <form
        class="inline-form"
        role="search"
        @submit.prevent="run(applyUserFilters)"
      >
        <UiFormField
          id="user-search"
          label="Search"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="userFilters.search"
              :aria-describedby="describedBy"
              :invalid="invalid"
              placeholder="Name or email"
            />
          </template>
        </UiFormField>
        <UiFormField
          id="user-status-filter"
          label="Account status"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiSelect
              :id="id"
              v-model="userFilters.status"
              :aria-describedby="describedBy"
              :invalid="invalid"
            >
              <option value="">
                Any status
              </option>
              <option value="active">
                Active
              </option>
              <option value="suspended">
                Suspended
              </option>
            </UiSelect>
          </template>
        </UiFormField>
        <UiButton type="submit">
          Apply
        </UiButton>
      </form>
    </UiPanel>

    <UiPanel
      v-if="can('platform.users.manage')"
      title="Create user"
      label="Management"
    >
      <form
        class="inline-form"
        @submit.prevent="run(createUser)"
      >
        <UiFormField
          id="new-user-email"
          label="Email"
          required
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="userForm.email"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="email"
              placeholder="user@example.test"
              type="email"
              required
            />
          </template>
        </UiFormField>
        <UiFormField
          id="new-user-display-name"
          label="Display name"
          required
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="userForm.displayName"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="name"
              placeholder="Display name"
              required
            />
          </template>
        </UiFormField>
        <UiButton type="submit">
          Create user
        </UiButton>
      </form>
    </UiPanel>

    <p
      v-if="temporaryPassword"
      class="one-time-secret"
    >
      Temporary password: <code>{{ temporaryPassword }}</code>
    </p>

    <UiLoadingState
      v-if="loading"
      label="Loading users."
    />
    <UiEmptyState
      v-else-if="users.length === 0"
      title="No users"
      message="No users were returned."
    />
    <div
      v-else
      class="data-table"
    >
      <div
        v-for="user in users"
        :key="user.id"
        class="data-row"
      >
        <span>
          <strong>{{ user.display_name }}</strong>
          <small>{{ user.email }} · {{ user.password_change_required ? 'password change required' : 'password current' }}</small>
          <UiStatusBadge
            :label="user.status"
            :category="userStatusCategory(user.status)"
          />
        </span>
        <span class="row-actions">
          <UiButton
            v-if="can('platform.users.manage')"
            type="button"
            variant="secondary"
            :disabled="userAction.state.status === 'submitting'"
            @click="run(() => resetPassword(user.id))"
          >Reset password</UiButton>
          <UiButton
            v-if="can('platform.users.manage')"
            type="button"
            :variant="user.status === 'active' ? 'danger' : 'secondary'"
            :disabled="userAction.state.status === 'submitting'"
            @click="runUserStatus(user.id, user.status === 'active' ? 'suspended' : 'active')"
          >
            {{ user.status === 'active' ? 'Suspend' : 'Activate' }}
          </UiButton>
          <RouterLink :to="`/admin/users/${user.id}`">
            Details
          </RouterLink>
        </span>
        <div class="subgrid">
          <p class="meta">
            Memberships: {{ user.membership_summary?.active ?? 0 }} active / {{ user.membership_summary?.total ?? 0 }} total
          </p>
          <p class="meta">
            Roles: {{ [...(user.role_summary?.platform ?? []), ...(user.role_summary?.tenant ?? [])].join(', ') || 'None' }}
          </p>
          <p class="meta">
            TelephonySession: {{ user.active_telephony_session ? user.active_telephony_session.status : 'none' }}
          </p>
          <p class="meta">
            Signaling: {{ registrationSummary(user) }}
          </p>
          <p class="meta">
            Updated: {{ displayValue(user.updated_at) }}
          </p>
        </div>
      </div>
    </div>
    <UiAlert
      v-if="userAction.state.status === 'failed'"
      variant="error"
      title="User action failed"
    >
      {{ userAction.state.error }}
    </UiAlert>
    <div class="inline-form">
      <UiButton
        type="button"
        variant="secondary"
        :disabled="userFilters.page <= 1"
        @click="run(() => goToUserPage(userFilters.page - 1))"
      >
        Previous
      </UiButton>
      <p class="meta">
        Page {{ userPagination.page }} · {{ userPagination.total }} users
      </p>
      <UiButton
        type="button"
        variant="secondary"
        :disabled="!userPagination.has_more"
        @click="run(() => goToUserPage(userFilters.page + 1))"
      >
        Next
      </UiButton>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiEmptyState from '../components/ui/UiEmptyState.vue'
import UiFormField from '../components/ui/UiFormField.vue'
import UiLoadingState from '../components/ui/UiLoadingState.vue'
import UiPanel from '../components/ui/UiPanel.vue'
import UiSelect from '../components/ui/UiSelect.vue'
import UiStatusBadge from '../components/ui/UiStatusBadge.vue'
import UiTextInput from '../components/ui/UiTextInput.vue'
import { useAsyncAction } from '../composables/asyncState'
import { notify } from '../state/notifications'
import {
  applyUserFilters,
  apiErrorMessage,
  can,
  createUser,
  displayValue,
  fail,
  goToUserPage,
  refreshUsers,
  registrationSummary,
  resetPassword,
  setUserStatus,
  temporaryPassword,
  tenantContextVersion,
  userFilters,
  userForm,
  userPagination,
  users,
} from '../state/appState'

const loading = ref(false)
const userAction = useAsyncAction(async (action: () => Promise<void>) => action(), {
  getErrorMessage: apiErrorMessage,
})

function userStatusCategory(status: string): 'success' | 'warning' | 'neutral' {
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

async function runUserStatus(userId: string, status: string): Promise<void> {
  await userAction.run(() => setUserStatus(userId, status))
  if (userAction.state.status === 'succeeded') {
    notify({
      variant: 'success',
      title: 'User updated',
      message: `User ${status === 'active' ? 'activated' : 'suspended'}.`,
    })

    return
  }

  if (userAction.state.status === 'failed') {
    notify({
      variant: 'error',
      title: 'User action failed',
      message: userAction.state.error,
    })
  }
}

async function load(): Promise<void> {
  loading.value = true
  await run(refreshUsers)
  loading.value = false
}

onMounted(load)
watch(tenantContextVersion, load)
</script>
