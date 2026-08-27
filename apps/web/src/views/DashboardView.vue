<template>
  <section
    class="workspace"
    aria-labelledby="dashboard-title"
  >
    <div class="section-heading">
      <div>
        <h2 id="dashboard-title">
          Overview
        </h2>
        <p class="meta">
          Review the current operational observations available to your account and move into the right management workflow.
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

    <UiPanel
      class="dashboard-context"
      label="Current scope"
      title="Context"
      description="The tenant and operator context applied to this overview."
    >
      <dl class="definition-grid dashboard-context__details">
        <dt>Active tenant</dt>
        <dd>{{ session?.active_tenant?.display_name ?? 'No tenant selected' }}</dd>
        <dt>Operator</dt>
        <dd>{{ session?.user.display_name }} · {{ session?.user.email }}</dd>
        <dt>Session</dt>
        <dd>
          <UiStatusBadge
            label="Active"
            category="success"
          />
        </dd>
        <template v-if="session?.catalog_version">
          <dt>Catalog</dt>
          <dd>{{ session.catalog_version }}</dd>
        </template>
      </dl>
    </UiPanel>

    <section
      class="dashboard-section"
      aria-labelledby="operational-status-title"
    >
      <div class="dashboard-section__heading">
        <div>
          <p class="panel-label">
            Operational status
          </p>
          <h3 id="operational-status-title">
            Available service observations
          </h3>
        </div>
        <p class="meta">
          Status is limited to the authorized summaries returned for this tenant.
        </p>
      </div>
    </section>

    <div class="dashboard-grid dashboard-grid--status">
      <UiPanel
        v-if="can('runtime.nodes.view')"
        class="dashboard-status-card"
        label="Telephony infrastructure"
        title="Telephony Nodes"
        description="Engines available for runtime-backed telephony operations."
      >
        <template v-if="runtimeCard.status === 'loading'">
          <UiLoadingState />
        </template>
        <template v-else-if="runtimeCard.status === 'empty'">
          <UiEmptyState
            title="No Telephony Nodes configured"
            message="Calls requiring a runtime cannot execute until a Telephony Node is available."
          />
          <RouterLink to="/admin/runtime-nodes">
            Manage Telephony Nodes
          </RouterLink>
        </template>
        <template v-else-if="runtimeCard.status === 'failure'">
          <UiAlert
            variant="error"
            title="Telephony Node summary unavailable"
          >
            {{ runtimeCard.message }}
          </UiAlert>
        </template>
        <template v-else-if="runtimeCard.status === 'success'">
          <div class="dashboard-status-card__summary">
            <strong>{{ runtimeCard.countLabel }}</strong>
            <span>configured node{{ runtimeCard.countLabel === '1' ? '' : 's' }}</span>
          </div>
          <ul>
            <li
              v-for="item in runtimeCard.items"
              :key="item"
            >
              {{ item }}
            </li>
          </ul>
        </template>
      </UiPanel>

      <DashboardSummaryCard
        v-if="canViewUsers"
        title="Users and telephony sessions"
        label="Access and activity"
        :state="usersCard"
      />

      <DashboardSummaryCard
        v-if="can('tenant.memberships.view')"
        title="Memberships"
        label="Access administration"
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
          message="No current operational issues were returned by the services available to your account."
        />
      </UiPanel>

      <UiPanel
        id="quick-links-title"
        label="Operations"
        title="Available tools"
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
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiEmptyState from '../components/ui/UiEmptyState.vue'
import UiLoadingState from '../components/ui/UiLoadingState.vue'
import UiPanel from '../components/ui/UiPanel.vue'
import UiStatusBadge from '../components/ui/UiStatusBadge.vue'
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

function formatState(state: string): string {
  return state.replaceAll('_', ' ')
}

function runtimeAttentionFor(node: RuntimeNode): string[] {
  const items: string[] = []
  if (['degraded', 'failed', 'unavailable'].includes(node.observed_state)) {
    items.push(`${node.name} is ${formatState(node.observed_state)}`)
  }
  if (['recovering', 'failed'].includes(node.desired_state)) {
    items.push(`${node.name} requires attention (desired ${formatState(node.desired_state)})`)
  }

  return items
}

function userAttentionFor(user: AdminUser): string[] {
  const items: string[] = []
  if (user.active_telephony_session && user.active_telephony_session.status !== 'active') {
    items.push(`${user.display_name} has a ${formatState(user.active_telephony_session.status)} telephony session`)
  }
  const registration = user.signaling_registration_summary
  if (registration?.pending_removal || ['failed', 'unavailable', 'expired'].includes(registration?.observed_state ?? '')) {
    items.push(`${user.display_name}: signaling requires attention (${formatState(registration?.observed_state ?? 'unavailable')}; desired ${formatState(registration?.desired_state ?? 'unavailable')})`)
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
      runtimeCard.value = { status: 'empty', emptyText: 'No telephony nodes were returned.' }
    } else {
      runtimeAttention.value = response.runtime_nodes.flatMap(runtimeAttentionFor)
      runtimeCard.value = {
        status: 'success',
        countLabel: String(response.runtime_nodes.length),
        emptyText: 'No telephony nodes were returned.',
        items: response.runtime_nodes.slice(0, 4).map((node) => `${node.name}: ${formatState(node.observed_state)} (desired ${formatState(node.desired_state)})`),
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
