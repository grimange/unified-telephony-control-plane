<template>
  <section
    class="workspace"
    aria-labelledby="users-title"
  >
    <div class="section-heading">
      <h2 id="users-title">
        Users
      </h2>
      <button
        type="button"
        @click="load"
      >
        Refresh
      </button>
    </div>
    <form
      class="inline-form"
      role="search"
      @submit.prevent="run(applyUserFilters)"
    >
      <label>
        Search
        <input
          v-model="userFilters.search"
          placeholder="Name or email"
        >
      </label>
      <label>
        Account status
        <select v-model="userFilters.status">
          <option value="">
            Any status
          </option>
          <option value="active">
            Active
          </option>
          <option value="suspended">
            Suspended
          </option>
        </select>
      </label>
      <button type="submit">
        Apply
      </button>
    </form>
    <form
      v-if="can('platform.users.manage')"
      class="inline-form"
      @submit.prevent="run(createUser)"
    >
      <input
        v-model="userForm.email"
        placeholder="user@example.test"
        type="email"
        required
      >
      <input
        v-model="userForm.displayName"
        placeholder="Display name"
        required
      >
      <button type="submit">
        Create user
      </button>
    </form>
    <p
      v-if="temporaryPassword"
      class="one-time-secret"
    >
      Temporary password: <code>{{ temporaryPassword }}</code>
    </p>
    <p
      v-if="loading"
      class="meta"
      role="status"
      aria-live="polite"
    >
      Loading users.
    </p>
    <p
      v-else-if="users.length === 0"
      class="meta"
    >
      No users were returned.
    </p>
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
          <small>{{ user.email }} · {{ user.status }}{{ user.password_change_required ? ' · password change required' : '' }}</small>
        </span>
        <span class="row-actions">
          <button
            v-if="can('platform.users.manage')"
            type="button"
            @click="run(() => resetPassword(user.id))"
          >Reset password</button>
          <button
            v-if="can('platform.users.manage')"
            type="button"
            @click="run(() => setUserStatus(user.id, user.status === 'active' ? 'suspended' : 'active'))"
          >
            {{ user.status === 'active' ? 'Suspend' : 'Activate' }}
          </button>
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
    <div class="inline-form">
      <button
        type="button"
        :disabled="userFilters.page <= 1"
        @click="run(() => goToUserPage(userFilters.page - 1))"
      >
        Previous
      </button>
      <p class="meta">
        Page {{ userPagination.page }} · {{ userPagination.total }} users
      </p>
      <button
        type="button"
        :disabled="!userPagination.has_more"
        @click="run(() => goToUserPage(userFilters.page + 1))"
      >
        Next
      </button>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import {
  applyUserFilters,
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

async function run(action: () => Promise<void>): Promise<void> {
  try {
    await action()
  } catch (errorValue) {
    fail(errorValue)
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
