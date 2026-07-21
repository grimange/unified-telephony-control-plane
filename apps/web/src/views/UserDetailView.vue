<template>
  <section
    class="workspace"
    aria-labelledby="user-detail-title"
  >
    <div class="section-heading">
      <h2 id="user-detail-title">
        User detail
      </h2>
      <span class="row-actions">
        <RouterLink to="/admin/users">
          Back to users
        </RouterLink>
        <button
          type="button"
          @click="load"
        >
          Refresh
        </button>
      </span>
    </div>
    <p
      v-if="!selectedUserDetail"
      class="meta"
      role="status"
      aria-live="polite"
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
            @click="run(endSelectedTelephonySession)"
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
              @click="run(issueSelectedSignalingCredential)"
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
</template>

<script setup lang="ts">
import { onMounted, watch, watchEffect } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import {
  can,
  closeOneTimeSignalingCredential,
  credentialState,
  displayValue,
  endSelectedTelephonySession,
  fail,
  issueCredentialButton,
  issueSelectedSignalingCredential,
  oneTimeSecretPanel,
  oneTimeSignalingCredential,
  refreshSelectedUser,
  selectedUserDetail,
  shortId,
  signalingLifecycleText,
  signalingSecretVisible,
  tenantContextVersion,
} from '../state/appState'

const route = useRoute()

watchEffect(() => {
  void issueCredentialButton.value
  void oneTimeSecretPanel.value
})

async function run(action: () => Promise<void>): Promise<void> {
  try {
    await action()
  } catch (errorValue) {
    fail(errorValue)
  }
}

async function load(): Promise<void> {
  const userId = String(route.params.id ?? '')
  if (userId === '') return
  await run(() => refreshSelectedUser(userId))
}

onMounted(load)
watch(() => route.params.id, load)
watch(tenantContextVersion, load)
</script>
