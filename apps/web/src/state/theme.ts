import { readonly, ref } from 'vue'

export const appearanceStorageKey = 'utcp.appearance'
export const appearancePreferences = ['system', 'light', 'dark'] as const

export type AppearancePreference = typeof appearancePreferences[number]
export type ResolvedTheme = 'light' | 'dark'

const appearancePreference = ref<AppearancePreference>('system')
const resolvedTheme = ref<ResolvedTheme>('light')
let colorSchemeQuery: MediaQueryList | null = null
let colorSchemeListener: ((event: MediaQueryListEvent) => void) | null = null

export function isAppearancePreference(value: unknown): value is AppearancePreference {
  return typeof value === 'string' && appearancePreferences.includes(value as AppearancePreference)
}

export function readStoredAppearancePreference(storage: Storage = window.localStorage): AppearancePreference {
  try {
    const stored = storage.getItem(appearanceStorageKey)

    return isAppearancePreference(stored) ? stored : 'system'
  } catch {
    return 'system'
  }
}

function currentSystemTheme(): ResolvedTheme {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return 'light'

  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

export function resolveAppearancePreference(preference: AppearancePreference): ResolvedTheme {
  if (preference === 'light' || preference === 'dark') return preference

  return currentSystemTheme()
}

function applyTheme(preference: AppearancePreference): void {
  const nextResolvedTheme = resolveAppearancePreference(preference)
  appearancePreference.value = preference
  resolvedTheme.value = nextResolvedTheme
  document.documentElement.dataset.appearance = preference
  document.documentElement.dataset.theme = nextResolvedTheme
  document.documentElement.style.colorScheme = nextResolvedTheme
}

function detachSystemListener(): void {
  if (!colorSchemeQuery || !colorSchemeListener) return

  colorSchemeQuery.removeEventListener('change', colorSchemeListener)
  colorSchemeQuery = null
  colorSchemeListener = null
}

function attachSystemListener(): void {
  if (typeof window.matchMedia !== 'function') return

  colorSchemeQuery = window.matchMedia('(prefers-color-scheme: dark)')
  colorSchemeListener = () => {
    if (appearancePreference.value === 'system') applyTheme('system')
  }
  colorSchemeQuery.addEventListener('change', colorSchemeListener)
}

export function initializeAppearance(): void {
  detachSystemListener()
  applyTheme(readStoredAppearancePreference())
  attachSystemListener()
}

export function setAppearancePreference(preference: AppearancePreference): void {
  const nextPreference = isAppearancePreference(preference) ? preference : 'system'
  try {
    window.localStorage.setItem(appearanceStorageKey, nextPreference)
  } catch {
    // Appearance is local presentation state; blocked storage should not break the app.
  }
  applyTheme(nextPreference)
}

export function resetAppearanceForTests(): void {
  detachSystemListener()
  appearancePreference.value = 'system'
  resolvedTheme.value = 'light'
  document.documentElement.removeAttribute('data-appearance')
  document.documentElement.removeAttribute('data-theme')
  document.documentElement.style.colorScheme = ''
}

export const currentAppearancePreference = readonly(appearancePreference)
export const currentResolvedTheme = readonly(resolvedTheme)
