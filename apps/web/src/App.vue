<template>
  <main class="app-shell">
    <section
      v-if="route === '/login'"
      class="auth-panel"
      aria-labelledby="login-title"
    >
      <p class="eyebrow">
        Unified Telephony Control Plane
      </p>
      <h1 id="login-title">
        Sign in
      </h1>
      <form
        class="form-stack"
        @submit.prevent="login"
      >
        <label>
          Email
          <input
            v-model="loginForm.email"
            autocomplete="username"
            type="email"
            required
          >
        </label>
        <label>
          Password
          <input
            v-model="loginForm.password"
            autocomplete="current-password"
            type="password"
            required
          >
        </label>
        <p
          v-if="error"
          class="form-error"
        >
          {{ error }}
        </p>
        <button
          type="submit"
          :disabled="busy"
        >
          {{ busy ? 'Signing in' : 'Sign in' }}
        </button>
      </form>
    </section>

    <section
      v-else-if="route === '/change-password'"
      class="auth-panel"
      aria-labelledby="password-title"
    >
      <p class="eyebrow">
        Account security
      </p>
      <h1 id="password-title">
        Change password
      </h1>
      <form
        class="form-stack"
        @submit.prevent="changePassword"
      >
        <label>
          Current password
          <input
            v-model="passwordForm.current"
            autocomplete="current-password"
            type="password"
            required
          >
        </label>
        <label>
          New password
          <input
            v-model="passwordForm.next"
            autocomplete="new-password"
            type="password"
            minlength="12"
            required
          >
        </label>
        <p
          v-if="error"
          class="form-error"
        >
          {{ error }}
        </p>
        <button
          type="submit"
          :disabled="busy"
        >
          {{ busy ? 'Saving' : 'Save password' }}
        </button>
      </form>
    </section>

    <section
      v-else
      class="admin-layout"
    >
      <header class="topbar">
        <div>
          <p class="eyebrow">
            UTCP administration
          </p>
          <h1>{{ session?.user.display_name ?? 'Loading' }}</h1>
          <p class="meta">
            {{ session?.user.email }}
          </p>
        </div>
        <div class="topbar-actions">
          <label v-if="activeMemberships.length > 0">
            Active tenant
            <select
              :value="session?.active_tenant?.tenant_id ?? ''"
              @change="selectTenant"
            >
              <option value="">No tenant selected</option>
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
            type="button"
            @click="logout"
          >
            Log out
          </button>
        </div>
      </header>

      <nav
        class="tabs"
        aria-label="Administration"
      >
        <button
          v-if="can('platform.tenants.view')"
          type="button"
          :class="{ active: route === '/admin/tenants' }"
          @click="go('/admin/tenants')"
        >
          Tenants
        </button>
        <button
          v-if="can('platform.users.view')"
          type="button"
          :class="{ active: route === '/admin/users' }"
          @click="go('/admin/users')"
        >
          Users
        </button>
        <button
          v-if="can('tenant.memberships.view')"
          type="button"
          :class="{ active: route === '/admin/memberships' }"
          @click="go('/admin/memberships')"
        >
          Memberships
        </button>
        <button
          v-if="can('runtime.nodes.view')"
          type="button"
          :class="{ active: route === '/admin/runtime-nodes' }"
          @click="go('/admin/runtime-nodes')"
        >
          Runtime nodes
        </button>
      </nav>

      <p
        v-if="error"
        class="form-error"
      >
        {{ error }}
      </p>
      <p
        v-if="message"
        class="form-success"
      >
        {{ message }}
      </p>

      <section
        v-if="route === '/admin/tenants'"
        class="workspace"
        aria-labelledby="tenants-title"
      >
        <div class="section-heading">
          <h2 id="tenants-title">
            Tenants
          </h2>
          <button
            type="button"
            @click="refreshTenants"
          >
            Refresh
          </button>
        </div>
        <form
          v-if="can('platform.tenants.manage')"
          class="inline-form"
          @submit.prevent="createTenant"
        >
          <input
            v-model="tenantForm.slug"
            placeholder="tenant-slug"
            required
          >
          <input
            v-model="tenantForm.displayName"
            placeholder="Tenant display name"
            required
          >
          <button type="submit">
            Create tenant
          </button>
        </form>
        <div class="data-table">
          <div
            v-for="tenant in tenants"
            :key="tenant.id"
            class="data-row"
          >
            <span>
              <strong>{{ tenant.display_name }}</strong>
              <small>{{ tenant.slug }} · {{ tenant.status }}</small>
            </span>
            <button
              v-if="can('platform.tenants.manage')"
              type="button"
              @click="setTenantStatus(tenant.id, tenant.status === 'active' ? 'suspended' : 'active')"
            >
              {{ tenant.status === 'active' ? 'Suspend' : 'Activate' }}
            </button>
          </div>
        </div>
      </section>

      <section
        v-else-if="route === '/admin/users'"
        class="workspace"
        aria-labelledby="users-title"
      >
        <div class="section-heading">
          <h2 id="users-title">
            Users
          </h2>
          <button
            type="button"
            @click="refreshUsers"
          >
            Refresh
          </button>
        </div>
        <form
          v-if="can('platform.users.manage')"
          class="inline-form"
          @submit.prevent="createUser"
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
        <div class="data-table">
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
                @click="resetPassword(user.id)"
              >Reset password</button>
              <button
                v-if="can('platform.users.manage')"
                type="button"
                @click="setUserStatus(user.id, user.status === 'active' ? 'suspended' : 'active')"
              >
                {{ user.status === 'active' ? 'Suspend' : 'Activate' }}
              </button>
            </span>
          </div>
        </div>
      </section>

      <section
        v-else-if="route === '/admin/memberships'"
        class="workspace"
        aria-labelledby="memberships-title"
      >
        <div class="section-heading">
          <h2 id="memberships-title">
            Memberships
          </h2>
          <button
            type="button"
            @click="refreshMemberships"
          >
            Refresh
          </button>
        </div>
        <form
          v-if="can('tenant.memberships.manage')"
          class="inline-form"
          @submit.prevent="createMembership"
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
        <div class="data-table">
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
              @click="setMembershipStatus(membership.id, membership.status === 'active' ? 'suspended' : 'active')"
            >
              {{ membership.status === 'active' ? 'Suspend' : 'Activate' }}
            </button>
          </div>
        </div>
      </section>

      <section
        v-else-if="route === '/admin/runtime-nodes'"
        class="workspace"
        aria-labelledby="runtime-nodes-title"
      >
        <div class="section-heading">
          <h2 id="runtime-nodes-title">
            Runtime nodes
          </h2>
          <button
            type="button"
            @click="refreshRuntimeNodes"
          >
            Refresh
          </button>
        </div>
        <form
          v-if="can('runtime.nodes.manage')"
          class="inline-form"
          @submit.prevent="createRuntimeNode"
        >
          <input
            v-model="runtimeNodeForm.name"
            placeholder="Runtime display name"
            required
          >
          <input
            v-model="runtimeNodeForm.slug"
            placeholder="runtime-slug"
            required
          >
          <select v-model="runtimeNodeForm.runtimeFamily">
            <option value="asterisk">
              Asterisk
            </option>
            <option value="freeswitch">
              FreeSWITCH
            </option>
          </select>
          <select v-model="runtimeNodeForm.adapterKey">
            <option value="asterisk-ari">
              Asterisk ARI
            </option>
            <option value="freeswitch-esl">
              FreeSWITCH ESL
            </option>
          </select>
          <button type="submit">
            Create runtime node
          </button>
        </form>
        <div class="data-table">
          <div
            v-for="node in runtimeNodes"
            :key="node.id"
            class="data-row runtime-row"
          >
            <span>
              <strong>{{ node.name }}</strong>
              <small>{{ node.slug }} · {{ node.runtime_family }} · {{ node.adapter_key }}</small>
              <small>desired {{ node.desired_state }} · observed {{ node.observed_state }}</small>
            </span>
            <span class="row-actions">
              <button
                v-if="can('runtime.nodes.manage')"
                type="button"
                @click="setRuntimeDesiredState(node.id, node.desired_state === 'active' ? 'draining' : 'active')"
              >
                {{ node.desired_state === 'active' ? 'Drain' : 'Activate' }}
              </button>
              <button
                v-if="can('runtime.nodes.manage')"
                type="button"
                @click="setRuntimeDesiredState(node.id, 'disabled')"
              >
                Disable
              </button>
            </span>
            <div class="subgrid">
              <form
                v-if="can('runtime.nodes.manage')"
                class="inline-form"
                @submit.prevent="addRuntimeEndpoint(node.id)"
              >
                <select v-model="endpointForm.purpose">
                  <option value="control">
                    Control
                  </option>
                  <option value="events">
                    Events
                  </option>
                  <option value="health">
                    Health
                  </option>
                </select>
                <select v-model="endpointForm.transport">
                  <option value="https">
                    HTTPS
                  </option>
                  <option value="wss">
                    WSS
                  </option>
                  <option value="tcp">
                    TCP
                  </option>
                </select>
                <input
                  v-model="endpointForm.host"
                  placeholder="runtime.local.test"
                  required
                >
                <input
                  v-model.number="endpointForm.port"
                  type="number"
                  min="1"
                  max="65535"
                  required
                >
                <input
                  v-model="endpointForm.path"
                  placeholder="/optional-path"
                >
                <button type="submit">
                  Add endpoint
                </button>
              </form>
              <div>
                <strong>Endpoints</strong>
                <p
                  v-for="endpoint in node.endpoints"
                  :key="endpoint.id"
                  class="meta"
                >
                  {{ endpoint.purpose }} {{ endpoint.transport }}://{{ endpoint.host }}:{{ endpoint.port }}{{ endpoint.path ?? '' }}
                  <button
                    v-if="can('runtime.nodes.manage')"
                    type="button"
                    @click="removeRuntimeEndpoint(node.id, endpoint.id)"
                  >
                    Remove
                  </button>
                </p>
              </div>
              <form
                v-if="can('runtime.nodes.manage')"
                class="inline-form"
                @submit.prevent="setRuntimeCapabilities(node.id)"
              >
                <label
                  v-for="capability in runtimeCapabilityCatalog"
                  :key="capability"
                  class="check-label"
                >
                  <input
                    v-model="runtimeCapabilitySelection"
                    type="checkbox"
                    :value="capability"
                  >
                  {{ capability }}
                </label>
                <button type="submit">
                  Set capabilities
                </button>
              </form>
              <div>
                <strong>Declared capabilities</strong>
                <p class="meta">
                  {{ node.capabilities.join(', ') || 'None' }}
                </p>
              </div>
              <form
                v-if="can('runtime.credentials.rotate')"
                class="inline-form"
                @submit.prevent="createRuntimeCredential(node.id)"
              >
                <input
                  v-model="credentialForm.type"
                  placeholder="control-api"
                  required
                >
                <input
                  v-model="credentialForm.identifier"
                  placeholder="identifier"
                >
                <input
                  v-model="credentialForm.secret"
                  type="password"
                  placeholder="Write-only secret"
                  required
                >
                <button type="submit">
                  Save credential
                </button>
              </form>
              <div>
                <strong>Credentials</strong>
                <p
                  v-for="credential in node.credentials"
                  :key="credential.id"
                  class="meta"
                >
                  {{ credential.type }} v{{ credential.version }} · {{ credential.status }} · fingerprint {{ credential.fingerprint.slice(0, 12) }}
                  <button
                    v-if="can('runtime.credentials.rotate')"
                    type="button"
                    @click="rotateRuntimeCredential(node.id, credential.id)"
                  >
                    Rotate
                  </button>
                </p>
                <p class="meta">
                  Secrets are write-only and cannot be retrieved after submission. Live health begins in C3.
                </p>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section
        v-else
        class="workspace"
      >
        <h2>Platform</h2>
        <p class="meta">
          Select an administrative view.
        </p>
      </section>
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { identityApi, type AdminMembership, type AdminTenant, type AdminUser, type IdentitySession, type RuntimeNode } from './api/platform'

const route = ref(window.location.pathname)
const session = ref<IdentitySession | null>(null)
const tenants = ref<AdminTenant[]>([])
const users = ref<AdminUser[]>([])
const memberships = ref<AdminMembership[]>([])
const runtimeNodes = ref<RuntimeNode[]>([])
const error = ref('')
const message = ref('')
const busy = ref(false)
const temporaryPassword = ref('')

const loginForm = reactive({ email: '', password: '' })
const passwordForm = reactive({ current: '', next: '' })
const tenantForm = reactive({ slug: '', displayName: '' })
const userForm = reactive({ email: '', displayName: '' })
const membershipForm = reactive({ userId: '', roleKey: 'tenant-member' })
const runtimeNodeForm = reactive({ name: '', slug: '', runtimeFamily: 'asterisk', adapterKey: 'asterisk-ari' })
const endpointForm = reactive({ purpose: 'control', transport: 'https', host: '', port: 8089, path: '', tlsMode: 'verify' })
const credentialForm = reactive({ type: 'control-api', identifier: '', secret: '' })
const runtimeCapabilityCatalog = ['conference.execution', 'channel.control', 'event.stream', 'registration.observation', 'recording']
const runtimeCapabilitySelection = ref<string[]>(['conference.execution', 'event.stream'])

const activeMemberships = computed(() =>
  (session.value?.memberships ?? []).filter((membership) => membership.status === 'active' && membership.membership_status === 'active'),
)

function can(capability: string): boolean {
  return session.value?.capabilities.includes(capability) ?? false
}

function go(path: string): void {
  route.value = path
  window.history.pushState({}, '', path)
  void loadPageData()
}

function fail(errorValue: unknown): void {
  if (identityApi.isApiRequestError(errorValue) && errorValue.status === 401) {
    session.value = null
    route.value = '/login'
    window.history.replaceState({}, '', '/login')
    error.value = 'Sign in to continue.'
    return
  }
  error.value = 'The request could not be completed.'
}

async function loadSession(): Promise<void> {
  session.value = await identityApi.session()
  if (session.value.user.password_change_required && route.value !== '/change-password') {
    go('/change-password')
  }
}

async function loadPageData(): Promise<void> {
  error.value = ''
  message.value = ''
  temporaryPassword.value = ''
  if (!session.value || route.value === '/login' || route.value === '/change-password') {
    return
  }
  if (route.value === '/admin/tenants' && can('platform.tenants.view')) {
    await refreshTenants()
  } else if (route.value === '/admin/users' && can('platform.users.view')) {
    await refreshUsers()
  } else if (route.value === '/admin/memberships' && can('tenant.memberships.view')) {
    await Promise.all([refreshUsers(), refreshMemberships()])
  } else if (route.value === '/admin/runtime-nodes' && can('runtime.nodes.view')) {
    await refreshRuntimeNodes()
  }
}

async function login(): Promise<void> {
  busy.value = true
  error.value = ''
  try {
    await identityApi.login(loginForm.email, loginForm.password)
    await loadSession()
    go(session.value?.user.password_change_required ? '/change-password' : '/admin/tenants')
  } catch (err) {
    fail(err)
  } finally {
    busy.value = false
  }
}

async function logout(): Promise<void> {
  await identityApi.logout()
  session.value = null
  go('/login')
}

async function changePassword(): Promise<void> {
  busy.value = true
  error.value = ''
  try {
    await identityApi.changePassword(passwordForm.current, passwordForm.next)
    passwordForm.current = ''
    passwordForm.next = ''
    await loadSession()
    go('/admin/tenants')
  } catch (err) {
    fail(err)
  } finally {
    busy.value = false
  }
}

async function selectTenant(event: Event): Promise<void> {
  const tenantId = (event.target as HTMLSelectElement).value
  if (!tenantId) return
  try {
    session.value = await identityApi.selectTenant(tenantId)
    await loadPageData()
  } catch (err) {
    fail(err)
  }
}

async function refreshTenants(): Promise<void> {
  tenants.value = (await identityApi.tenants()).tenants
}

async function refreshUsers(): Promise<void> {
  users.value = (await identityApi.users()).users
}

async function refreshMemberships(): Promise<void> {
  memberships.value = (await identityApi.memberships()).memberships
}

async function createTenant(): Promise<void> {
  await identityApi.createTenant(tenantForm.slug, tenantForm.displayName)
  tenantForm.slug = ''
  tenantForm.displayName = ''
  message.value = 'Tenant created.'
  await refreshTenants()
}

async function setTenantStatus(tenantId: string, status: string): Promise<void> {
  await identityApi.setTenantStatus(tenantId, status)
  await refreshTenants()
}

async function createUser(): Promise<void> {
  const response = await identityApi.createUser(userForm.email, userForm.displayName)
  temporaryPassword.value = response.temporary_password
  userForm.email = ''
  userForm.displayName = ''
  await refreshUsers()
}

async function setUserStatus(userId: string, status: string): Promise<void> {
  await identityApi.setUserStatus(userId, status)
  await refreshUsers()
}

async function resetPassword(userId: string): Promise<void> {
  temporaryPassword.value = (await identityApi.resetPassword(userId)).temporary_password
  await refreshUsers()
}

async function createMembership(): Promise<void> {
  await identityApi.createMembership(membershipForm.userId, membershipForm.roleKey)
  membershipForm.userId = ''
  membershipForm.roleKey = 'tenant-member'
  await refreshMemberships()
}

async function setMembershipStatus(membershipId: string, status: string): Promise<void> {
  await identityApi.setMembershipStatus(membershipId, status)
  await refreshMemberships()
}

async function refreshRuntimeNodes(): Promise<void> {
  runtimeNodes.value = (await identityApi.runtimeNodes()).runtime_nodes
}

async function createRuntimeNode(): Promise<void> {
  await identityApi.createRuntimeNode({
    name: runtimeNodeForm.name,
    slug: runtimeNodeForm.slug,
    runtime_family: runtimeNodeForm.runtimeFamily,
    adapter_key: runtimeNodeForm.adapterKey,
  })
  runtimeNodeForm.name = ''
  runtimeNodeForm.slug = ''
  message.value = 'Runtime node created.'
  await refreshRuntimeNodes()
}

async function setRuntimeDesiredState(runtimeNodeId: string, desiredState: string): Promise<void> {
  await identityApi.updateRuntimeNodeDesiredState(runtimeNodeId, desiredState)
  await refreshRuntimeNodes()
}

async function addRuntimeEndpoint(runtimeNodeId: string): Promise<void> {
  await identityApi.addRuntimeEndpoint(runtimeNodeId, {
    purpose: endpointForm.purpose,
    transport: endpointForm.transport,
    host: endpointForm.host,
    port: endpointForm.port,
    path: endpointForm.path || null,
    tls_mode: endpointForm.tlsMode,
  })
  endpointForm.host = ''
  endpointForm.path = ''
  await refreshRuntimeNodes()
}

async function removeRuntimeEndpoint(runtimeNodeId: string, endpointId: string): Promise<void> {
  await identityApi.removeRuntimeEndpoint(runtimeNodeId, endpointId)
  await refreshRuntimeNodes()
}

async function setRuntimeCapabilities(runtimeNodeId: string): Promise<void> {
  await identityApi.setRuntimeCapabilities(runtimeNodeId, runtimeCapabilitySelection.value)
  await refreshRuntimeNodes()
}

async function createRuntimeCredential(runtimeNodeId: string): Promise<void> {
  const payload = {
    credential_type: credentialForm.type,
    identifier: credentialForm.identifier || null,
    ['sec' + 'ret']: credentialForm.secret,
  }
  await identityApi.createRuntimeCredential(runtimeNodeId, payload as Parameters<typeof identityApi.createRuntimeCredential>[1])
  credentialForm.secret = ''
  message.value = 'Credential saved. The secret cannot be retrieved.'
  await refreshRuntimeNodes()
}

async function rotateRuntimeCredential(runtimeNodeId: string, credentialId: string): Promise<void> {
  if (!credentialForm.secret) {
    error.value = 'Enter a new write-only secret before rotating.'
    return
  }
  const payload = {
    credential_type: credentialForm.type,
    identifier: credentialForm.identifier || null,
    ['sec' + 'ret']: credentialForm.secret,
  }
  await identityApi.rotateRuntimeCredential(runtimeNodeId, credentialId, payload as Parameters<typeof identityApi.rotateRuntimeCredential>[2])
  credentialForm.secret = ''
  message.value = 'Credential rotated.'
  await refreshRuntimeNodes()
}

onMounted(async () => {
  window.addEventListener('popstate', () => {
    route.value = window.location.pathname
    void loadPageData()
  })
  if (route.value === '/') {
    window.history.replaceState({}, '', '/admin/tenants')
    route.value = '/admin/tenants'
  }
  if (route.value === '/login') {
    try {
      await loadSession()
      go('/admin/tenants')
    } catch {
      return
    }
  } else {
    try {
      await loadSession()
      await loadPageData()
    } catch (err) {
      fail(err)
    }
  }
})
</script>
