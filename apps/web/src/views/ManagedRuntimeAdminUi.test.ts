import { describe, expect, it } from 'vitest'
import runtimeNodesViewSource from './RuntimeNodesView.vue?raw'
import { managedDeprovisioningLabel, managedProvisioningLabel, runtimeNodePrimaryStatus } from './runtimeNodeManagementPresentation'
import { catalogOptions } from './runtimeCatalogPresentation'
import { managedRuntimeOptions } from './runtimeManagedOptions'
import platformSource from '../api/platform.ts?raw'
import type { RuntimeManagementCatalog, RuntimeOperationStatus } from '../api/platform'

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

  const catalog = (adapterKeys: RuntimeManagementCatalog['adapter_keys']): RuntimeManagementCatalog => ({
    catalog_version: 'test',
    runtime_families: {
      asterisk: { display_name: 'Asterisk', description: null, adapters: adapterKeys['asterisk-ari'] ? ['asterisk-ari'] : [] },
      freeswitch: { display_name: 'FreeSWITCH', description: null, adapters: adapterKeys['freeswitch-esl'] ? ['freeswitch-esl'] : [] },
      simulator: { display_name: 'Deterministic simulator', description: null, adapters: adapterKeys['simulator-deterministic'] ? ['simulator-deterministic'] : [] },
    },
    adapter_keys: adapterKeys,
    runtime_capabilities: {},
    desired_states: {},
    endpoint_purposes: {},
    endpoint_transports: [],
    endpoint_tls_modes: [],
  })

  const adapter = (runtimeFamily: string, displayName: string, credentialsRequired: boolean) => ({
    runtime_family: runtimeFamily,
    display_name: displayName,
    description: null,
    supported_capabilities: [],
    required_capabilities: [],
    endpoint_requirements: [],
    credentials_required: credentialsRequired,
    adapter_configuration_available: false,
  })

  it('derives managed choices from the backend catalog and removes the Asterisk gate', () => {
    const options = managedRuntimeOptions(catalog({
      'asterisk-ari': adapter('asterisk', 'Asterisk ARI', true),
      'freeswitch-esl': adapter('freeswitch', 'FreeSWITCH ESL', true),
      'simulator-deterministic': adapter('simulator', 'Deterministic simulator', false),
    }))
    expect(options.map(({ runtimeFamily, adapterKey }) => ({ runtimeFamily, adapterKey }))).toEqual([
      { runtimeFamily: 'asterisk', adapterKey: 'asterisk-ari' },
      { runtimeFamily: 'freeswitch', adapterKey: 'freeswitch-esl' },
    ])
    expect(runtimeNodesViewSource).toContain('managedRuntimeOptions.length > 0')
    expect(runtimeNodesViewSource).toContain('managedRuntimeOptions.length > 1')
    expect(runtimeNodesViewSource).not.toContain("runtimeCatalog?.adapter_keys?.['asterisk-ari']")
    expect(runtimeNodesViewSource).not.toContain("runtimeFamily: 'asterisk'")
    expect(runtimeNodesViewSource).not.toContain("adapterKey: 'asterisk-ari'")
    expect(runtimeNodesViewSource).toContain('runtime_family: managedRuntimeForm.value.runtimeFamily')
    expect(runtimeNodesViewSource).toContain('adapter_key: managedRuntimeForm.value.adapterKey')
    expect(runtimeNodesViewSource).toContain('selectManagedRuntimeOption()')
    expect(runtimeNodesViewSource).toContain('deploymentTargetsResource')
    expect(platformSource).toContain("'/api/v1/admin/deployment-targets'")
  })

  it('handles zero, one, and FreeSWITCH-only managed catalogs deterministically', () => {
    const asterisk = adapter('asterisk', 'Asterisk ARI', true)
    const freeswitch = adapter('freeswitch', 'FreeSWITCH ESL', true)
    expect(managedRuntimeOptions(catalog({}))).toEqual([])
    expect(managedRuntimeOptions(catalog({ 'asterisk-ari': asterisk }))).toHaveLength(1)
    expect(managedRuntimeOptions(catalog({ 'freeswitch-esl': freeswitch }))).toEqual([
      expect.objectContaining({ runtimeFamily: 'freeswitch', adapterKey: 'freeswitch-esl', providerLabel: 'FreeSWITCH' }),
    ])
    expect(managedRuntimeOptions(catalog({ 'asterisk-ari': asterisk, 'freeswitch-esl': freeswitch }))).toHaveLength(2)
  })

  it('keeps the normal managed form to business intent without exposing infrastructure or credentials', () => {
    expect(runtimeNodesViewSource).toContain('selectedManagedRuntimeOption?.providerLabel')
    expect(runtimeNodesViewSource).not.toContain('Asterisk ·')
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
