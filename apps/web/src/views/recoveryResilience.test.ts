import { describe, expect, it } from 'vitest'
import {
  CONNECTIVITY_DEBOUNCE_MS,
  RECOVERY_REQUEST_TIMEOUT_MS,
  RECOVERY_RETRY_DELAYS_MS,
  RECOVERY_RETRY_MAX_INDEX,
  RECOVERY_RETRY_JITTER_RATIO,
  recoveryRetryBaseDelay,
  recoveryRetryDelay,
} from './recoveryResilience'

describe('recovery resilience policy', () => {
  it('uses the bounded retry ladder and caps at its final delay', () => {
    expect([...RECOVERY_RETRY_DELAYS_MS]).toEqual([1000, 2000, 3000, 5000, 8000, 10000])
    expect(RECOVERY_RETRY_MAX_INDEX).toBe(RECOVERY_RETRY_DELAYS_MS.length - 1)
    expect([0, 1, 2, 3, 4, 5, 6, 99].map(recoveryRetryBaseDelay)).toEqual([
      1000, 2000, 3000, 5000, 8000, 10000, 10000, 10000,
    ])
  })

  it('keeps jitter within the fixed twenty percent bound', () => {
    expect(recoveryRetryDelay(0, () => 0)).toBe(800)
    expect(recoveryRetryDelay(0, () => 0.5)).toBe(1000)
    expect(recoveryRetryDelay(0, () => 1)).toBe(1200)
    expect(recoveryRetryDelay(5, () => 0)).toBe(8000)
    expect(RECOVERY_RETRY_JITTER_RATIO).toBe(0.2)
  })

  it('keeps timeout and connectivity debounce bounded constants', () => {
    expect(RECOVERY_REQUEST_TIMEOUT_MS).toBe(10000)
    expect(CONNECTIVITY_DEBOUNCE_MS).toBe(1000)
  })
})
