import { computed, ref } from 'vue'

export type NotificationVariant = 'success' | 'information' | 'warning' | 'error'

export type AppNotification = {
  id: string
  variant: NotificationVariant
  title: string
  message: string
  dismissible: boolean
}

type NotificationInput = {
  variant: NotificationVariant
  title: string
  message: string
  dismissible?: boolean
  autoExpireMs?: number
  sensitiveValues?: string[]
}

const notifications = ref<AppNotification[]>([])
const timers = new Map<string, ReturnType<typeof setTimeout>>()
let sequence = 0

export const appNotifications = computed(() => notifications.value)

function nextNotificationId(): string {
  sequence += 1

  return `notification-${sequence}`
}

function scrubSensitiveValues(text: string, sensitiveValues: string[] = []): string {
  return sensitiveValues.reduce((nextText, sensitiveValue) => {
    if (!sensitiveValue) return nextText

    return nextText.split(sensitiveValue).join('[redacted]')
  }, text)
}

export function notify(input: NotificationInput): string {
  const id = nextNotificationId()
  const notification: AppNotification = {
    id,
    variant: input.variant,
    title: scrubSensitiveValues(input.title, input.sensitiveValues),
    message: scrubSensitiveValues(input.message, input.sensitiveValues),
    dismissible: input.dismissible ?? true,
  }

  notifications.value = [notification, ...notifications.value]

  if ((input.variant === 'success' || input.variant === 'information') && input.autoExpireMs !== 0) {
    timers.set(id, setTimeout(() => dismissNotification(id), input.autoExpireMs ?? 5000))
  }

  return id
}

export function dismissNotification(id: string): void {
  const timer = timers.get(id)
  if (timer) clearTimeout(timer)
  timers.delete(id)
  notifications.value = notifications.value.filter((notification) => notification.id !== id)
}

export function clearNotifications(): void {
  for (const timer of timers.values()) clearTimeout(timer)
  timers.clear()
  notifications.value = []
}

export function resetNotificationsForTests(): void {
  clearNotifications()
  sequence = 0
}
