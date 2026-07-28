<template>
  <main class="app-shell">
    <UiPanel
      id="login-title"
      class="auth-panel"
      label="Access"
      title="Sign in"
    >
      <div>
        <h1>Unified Telephony Control Plane</h1>
        <p class="meta">
          Operate tenant access, telephony runtime nodes, lifecycle operations, reconciliation, and audit evidence from one control-plane workspace.
        </p>
      </div>
      <form
        class="form-stack"
        @submit.prevent="login"
      >
        <UiFormField
          id="login-email"
          label="Email"
          required
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="loginForm.email"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="username"
              type="email"
              required
            />
          </template>
        </UiFormField>
        <UiFormField
          id="login-password"
          label="Password"
          :error="error"
          required
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="loginForm.password"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="current-password"
              type="password"
              required
            />
          </template>
        </UiFormField>
        <UiAlert
          v-if="loginNotice && !error"
          variant="info"
          title="Sign in to continue"
        >
          {{ loginNotice }}
        </UiAlert>
        <UiAlert
          v-if="error"
          variant="error"
          title="Authentication failed"
        >
          {{ error }}
        </UiAlert>
        <UiLoadingState
          v-if="busy"
          label="Signing in."
        />
        <UiButton
          type="submit"
          :loading="busy"
          loading-label="Signing in"
        >
          Sign in
        </UiButton>
      </form>
    </UiPanel>
  </main>
</template>

<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router'
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiFormField from '../components/ui/UiFormField.vue'
import UiLoadingState from '../components/ui/UiLoadingState.vue'
import UiPanel from '../components/ui/UiPanel.vue'
import UiTextInput from '../components/ui/UiTextInput.vue'
import { authenticate, busy, error, loginForm, loginNotice } from '../state/appState'
import { authorizedRedirectTarget } from '../router'

const route = useRoute()
const router = useRouter()

async function login(): Promise<void> {
  const nextSession = await authenticate()
  if (nextSession === null) return
  if (nextSession.user.password_change_required) {
    await router.push({ path: '/change-password', query: { redirect: route.query.redirect } })

    return
  }

  await router.push(authorizedRedirectTarget(route.query.redirect))
}
</script>
