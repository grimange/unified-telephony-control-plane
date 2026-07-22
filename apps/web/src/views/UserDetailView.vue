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
        <UiButton
          type="button"
          variant="secondary"
          @click="load"
        >
          Refresh
        </UiButton>
      </span>
    </div>

    <UiLoadingState
      v-if="!selectedUserDetail"
      label="Loading user detail."
    />
    <div
      v-else
      class="detail-stack"
    >
      <UiPanel
        id="user-account-title"
        title="Account"
        label="Identity"
      >
        <dl class="definition-grid">
          <dt>Display name</dt>
          <dd>{{ selectedUserDetail.user.display_name }}</dd>
          <dt>Email</dt>
          <dd>{{ selectedUserDetail.user.email }}</dd>
          <dt>Account status</dt>
          <dd>
            <UiStatusBadge
              :label="selectedUserDetail.user.status"
              :category="accountStatusCategory(selectedUserDetail.user.status)"
            />
          </dd>
          <dt>Forced password change</dt>
          <dd>{{ selectedUserDetail.user.password_change_required ? 'required' : 'not required' }}</dd>
          <dt>Created</dt>
          <dd>{{ selectedUserDetail.user.created_at }}</dd>
          <dt>Updated</dt>
          <dd>{{ selectedUserDetail.user.updated_at }}</dd>
        </dl>
      </UiPanel>

      <UiPanel
        id="user-memberships-title"
        title="Tenant memberships"
        label="Tenant access"
      >
        <UiEmptyState
          v-if="selectedUserDetail.memberships.length === 0"
          title="No memberships"
          message="No tenant memberships were returned."
        />
        <div
          v-for="membership in selectedUserDetail.memberships"
          v-else
          :key="membership.id"
          class="data-row"
        >
          <span>
            <strong>{{ membership.tenant_display_name }}</strong>
            <small>{{ membership.tenant_slug }} · roles {{ membership.roles.join(', ') || 'none' }}</small>
            <UiStatusBadge
              :label="membership.status"
              :category="accountStatusCategory(membership.status)"
            />
          </span>
        </div>
      </UiPanel>

      <UiPanel
        id="user-roles-title"
        title="Roles and capabilities"
        label="Authorization"
      >
        <p class="meta">
          Platform roles: {{ selectedUserDetail.platform_roles.join(', ') || 'None' }}
        </p>
        <p class="meta">
          Platform capabilities: {{ selectedUserDetail.effective_capabilities.platform.join(', ') || 'None' }}
        </p>
        <p class="meta">
          Active tenant capabilities: {{ selectedUserDetail.effective_capabilities.tenant.join(', ') || 'None' }}
        </p>
      </UiPanel>

      <UiPanel
        id="telephony-session-title"
        title="Active TelephonySession"
        label="Telephony"
      >
        <UiEmptyState
          v-if="!selectedUserDetail.active_telephony_session"
          title="No active TelephonySession"
          message="Signaling registration is unavailable."
        />
        <div v-else>
          <dl class="definition-grid">
            <dt>Session</dt>
            <dd>{{ shortId(selectedUserDetail.active_telephony_session.id) }}</dd>
            <dt>Status</dt>
            <dd>
              <UiStatusBadge
                :label="selectedUserDetail.active_telephony_session.status"
                :category="telephonyStatusCategory(selectedUserDetail.active_telephony_session.status)"
              />
            </dd>
            <dt>Issued</dt>
            <dd>{{ selectedUserDetail.active_telephony_session.issued_at }}</dd>
            <dt>Expiry</dt>
            <dd>{{ selectedUserDetail.active_telephony_session.expires_at }}</dd>
            <dt>Ended</dt>
            <dd>{{ displayValue(selectedUserDetail.active_telephony_session.ended_at) }}</dd>
          </dl>
          <UiButton
            v-if="can('telephony.sessions.manage') && selectedUserDetail.active_telephony_session.status === 'active'"
            type="button"
            variant="danger"
            :disabled="detailActionSubmitting(endTelephonySessionActionKey)"
            :loading="detailActionSubmitting(endTelephonySessionActionKey)"
            @click="runDetailAction(endTelephonySessionActionKey, endSelectedTelephonySession, 'TelephonySession ended.')"
          >
            End TelephonySession
          </UiButton>

          <section
            class="detail-block nested"
            aria-labelledby="signaling-registration-title"
          >
            <p class="panel-label">
              SIP access
            </p>
            <h3 id="signaling-registration-title">
              Signaling registration
            </h3>
            <UiEmptyState
              v-if="!selectedUserDetail.signaling"
              title="No signaling credential"
              message="Registration is not yet available to a SIP client."
            />
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
              <UiAlert
                variant="info"
                title="Signaling lifecycle"
              >
                {{ signalingLifecycleText(selectedUserDetail.signaling) }}
              </UiAlert>
            </div>
            <UiButton
              v-if="can('telephony.signaling.manage') && selectedUserDetail.active_telephony_session?.status === 'active'"
              ref="issueCredentialButton"
              type="button"
              variant="secondary"
              :disabled="detailActionSubmitting(issueSignalingCredentialActionKey)"
              :loading="detailActionSubmitting(issueSignalingCredentialActionKey)"
              @click="runDetailAction(issueSignalingCredentialActionKey, issueSelectedSignalingCredential, 'Signaling credential issued.')"
            >
              {{ selectedUserDetail.signaling?.credential ? 'Reissue signaling credential' : 'Issue signaling credential' }}
            </UiButton>
            <section
              v-if="oneTimeSignalingCredential"
              ref="oneTimeSecretPanel"
              class="one-time-secret"
              role="status"
              aria-live="polite"
              aria-labelledby="one-time-signaling-title"
              tabindex="-1"
            >
              <h3 id="one-time-signaling-title">
                Temporary SIP credential issued
              </h3>
              <UiAlert
                variant="warning"
                title="One-time secret"
              >
                This temporary SIP secret cannot be retrieved again. Reissuing invalidates the previous credential.
              </UiAlert>
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
                  <UiButton
                    type="button"
                    variant="secondary"
                    @click="signalingSecretVisible = !signalingSecretVisible"
                  >
                    {{ signalingSecretVisible ? 'Hide secret' : 'Reveal secret' }}
                  </UiButton>
                </dd>
              </dl>
              <UiButton
                type="button"
                variant="secondary"
                @click="closeOneTimeSignalingCredential"
              >
                Close credential
              </UiButton>
            </section>
          </section>
        </div>
      </UiPanel>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted, watch, watchEffect } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiEmptyState from '../components/ui/UiEmptyState.vue'
import UiLoadingState from '../components/ui/UiLoadingState.vue'
import UiPanel from '../components/ui/UiPanel.vue'
import UiStatusBadge from '../components/ui/UiStatusBadge.vue'
import { useAsyncActionMap } from '../composables/asyncState'
import {
  apiErrorMessage,
  can,
  closeOneTimeSignalingCredential,
  credentialState,
  displayValue,
  endSelectedTelephonySession,
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
import { notify } from '../state/notifications'

const route = useRoute()
const detailActions = useAsyncActionMap<void>({
  getErrorMessage: apiErrorMessage,
})
const endTelephonySessionActionKey = 'user-detail:telephony-session:end'
const issueSignalingCredentialActionKey = 'user-detail:signaling-credential:issue'

function accountStatusCategory(status: string): 'success' | 'warning' | 'neutral' {
  if (status === 'active') return 'success'
  if (status === 'suspended') return 'warning'

  return 'neutral'
}

function telephonyStatusCategory(status: string): 'success' | 'warning' | 'danger' | 'neutral' {
  if (status === 'active') return 'success'
  if (['ended', 'expired'].includes(status)) return 'warning'
  if (['failed', 'revoked'].includes(status)) return 'danger'

  return 'neutral'
}

watchEffect(() => {
  void issueCredentialButton.value
  void oneTimeSecretPanel.value
})

function detailActionSubmitting(key: string): boolean {
  return detailActions.isSubmitting(key)
}

async function runDetailAction(key: string, action: () => Promise<void>, successMessage: string): Promise<void> {
  await detailActions.run(key, action)
  const state = detailActions.stateFor(key)
  if (state.status === 'succeeded') {
    notify({
      variant: 'success',
      title: 'User detail updated',
      message: successMessage,
    })
    return
  }

  if (state.status === 'failed') {
    notify({
      variant: 'error',
      title: 'User detail action failed',
      message: state.error,
    })
  }
}

async function load(): Promise<void> {
  const userId = String(route.params.id ?? '')
  if (userId === '') return
  await refreshSelectedUser(userId)
}

onMounted(load)
watch(() => route.params.id, load)
watch(tenantContextVersion, load)
</script>
