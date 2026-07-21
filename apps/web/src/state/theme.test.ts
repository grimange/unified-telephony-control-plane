import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  appearanceStorageKey,
  currentAppearancePreference,
  currentResolvedTheme,
  initializeAppearance,
  readStoredAppearancePreference,
  resetAppearanceForTests,
  setAppearancePreference,
} from './theme'

function installMatchMedia(matches: boolean) {
  let currentMatches = matches
  const listeners = new Set<(event: MediaQueryListEvent) => void>()
  const query = {
    get matches() {
      return currentMatches
    },
    media: '(prefers-color-scheme: dark)',
    onchange: null,
    addEventListener: vi.fn((_event: 'change', listener: (event: MediaQueryListEvent) => void) => {
      listeners.add(listener)
    }),
    removeEventListener: vi.fn((_event: 'change', listener: (event: MediaQueryListEvent) => void) => {
      listeners.delete(listener)
    }),
    addListener: vi.fn(),
    removeListener: vi.fn(),
    dispatchEvent: vi.fn(),
  } as unknown as MediaQueryList

  vi.stubGlobal('matchMedia', vi.fn(() => query))

  return {
    setMatches(nextMatches: boolean) {
      currentMatches = nextMatches
      for (const listener of listeners) {
        listener({ matches: nextMatches } as MediaQueryListEvent)
      }
    },
  }
}

describe('appearance theme state', () => {
  beforeEach(() => {
    window.localStorage.clear()
    resetAppearanceForTests()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    window.localStorage.clear()
    resetAppearanceForTests()
  })

  it('resolves missing, explicit, and invalid preferences deterministically', () => {
    installMatchMedia(true)

    expect(readStoredAppearancePreference()).toBe('system')

    window.localStorage.setItem(appearanceStorageKey, 'light')
    initializeAppearance()
    expect(currentAppearancePreference.value).toBe('light')
    expect(currentResolvedTheme.value).toBe('light')
    expect(document.documentElement.dataset.theme).toBe('light')

    window.localStorage.setItem(appearanceStorageKey, 'dark')
    initializeAppearance()
    expect(currentAppearancePreference.value).toBe('dark')
    expect(currentResolvedTheme.value).toBe('dark')
    expect(document.documentElement.dataset.theme).toBe('dark')

    window.localStorage.setItem(appearanceStorageKey, 'tenant-1')
    initializeAppearance()
    expect(currentAppearancePreference.value).toBe('system')
    expect(currentResolvedTheme.value).toBe('dark')
    expect(document.documentElement.dataset.appearance).toBe('system')
  })

  it('persists only the appearance preference', () => {
    installMatchMedia(false)
    initializeAppearance()

    setAppearancePreference('dark')

    expect(window.localStorage.length).toBe(1)
    expect(window.localStorage.getItem(appearanceStorageKey)).toBe('dark')
    expect(JSON.stringify(window.localStorage)).not.toContain('tenant')
    expect(JSON.stringify(window.localStorage)).not.toContain('capability')
    expect(JSON.stringify(window.localStorage)).not.toContain('secret')
    expect(JSON.stringify(window.localStorage)).not.toContain('user')
  })

  it('follows system color scheme only while preference is system', () => {
    const media = installMatchMedia(false)
    initializeAppearance()

    expect(currentAppearancePreference.value).toBe('system')
    expect(currentResolvedTheme.value).toBe('light')

    media.setMatches(true)
    expect(currentResolvedTheme.value).toBe('dark')

    setAppearancePreference('light')
    media.setMatches(true)
    expect(currentAppearancePreference.value).toBe('light')
    expect(currentResolvedTheme.value).toBe('light')

    setAppearancePreference('dark')
    media.setMatches(false)
    expect(currentAppearancePreference.value).toBe('dark')
    expect(currentResolvedTheme.value).toBe('dark')
  })
})
