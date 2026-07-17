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
          v-if="canViewUsers"
          type="button"
          :class="{ active: route.startsWith('/admin/users') }"
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
          class="inline-form"
          role="search"
          @submit.prevent="applyUserFilters"
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
              <button
                type="button"
                @click="goUserDetail(user.id)"
              >
                Details
              </button>
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
            @click="goToUserPage(userFilters.page - 1)"
          >
            Previous
          </button>
          <p class="meta">
            Page {{ userPagination.page }} · {{ userPagination.total }} users
          </p>
          <button
            type="button"
            :disabled="!userPagination.has_more"
            @click="goToUserPage(userFilters.page + 1)"
          >
            Next
          </button>
        </div>
      </section>

      <section
        v-else-if="route.startsWith('/admin/users/')"
        class="workspace"
        aria-labelledby="user-detail-title"
      >
        <div class="section-heading">
          <h2 id="user-detail-title">
            User detail
          </h2>
          <span class="row-actions">
            <button
              type="button"
              @click="go('/admin/users')"
            >
              Back to users
            </button>
            <button
              type="button"
              @click="refreshSelectedUser"
            >
              Refresh
            </button>
          </span>
        </div>
        <p
          v-if="!selectedUserDetail"
          class="meta"
        >
          Loading user detail.
        </p>
        <div
          v-else
          class="detail-stack"
        >
          <section
            class="detail-section"
            aria-labelledby="user-account-title"
          >
            <h3 id="user-account-title">
              Account
            </h3>
            <dl class="definition-grid">
              <dt>Display name</dt>
              <dd>{{ selectedUserDetail.user.display_name }}</dd>
              <dt>Email</dt>
              <dd>{{ selectedUserDetail.user.email }}</dd>
              <dt>Account status</dt>
              <dd>{{ selectedUserDetail.user.status }}</dd>
              <dt>Forced password change</dt>
              <dd>{{ selectedUserDetail.user.password_change_required ? 'required' : 'not required' }}</dd>
              <dt>Created</dt>
              <dd>{{ selectedUserDetail.user.created_at }}</dd>
              <dt>Updated</dt>
              <dd>{{ selectedUserDetail.user.updated_at }}</dd>
            </dl>
          </section>

          <section
            class="detail-section"
            aria-labelledby="user-memberships-title"
          >
            <h3 id="user-memberships-title">
              Tenant memberships
            </h3>
            <div
              v-for="membership in selectedUserDetail.memberships"
              :key="membership.id"
              class="data-row"
            >
              <span>
                <strong>{{ membership.tenant_display_name }}</strong>
                <small>{{ membership.tenant_slug }} · {{ membership.status }} · roles {{ membership.roles.join(', ') || 'none' }}</small>
              </span>
            </div>
          </section>

          <section
            class="detail-section"
            aria-labelledby="user-roles-title"
          >
            <h3 id="user-roles-title">
              Roles and capabilities
            </h3>
            <p class="meta">
              Platform roles: {{ selectedUserDetail.platform_roles.join(', ') || 'None' }}
            </p>
            <p class="meta">
              Platform capabilities: {{ selectedUserDetail.effective_capabilities.platform.join(', ') || 'None' }}
            </p>
            <p class="meta">
              Active tenant capabilities: {{ selectedUserDetail.effective_capabilities.tenant.join(', ') || 'None' }}
            </p>
          </section>

          <section
            class="detail-section"
            aria-labelledby="telephony-session-title"
          >
            <h3 id="telephony-session-title">
              Active TelephonySession
            </h3>
            <p
              v-if="!selectedUserDetail.active_telephony_session"
              class="meta"
            >
              No active TelephonySession. Signaling registration is unavailable.
            </p>
            <div v-else>
              <dl class="definition-grid">
                <dt>Session</dt>
                <dd>{{ shortId(selectedUserDetail.active_telephony_session.id) }}</dd>
                <dt>Status</dt>
                <dd>{{ selectedUserDetail.active_telephony_session.status }}</dd>
                <dt>Issued</dt>
                <dd>{{ selectedUserDetail.active_telephony_session.issued_at }}</dd>
                <dt>Expiry</dt>
                <dd>{{ selectedUserDetail.active_telephony_session.expires_at }}</dd>
                <dt>Ended</dt>
                <dd>{{ displayValue(selectedUserDetail.active_telephony_session.ended_at) }}</dd>
              </dl>
              <button
                v-if="can('telephony.sessions.manage') && selectedUserDetail.active_telephony_session.status === 'active'"
                type="button"
                @click="endSelectedTelephonySession"
              >
                End TelephonySession
              </button>

              <section
                class="detail-section nested"
                aria-labelledby="signaling-registration-title"
              >
                <h4 id="signaling-registration-title">
                  Signaling registration
                </h4>
                <p
                  v-if="!selectedUserDetail.signaling"
                  class="meta"
                >
                  No signaling credential has been issued. Registration is not yet available to a SIP client.
                </p>
                <div v-else>
                  <dl class="definition-grid">
                    <dt>Signaling identity</dt>
                    <dd>{{ selectedUserDetail.signaling.signaling_identity }}</dd>
                    <dt>Credential state</dt>
                    <dd>{{ credentialState(selectedUserDetail.signaling) }}</dd>
                    <dt>Credential expiry</dt>
                    <dd>{{ displayValue(selectedUserDetail.signaling.credential?.expires_at) }}</dd>
                    <dt>Desired registration state</dt>
                    <dd>{{ selectedUserDetail.signaling.registration.desired_state }}</dd>
                    <dt>Observed runtime state</dt>
                    <dd>{{ selectedUserDetail.signaling.registration.observed_state }}</dd>
                    <dt>Latest registration event</dt>
                    <dd>{{ displayValue(selectedUserDetail.signaling.registration.last_event_type) }}</dd>
                    <dt>Latest observation</dt>
                    <dd>{{ displayValue(selectedUserDetail.signaling.registration.observed_at) }}</dd>
                    <dt>Observed Contact expiry</dt>
                    <dd>{{ displayValue(selectedUserDetail.signaling.registration.observed_expires_at) }}</dd>
                    <dt>Pending removal</dt>
                    <dd>{{ selectedUserDetail.signaling.registration.pending_removal ? 'yes' : 'no' }}</dd>
                    <dt>Reconciliation state</dt>
                    <dd>{{ displayValue(selectedUserDetail.signaling.registration.reconciliation_status) }}</dd>
                    <dt>Reconciliation reason</dt>
                    <dd>{{ displayValue(selectedUserDetail.signaling.registration.reconciliation_reason) }}</dd>
                  </dl>
                  <p class="meta">
                    {{ signalingLifecycleText(selectedUserDetail.signaling) }}
                  </p>
                </div>
                <button
                  v-if="can('telephony.signaling.manage') && selectedUserDetail.active_telephony_session?.status === 'active'"
                  ref="issueCredentialButton"
                  type="button"
                  @click="issueSelectedSignalingCredential"
                >
                  {{ selectedUserDetail.signaling?.credential ? 'Reissue signaling credential' : 'Issue signaling credential' }}
                </button>
                <section
                  v-if="oneTimeSignalingCredential"
                  ref="oneTimeSecretPanel"
                  class="one-time-secret"
                  role="status"
                  aria-live="polite"
                  aria-labelledby="one-time-signaling-title"
                  tabindex="-1"
                >
                  <h4 id="one-time-signaling-title">
                    Temporary SIP credential issued
                  </h4>
                  <p class="meta">
                    This temporary SIP secret cannot be retrieved again. Reissuing invalidates the previous credential.
                  </p>
                  <dl class="definition-grid">
                    <dt>SIP username</dt>
                    <dd>{{ oneTimeSignalingCredential.username }}</dd>
                    <dt>Realm</dt>
                    <dd>{{ oneTimeSignalingCredential.realm }}</dd>
                    <dt>WSS URI</dt>
                    <dd>{{ oneTimeSignalingCredential.wss_uri }}</dd>
                    <dt>Expiry</dt>
                    <dd>{{ oneTimeSignalingCredential.expires_at }}</dd>
                    <dt>Temporary SIP secret</dt>
                    <dd>
                      <code>{{ signalingSecretVisible ? oneTimeSignalingCredential.sip_secret : 'hidden' }}</code>
                      <button
                        type="button"
                        @click="signalingSecretVisible = !signalingSecretVisible"
                      >
                        {{ signalingSecretVisible ? 'Hide secret' : 'Reveal secret' }}
                      </button>
                    </dd>
                  </dl>
                  <button
                    type="button"
                    @click="closeOneTimeSignalingCredential"
                  >
                    Close credential
                  </button>
                </section>
              </section>
            </div>
          </section>
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
            <option
              v-for="family in runtimeFamilyOptions"
              :key="family.key"
              :value="family.key"
            >
              {{ family.label }}
            </option>
          </select>
          <select v-model="runtimeNodeForm.adapterKey">
            <option
              v-for="adapter in adapterOptionsFor(runtimeNodeForm.runtimeFamily)"
              :key="adapter.key"
              :value="adapter.key"
            >
              {{ adapter.label }}
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
                  v-for="capability in capabilityOptionsFor(node)"
                  :key="capability"
                  class="check-label"
                >
                  <input
                    v-model="runtimeCapabilitySelections[node.id]"
                    type="checkbox"
                    :value="capability"
                  >
                  {{ capabilityLabel(capability) }}
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
                  <button
                    v-if="can('runtime.credentials.rotate') && canRetireCredential(node, credential)"
                    type="button"
                    @click="retireRuntimeCredential(node.id, credential.id)"
                  >
                    Retire
                  </button>
                </p>
                <p class="meta">
                  Secrets are write-only and cannot be retrieved after submission.
                </p>
              </div>
              <form
                v-if="can('runtime.nodes.manage') && adapterConfigurationSupported(node) && node.adapter_key === 'asterisk-ari'"
                class="inline-form"
                @submit.prevent="saveAsteriskAdapterConfiguration(node.id)"
              >
                <input
                  v-model="asteriskConfigurationForm(node.id).application_name"
                  placeholder="ARI application name"
                  required
                >
                <input
                  v-model.number="asteriskConfigurationForm(node.id).connect_timeout_ms"
                  aria-label="Connect timeout"
                  type="number"
                  min="250"
                  required
                >
                <input
                  v-model.number="asteriskConfigurationForm(node.id).request_timeout_ms"
                  aria-label="Request timeout"
                  type="number"
                  min="250"
                  required
                >
                <input
                  v-model.number="asteriskConfigurationForm(node.id).websocket_handshake_timeout_ms"
                  aria-label="WebSocket handshake timeout"
                  type="number"
                  min="250"
                  required
                >
                <input
                  v-model.number="asteriskConfigurationForm(node.id).heartbeat_interval_ms"
                  aria-label="Heartbeat interval"
                  type="number"
                  min="1000"
                  required
                >
                <input
                  v-model.number="asteriskConfigurationForm(node.id).reconnect_min_delay_ms"
                  aria-label="Minimum reconnect delay"
                  type="number"
                  min="100"
                  required
                >
                <input
                  v-model.number="asteriskConfigurationForm(node.id).reconnect_max_delay_ms"
                  aria-label="Maximum reconnect delay"
                  type="number"
                  min="100"
                  required
                >
                <button type="submit">
                  Save adapter configuration
                </button>
              </form>
              <div v-if="runtimeEvidence[node.id]">
                <strong>Runtime evidence</strong>
                <p class="meta">
                  Desired state: {{ runtimeEvidence[node.id].desired_state }} · Observed state: {{ runtimeEvidence[node.id].observed_state }}
                </p>
                <p class="meta">
                  Last observation: {{ displayValue(runtimeEvidence[node.id].observed_at) }}
                </p>
                <p class="meta">
                  Configuration generation: {{ runtimeEvidence[node.id].desired_configuration_generation }} · Observed generation: {{ displayValue(runtimeEvidence[node.id].observed_configuration_generation) }}
                </p>
                <p class="meta">
                  Event connection status: {{ runtimeEvidence[node.id].connection.state }} · Latest connection time: {{ displayValue(runtimeEvidence[node.id].connection.latest_epoch_opened_at) }} · Latest disconnect time: {{ displayValue(runtimeEvidence[node.id].connection.latest_epoch_closed_at) }}
                </p>
                <p class="meta">
                  Reconciliation state: {{ runtimeEvidence[node.id].reconciliation.state }} · Next retry: {{ displayValue(runtimeEvidence[node.id].reconciliation.next_retry_at) }}
                </p>
                <p class="meta">
                  Sanitized failure: {{ displayValue(runtimeEvidence[node.id].reconciliation.sanitized_failure_code ?? runtimeEvidence[node.id].reconciliation.sanitized_failure_class) }}
                </p>
                <p class="meta">
                  Last successful inspection: {{ displayValue(runtimeEvidence[node.id].inspection.last_success_at) }}
                </p>
              </div>
              <div v-if="runtimeHistory[node.id]?.history.length">
                <strong>History</strong>
                <p
                  v-for="entry in runtimeHistory[node.id].history"
                  :key="entry.id"
                  class="meta"
                >
                  {{ entry.timestamp }} · {{ entry.action }} · {{ entry.actor }} · {{ entry.summary }}
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
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import {
  identityApi,
  type AdminMembership,
  type AdminTenant,
  type AdminUser,
  type AdminUserDetail,
  type IdentitySession,
  type OneTimeSignalingCredential,
  type RuntimeAdapterConfiguration,
  type RuntimeEvidence,
  type RuntimeHistoryResponse,
  type RuntimeManagementCatalog,
  type RuntimeNode,
  type SignalingMetadata,
} from './api/platform'

const route = ref(window.location.pathname)
const session = ref<IdentitySession | null>(null)
const tenants = ref<AdminTenant[]>([])
const users = ref<AdminUser[]>([])
const selectedUserDetail = ref<AdminUserDetail | null>(null)
const oneTimeSignalingCredential = ref<OneTimeSignalingCredential | null>(null)
const signalingSecretVisible = ref(false)
const oneTimeSecretPanel = ref<HTMLElement | null>(null)
const issueCredentialButton = ref<HTMLButtonElement | null>(null)
const memberships = ref<AdminMembership[]>([])
const runtimeNodes = ref<RuntimeNode[]>([])
const runtimeCatalog = ref<RuntimeManagementCatalog | null>(null)
const runtimeCapabilitySelections = reactive<Record<string, string[]>>({})
const adapterConfigurations = reactive<Record<string, RuntimeAdapterConfiguration>>({})
const adapterConfigurationForms = reactive<Record<string, RuntimeAdapterConfiguration>>({})
const runtimeEvidence = reactive<Record<string, RuntimeEvidence>>({})
const runtimeHistory = reactive<Record<string, RuntimeHistoryResponse>>({})
const error = ref('')
const message = ref('')
const busy = ref(false)
const temporaryPassword = ref('')

const loginForm = reactive({ email: '', password: '' })
const passwordForm = reactive({ current: '', next: '' })
const tenantForm = reactive({ slug: '', displayName: '' })
const userForm = reactive({ email: '', displayName: '' })
const userFilters = reactive({ search: '', status: '', page: 1, perPage: 20 })
const userPagination = reactive({ page: 1, per_page: 20, total: 0, has_more: false })
const membershipForm = reactive({ userId: '', roleKey: 'tenant-member' })
const runtimeNodeForm = reactive({ name: '', slug: '', runtimeFamily: 'asterisk', adapterKey: 'asterisk-ari' })
const endpointForm = reactive({ purpose: 'control', transport: 'https', host: '', port: 8089, path: '', tlsMode: 'verify' })
const credentialForm = reactive({ type: 'control-api', identifier: '', secret: '' })

const activeMemberships = computed(() =>
  (session.value?.memberships ?? []).filter((membership) => membership.status === 'active' && membership.membership_status === 'active'),
)

const canViewUsers = computed(() => can('platform.users.view') || can('tenant.memberships.view'))

const runtimeFamilyOptions = computed(() =>
  Object.entries(runtimeCatalog.value?.runtime_families ?? {}).map(([key, family]) => ({
    key,
    label: family.display_name,
  })),
)

function can(capability: string): boolean {
  return session.value?.capabilities.includes(capability) ?? false
}

function go(path: string): void {
  clearOneTimeSignalingCredential()
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
  const details = identityApi.isApiRequestError(errorValue) ? errorValue.details : null
  if (details && typeof details === 'object' && 'message' in details && typeof details.message === 'string') {
    error.value = details.message
    return
  }
  error.value = 'The request could not be completed.'
}

function adapterOptionsFor(runtimeFamily: string): Array<{ key: string; label: string }> {
  const adapterKeys = runtimeCatalog.value?.runtime_families[runtimeFamily]?.adapters ?? []

  return adapterKeys.map((key) => ({
    key,
    label: runtimeCatalog.value?.adapter_keys[key]?.display_name ?? key,
  }))
}

function capabilityOptionsFor(node: RuntimeNode): string[] {
  return runtimeCatalog.value?.adapter_keys[node.adapter_key]?.supported_capabilities ?? []
}

function capabilityLabel(capability: string): string {
  return runtimeCatalog.value?.runtime_capabilities[capability]?.display_name ?? capability
}

function adapterConfigurationSupported(node: RuntimeNode): boolean {
  return runtimeCatalog.value?.adapter_keys[node.adapter_key]?.adapter_configuration_available ?? false
}

function displayValue(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return 'None'

  return String(value)
}

function shortId(value: string): string {
  return value.length > 12 ? `${value.slice(0, 8)}…${value.slice(-4)}` : value
}

function selectedUserId(): string | null {
  const match = route.value.match(/^\/admin\/users\/([^/]+)$/)

  return match?.[1] ?? null
}

function registrationSummary(user: AdminUser): string {
  if (!user.active_telephony_session) return 'no active TelephonySession'
  const registration = user.signaling_registration_summary
  if (!registration) return 'no signaling registration'

  return `${registration.desired_state} / ${registration.observed_state}`
}

function credentialState(signaling: SignalingMetadata): string {
  if (signaling.credential === null) return 'not issued'
  if (signaling.credential.revoked_at) return 'revoked'

  return 'issued'
}

function signalingLifecycleText(signaling: SignalingMetadata): string {
  const credential = signaling.credential
  const registration = signaling.registration
  if (registration.pending_removal) return 'Registration removed. Contact pending expiration. New registrations and refreshes are blocked.'
  if (registration.desired_state === 'removed' && ['expired', 'unregistered'].includes(registration.observed_state)) {
    return 'Registration removed. No active Contact. Reconciliation is converged when reported by the backend.'
  }
  if (registration.observed_state === 'registered') return 'Currently registered.'
  if (credential === null) return 'No signaling credential has been issued. Registration is not yet available to a SIP client.'

  return 'Registration allowed. Not currently registered. Waiting for the SIP client to register.'
}

function clearOneTimeSignalingCredential(): void {
  oneTimeSignalingCredential.value = null
  signalingSecretVisible.value = false
}

function closeOneTimeSignalingCredential(): void {
  clearOneTimeSignalingCredential()
  void nextTick(() => issueCredentialButton.value?.focus())
}

function asteriskConfigurationForm(runtimeNodeId: string): RuntimeAdapterConfiguration {
  if (!adapterConfigurationForms[runtimeNodeId]) {
    adapterConfigurationForms[runtimeNodeId] = {}
  }

  return adapterConfigurationForms[runtimeNodeId]
}

function canRetireCredential(node: RuntimeNode, credential: RuntimeNode['credentials'][number]): boolean {
  if (credential.status !== 'active') return false

  return node.credentials.filter((candidate) => candidate.type === credential.type && candidate.status === 'active').length > 1
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
  if (!route.value.startsWith('/admin/users/')) {
    selectedUserDetail.value = null
  }
  if (!session.value || route.value === '/login' || route.value === '/change-password') {
    return
  }
  if (route.value === '/admin/tenants' && can('platform.tenants.view')) {
    await refreshTenants()
  } else if (route.value === '/admin/users' && canViewUsers.value) {
    await refreshUsers()
  } else if (route.value.startsWith('/admin/users/') && canViewUsers.value) {
    await refreshSelectedUser()
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
  selectedUserDetail.value = null
  clearOneTimeSignalingCredential()
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
    selectedUserDetail.value = null
    clearOneTimeSignalingCredential()
    session.value = await identityApi.selectTenant(tenantId)
    if (route.value.startsWith('/admin/users/')) {
      go('/admin/users')
      return
    }
    await loadPageData()
  } catch (err) {
    fail(err)
  }
}

async function refreshTenants(): Promise<void> {
  tenants.value = (await identityApi.tenants()).tenants
}

async function refreshUsers(): Promise<void> {
  const response = await identityApi.users({
    search: userFilters.search,
    status: userFilters.status,
    page: userFilters.page,
    per_page: userFilters.perPage,
  })
  users.value = response.users
  Object.assign(userPagination, response.pagination ?? {
    page: userFilters.page,
    per_page: userFilters.perPage,
    total: response.users.length,
    has_more: false,
  })
}

async function goToUserPage(page: number): Promise<void> {
  if (page < 1) return
  userFilters.page = page
  await refreshUsers()
}

async function applyUserFilters(): Promise<void> {
  userFilters.page = 1
  await refreshUsers()
}

function goUserDetail(userId: string): void {
  clearOneTimeSignalingCredential()
  go(`/admin/users/${userId}`)
}

async function refreshSelectedUser(): Promise<void> {
  const userId = selectedUserId()
  if (userId === null) return
  const response = await identityApi.user(userId)
  if (selectedUserId() !== userId) return
  selectedUserDetail.value = response
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
  if (selectedUserDetail.value?.user.id === userId) {
    await refreshSelectedUser()
  }
}

async function resetPassword(userId: string): Promise<void> {
  await identityApi.resetPassword(userId)
  temporaryPassword.value = ''
  message.value = 'Temporary password reset issued.'
  await refreshUsers()
}

async function endSelectedTelephonySession(): Promise<void> {
  if (!selectedUserDetail.value?.active_telephony_session) return
  if (!window.confirm('End this TelephonySession?')) return
  clearOneTimeSignalingCredential()
  await identityApi.endUserTelephonySession(selectedUserDetail.value.user.id, selectedUserDetail.value.active_telephony_session.id)
  message.value = 'TelephonySession ended.'
  await refreshSelectedUser()
}

async function issueSelectedSignalingCredential(): Promise<void> {
  if (!selectedUserDetail.value?.active_telephony_session) return
  clearOneTimeSignalingCredential()
  const response = await identityApi.issueUserSignalingCredential(
    selectedUserDetail.value.user.id,
    selectedUserDetail.value.active_telephony_session.id,
  )
  oneTimeSignalingCredential.value = response.credential
  signalingSecretVisible.value = false
  message.value = 'Temporary SIP credential issued.'
  await nextTick()
  oneTimeSecretPanel.value?.focus()
  await refreshSelectedUser()
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
  runtimeCatalog.value = (await identityApi.runtimeNodeCatalog()).catalog
  const adapterOptions = adapterOptionsFor(runtimeNodeForm.runtimeFamily)
  if (adapterOptions.length > 0 && !adapterOptions.some((adapter) => adapter.key === runtimeNodeForm.adapterKey)) {
    runtimeNodeForm.adapterKey = adapterOptions[0].key
  }
  runtimeNodes.value = (await identityApi.runtimeNodes()).runtime_nodes
  runtimeNodes.value.forEach((node) => {
    runtimeCapabilitySelections[node.id] = [...node.capabilities]
  })
  await Promise.all(runtimeNodes.value.map((node) => loadRuntimeNodeDetails(node)))
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
  await identityApi.setRuntimeCapabilities(runtimeNodeId, runtimeCapabilitySelections[runtimeNodeId] ?? [])
  await refreshRuntimeNodes()
}

async function loadRuntimeNodeDetails(node: RuntimeNode): Promise<void> {
  const requests: Array<Promise<void>> = [
    identityApi.runtimeEvidence(node.id)
      .then((response) => {
        runtimeEvidence[node.id] = response.runtime_evidence
      })
      .catch(() => undefined),
    identityApi.runtimeHistory(node.id)
      .then((response) => {
        runtimeHistory[node.id] = response
      })
      .catch(() => undefined),
  ]

  if (adapterConfigurationSupported(node)) {
    requests.push(
      identityApi.getRuntimeAdapterConfiguration(node.id)
        .then((response) => {
          adapterConfigurations[node.id] = response.adapter_configuration
          const profile = response.adapter_configuration.profile
          const defaults = response.adapter_configuration.defaults
          adapterConfigurationForms[node.id] = {
            ...(profile && typeof profile === 'object' ? profile : {}),
            ...(!profile && defaults && typeof defaults === 'object' ? defaults : {}),
          }
        })
        .catch(() => undefined),
    )
  }

  await Promise.all(requests)
}

async function saveAsteriskAdapterConfiguration(runtimeNodeId: string): Promise<void> {
  const response = await identityApi.putRuntimeAdapterConfiguration(runtimeNodeId, asteriskConfigurationForm(runtimeNodeId))
  adapterConfigurations[runtimeNodeId] = response.adapter_configuration
  adapterConfigurationForms[runtimeNodeId] = { ...response.adapter_configuration }
  message.value = 'Adapter configuration saved.'
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

async function retireRuntimeCredential(runtimeNodeId: string, credentialId: string): Promise<void> {
  if (!window.confirm('Retire this credential?')) {
    return
  }
  await identityApi.retireRuntimeCredential(runtimeNodeId, credentialId)
  message.value = 'Credential retired.'
  await refreshRuntimeNodes()
}

watch(
  () => runtimeNodeForm.runtimeFamily,
  (runtimeFamily) => {
    const adapters = adapterOptionsFor(runtimeFamily)
    if (adapters.length > 0) {
      runtimeNodeForm.adapterKey = adapters[0].key
    }
  },
)

onMounted(async () => {
  window.addEventListener('popstate', () => {
    clearOneTimeSignalingCredential()
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
