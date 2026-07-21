<template>
  <section
    class="workspace"
    aria-labelledby="dashboard-title"
  >
    <div class="section-heading">
      <div>
        <h2 id="dashboard-title">
          Dashboard
        </h2>
        <p class="meta">
          Current operator and tenant context from the authenticated session.
        </p>
      </div>
      <button
        type="button"
        @click="loadDashboard"
      >
        Refresh
      </button>
    </div>

    <div class="dashboard-grid">
      <section
        class="dashboard-card"
        aria-labelledby="identity-card-title"
      >
        <p class="panel-label">
          Identity
        </p>
        <h3 id="identity-card-title">
          {{ session?.user.display_name }}
        </h3>
        <dl class="definition-grid">
          <dt>Email</dt>
          <dd>{{ session?.user.email }}</dd>
          <dt>Status</dt>
          <dd>{{ session?.user.status }}</dd>
          <dt>Tenant</dt>
          <dd>{{ session?.active_tenant?.display_name ?? 'No tenant selected' }}</dd>
          <dt>Tenant selection</dt>
          <dd>{{ session?.active_tenant ? 'Selected' : 'Required for tenant-scoped operations' }}</dd>
          <dt>Session expires</dt>
          <dd>{{ session?.expires_at }}</dd>
        </dl>
      </section>

      <DashboardSummaryCard
        v-if="can('runtime.nodes.view')"
        title="Runtime nodes"
        label="Operations"
        :state="runtimeCard"
      />

      <DashboardSummaryCard
        v-if="canViewUsers"
        title="Users and TelephonySessions"
        label="Management"
        :state="usersCard"
      />

      <DashboardSummaryCard
        v-if="can('tenant.memberships.view')"
        title="Memberships"
        label="Tenant access"
        :state="membershipsCard"
      />

      <section
        class="dashboard-card"
        aria-labelledby="attention-title"
      >
        <p class="panel-label">
          Needs attention
        </p>
        <h3 id="attention-title">
          Attention summary
        </h3>
        <p
          v-if="attentionLoading"
          class="meta"
          role="status"
          aria-live="polite"
        >
          Loading.
        </p>
        <ul v-else-if="attentionItems.length > 0">
          <li
            v-for="item in attentionItems"
            :key="item"
          >
            {{ item }}
          </li>
        </ul>
        <p
          v-else
          class="meta"
        >
          No degraded or unavailable state was returned by authorized summary requests.
        </p>
      </section>

      <section
        class="dashboard-card"
        aria-labelledby="quick-links-title"
      >
        <p class="panel-label">
          Quick navigation
        </p>
        <h3 id="quick-links-title">
          Available management
        </h3>
        <div class="quick-links">
          <RouterLink
            v-for="entry in navigation"
            :key="entry.route"
            :to="entry.route"
          >
            {{ entry.label }}
          </RouterLink>
        </div>
      </section>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { identityApi, type AdminUser, type RuntimeNode } from '../api/platform'
import DashboardSummaryCard, { type DashboardCardState } from '../components/dashboard/DashboardSummaryCard.vue'
import {
  apiErrorMessage,
  can,
  canViewUsers,
  navigation,
  session,
  tenantContextVersion,
} from '../state/appState'

const runtimeCard = ref<DashboardCardState>({ status: 'loading' })
const usersCard = ref<DashboardCardState>({ status: 'loading' })
const membershipsCard = ref<DashboardCardState>({ status: 'loading' })
const runtimeAttention = ref<string[]>([])
const userAttention = ref<string[]>([])
const membershipAttention = ref<string[]>([])
const loadingSections = ref(0)

const attentionItems = computed(() => [...runtimeAttention.value, ...userAttention.value, ...membershipAttention.value])
const attentionLoading = computed(() => loadingSections.value > 0)

function runtimeAttentionFor(node: RuntimeNode): string[] {
  const items: string[] = []
  if (['degraded', 'failed', 'unavailable'].includes(node.observed_state)) {
    items.push(`${node.name}: observed ${node.observed_state}`)
  }
  if (['recovering', 'failed'].includes(node.desired_state)) {
    items.push(`${node.name}: desired ${node.desired_state}`)
  }

  return items
}

function userAttentionFor(user: AdminUser): string[] {
  const items: string[] = []
  if (user.active_telephony_session && user.active_telephony_session.status !== 'active') {
    items.push(`${user.display_name}: TelephonySession ${user.active_telephony_session.status}`)
  }
  const registration = user.signaling_registration_summary
  if (registration?.pending_removal || ['failed', 'unavailable', 'expired'].includes(registration?.observed_state ?? '')) {
    items.push(`${user.display_name}: signaling ${registration?.desired_state ?? 'unavailable'} / ${registration?.observed_state ?? 'unavailable'}`)
  }

  return items
}

async function loadRuntimeSummary(): Promise<void> {
  if (!can('runtime.nodes.view')) {
    runtimeCard.value = { status: 'unauthorized' }

    return
  }

  runtimeCard.value = { status: 'loading' }
  runtimeAttention.value = []
  loadingSections.value += 1
  try {
    const response = await identityApi.runtimeNodes()
    if (response.runtime_nodes.length === 0) {
      runtimeCard.value = { status: 'empty', emptyText: 'No RuntimeNodes were returned.' }
    } else {
      runtimeAttention.value = response.runtime_nodes.flatMap(runtimeAttentionFor)
      runtimeCard.value = {
        status: 'success',
        countLabel: String(response.runtime_nodes.length),
        emptyText: 'No RuntimeNodes were returned.',
        items: response.runtime_nodes.slice(0, 4).map((node) => `${node.name}: desired ${node.desired_state}, observed ${node.observed_state}`),
      }
    }
  } catch (errorValue) {
    if (identityApi.isApiRequestError(errorValue) && [401, 403].includes(errorValue.status)) {
      runtimeCard.value = { status: 'unauthorized' }
    } else {
      runtimeCard.value = { status: 'failure', message: apiErrorMessage(errorValue) }
    }
  } finally {
    loadingSections.value -= 1
  }
}

async function loadUserSummary(): Promise<void> {
  if (!canViewUsers.value) {
    usersCard.value = { status: 'unauthorized' }

    return
  }

  usersCard.value = { status: 'loading' }
  userAttention.value = []
  loadingSections.value += 1
  try {
    const response = await identityApi.users({ page: 1, per_page: 5 })
    if (response.users.length === 0) {
      usersCard.value = { status: 'empty', emptyText: 'No users were returned.' }
    } else {
      userAttention.value = response.users.flatMap(userAttentionFor)
      const activeSessions = response.users.filter((user) => user.active_telephony_session).length
      usersCard.value = {
        status: 'success',
        countLabel: response.pagination ? String(response.pagination.total) : String(response.users.length),
        emptyText: 'No users were returned.',
        items: [
          `${activeSessions} active TelephonySession record${activeSessions === 1 ? '' : 's'} in the returned page`,
          ...response.users.slice(0, 3).map((user) => `${user.display_name}: ${user.status}`),
        ],
      }
    }
  } catch (errorValue) {
    if (identityApi.isApiRequestError(errorValue) && [401, 403].includes(errorValue.status)) {
      usersCard.value = { status: 'unauthorized' }
    } else {
      usersCard.value = { status: 'failure', message: apiErrorMessage(errorValue) }
    }
  } finally {
    loadingSections.value -= 1
  }
}

async function loadMembershipSummary(): Promise<void> {
  if (!can('tenant.memberships.view')) {
    membershipsCard.value = { status: 'unauthorized' }

    return
  }

  membershipsCard.value = { status: 'loading' }
  membershipAttention.value = []
  loadingSections.value += 1
  try {
    const response = await identityApi.memberships()
    if (response.memberships.length === 0) {
      membershipsCard.value = { status: 'empty', emptyText: 'No memberships were returned.' }
    } else {
      const suspended = response.memberships.filter((membership) => membership.status !== 'active')
      membershipAttention.value = suspended.map((membership) => `${membership.display_name}: membership ${membership.status}`)
      membershipsCard.value = {
        status: 'success',
        countLabel: String(response.memberships.length),
        emptyText: 'No memberships were returned.',
        items: response.memberships.slice(0, 4).map((membership) => `${membership.display_name}: ${membership.status}`),
      }
    }
  } catch (errorValue) {
    if (identityApi.isApiRequestError(errorValue) && [401, 403].includes(errorValue.status)) {
      membershipsCard.value = { status: 'unauthorized' }
    } else {
      membershipsCard.value = { status: 'failure', message: apiErrorMessage(errorValue) }
    }
  } finally {
    loadingSections.value -= 1
  }
}

async function loadDashboard(): Promise<void> {
  await Promise.all([
    loadRuntimeSummary(),
    loadUserSummary(),
    loadMembershipSummary(),
  ])
}

onMounted(loadDashboard)
watch(tenantContextVersion, loadDashboard)
</script>
