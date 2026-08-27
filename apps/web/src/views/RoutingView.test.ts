import { describe, expect, it } from 'vitest'
import routingSource from './RoutingView.vue?raw'
import callerIdentitySource from './CallerIdentitiesView.vue?raw'
import platformSource from '../api/platform.ts?raw'
import type { IdentitySession } from '../api/platform'
import { navigationEntries, visibleNavigationEntries } from '../navigation'

const session = (capabilities: string[]): IdentitySession => ({
  user: { id: 'user-1', email: 'operator@example.test', display_name: 'Operator', status: 'active', password_change_required: false },
  capabilities,
  active_tenant: { tenant_id: 'tenant-1', slug: 'tenant', display_name: 'Tenant' },
  memberships: [],
  catalog_version: 'test',
  expires_at: '2099-01-01T00:00:00Z',
})

describe('UI-4 routing operator surface', () => {
  it('exposes routing navigation only with routing view and keeps caller identities under C7A authorization', () => {
    expect(navigationEntries.find((entry) => entry.route === '/routing/routes')?.requiredCapability).toBe('telephony.routing.view')
    expect(navigationEntries.find((entry) => entry.route === '/routing/caller-identities')?.requiredCapability).toBe('telephony.external_connectivity.view')
    expect(visibleNavigationEntries(session(['telephony.routing.view'])).map((entry) => entry.route)).toContain('/routing/routes')
    expect(visibleNavigationEntries(session(['telephony.routing.view'])).map((entry) => entry.route)).not.toContain('/routing/caller-identities')
    expect(visibleNavigationEntries(session(['telephony.external_connectivity.view'])).map((entry) => entry.route)).toContain('/routing/caller-identities')
  })

  it('uses canonical route resources and lifecycle actions without RouteDecision or reconciliation controls', () => {
    expect(routingSource).toContain('Inbound')
    expect(routingSource).toContain('Outbound')
    expect(routingSource).toContain('Lower numbers are considered first.')
    expect(routingSource).toContain('destination_ref')
    expect(routingSource).toContain("'active'")
    expect(routingSource).toContain("'disabled'")
    expect(routingSource).toContain("'retired'")
    expect(routingSource).not.toContain('RouteDecision')
    expect(routingSource).not.toMatch(/Reconcile|Sync Routes|Apply Routes|Push to Kamailio/)
    expect(platformSource).toContain("'/api/v1/admin/inbound-routes'")
    expect(platformSource).toContain("'/api/v1/admin/outbound-routes'")
    expect(platformSource).toContain('/desired-state')
  })

  it('keeps references resilient and filters selectors using canonical association facts', () => {
    expect(routingSource).toContain('Address unavailable')
    expect(routingSource).toContain('External Trunk unavailable')
    expect(routingSource).toContain("association.direction===direction.value || association.direction==='both'")
    expect(routingSource).toContain('Caller identity selected at call time')
    expect(routingSource).toContain('tenantContextVersion')
  })

  it('surfaces caller identity lifecycle and policy creation without a removal authority', () => {
    expect(callerIdentitySource).toContain('Authorized External Trunks')
    expect(callerIdentitySource).toContain('Authorize')
    expect(callerIdentitySource).toContain("'retired'")
    expect(callerIdentitySource).toContain('tenantContextVersion')
    expect(callerIdentitySource).not.toMatch(/Remove policy|Delete policy|Disable policy/)
    expect(platformSource).toContain("'/api/v1/admin/caller-identities'")
    expect(platformSource).toContain('/policies')
    expect(callerIdentitySource).not.toMatch(/secret|password|credential/i)
  })
})
