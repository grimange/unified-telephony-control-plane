<template>
  <main class="app-shell">
    <UiPanel
      id="password-title"
      class="auth-panel"
      label="Account security"
      title="Change password"
    >
      <div>
        <h1>Secure your UTCP account</h1>
        <p class="meta">
          Set a new password before entering the UTCP control plane.
        </p>
      </div>
      <form
        class="form-stack"
        @submit.prevent="changePassword"
      >
        <UiFormField
          id="current-password"
          label="Current password"
          required
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="passwordForm.current"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="current-password"
              type="password"
              required
            />
          </template>
        </UiFormField>
        <UiFormField
          id="new-password"
          label="New password"
          help="Server validation supplies the current password requirements."
          required
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="passwordForm.next"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="new-password"
              type="password"
              minlength="12"
              required
            />
          </template>
        </UiFormField>
        <UiFormField
          id="confirm-password"
          label="Confirm new password"
          :error="error"
          required
        >
          <template #default="{ id, describedBy, invalid }">
            <UiTextInput
              :id="id"
              v-model="passwordForm.confirm"
              :aria-describedby="describedBy"
              :invalid="invalid"
              autocomplete="new-password"
              type="password"
              minlength="12"
              required
            />
          </template>
        </UiFormField>
        <UiAlert
          v-if="error"
          variant="error"
          title="Password change failed"
        >
          {{ error }}
        </UiAlert>
        <UiAlert
          v-if="!error && !busy"
          variant="info"
          title="Password lifecycle"
        >
          Successful changes refresh the authenticated session before redirecting.
        </UiAlert>
        <UiLoadingState
          v-if="busy"
          label="Saving password."
        />
        <UiButton
          type="submit"
          :loading="busy"
          loading-label="Saving"
        >
          Save password
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
import { authorizedRedirectTarget } from '../router'
import { busy, error, passwordForm, savePasswordChange } from '../state/appState'

const route = useRoute()
const router = useRouter()

async function changePassword(): Promise<void> {
  const nextSession = await savePasswordChange()
  if (nextSession === null) return

  await router.push(authorizedRedirectTarget(route.query.redirect))
}
</script>
