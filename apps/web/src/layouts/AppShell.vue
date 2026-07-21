<template>
  <main class="app-shell app-shell--wide">
    <header class="topbar app-topbar">
      <div>
        <p class="eyebrow">
          Unified Telephony Control Plane
        </p>
        <h1>{{ pageTitle }}</h1>
        <p class="meta">
          {{ session?.user.display_name }} · {{ session?.user.email }}
        </p>
      </div>
      <div class="topbar-actions">
        <label v-if="activeMemberships.length > 0">
          Active tenant
          <select
            :value="session?.active_tenant?.tenant_id ?? ''"
            @change="selectTenant"
          >
            <option value="">
              No tenant selected
            </option>
            <option
              v-for="membership in activeMemberships"
              :key="membership.tenant_id"
              :value="membership.tenant_id"
            >
              {{ membership.display_name }}
            </option>
          </select>
        </label>
        <button
          class="compact-nav-toggle"
          type="button"
          aria-controls="primary-navigation"
          :aria-expanded="navigationOpen"
          @click="navigationOpen = !navigationOpen"
        >
          Menu
        </button>
        <button
          type="button"
          @click="logout"
        >
          Log out
        </button>
      </div>
    </header>

    <div class="shell-grid">
      <aside
        id="primary-navigation"
        class="shell-sidebar"
        :class="{ open: navigationOpen }"
      >
        <p class="panel-label">
          Navigation
        </p>
        <nav
          class="side-nav"
          aria-label="Primary"
          @keydown.esc="navigationOpen = false"
        >
          <RouterLink
            v-for="entry in navigation"
            :key="entry.route"
            :to="entry.route"
            :class="{ active: isActive(entry.route, entry.exact) }"
            :aria-current="isActive(entry.route, entry.exact) ? 'page' : undefined"
            @click="navigationOpen = false"
          >
            {{ entry.label }}
          </RouterLink>
        </nav>
        <div class="tenant-context">
          <strong>Tenant context</strong>
          <p class="meta">
            {{ session?.active_tenant?.display_name ?? 'No tenant selected' }}
          </p>
          <p class="meta">
            Catalog {{ session?.catalog_version ?? 'Unavailable' }}
          </p>
        </div>
      </aside>

      <section class="shell-content">
        <p
          v-if="error"
          class="form-error"
          role="alert"
        >
          {{ error }}
        </p>
        <p
          v-if="message"
          class="form-success"
          role="status"
        >
          {{ message }}
        </p>
        <RouterView />
      </section>
    </div>
  </main>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  activeMemberships,
  clearOneTimeSignalingCredential,
  endSession,
  error,
  message,
  navigation,
  session,
  switchTenant,
} from '../state/appState'

const route = useRoute()
const router = useRouter()
const navigationOpen = ref(false)
const pageTitle = computed(() => String(route.meta.title ?? 'Dashboard'))

function isActive(path: string, exact = false): boolean {
  if (exact) return route.path === path

  return route.path === path || route.path.startsWith(`${path}/`)
}

async function logout(): Promise<void> {
  await endSession()
  await router.push('/login')
}

async function selectTenant(event: Event): Promise<void> {
  const tenantId = (event.target as HTMLSelectElement).value
  await switchTenant(tenantId)
  if (route.name === 'admin-user-detail') {
    await router.push('/admin/users')
  }
}

watch(() => route.fullPath, () => {
  clearOneTimeSignalingCredential()
  navigationOpen.value = false
})
</script>
