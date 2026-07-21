<template>
  <section
    class="workspace"
    aria-labelledby="memberships-title"
  >
    <div class="section-heading">
      <h2 id="memberships-title">
        Memberships
      </h2>
      <button
        type="button"
        @click="load"
      >
        Refresh
      </button>
    </div>
    <form
      v-if="can('tenant.memberships.manage')"
      class="inline-form"
      @submit.prevent="run(createMembership)"
    >
      <select
        v-model="membershipForm.userId"
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
      </select>
      <select
        v-model="membershipForm.roleKey"
        required
      >
        <option value="tenant-member">
          Tenant member
        </option>
        <option value="tenant-admin">
          Tenant admin
        </option>
      </select>
      <button type="submit">
        Add membership
      </button>
    </form>
    <p
      v-if="loading"
      class="meta"
      role="status"
      aria-live="polite"
    >
      Loading memberships.
    </p>
    <p
      v-else-if="memberships.length === 0"
      class="meta"
    >
      No memberships were returned.
    </p>
    <div
      v-else
      class="data-table"
    >
      <div
        v-for="membership in memberships"
        :key="membership.id"
        class="data-row"
      >
        <span>
          <strong>{{ membership.display_name }}</strong>
          <small>{{ membership.email }} · {{ membership.status }}</small>
        </span>
        <button
          v-if="can('tenant.memberships.manage')"
          type="button"
          @click="run(() => setMembershipStatus(membership.id, membership.status === 'active' ? 'suspended' : 'active'))"
        >
          {{ membership.status === 'active' ? 'Suspend' : 'Activate' }}
        </button>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import {
  can,
  createMembership,
  fail,
  membershipForm,
  memberships,
  refreshMemberships,
  setMembershipStatus,
  tenantContextVersion,
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
  await run(refreshMemberships)
  loading.value = false
}

onMounted(load)
watch(tenantContextVersion, load)
</script>
