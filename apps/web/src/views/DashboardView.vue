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
          Review current control-plane state and move into management, runtime, reconciliation, and audit workflows.
        </p>
      </div>
      <UiButton
        type="button"
        variant="secondary"
        :loading="attentionLoading"
        loading-label="Refreshing"
        @click="loadDashboard"
      >
        Refresh
      </UiButton>
    </div>

    <div class="dashboard-grid">
      <UiPanel
        id="identity-card-title"
        label="Identity"
        :title="session?.user.display_name ?? 'Current user'"
      >
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
      </UiPanel>

      <DashboardSummaryCard
        v-if="can('runtime.nodes.view')"
        title="Runtime nodes"
        label="Operations"
        :state="runtimeCard"
      />

      <DashboardSummaryCard
        v-if="canViewUsers"
        title="Users and telephony sessions"
        label="Management"
        :state="usersCard"
      />

      <DashboardSummaryCard
        v-if="can('tenant.memberships.view')"
        title="Memberships"
        label="Tenant access"
        :state="membershipsCard"
      />

      <UiPanel
        id="attention-title"
        label="Needs attention"
        title="Attention summary"
      >
        <UiLoadingState
          v-if="attentionLoading"
        />
        <ul v-else-if="attentionItems.length > 0">
          <li
            v-for="item in attentionItems"
            :key="item"
          >
            {{ item }}
          </li>
        </ul>
        <UiEmptyState
          v-else
          title="No attention items"
          message="No degraded or unavailable state was returned by authorized summary requests."
        />
      </UiPanel>

      <UiPanel
        id="quick-links-title"
        label="Quick navigation"
        title="Available management"
      >
        <div class="quick-links">
          <RouterLink
            v-for="entry in navigation"
            :key="entry.route"
            :to="entry.route"
          >
            {{ entry.label }}
          </RouterLink>
        </div>
      </UiPanel>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { identityApi, type AdminUser, type RuntimeNode } from '../api/platform'
import DashboardSummaryCard, { type DashboardCardState } from '../components/dashboard/DashboardSummaryCard.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiEmptyState from '../components/ui/UiEmptyState.vue'
import UiLoadingState from '../components/ui/UiLoadingState.vue'
import UiPanel from '../components/ui/UiPanel.vue'
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
    items.push(`${user.display_name}: telephony session ${user.active_telephony_session.status}`)
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
      runtimeCard.value = { status: 'empty', emptyText: 'No runtime nodes were returned.' }
    } else {
      runtimeAttention.value = response.runtime_nodes.flatMap(runtimeAttentionFor)
      runtimeCard.value = {
        status: 'success',
        countLabel: String(response.runtime_nodes.length),
        emptyText: 'No runtime nodes were returned.',
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
          `${activeSessions} active telephony session record${activeSessions === 1 ? '' : 's'} in the returned page`,
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
