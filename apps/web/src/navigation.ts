import type { IdentitySession } from './api/platform'

export type NavigationEntry = {
  route: string
  label: string
  group: 'overview' | 'external-connectivity' | 'calls' | 'telephony-infrastructure' | 'routing' | 'system'
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
  { key: 'external-connectivity', label: 'External Connectivity' },
  { key: 'calls', label: 'Calls' },
  { key: 'telephony-infrastructure', label: 'Telephony Infrastructure' },
  { key: 'routing', label: 'Routing' },
  { key: 'system', label: 'System' },
]

export function hasCapability(session: IdentitySession | null, capability: string): boolean {
  return session?.capabilities.includes(capability) ?? false
}

export const navigationEntries: NavigationEntry[] = [
  { route: '/dashboard', label: 'Dashboard', group: 'overview', exact: true },
  { route: '/calls', label: 'Calls', group: 'calls', exact: true, requiredCapability: 'telephony.calls.view', requiresActiveTenant: true },
  { route: '/operations/conferences', label: 'Conferences', group: 'calls', exact: true, requiredCapability: 'telephony.conferences.view' },
  { route: '/admin/runtime-nodes', label: 'Telephony Nodes', group: 'telephony-infrastructure', exact: true, requiredCapability: 'runtime.nodes.view' },
  { route: '/admin/tenants', label: 'Tenants', group: 'system', exact: true, requiredCapability: 'platform.tenants.view' },
  {
    route: '/admin/users',
    label: 'Users',
    group: 'system',
    isVisible: (session) =>
      hasCapability(session, 'platform.users.view') ||
      hasCapability(session, 'tenant.memberships.view'),
  },
  { route: '/admin/memberships', label: 'Memberships', group: 'system', exact: true, requiredCapability: 'tenant.memberships.view' },
  { route: '/admin/audit-records', label: 'Audit', group: 'system', exact: true, requiredCapability: 'tenant.memberships.manage', requiresActiveTenant: true },
  { route: '/operations/runtime-operations', label: 'Advanced operations', group: 'system', exact: true, requiredCapability: 'runtime.nodes.view' },
  { route: '/operations/runtime-reconciliations', label: 'Runtime reconciliations', group: 'system', exact: true, requiredCapability: 'runtime.nodes.view' },
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
