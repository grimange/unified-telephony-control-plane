import type { IdentitySession } from './api/platform'

export type NavigationEntry = {
  route: string
  label: string
  group: 'overview' | 'access-tenancy' | 'runtime-control' | 'evidence'
  exact?: boolean
  requiredCapability?: string
  requiresActiveTenant?: boolean
  isVisible?: (session: IdentitySession) => boolean
}

export type NavigationGroup = {
  key: NavigationEntry['group']
  label: string
  entries: NavigationEntry[]
}

const navigationGroups: Array<{ key: NavigationEntry['group']; label: string }> = [
  { key: 'overview', label: 'Overview' },
  { key: 'access-tenancy', label: 'Access and tenancy' },
  { key: 'runtime-control', label: 'Runtime control' },
  { key: 'evidence', label: 'Evidence' },
]

export function hasCapability(session: IdentitySession | null, capability: string): boolean {
  return session?.capabilities.includes(capability) ?? false
}

export const navigationEntries: NavigationEntry[] = [
  { route: '/dashboard', label: 'Dashboard', group: 'overview', exact: true },
  { route: '/admin/tenants', label: 'Tenants', group: 'access-tenancy', exact: true, requiredCapability: 'platform.tenants.view' },
  {
    route: '/admin/users',
    label: 'Users',
    group: 'access-tenancy',
    isVisible: (session) =>
      hasCapability(session, 'platform.users.view') ||
      hasCapability(session, 'tenant.memberships.view'),
  },
  { route: '/admin/memberships', label: 'Memberships', group: 'access-tenancy', exact: true, requiredCapability: 'tenant.memberships.view' },
  { route: '/admin/audit-records', label: 'Audit records', group: 'evidence', exact: true, requiredCapability: 'tenant.memberships.manage', requiresActiveTenant: true },
  { route: '/admin/runtime-nodes', label: 'Runtime nodes', group: 'runtime-control', exact: true, requiredCapability: 'runtime.nodes.view' },
  { route: '/operations/runtime-operations', label: 'Runtime operations', group: 'runtime-control', exact: true, requiredCapability: 'runtime.nodes.view' },
  { route: '/operations/runtime-reconciliations', label: 'Runtime reconciliations', group: 'runtime-control', exact: true, requiredCapability: 'runtime.nodes.view' },
  { route: '/operations/conferences', label: 'Conference operations', group: 'runtime-control', exact: true, requiredCapability: 'telephony.conferences.view' },
]

export function visibleNavigationEntries(session: IdentitySession | null): NavigationEntry[] {
  if (session === null) return []

  return navigationEntries.filter((entry) => {
    if (entry.requiresActiveTenant && session.active_tenant === null) return false
    if (entry.requiredCapability) return hasCapability(session, entry.requiredCapability)
    if (entry.isVisible) return entry.isVisible(session)

    return true
  })
}

export function visibleNavigationGroups(session: IdentitySession | null): NavigationGroup[] {
  const visibleEntries = visibleNavigationEntries(session)
  const visibleGroupKeys = [...new Set(visibleEntries.map((entry) => entry.group))]

  return visibleGroupKeys
    .map((groupKey) => navigationGroups.find((group) => group.key === groupKey))
    .filter((group): group is { key: NavigationEntry['group']; label: string } => group !== undefined)
    .map((group) => ({
      ...group,
      entries: visibleEntries.filter((entry) => entry.group === group.key),
    }))
}
