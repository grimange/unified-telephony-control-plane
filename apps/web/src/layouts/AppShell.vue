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
        <UiFormField
          v-if="activeMemberships.length > 0"
          id="active-tenant"
          label="Active tenant"
        >
          <template #default="{ id }">
            <UiSelect
              :id="id"
              :model-value="session?.active_tenant?.tenant_id ?? ''"
              @update:model-value="selectTenant"
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
            </UiSelect>
          </template>
        </UiFormField>
        <UiFormField
          id="appearance"
          label="Appearance"
        >
          <template #default="{ id }">
            <UiSelect
              :id="id"
              :model-value="currentAppearancePreference"
              aria-label="Appearance"
              @update:model-value="updateAppearance"
            >
              <option value="system">
                System
              </option>
              <option value="light">
                Light
              </option>
              <option value="dark">
                Dark
              </option>
            </UiSelect>
          </template>
        </UiFormField>
        <UiButton
          class="compact-nav-toggle"
          variant="secondary"
          type="button"
          aria-controls="primary-navigation"
          :aria-expanded="navigationOpen"
          @click="navigationOpen = !navigationOpen"
        >
          Menu
        </UiButton>
        <UiButton
          type="button"
          @click="logout"
        >
          Log out
        </UiButton>
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
        >
          <RouterLink
            v-for="entry in navigation"
            :key="entry.route"
            :to="entry.route"
            :class="{ active: isActive(entry.route, entry.exact) }"
            :aria-current="isActive(entry.route, entry.exact) ? 'page' : undefined"
            @click="navigationOpen = false"
            @keydown.esc="navigationOpen = false"
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
        <RouterView />
      </section>
    </div>
  </main>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import UiButton from '../components/ui/UiButton.vue'
import UiFormField from '../components/ui/UiFormField.vue'
import UiSelect from '../components/ui/UiSelect.vue'
import {
  activeMemberships,
  clearOneTimeSignalingCredential,
  endSession,
  navigation,
  session,
  switchTenant,
} from '../state/appState'
import {
  currentAppearancePreference,
  isAppearancePreference,
  setAppearancePreference,
  type AppearancePreference,
} from '../state/theme'

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

function updateAppearance(preference: string | number): void {
  const nextPreference: AppearancePreference = isAppearancePreference(preference) ? preference : 'system'
  setAppearancePreference(nextPreference)
}

async function selectTenant(tenantId: string | number): Promise<void> {
  await switchTenant(String(tenantId))
  if (route.name === 'admin-user-detail') {
    await router.push('/admin/users')
  }
}

watch(() => route.fullPath, () => {
  clearOneTimeSignalingCredential()
  navigationOpen.value = false
})
</script>
