import { describe, expect, it } from 'vitest'
import runtimeNodesViewSource from './RuntimeNodesView.vue?raw'
import { managedDeprovisioningLabel, managedProvisioningLabel, runtimeNodePrimaryStatus } from './runtimeNodeManagementPresentation'
import { catalogOptions } from './runtimeCatalogPresentation'
import platformSource from '../api/platform.ts?raw'
import type { RuntimeOperationStatus } from '../api/platform'

const operation = (status: RuntimeOperationStatus, id: string) => ({
  id,
  status,
  failure: null,
  started_at: null,
  completed_at: null,
  updated_at: null,
})

describe('RNP-5 managed RuntimeNode Admin UI contract', () => {
  it('loads endpoint transport and TLS options from the backend catalog without a fallback list', () => {
    expect(runtimeNodesViewSource).toContain('endpointTransportOptions')
    expect(runtimeNodesViewSource).toContain('endpointTlsModeOptions')
    expect(runtimeNodesViewSource).toContain('runtimeCatalog.value?.endpoint_transports')
    expect(runtimeNodesViewSource).not.toContain('<option value="https">')
    expect(runtimeNodesViewSource).not.toContain('<option value="wss">')
    expect(runtimeNodesViewSource).not.toContain('<option value="tcp">')
    expect(runtimeNodesViewSource).not.toContain('<option value="insecure">')
    expect(runtimeNodesViewSource).not.toContain('Object.entries(catalog')
  })
  it('renders array-shaped backend transport and TLS catalog values, not array indexes', () => {
    expect(catalogOptions(['http', 'https', 'ws'])).toEqual([
      { key: 'http', label: 'http' },
      { key: 'https', label: 'https' },
      { key: 'ws', label: 'ws' },
    ])
    expect(catalogOptions(['disabled', 'required'])).toEqual([
      { key: 'disabled', label: 'disabled' },
      { key: 'required', label: 'required' },
    ])
    expect(catalogOptions(['http', 'https', 'ws']).map((option) => option.key)).not.toEqual(['0', '1', '2'])
  })
  it('keeps managed onboarding inside the existing Runtime Nodes surface', () => {
    expect(runtimeNodesViewSource).toContain('title="Add runtime"')
    expect(runtimeNodesViewSource).toContain('Create a new runtime')
    expect(runtimeNodesViewSource).toContain('Register an existing runtime')
    expect(runtimeNodesViewSource).not.toContain('Managed Runtimes')
  })

  it('offers only the canonical Asterisk managed runtime and backend targets', () => {
    expect(runtimeNodesViewSource).toContain("runtimeCatalog?.adapter_keys?.['asterisk-ari']")
    expect(runtimeNodesViewSource).toContain('deploymentTargetsResource')
    expect(runtimeNodesViewSource).not.toContain('FreeSWITCH')
    expect(platformSource).toContain("'/api/v1/admin/deployment-targets'")
  })

  it('keeps the normal managed form to business intent without exposing infrastructure or credentials', () => {
    expect(runtimeNodesViewSource).toContain('Asterisk ·')
    expect(runtimeNodesViewSource).toContain('UTCP will configure credentials, endpoints, and infrastructure automatically.')
    expect(runtimeNodesViewSource).not.toContain('Review generated configuration')
    expect(runtimeNodesViewSource).not.toContain('managed-runtime-slug')
    expect(runtimeNodesViewSource).not.toContain('id="managed-runtime-slug"')
    expect(runtimeNodesViewSource).not.toContain('ARI password')
    expect(runtimeNodesViewSource).not.toContain('Secret YAML')
    expect(runtimeNodesViewSource).not.toContain('kubeconfig')
  })

  it('submits one canonical provisioning request and derives progress from the node projection', () => {
    expect(platformSource).toContain('createRuntimeProvisioning:')
    expect(platformSource).toContain("'/api/v1/admin/runtime-provisioning'")
    expect(runtimeNodesViewSource).toContain('runtimeActionSubmitting(managedProvisionActionKey)')
    expect(runtimeNodesViewSource).toContain('managedProvisioningLabel(node)')
    expect(runtimeNodesViewSource).toContain("runtimeManagement(node).mode === 'managed'")
    expect(runtimeNodesViewSource).not.toContain('Start Provisioning')
    expect(runtimeNodesViewSource).not.toContain('Destroy Infrastructure')
  })

  it('keeps generated managed configuration read-only while preserving the external form', () => {
    expect(runtimeNodesViewSource).toContain("runtimeManagement(node).mode !== 'managed'")
    expect(runtimeNodesViewSource).toContain('Register a runtime whose infrastructure is managed outside UTCP.')
    expect(runtimeNodesViewSource).toContain('Advanced diagnostics')
  })

  it('maps canonical provisioning statuses while preserving readiness as a separate authority', () => {
    expect(managedProvisioningLabel({
      observed_state: 'unavailable',
      management: { provisioning: operation('succeeded', 'provision-1'), deprovisioning: null },
    })).toBe('Starting')
    expect(managedProvisioningLabel({
      observed_state: 'ready',
      management: { provisioning: operation('succeeded', 'provision-2'), deprovisioning: null },
    })).toBe('Ready')
    expect(managedProvisioningLabel({
      observed_state: 'unobserved',
      management: { provisioning: operation('terminal_failed', 'provision-3'), deprovisioning: null },
    })).toBe('Needs attention')
    expect(managedProvisioningLabel({
      observed_state: 'unobserved',
      management: { provisioning: operation('expired', 'provision-4'), deprovisioning: null },
    })).toBe('Needs attention')
  })

  it('maps canonical deprovisioning terminal statuses', () => {
    expect(managedDeprovisioningLabel({
      observed_state: 'retired',
      management: { provisioning: null, deprovisioning: operation('succeeded', 'deprovision-1') },
    })).toBe('Deprovisioned')
    expect(managedDeprovisioningLabel({
      observed_state: 'retired',
      management: { provisioning: null, deprovisioning: operation('terminal_failed', 'deprovision-2') },
    })).toBe('Needs attention')
  })

  it('derives one primary human status from canonical state', () => {
    expect(runtimeNodePrimaryStatus({ desired_state: 'active', observed_state: 'unobserved', management: { provisioning: operation('succeeded', 'provision-5'), deprovisioning: null } })).toBe('Starting')
    expect(runtimeNodePrimaryStatus({ desired_state: 'active', observed_state: 'ready', management: { provisioning: operation('succeeded', 'provision-6'), deprovisioning: null } })).toBe('Ready')
    expect(runtimeNodePrimaryStatus({ desired_state: 'active', observed_state: 'unavailable', management: { provisioning: operation('succeeded', 'provision-7'), deprovisioning: null } })).toBe('Needs attention')
    expect(runtimeNodePrimaryStatus({ desired_state: 'drained', observed_state: 'unavailable', management: null })).toBe('Out of service')
    expect(runtimeNodePrimaryStatus({ desired_state: 'retired', observed_state: 'retired', management: { provisioning: null, deprovisioning: operation('succeeded', 'deprovision-3') } })).toBe('Retired')
  })
})
