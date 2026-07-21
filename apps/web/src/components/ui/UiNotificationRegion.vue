<template>
  <section
    v-if="appNotifications.length > 0"
    class="notification-region"
    aria-label="Notifications"
  >
    <article
      v-for="notification in appNotifications"
      :id="notification.id"
      :key="notification.id"
      class="ui-notification"
      :class="`ui-notification--${notification.variant}`"
      :role="notification.variant === 'error' ? 'alert' : 'status'"
      :aria-live="notification.variant === 'error' ? 'assertive' : 'polite'"
    >
      <div class="ui-notification__header">
        <strong>{{ notification.title }}</strong>
        <UiButton
          v-if="notification.dismissible"
          type="button"
          variant="ghost"
          :aria-label="`Dismiss ${notification.title}`"
          @click="dismissNotification(notification.id)"
        >
          Dismiss
        </UiButton>
      </div>
      <p>{{ notification.message }}</p>
    </article>
  </section>
</template>

<script setup lang="ts">
import UiButton from './UiButton.vue'
import { appNotifications, dismissNotification } from '../../state/notifications'
</script>
