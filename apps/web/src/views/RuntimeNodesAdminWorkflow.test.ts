import { describe, expect, it } from 'vitest'
import runtimeNodesViewSource from './RuntimeNodesView.vue?raw'
import platformSource from '../api/platform.ts?raw'
import appStateSource from '../state/appState.ts?raw'

describe('RNM-5 RuntimeNode Admin workflow contract', () => {
  it('presents the lifecycle as state-aware management actions', () => {
    expect(runtimeNodesViewSource).toContain("node.desired_state === 'draining'")
    expect(runtimeNodesViewSource).toContain("node.desired_state === 'drained'")
    expect(runtimeNodesViewSource).toContain("node.desired_state !== 'retired'")
    expect(runtimeNodesViewSource).toContain('Cancel drain')
    expect(runtimeNodesViewSource).toContain('Decommission')
    expect(runtimeNodesViewSource).toContain('Load more history')
  })

  it('keeps infrastructure details out of the normal edit payload', () => {
    expect(appStateSource).toContain('placement_region')
    expect(appStateSource).toContain('placement_zone')
    expect(appStateSource).toContain('capacity_weight')
    expect(appStateSource).not.toContain('kubernetes_workload')
    expect(runtimeNodesViewSource).toContain('Register a runtime whose infrastructure is managed outside UTCP.')
  })

  it('uses canonical endpoint, node, history and credential contracts', () => {
    expect(platformSource).toContain('updateRuntimeNode:')
    expect(platformSource).toContain('updateRuntimeEndpoint:')
    expect(platformSource).toContain('before?: string | null')
    expect(appStateSource).toContain('credential_type: credentialForm.type')
    expect(appStateSource).toContain('loadMoreRuntimeHistory')
  })

  it('keeps observed capability evidence read-only and separates drift', () => {
    expect(runtimeNodesViewSource).toContain('Capability evidence')
    expect(runtimeNodesViewSource).toContain('declared_not_observed')
    expect(runtimeNodesViewSource).toContain('observed_not_declared')
    expect(runtimeNodesViewSource).toContain('Not yet observed')
    expect(runtimeNodesViewSource).toContain('Secrets are write-only')
  })

  it('normalizes the adapter from the catalog when the runtime family changes', () => {
    expect(appStateSource).toContain('normalizeRuntimeNodeAdapter')
    expect(appStateSource).toContain('runtimeNodeForm.adapterKey = adapters.length === 1 ? adapters[0].key : \'\'')
    expect(appStateSource).toContain('watch(\n  () => runtimeNodeForm.runtimeFamily')
    expect(runtimeNodesViewSource).toContain('Select an adapter')
  })

  it('sends the existing credential type when rotating a write-only secret', () => {
    expect(appStateSource).toContain('credential_type: credentialType')
    expect(runtimeNodesViewSource).toContain('rotateRuntimeCredential(node.id, credential.id, credential.type)')
    expect(appStateSource).toContain("['sec' + 'ret']: nextSecret")
  })

  it('forces expanded evidence refresh after explicit refresh, reopen, and lifecycle changes', () => {
    expect(runtimeNodesViewSource).toContain('@click="load(true)"')
    expect(runtimeNodesViewSource).toContain('loadRuntimeNodeDetails(node, true)')
    expect(runtimeNodesViewSource).toContain('reloadRuntimeNodeDetails(runtimeNodeId)')
    expect(appStateSource).toContain('await reloadRuntimeNodeDetails(runtimeNodeId)')
  })
})
