import type { RuntimeManagedOperation, RuntimeOperationStatus } from '../api/platform'

export type ManagedRuntimeStatusNode = {
  desired_state?: string
  observed_state: string
  management?: {
    provisioning: RuntimeManagedOperation | null
    deprovisioning: RuntimeManagedOperation | null
  } | null
}

const inProgressStatuses: RuntimeOperationStatus[] = ['pending', 'leased', 'running', 'retry_scheduled']

function managedOperation(node: ManagedRuntimeStatusNode) {
  return node.management ?? {
    provisioning: null,
    deprovisioning: null,
  }
}

export function managedProvisioningLabel(node: ManagedRuntimeStatusNode): string {
  const operation = managedOperation(node).provisioning
  if (!operation) return node.desired_state === 'draft' ? 'Creating' : 'Starting'
  if (operation.status === 'succeeded') return node.observed_state === 'ready' ? 'Ready' : 'Starting'
  if (operation.status === 'terminal_failed' || operation.status === 'expired' || operation.status === 'cancelled') return 'Needs attention'
  if (inProgressStatuses.includes(operation.status)) return 'Creating'

  return 'Creating'
}

export function managedDeprovisioningLabel(node: ManagedRuntimeStatusNode): string {
  const operation = managedOperation(node).deprovisioning
  if (!operation) return 'Not started'
  if (operation.status === 'succeeded') return 'Deprovisioned'
  if (operation.status === 'terminal_failed' || operation.status === 'expired' || operation.status === 'cancelled') return 'Needs attention'
  if (inProgressStatuses.includes(operation.status)) return 'Taking out of service'

  return 'Taking out of service'
}

export function runtimeNodePrimaryStatus(node: ManagedRuntimeStatusNode): string {
  if (node.desired_state === 'retired') return 'Retired'
  if (node.desired_state === 'draining') return 'Taking out of service'
  if (node.desired_state === 'drained' || node.desired_state === 'disabled') return 'Out of service'
  if (node.observed_state === 'ready') return 'Ready'
  if (node.observed_state === 'unavailable' || node.observed_state === 'degraded') return 'Needs attention'

  return managedProvisioningLabel(node)
}
