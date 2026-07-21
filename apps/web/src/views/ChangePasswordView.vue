<template>
  <main class="app-shell">
    <section
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
          role="alert"
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
  </main>
</template>

<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router'
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
