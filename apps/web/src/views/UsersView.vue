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
        :loading="usersResource.state.status === 'refreshing'"
        loading-label="Refreshing"
        @click="load"
      >
        Refresh
      </UiButton>
    </div>

    <UiPanel
      title="Filter users"
      label="Search"
    >
      <UiFilterBar
        @apply="applyFilters"
        @clear="clearFilters"
      >
        <UiFormField
          id="user-search"
          label="Search"
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="filterDraft.search"
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
              v-model="filterDraft.status"
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
      </UiFilterBar>
    </UiPanel>

    <UiPanel
      v-if="can('platform.users.manage')"
      title="Create user"
      label="Management"
    >
      <form
        class="inline-form"
        @submit.prevent="run(createUserAndRefresh)"
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

    <UiDataList
      :status="usersResource.state.status"
      :error="usersResource.state.error"
      :has-data="renderedUsers.length > 0"
      title="User list"
      label="Directory"
      loading-label="Loading users."
      refreshing-label="Refreshing users."
      empty-title="No users"
      empty-message="No users were returned."
      error-title="Users unavailable"
      forbidden-title="Users forbidden"
    >
      <template #actions>
        <UiListSummary
          :page="renderedPagination.page"
          :total="renderedPagination.total"
          :count="renderedUsers.length"
          item-label="users"
        />
      </template>
      <div class="data-table">
        <div
          v-for="user in renderedUsers"
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
              @click="run(() => resetPasswordAndRefresh(user.id))"
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
    </UiDataList>
    <UiAlert
      v-if="userAction.state.status === 'failed'"
      variant="error"
      title="User action failed"
    >
      {{ userAction.state.error }}
    </UiAlert>
    <UiPagination
      v-if="usersResource.state.status === 'success' || usersResource.state.status === 'refreshing'"
      :page="renderedPagination.page"
      :per-page="renderedPagination.per_page"
      :total="renderedPagination.total"
      :has-more="renderedPagination.has_more"
      :page-size-options="[10, 20, 50]"
      @previous="setPage(renderedPagination.page - 1)"
      @next="setPage(renderedPagination.page + 1)"
      @update:per-page="setPerPage"
    />
  </section>
</template>

<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { RouterLink } from 'vue-router'
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiDataList from '../components/ui/UiDataList.vue'
import UiFilterBar from '../components/ui/UiFilterBar.vue'
import UiFormField from '../components/ui/UiFormField.vue'
import UiListSummary from '../components/ui/UiListSummary.vue'
import UiPagination from '../components/ui/UiPagination.vue'
import UiPanel from '../components/ui/UiPanel.vue'
import UiSelect from '../components/ui/UiSelect.vue'
import UiStatusBadge from '../components/ui/UiStatusBadge.vue'
import UiTextInput from '../components/ui/UiTextInput.vue'
import { useAsyncAction, useAsyncResource } from '../composables/asyncState'
import { useListQueryState } from '../composables/listQueryState'
import { router } from '../router'
import { notify } from '../state/notifications'
import {
  apiErrorMessage,
  applyUsersListResult,
  can,
  createUser,
  displayValue,
  emptyUsersListResult,
  fail,
  refreshUsers,
  registrationSummary,
  resetPassword,
  setUserStatus,
  temporaryPassword,
  tenantContextVersion,
  userForm,
} from '../state/appState'

const filterDraft = reactive({ search: '', status: '' })
const userListQuery = useListQueryState<{ status: string }>(router, {
  search: true,
  pagination: true,
  filters: {
    status: { query: 'status', allowedValues: ['active', 'suspended'] },
  },
  defaultPerPage: 20,
  allowedPerPage: [10, 20, 50],
})
const usersResource = useAsyncResource(
  () => refreshUsers({
    search: userListQuery.state.search,
    status: userListQuery.state.filters.status,
    page: userListQuery.state.page,
    perPage: userListQuery.state.perPage,
  }),
  {
    isEmpty: (result) => result.users.length === 0,
    getErrorMessage: apiErrorMessage,
  },
)
const currentEmptyResult = computed(() => emptyUsersListResult({
  page: userListQuery.state.page,
  perPage: userListQuery.state.perPage,
}))
const renderedResult = computed(() => usersResource.state.data ?? currentEmptyResult.value)
const renderedUsers = computed(() => renderedResult.value.users)
const renderedPagination = computed(() => renderedResult.value.pagination)
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

async function load(): Promise<void> {
  const result = await usersResource.load()
  if (result) applyUsersListResult(result)
}

async function applyFilters(): Promise<void> {
  await userListQuery.applyFilters({
    search: filterDraft.search,
    filters: { status: filterDraft.status },
  })
}

async function clearFilters(): Promise<void> {
  await userListQuery.clear()
}

async function setPage(page: number): Promise<void> {
  await userListQuery.setPage(page)
}

async function setPerPage(perPage: number): Promise<void> {
  await userListQuery.setPerPage(perPage)
}

async function createUserAndRefresh(): Promise<void> {
  await createUser()
  await load()
}

async function resetPasswordAndRefresh(userId: string): Promise<void> {
  await resetPassword(userId)
  await load()
}

async function runUserStatus(userId: string, status: string): Promise<void> {
  await userAction.run(() => setUserStatus(userId, status))
  if (userAction.state.status === 'succeeded') {
    await load()
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

watch(
  () => [userListQuery.state.search, userListQuery.state.filters.status],
  () => {
    filterDraft.search = userListQuery.state.search
    filterDraft.status = userListQuery.state.filters.status
  },
  { immediate: true },
)
watch(
  () => [
    userListQuery.state.search,
    userListQuery.state.filters.status,
    userListQuery.state.page,
    userListQuery.state.perPage,
  ],
  () => {
    void load()
  },
  { immediate: true },
)
watch(tenantContextVersion, async () => {
  usersResource.reset()
  const changed = await userListQuery.apply({ page: 1 }, 'replace')
  if (!changed) await load()
})
</script>
