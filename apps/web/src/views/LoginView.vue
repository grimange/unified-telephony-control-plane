<template>
  <main class="app-shell">
    <section
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
          role="alert"
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
  </main>
</template>

<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router'
import { authenticate, busy, error, loginForm } from '../state/appState'
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
