export const RECOVERY_RETRY_DELAYS_MS = [1_000, 2_000, 3_000, 5_000, 8_000, 10_000] as const
export const RECOVERY_RETRY_MAX_INDEX = RECOVERY_RETRY_DELAYS_MS.length - 1
export const RECOVERY_RETRY_JITTER_RATIO = 0.2
export const RECOVERY_REQUEST_TIMEOUT_MS = 10_000
export const CONNECTIVITY_DEBOUNCE_MS = 1_000

export function recoveryRetryBaseDelay(retryIndex: number): number {
  return RECOVERY_RETRY_DELAYS_MS[Math.min(Math.max(retryIndex, 0), RECOVERY_RETRY_MAX_INDEX)]
}

export function recoveryRetryDelay(
  retryIndex: number,
  random: () => number = Math.random,
): number {
  const base = recoveryRetryBaseDelay(retryIndex)
  const jitter = (random() * 2 - 1) * RECOVERY_RETRY_JITTER_RATIO

  return Math.round(base * (1 + jitter))
}
