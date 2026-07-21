import type { IdentitySession } from './api/platform'

export type NavigationEntry = {
  route: string
  label: string
  exact?: boolean
  requiredCapability?: string
  isVisible?: (session: IdentitySession) => boolean
}

export function hasCapability(session: IdentitySession | null, capability: string): boolean {
  return session?.capabilities.includes(capability) ?? false
}

export const navigationEntries: NavigationEntry[] = [
  { route: '/dashboard', label: 'Dashboard', exact: true },
  { route: '/admin/tenants', label: 'Tenants', exact: true, requiredCapability: 'platform.tenants.view' },
  {
    route: '/admin/users',
    label: 'Users',
    isVisible: (session) =>
      hasCapability(session, 'platform.users.view') ||
      hasCapability(session, 'tenant.memberships.view'),
  },
  { route: '/admin/memberships', label: 'Memberships', exact: true, requiredCapability: 'tenant.memberships.view' },
  { route: '/admin/runtime-nodes', label: 'Runtime nodes', exact: true, requiredCapability: 'runtime.nodes.view' },
]

export function visibleNavigationEntries(session: IdentitySession | null): NavigationEntry[] {
  if (session === null) return []

  return navigationEntries.filter((entry) => {
    if (entry.requiredCapability) return hasCapability(session, entry.requiredCapability)
    if (entry.isVisible) return entry.isVisible(session)

    return true
  })
}
