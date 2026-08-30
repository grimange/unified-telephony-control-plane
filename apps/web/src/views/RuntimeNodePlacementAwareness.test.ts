import { describe, expect, it } from 'vitest'
import runtimeNodesViewSource from './RuntimeNodesView.vue?raw'
import platformSource from '../api/platform.ts?raw'
import appStateSource from '../state/appState.ts?raw'

describe('K5B RuntimeNode placement awareness contract', () => {
  it('loads observed placement through the authenticated read-only API', () => {
    expect(platformSource).toContain("runtimeNodePlacement: (runtimeNodeId: string)")
    expect(platformSource).toContain('/placement')
    expect(appStateSource).toContain('runtimeNodePlacements')
    expect(appStateSource).toContain('identityApi.runtimeNodePlacement(node.id)')
  })

  it('renders observed host and topology facts without placement controls', () => {
    expect(runtimeNodesViewSource).toContain('Current host placement is observed from Kubernetes and is read-only.')
    expect(runtimeNodesViewSource).toContain('Host status')
    expect(runtimeNodesViewSource).toContain('topology.kubernetes.io/zone')
    expect(runtimeNodesViewSource).toContain('topology.kubernetes.io/region')
    expect(runtimeNodesViewSource).toContain('Co-resident RuntimeNodes')
    expect(runtimeNodesViewSource).not.toContain('Move Runtime')
    expect(runtimeNodesViewSource).not.toContain('Assign Host')
    expect(runtimeNodesViewSource).not.toContain('Drain Host')
  })

  it('keeps placement uncertainty explicit', () => {
    expect(runtimeNodesViewSource).toContain('no_managed_kubernetes_identity')
    expect(runtimeNodesViewSource).toContain('identity_present_but_not_currently_observed')
    expect(runtimeNodesViewSource).toContain('ambiguous_multiple_nodes_observed')
    expect(runtimeNodesViewSource).toContain('Kubernetes placement is currently unavailable.')
  })
})
