import { disconnectRuntimeNodeRealtime } from '../realtime/runtimeNodeRealtime'

export type LivenessResponse = {
  status: 'ok'
  service: string
}

export type ReadinessResponse = {
  status: 'ready' | 'not_ready'
  service: string
  dependencies: Record<string, 'ok' | 'unavailable'>
}

export type VersionResponse = {
  service: string
  version: string
  commit: string
  built_at: string
}

export type PlatformApiClient = {
  getLiveness: () => Promise<LivenessResponse>
  getReadiness: () => Promise<ReadinessResponse>
  getVersion: () => Promise<VersionResponse>
}

export type IdentitySession = {
  user: {
    id: string
    email: string
    display_name: string
    status: string
    password_change_required: boolean
  }
  active_tenant: null | {
    tenant_id: string
    slug: string
    display_name: string
  }
  memberships: Array<{
    membership_id: string
    tenant_id: string
    slug: string
    display_name: string
    status: string
    membership_status: string
  }>
  capabilities: string[]
  catalog_version: string
  expires_at: string
}

export type AdminTenant = {
  id: string
  slug: string
  display_name: string
  status: string
}

export type KubernetesHost = {
  uid: string
  name: string
  ready: boolean
  conditions: Array<{ type: string; status: string; reason?: string | null }>
  addresses: Array<{ type: string; address: string }>
  capacity: Record<string, string | number>
  allocatable: Record<string, string | number>
  labels: Record<string, string>
  taints: unknown[]
  unschedulable: boolean
  workloads: Array<{ name: string; namespace: string; phase?: string; runtime_node_id?: string | null; runtime_node_name?: string | null }>
  runtime_nodes: Array<{ id: string; name: string; active_telephony_work: number }>
}

export type HostMaintenance = {
  id: string
  node_uid: string
  node_name: string
  status: string
  phase: string
  runtime_node_ids: string[]
  failure_code?: string | null
  failure_details?: string | null
  requested_at: string
  completed_at?: string | null
}

export type AdminUser = {
  id: string
  email: string
  display_name: string
  status: string
  password_change_required: boolean
  updated_at?: string
  membership_summary?: {
    total: number
    active: number
    suspended: number
  }
  role_summary?: {
    platform: string[]
    tenant: string[]
  }
  active_telephony_session?: TelephonySessionSummary | null
  signaling_registration_summary?: SignalingRegistrationMetadata | null
}

export type TelephonySessionSummary = {
  id: string
  tenant_id?: string
  status: string
  issued_at: string
  expires_at: string
  ended_at?: string | null
}

export type SignalingCredentialMetadata = {
  username: string
  realm: string
  algorithm: string
  issued_at: string
  expires_at: string
  revoked_at: string | null
  wss_uri: string
}

export type SignalingRegistrationMetadata = {
  desired_state: string
  observed_state: string
  observed_at: string | null
  observed_expires_at: string | null
  last_event_type?: string | null
  failure_class?: string | null
  pending_removal: boolean
  reconciliation_status?: string | null
  reconciliation_reason?: string | null
}

export type SignalingMetadata = {
  signaling_identity: string
  credential: SignalingCredentialMetadata | null
  registration: SignalingRegistrationMetadata
}

export type OneTimeSignalingCredential = {
  username: string
  realm: string
  algorithm: string
  sip_secret: string
  wss_uri: string
  issued_at: string
  expires_at: string
}

export type ReferenceDialerBootstrap = {
  application: 'reference-dialer'
  tenant_id: string
  telephony_session: TelephonySessionSummary | null
  signaling: SignalingMetadata | null
  conferences: Conference[]
  participation: ReferenceDialerParticipation | null
}

export type ReferenceDialerParticipation = {
  participant_id: string
  conference_id: string
  state: 'active' | 'awaiting_runtime' | 'recoverable' | 'expired' | string
  recoverable: boolean
  recoverable_until: string | null
}

export type ConferenceAdmission = {
  participant: ConferenceParticipant
  signaling_destination: string
}

export type AdminUserDetail = {
  user: AdminUser & {
    created_at: string
    last_login_at: string | null
    password_changed_at: string | null
  }
  memberships: Array<{
    id: string
    tenant_id: string
    tenant_slug: string
    tenant_display_name: string
    status: string
    roles: string[]
    created_at: string
    updated_at: string
  }>
  platform_roles: string[]
  effective_capabilities: {
    platform: string[]
    tenant: string[]
  }
  active_telephony_session: TelephonySessionSummary | null
  signaling: SignalingMetadata | null
}

export type AdminMembership = {
  id: string
  user_id: string
  email: string
  display_name: string
  status: string
}

export type RoleCatalog = {
  catalog_version: string
  roles: Record<string, { scope: string; display_name: string; capabilities: string[] }>
  capabilities: string[]
}

export type RuntimeNode = {
  id: string
  tenant_id: string
  name: string
  slug: string
  runtime_family: string
  adapter_key: string
  desired_state: string
  observed_state: string
  configuration_version: number
  placement: {
    region: string | null
    zone: string | null
    priority: number
    capacity_weight: number
    labels: Record<string, string>
  }
  endpoints: Array<{
    id: string
    purpose: string
    transport: string
    host: string
    port: number
    path: string | null
    tls_mode: string
    priority: number
    enabled: boolean
  }>
  credentials: Array<{
    id: string
    type: string
    identifier: string | null
    fingerprint: string
    version: number
    status: string
    rotated_at: string
    expires_at: string | null
  }>
  capabilities: string[]
  management?: {
    mode: 'managed' | 'external'
    provisioning_request: {
      id: string
      status: string
      deployment_target: {
        id: string
        name: string
        slug: string
        kind: string
      }
    } | null
    provisioning: RuntimeManagedOperation | null
    deprovisioning: RuntimeManagedOperation | null
  }
}

export type RuntimeNodePlacement = {
  status: 'placed' | 'no_managed_kubernetes_identity' | 'identity_present_but_not_currently_observed' | 'ambiguous_multiple_nodes_observed' | 'kubernetes_observation_unavailable'
  kubernetes_node: KubernetesHost | null
  workload: {
    namespace: string
    deployment: string
    pods: Array<{ name: string; namespace: string; node_name: string; phase?: string | null }>
  } | null
  co_resident_runtime_nodes: Array<{ id: string; name: string }>
}

export type ExternalTrunkRegistrationObservation = {
  state: string
  failure_category: string | null
  last_attempt_at: string | null
  last_success_at: string | null
  expires_at: string | null
  observed_at: string | null
  observation_version: number
}

export type ExternalTrunkEndpoint = {
  id: string
  external_trunk_id: string
  endpoint_uri: string
  signaling_mode: string
  transport: string
  authentication_mode: string
  credential_reference_id: string | null
  registration_target: string | null
  registration_realm: string | null
  registration_identity: string | null
  registration_observation: ExternalTrunkRegistrationObservation | null
  capabilities: string[]
  desired_state: string
  priority: number
}

export type ExternalTrunkCredentialReference = {
  id: string
  credential_type: string
  identifier: string | null
  version: number
  status: string
  rotated_at: string
  expires_at: string | null
}

export type ExternalTrunkAddress = {
  id: string
  type: string
  value: string
  direction: string
  desired_state: string
}

export type ExternalTrunk = {
  id: string
  tenant_id: string
  name: string
  slug: string
  description: string | null
  supported_directions: string[]
  capabilities: string[]
  desired_state: string
  observed_health: string
  observed_health_reason: string | null
  configuration_version: number
  ready: boolean
  eligible_for_future_use: boolean
  endpoints: ExternalTrunkEndpoint[]
  credential_references: ExternalTrunkCredentialReference[]
  addresses: ExternalTrunkAddress[]
}

export type TelephonyAddress = {
  id: string
  tenant_id: string
  type: string
  value: string
  desired_state: string
}

export type RouteDestinationRef = {
  type: 'telephony_address' | 'opaque' | string
  value: string
}

export type InboundRoute = {
  id: string
  tenant_id: string
  name: string
  slug: string
  external_trunk_id: string
  telephony_address_id: string
  destination_ref: RouteDestinationRef | null
  caller_identity_id: string | null
  priority: number
  desired_state: string
  direction: 'inbound'
}

export type OutboundRoute = Omit<InboundRoute, 'destination_ref' | 'direction'> & {
  direction: 'outbound'
  destination_ref: null
}

export type CallerIdentityPolicy = {
  id: string
  external_trunk_id: string
  external_trunk_name: string
  desired_state: string
}

export type CallerIdentity = {
  id: string
  tenant_id: string
  name: string
  telephony_address_id: string
  telephony_address: TelephonyAddress
  display_name: string | null
  desired_state: string
  policies: CallerIdentityPolicy[]
}

export type RuntimeManagedOperation = {
  id: string
  status: RuntimeOperationStatus
  failure: { class: string | null; code: string | null } | null
  started_at: string | null
  completed_at: string | null
  updated_at: string | null
}

export type DeploymentTarget = {
  id: string
  name: string
  slug: string
  kind: string
  configuration: Record<string, unknown>
  created_at: string
  updated_at: string
}

export type RuntimeProvisioningRequest = {
  id: string
  deployment_target_id: string
  runtime_family: string
  adapter_key: string
  requested_name: string
  requested_slug: string
  status: string
  runtime_node: RuntimeNode
  created_at: string
  updated_at: string
}

export type RuntimeManagementCatalog = {
  catalog_version: string
  runtime_families: Record<string, { display_name: string; description: string | null; adapters: string[] }>
  adapter_keys: Record<string, {
    runtime_family: string
    display_name: string
    description: string | null
    supported_capabilities: string[]
    required_capabilities: string[]
    endpoint_requirements: Array<{ purpose: string; transports: string[]; required: boolean }>
    credentials_required: boolean
    adapter_configuration_available: boolean
    adapter_configuration?: RuntimeAdapterConfigurationDescriptorCollection
  }>
  runtime_capabilities: Record<string, { display_name: string; description: string | null }>
  desired_states: Record<string, { display_name: string; description: string | null }>
  endpoint_purposes: Record<string, { display_name: string; description: string | null }>
  endpoint_transports: string[]
  endpoint_tls_modes: string[]
}

export type RuntimeAdapterConfigurationInputType = 'text' | 'integer' | 'json'

export type RuntimeAdapterConfigurationValidation = {
  min?: number
  max?: number
  step?: number
  min_length?: number
  max_length?: number
}

export type RuntimeAdapterConfigurationFieldDescriptor = {
  key: string
  label: string
  help: string
  input_type: RuntimeAdapterConfigurationInputType
  required: boolean
  read_only: boolean
  write_only: boolean
  default: unknown
  order: number
  validation?: RuntimeAdapterConfigurationValidation
}

export type RuntimeAdapterConfigurationDescriptorCollection = {
  fields: RuntimeAdapterConfigurationFieldDescriptor[]
}

export type RuntimeAdapterConfiguration = Record<string, unknown>

export type RuntimeEvidence = {
  desired_state: string
  observed_state: string
  observed_at: string | null
  desired_configuration_generation: number
  observed_configuration_generation: number | null
  listener: {
    status: string
    lease_freshness: string
    last_claimed_at: string | null
    last_renewed_at: string | null
  }
  connection: {
    state: string
    latest_epoch_opened_at: string | null
    latest_epoch_closed_at: string | null
    latest_event_at: string | null
    latest_disconnect_class: string | null
  }
  reconciliation: {
    state: string
    last_evaluated_at: string | null
    next_retry_at: string | null
    sanitized_failure_class: string | null
    sanitized_failure_code: string | null
    sanitized_message: string | null
  }
  inspection: {
    last_success_at: string | null
    last_failure_at: string | null
    failure_class: string | null
  }
  capabilities: {
    declared: string[]
    observed: string[] | null
    declared_not_observed: string[]
    observed_not_declared: string[]
    observed_at: string | null
    freshness: 'fresh' | 'stale' | 'unknown'
    source: string | null
    source_observation_id?: string
    configuration_generation?: number | null
  }
  drain: {
    drain_state: string
    initial_work: number
    remaining_work: number
    started_at: string | null
    last_evaluated_at: string | null
    deadline_at: string | null
    completed_at: string | null
    timed_out: boolean
    timed_out_at: string | null
    failure_class: string | null
    failure_code: string | null
  } | null
  decommission: {
    operation_id: string
    status: string
    started_at: string | null
    completed_at: string | null
    failure_class: string | null
    failure_code: string | null
    failure_message: string | null
  } | null
}

export type RuntimeHistoryResponse = {
  history: Array<{
    id: string
    timestamp: string
    action: string
    actor: string
    summary: string
  }>
  pagination: {
    limit: number
    has_more: boolean
    next_before: string | null
  }
}

export type RuntimeNodeEditForm = {
  name: string
  placement_region: string
  placement_zone: string
  placement_priority: number
  capacity_weight: number
}

export type RuntimeEndpointEditForm = {
  purpose: string
  transport: string
  host: string
  port: number
  path: string
  tls_mode: string
  priority: number
  enabled: string
}

export type Conference = {
  id: string
  tenant_id: string
  slug: string
  display_name: string
  runtime_node_id: string | null
  active_runtime_binding_id?: string | null
  active_binding_runtime_node_id?: string | null
  runtime_binding_lifecycle_status?: string | null
  last_runtime_binding_retirement_reason?: string | null
  last_runtime_binding_retired_at?: string | null
  desired_state: string
  observed_state: string
  failover_state?: string | null
  failover_binding_id?: string | null
  failover_generation?: number | null
  failover_started_at?: string | null
  configuration_generation: number
  observed_generation: number | null
  observed_at: string | null
  opened_at: string | null
  draining_at: string | null
  closed_at: string | null
  created_at: string
  updated_at: string
}

export type ConferenceParticipant = {
  id: string
  tenant_id: string
  conference_id: string
  telephony_session_id: string
  user_id?: string | null
  desired_state: string
  observed_state: string
  role: string
  admission_reason?: string | null
  joined_at: string | null
  left_at: string | null
  failure_class: string | null
  failure_code: string | null
  created_at: string
  updated_at: string
}

export type RuntimeOperationStatus =
  | 'pending'
  | 'leased'
  | 'running'
  | 'retry_scheduled'
  | 'succeeded'
  | 'terminal_failed'
  | 'cancelled'
  | 'expired'

export type RuntimeOperationType = string

export type RuntimeOperationRuntimeNodeReference = {
  id: string
  name: string
  slug: string
  runtime_family: string
  adapter_key: string
}

export type RuntimeOperationAttempt = {
  count: number
  max: number
}

export type RuntimeOperationAggregateReference = {
  type: string
  id: string
}

export type RuntimeOperationFailure = {
  class: string | null
  code: string | null
  summary: string
  occurred_at: string | null
}

export type RuntimeOperationReconciliationReference = {
  id: string
  target_type: string
  target_id: string
  status: string
}

export type RuntimeOperation = {
  id: string
  runtime_node_id: string | null
  runtime_node: RuntimeOperationRuntimeNodeReference | null
  operation_type: RuntimeOperationType
  aggregate: RuntimeOperationAggregateReference
  status: RuntimeOperationStatus
  attempt: RuntimeOperationAttempt
  priority: number
  correlation_id: string
  failure: RuntimeOperationFailure | null
  available_at: string
  started_at: string | null
  completed_at: string | null
  cancelled_at: string | null
  created_at: string
  updated_at: string
}

export type RuntimeOperationDetail = RuntimeOperation & {
  payload_version: number
  causation_id: string | null
  request_id: string
  expires_at: string | null
  reconciliation: RuntimeOperationReconciliationReference | null
}

export type RuntimeOperationPagination = {
  page: number
  per_page: number
  total: number
  has_more: boolean
}

export type RuntimeOperationListFilters = {
  runtime_node_id?: string
  status?: RuntimeOperationStatus | ''
  operation_type?: RuntimeOperationType
  created_from?: string
  created_to?: string
  correlation_id?: string
  page?: number
  per_page?: number
}

export type RuntimeReconciliationStatus =
  | 'waiting'
  | 'leased'
  | 'converged'
  | 'operation_required'
  | 'blocked'
  | 'unsupported'
  | 'retry_scheduled'

export type RuntimeReconciliationTargetType =
  | 'runtime_node'
  | 'conference'
  | 'conference_participant'
  | 'signaling_registration'

export type RuntimeReconciliationTargetReference = {
  type: RuntimeReconciliationTargetType
  id: string
}

export type RuntimeReconciliationRuntimeNodeReference = {
  id: string
  name: string
  slug: string
  runtime_family: string
  adapter_key: string
}

export type RuntimeReconciliationRuntimeOperationReference = {
  id: string
  operation_type: string
  status: RuntimeOperationStatus
  created_at: string | null
  completed_at: string | null
}

export type RuntimeReconciliationFailure = {
  category: string
  code: string | null
  summary: string
  occurred_at: string | null
}

export type RuntimeReconciliation = {
  id: string
  target: RuntimeReconciliationTargetReference
  runtime_node: RuntimeReconciliationRuntimeNodeReference | null
  status: RuntimeReconciliationStatus
  desired_generation: number
  observed_generation: number | null
  has_drift: boolean | null
  attempt_count: number
  last_checked_at: string | null
  next_check_at: string | null
  last_operation_id: string | null
  runtime_operation: RuntimeReconciliationRuntimeOperationReference | null
  failure: RuntimeReconciliationFailure | null
  created_at: string
  updated_at: string
}

export type RuntimeReconciliationDetail = RuntimeReconciliation

export type RuntimeReconciliationPagination = {
  page: number
  per_page: number
  total: number
  has_more: boolean
}

export type RuntimeReconciliationListFilters = {
  runtime_node_id?: string
  status?: RuntimeReconciliationStatus | ''
  target_type?: RuntimeReconciliationTargetType | ''
  runtime_operation_id?: string
  updated_from?: string
  updated_to?: string
  page?: number
  per_page?: number
}

export type AuditActorReference = {
  type: string | null
  id: string | null
}

export type AuditSubjectReference = {
  type: string | null
  id: string | null
}

export type AuditOutcome = {
  status: string | null
  code: string | null
  summary: string | null
} | null

export type AuditSafeMetadataValue = string | number | boolean | null | AuditSafeMetadataValue[] | { [key: string]: AuditSafeMetadataValue }

export type AuditSafeMetadata = Record<string, AuditSafeMetadataValue>

export type AuditRecord = {
  id: string
  action: string
  actor: AuditActorReference
  subject: AuditSubjectReference
  outcome: AuditOutcome
  correlation_id: string | null
  request_id: string | null
  occurred_at: string | null
  created_at: string | null
}

export type AuditRecordDetail = AuditRecord & {
  reason: string | null
  metadata: AuditSafeMetadata
}

export type AuditRecordPagination = {
  page: number
  per_page: number
  total: number
  has_more: boolean
}

export type AuditRecordListFilters = {
  actor_id?: string
  actor_type?: string
  action?: string
  subject_type?: string
  subject_id?: string
  correlation_id?: string
  request_id?: string
  occurred_from?: string
  occurred_to?: string
  page?: number
  per_page?: number
}

export type Call = {
  id: string
  tenant_id: string
  direction: 'inbound' | 'outbound' | string
  state: string
  desired_state?: string
  termination_reason: string | null
  terminated_at: string | null
  correlation_id: string | null
  created_at: string
  updated_at: string
  destination_ref?: string | null
}

export type CallLeg = {
  id: string
  call_id: string
  direction: string
  role: string
  state: string
  runtime_node_id: string | null
  runtime_channel_id: string | null
  remote_identity: string | null
  bridged_to_leg_id: string | null
  bridged_at: string | null
  termination_reason: string | null
  terminated_at: string | null
  telephony_session_id: string | null
}

export type CallOperation = {
  id: string
  operation_type: string
  target: { type: string; id: string }
  status: string
  attempts: number
  failure_class: string | null
  failure_code: string | null
  correlation_id: string | null
  request_id: string | null
  created_at: string
  started_at: string | null
  completed_at: string | null
}

export type CallTimelineEntry = {
  id: string
  type: string
  source: 'command' | 'observation' | 'audit' | string
  occurred_at: string
  recorded_at: string | null
  call_id: string
  leg_id: string | null
  summary: string
  metadata: Record<string, unknown>
}

export type CallPagination = {
  page: number
  per_page: number
  total: number
  has_more: boolean
}

export class ApiRequestError extends Error {
  status: number
  details: unknown

  constructor(status: number, details: unknown = null) {
    super('API request failed')
    this.name = 'ApiRequestError'
    this.status = status
    this.details = details
  }
}

export class ApiRequestTimeoutError extends Error {
  constructor() {
    super('API request timed out')
    this.name = 'ApiRequestTimeoutError'
  }
}

export type ApiRequestOptions = RequestInit & {
  timeoutMs?: number
}

const apiBaseUrl = (): string => import.meta.env.VITE_UTCP_API_BASE_URL ?? ''

async function fetchJson<T>(
  path: string,
  allowStatuses: number[] = [200],
  options: ApiRequestOptions = {},
): Promise<T> {
  const { timeoutMs, signal: suppliedSignal, ...requestInit } = options
  const externalSignal = suppliedSignal ?? undefined
  const controller = timeoutMs === undefined ? null : new AbortController()
  let timedOut = false
  let timeout: ReturnType<typeof setTimeout> | null = null
  let removeExternalAbortListener: (() => void) | null = null

  if (controller !== null) {
    timeout = setTimeout(() => {
      timedOut = true
      controller.abort()
    }, timeoutMs)
    if (externalSignal !== undefined) {
      const abort = () => controller.abort(externalSignal.reason)
      if (externalSignal.aborted) abort()
      else {
        externalSignal.addEventListener('abort', abort, { once: true })
        removeExternalAbortListener = () => externalSignal.removeEventListener('abort', abort)
      }
    }
  }

  try {
    const response = await fetch(`${apiBaseUrl()}${path}`, {
      credentials: 'same-origin',
      ...requestInit,
      ...(controller === null ? { signal: externalSignal } : { signal: controller.signal }),
      headers: {
        Accept: 'application/json',
        ...(options.headers ?? {}),
      },
    })

    if (!allowStatuses.includes(response.status)) {
      if (response.status === 401) disconnectRuntimeNodeRealtime()
      let details: unknown = null
      try {
        details = await response.json()
      } catch {
        details = null
      }
      throw new ApiRequestError(response.status, details)
    }

    return (await response.json()) as T
  } catch (errorValue) {
    if (timedOut) throw new ApiRequestTimeoutError()
    throw errorValue
  } finally {
    if (timeout !== null) clearTimeout(timeout)
    removeExternalAbortListener?.()
  }
}

let csrfToken: string | null = null

async function csrf(options: ApiRequestOptions = {}): Promise<string> {
  if (csrfToken === null) {
    const response = await fetchJson<{ csrf_token: string }>('/api/v1/auth/csrf', [200], options)
    csrfToken = response.csrf_token
  }

  return csrfToken
}

async function postJson<T>(
  path: string,
  payload: Record<string, unknown>,
  allowStatuses: number[] = [200],
  extraHeaders: Record<string, string> = {},
  requestOptions: ApiRequestOptions = {},
): Promise<T> {
  const token = await csrf(requestOptions)

  return fetchJson<T>(path, allowStatuses, {
    ...requestOptions,
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': token,
      ...extraHeaders,
    },
    body: JSON.stringify(payload),
  })
}

async function patchJson<T>(path: string, payload: Record<string, unknown>, allowStatuses: number[] = [200]): Promise<T> {
  const token = await csrf()

  return fetchJson<T>(path, allowStatuses, {
    method: 'PATCH',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': token,
    },
    body: JSON.stringify(payload),
  })
}

async function putJson<T>(path: string, payload: Record<string, unknown>, allowStatuses: number[] = [200]): Promise<T> {
  const token = await csrf()

  return fetchJson<T>(path, allowStatuses, {
    method: 'PUT',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': token,
    },
    body: JSON.stringify(payload),
  })
}

async function deleteJson<T>(path: string, allowStatuses: number[] = [200]): Promise<T> {
  const token = await csrf()

  return fetchJson<T>(path, allowStatuses, {
    method: 'DELETE',
    headers: {
      'X-CSRF-TOKEN': token,
    },
  })
}

export function createPlatformApiClient(): PlatformApiClient {
  return {
    getLiveness: () => fetchJson<LivenessResponse>('/api/health/live'),
    getReadiness: () => fetchJson<ReadinessResponse>('/api/health/ready', [200, 503]),
    getVersion: () => fetchJson<VersionResponse>('/api/version'),
  }
}

export const identityApi = {
  session: () => fetchJson<IdentitySession>('/api/v1/auth/session'),
  login: async (email: string, password: string) => {
    const response = await postJson<{ message: string }>('/api/v1/auth/login', { email, password })
    csrfToken = null

    return response
  },
  logout: async () => {
    const response = await postJson<{ message: string }>('/api/v1/auth/logout', {})
    csrfToken = null

    return response
  },
  selectTenant: (tenantId: string) => postJson<IdentitySession>('/api/v1/auth/tenant-context', { tenant_id: tenantId }),
  changePassword: (currentPassword: string, newPassword: string) =>
    postJson<{ message: string }>('/api/v1/auth/change-password', {
      current_password: currentPassword,
      new_password: newPassword,
    }).then((response) => {
      csrfToken = null

      return response
    }),
  tenants: () => fetchJson<{ tenants: AdminTenant[] }>('/api/v1/admin/tenants'),
  createTenant: (slug: string, displayName: string) =>
    postJson<{ tenant: AdminTenant }>('/api/v1/admin/tenants', { slug, display_name: displayName }, [201]),
  setTenantStatus: (tenantId: string, status: string) =>
    patchJson<{ message: string }>(`/api/v1/admin/tenants/${tenantId}`, { status }),
  users: (params: { search?: string; status?: string; page?: number; per_page?: number } = {}) => {
    const query = new URLSearchParams()
    if (params.search) query.set('search', params.search)
    if (params.status) query.set('status', params.status)
    if (params.page) query.set('page', String(params.page))
    if (params.per_page) query.set('per_page', String(params.per_page))
    const suffix = query.toString() === '' ? '' : `?${query.toString()}`

    return fetchJson<{ users: AdminUser[]; pagination?: { page: number; per_page: number; total: number; has_more: boolean } }>(`/api/v1/admin/users${suffix}`)
  },
  user: (userId: string) => fetchJson<AdminUserDetail>(`/api/v1/admin/users/${userId}`),
  createUser: (email: string, displayName: string) =>
    postJson<{ user: AdminUser; temporary_password: string }>(
      '/api/v1/admin/users',
      { email, display_name: displayName },
      [201],
    ),
  setUserStatus: (userId: string, status: string) =>
    patchJson<{ message: string }>(`/api/v1/admin/users/${userId}`, { status }),
  resetPassword: (userId: string) =>
    postJson<{ expires_at: string; password_change_required: boolean; temporary_password_displayed: false }>(
      `/api/v1/admin/users/${userId}/password-reset`,
      {},
    ),
  endUserTelephonySession: (userId: string, sessionId: string) =>
    postJson<{ telephony_session: TelephonySessionSummary }>(`/api/v1/admin/users/${userId}/telephony-sessions/${sessionId}/end`, {}),
  issueUserSignalingCredential: (userId: string, sessionId: string) =>
    postJson<{ credential: OneTimeSignalingCredential }>(
      `/api/v1/admin/users/${userId}/telephony-sessions/${sessionId}/signaling-credential`,
      {},
    ),
  memberships: () => fetchJson<{ memberships: AdminMembership[] }>('/api/v1/admin/memberships'),
  createMembership: (userId: string, roleKey: string) =>
    postJson<{ membership_id: string }>('/api/v1/admin/memberships', { user_id: userId, role_key: roleKey }, [201]),
  setMembershipStatus: (membershipId: string, status: string) =>
    patchJson<{ message: string }>(`/api/v1/admin/memberships/${membershipId}`, { status }),
  roles: () => fetchJson<RoleCatalog>('/api/v1/admin/roles'),
  runtimeNodeCatalog: () => fetchJson<{ catalog: RuntimeManagementCatalog }>('/api/v1/admin/runtime-node-catalog'),
  deploymentTargets: () => fetchJson<{ deployment_targets: DeploymentTarget[] }>('/api/v1/admin/deployment-targets'),
  runtimeNodes: () => fetchJson<{ runtime_nodes: RuntimeNode[] }>('/api/v1/admin/runtime-nodes'),
  runtimeNodePlacement: (runtimeNodeId: string) => fetchJson<{ placement: RuntimeNodePlacement }>(`/api/v1/admin/runtime-nodes/${runtimeNodeId}/placement`),
  kubernetesHosts: () => fetchJson<{ hosts: KubernetesHost[] }>('/api/v1/admin/infrastructure/hosts'),
  hostMaintenances: () => fetchJson<{ maintenances: HostMaintenance[] }>('/api/v1/admin/infrastructure/maintenances'),
  requestHostMaintenance: (nodeUid: string, reason?: string) =>
    postJson<{ maintenance: HostMaintenance }>(`/api/v1/admin/infrastructure/hosts/${nodeUid}/maintenance`, { reason: reason ?? null }, [202]),
  createRuntimeProvisioning: (payload: Record<string, unknown>, idempotencyKey: string) =>
    postJson<{ provisioning_request: RuntimeProvisioningRequest }>(
      '/api/v1/admin/runtime-provisioning',
      payload,
      [202],
      { 'Idempotency-Key': idempotencyKey },
    ),
  createRuntimeNode: (payload: Record<string, unknown>) =>
    postJson<{ runtime_node: RuntimeNode }>('/api/v1/admin/runtime-nodes', payload, [201]),
  updateRuntimeNode: (runtimeNodeId: string, payload: Record<string, unknown>) =>
    patchJson<{ runtime_node: RuntimeNode }>(`/api/v1/admin/runtime-nodes/${runtimeNodeId}`, payload),
  updateRuntimeNodeDesiredState: (runtimeNodeId: string, desiredState: string) =>
    postJson<{ runtime_node: RuntimeNode }>(`/api/v1/admin/runtime-nodes/${runtimeNodeId}/desired-state`, { desired_state: desiredState }),
  decommissionRuntimeNode: (runtimeNodeId: string) =>
    postJson<{ runtime_node: RuntimeNode; runtime_operation: { id: string; status: string } }>(
      `/api/v1/admin/runtime-nodes/${runtimeNodeId}/decommission`,
      {},
      [202],
    ),
  addRuntimeEndpoint: (runtimeNodeId: string, payload: Record<string, unknown>) =>
    postJson<{ endpoint: RuntimeNode['endpoints'][number]; runtime_node: RuntimeNode }>(
      `/api/v1/admin/runtime-nodes/${runtimeNodeId}/endpoints`,
      payload,
      [201],
    ),
  updateRuntimeEndpoint: (runtimeNodeId: string, endpointId: string, payload: Record<string, unknown>) =>
    patchJson<{ endpoint: RuntimeNode['endpoints'][number]; runtime_node: RuntimeNode }>(
      `/api/v1/admin/runtime-nodes/${runtimeNodeId}/endpoints/${endpointId}`,
      payload,
    ),
  removeRuntimeEndpoint: (runtimeNodeId: string, endpointId: string) =>
    deleteJson<{ message: string }>(`/api/v1/admin/runtime-nodes/${runtimeNodeId}/endpoints/${endpointId}`),
  setRuntimeCapabilities: (runtimeNodeId: string, capabilities: string[]) =>
    putJson<{ runtime_node: RuntimeNode }>(`/api/v1/admin/runtime-nodes/${runtimeNodeId}/capabilities`, { capabilities }),
  getRuntimeAdapterConfiguration: (runtimeNodeId: string) =>
    fetchJson<{ adapter_configuration: RuntimeAdapterConfiguration }>(`/api/v1/admin/runtime-nodes/${runtimeNodeId}/adapter-configuration`),
  putRuntimeAdapterConfiguration: (runtimeNodeId: string, payload: RuntimeAdapterConfiguration) =>
    putJson<{ adapter_configuration: RuntimeAdapterConfiguration }>(
      `/api/v1/admin/runtime-nodes/${runtimeNodeId}/adapter-configuration`,
      payload,
    ),
  createRuntimeCredential: (runtimeNodeId: string, payload: Record<string, unknown>) =>
    postJson<{ credential: RuntimeNode['credentials'][number]; runtime_node: RuntimeNode }>(
      `/api/v1/admin/runtime-nodes/${runtimeNodeId}/credentials`,
      payload,
      [201],
    ),
  rotateRuntimeCredential: (runtimeNodeId: string, credentialId: string, payload: Record<string, unknown>) =>
    postJson<{ credential: RuntimeNode['credentials'][number]; runtime_node: RuntimeNode }>(
      `/api/v1/admin/runtime-nodes/${runtimeNodeId}/credentials/${credentialId}/rotate`,
      payload,
    ),
  retireRuntimeCredential: (runtimeNodeId: string, credentialId: string) =>
    postJson<{ credential: RuntimeNode['credentials'][number]; runtime_node: RuntimeNode }>(
      `/api/v1/admin/runtime-nodes/${runtimeNodeId}/credentials/${credentialId}/retire`,
      {},
    ),
  runtimeEvidence: (runtimeNodeId: string) =>
    fetchJson<{ runtime_evidence: RuntimeEvidence }>(`/api/v1/admin/runtime-nodes/${runtimeNodeId}/runtime-evidence`),
  runtimeHistory: (runtimeNodeId: string, before?: string | null) => {
    const query = new URLSearchParams({ limit: '10' })
    if (before) query.set('before', before)

    return fetchJson<RuntimeHistoryResponse>(`/api/v1/admin/runtime-nodes/${runtimeNodeId}/history?${query.toString()}`)
  },
  runtimeOperations: (params: RuntimeOperationListFilters = {}) => {
    const query = new URLSearchParams()
    if (params.runtime_node_id) query.set('runtime_node_id', params.runtime_node_id)
    if (params.status) query.set('status', params.status)
    if (params.operation_type) query.set('operation_type', params.operation_type)
    if (params.created_from) query.set('created_from', params.created_from)
    if (params.created_to) query.set('created_to', params.created_to)
    if (params.correlation_id) query.set('correlation_id', params.correlation_id)
    if (params.page) query.set('page', String(params.page))
    if (params.per_page) query.set('per_page', String(params.per_page))
    const suffix = query.toString() === '' ? '' : `?${query.toString()}`

    return fetchJson<{ runtime_operations: RuntimeOperation[]; pagination: RuntimeOperationPagination }>(`/api/v1/admin/runtime-operations${suffix}`)
  },
  runtimeOperation: (runtimeOperationId: string) =>
    fetchJson<{ runtime_operation: RuntimeOperationDetail }>(`/api/v1/admin/runtime-operations/${runtimeOperationId}`),
  runtimeReconciliations: (params: RuntimeReconciliationListFilters = {}) => {
    const query = new URLSearchParams()
    if (params.runtime_node_id) query.set('runtime_node_id', params.runtime_node_id)
    if (params.status) query.set('status', params.status)
    if (params.target_type) query.set('target_type', params.target_type)
    if (params.runtime_operation_id) query.set('runtime_operation_id', params.runtime_operation_id)
    if (params.updated_from) query.set('updated_from', params.updated_from)
    if (params.updated_to) query.set('updated_to', params.updated_to)
    if (params.page) query.set('page', String(params.page))
    if (params.per_page) query.set('per_page', String(params.per_page))
    const suffix = query.toString() === '' ? '' : `?${query.toString()}`

    return fetchJson<{ runtime_reconciliations: RuntimeReconciliation[]; pagination: RuntimeReconciliationPagination }>(`/api/v1/admin/runtime-reconciliations${suffix}`)
  },
  runtimeReconciliation: (runtimeReconciliationId: string) =>
    fetchJson<{ runtime_reconciliation: RuntimeReconciliationDetail }>(`/api/v1/admin/runtime-reconciliations/${runtimeReconciliationId}`),
  listAuditRecords: (params: AuditRecordListFilters = {}) => {
    const query = new URLSearchParams()
    if (params.actor_id) query.set('actor_id', params.actor_id)
    if (params.actor_type) query.set('actor_type', params.actor_type)
    if (params.action) query.set('action', params.action)
    if (params.subject_type) query.set('subject_type', params.subject_type)
    if (params.subject_id) query.set('subject_id', params.subject_id)
    if (params.correlation_id) query.set('correlation_id', params.correlation_id)
    if (params.request_id) query.set('request_id', params.request_id)
    if (params.occurred_from) query.set('occurred_from', params.occurred_from)
    if (params.occurred_to) query.set('occurred_to', params.occurred_to)
    if (params.page) query.set('page', String(params.page))
    if (params.per_page) query.set('per_page', String(params.per_page))
    const suffix = query.toString() === '' ? '' : `?${query.toString()}`

    return fetchJson<{ audit_records: AuditRecord[]; pagination: AuditRecordPagination }>(`/api/v1/admin/audit-records${suffix}`)
  },
  getAuditRecord: (auditRecordId: string) =>
    fetchJson<{ audit_record: AuditRecordDetail }>(`/api/v1/admin/audit-records/${auditRecordId}`),
  externalTrunks: () => fetchJson<{ external_trunks: ExternalTrunk[] }>('/api/v1/admin/external-trunks'),
  externalTrunk: (trunkId: string) => fetchJson<{ external_trunk: ExternalTrunk }>(`/api/v1/admin/external-trunks/${trunkId}`),
  createExternalTrunk: (payload: Record<string, unknown>) => postJson<{ external_trunk: ExternalTrunk }>('/api/v1/admin/external-trunks', payload, [201]),
  setExternalTrunkState: (trunkId: string, desiredState: string) => postJson<{ external_trunk: ExternalTrunk }>(`/api/v1/admin/external-trunks/${trunkId}/desired-state`, { desired_state: desiredState }),
  createExternalTrunkEndpoint: (trunkId: string, payload: Record<string, unknown>) => postJson<{ endpoint: ExternalTrunkEndpoint }>(`/api/v1/admin/external-trunks/${trunkId}/endpoints`, payload, [201]),
  setExternalTrunkEndpointState: (trunkId: string, endpointId: string, desiredState: string) => postJson<{ endpoint: ExternalTrunkEndpoint }>(`/api/v1/admin/external-trunks/${trunkId}/endpoints/${endpointId}/desired-state`, { desired_state: desiredState }),
  createExternalTrunkCredential: (trunkId: string, payload: Record<string, unknown>) => postJson<{ credential_reference: ExternalTrunkCredentialReference }>(`/api/v1/admin/external-trunks/${trunkId}/credentials`, payload, [201]),
  attachExternalTrunkAddress: (trunkId: string, payload: Record<string, unknown>) => postJson<{ external_trunk: ExternalTrunk }>(`/api/v1/admin/external-trunks/${trunkId}/addresses`, payload, [201]),
  telephonyAddresses: () => fetchJson<{ telephony_addresses: TelephonyAddress[] }>('/api/v1/admin/telephony-addresses'),
  createTelephonyAddress: (payload: Record<string, unknown>) => postJson<{ telephony_address: TelephonyAddress }>('/api/v1/admin/telephony-addresses', payload, [201]),
  setTelephonyAddressState: (addressId: string, desiredState: string) => postJson<{ telephony_address: TelephonyAddress }>(`/api/v1/admin/telephony-addresses/${addressId}/desired-state`, { desired_state: desiredState }),
  inboundRoutes: () => fetchJson<{ inbound_routes: InboundRoute[] }>('/api/v1/admin/inbound-routes'),
  outboundRoutes: () => fetchJson<{ outbound_routes: OutboundRoute[] }>('/api/v1/admin/outbound-routes'),
  createInboundRoute: (payload: Record<string, unknown>) => postJson<{ inbound_route: InboundRoute }>('/api/v1/admin/inbound-routes', payload, [201], { 'Idempotency-Key': crypto.randomUUID() }),
  createOutboundRoute: (payload: Record<string, unknown>) => postJson<{ outbound_route: OutboundRoute }>('/api/v1/admin/outbound-routes', payload, [201], { 'Idempotency-Key': crypto.randomUUID() }),
  setInboundRouteState: (routeId: string, desiredState: string) => postJson<{ inbound_route: InboundRoute }>(`/api/v1/admin/inbound-routes/${routeId}/desired-state`, { desired_state: desiredState }),
  setOutboundRouteState: (routeId: string, desiredState: string) => postJson<{ outbound_route: OutboundRoute }>(`/api/v1/admin/outbound-routes/${routeId}/desired-state`, { desired_state: desiredState }),
  callerIdentities: () => fetchJson<{ caller_identities: CallerIdentity[] }>('/api/v1/admin/caller-identities'),
  createCallerIdentity: (payload: Record<string, unknown>) => postJson<{ caller_identity: CallerIdentity }>('/api/v1/admin/caller-identities', payload, [201], { 'Idempotency-Key': crypto.randomUUID() }),
  setCallerIdentityState: (identityId: string, desiredState: string) => postJson<{ caller_identity: CallerIdentity }>(`/api/v1/admin/caller-identities/${identityId}/desired-state`, { desired_state: desiredState }),
  createCallerIdentityPolicy: (identityId: string, externalTrunkId: string) => postJson<{ caller_identity: CallerIdentity }>(`/api/v1/admin/caller-identities/${identityId}/policies`, { external_trunk_id: externalTrunkId }, [201]),
  conferences: () => fetchJson<{ conferences: Conference[] }>('/api/v1/admin/conferences'),
  conference: (conferenceId: string) => fetchJson<{ conference: Conference }>(`/api/v1/admin/conferences/${conferenceId}`),
  conferenceParticipants: (conferenceId: string) =>
    fetchJson<{ participants: ConferenceParticipant[] }>(`/api/v1/admin/conferences/${conferenceId}/participants`),
  isApiRequestError: (error: unknown): error is ApiRequestError => error instanceof ApiRequestError,
}

function paginatedQuery(params: { page?: number; per_page?: number }): string {
  const query = new URLSearchParams()
  if (params.page) query.set('page', String(params.page))
  if (params.per_page) query.set('per_page', String(params.per_page))

  return query.toString() === '' ? '' : `?${query.toString()}`
}

export const callApi = {
  list: (params: { page?: number; per_page?: number } = {}) =>
    fetchJson<{ data: Call[]; pagination: CallPagination }>(`/api/v1/calls${paginatedQuery(params)}`),
  get: (callId: string) => fetchJson<{ data: Call }>(`/api/v1/calls/${callId}`),
  legs: (callId: string, params: { page?: number; per_page?: number } = {}) =>
    fetchJson<{ data: CallLeg[]; pagination: CallPagination }>(`/api/v1/calls/${callId}/legs${paginatedQuery(params)}`),
  createOutbound: (destinationRef: string, runtimeNodeId: string, idempotencyKey: string) =>
    postJson<{ data: Call }>(
      '/api/v1/calls',
      { direction: 'outbound', destination_ref: destinationRef, runtime_node_id: runtimeNodeId || null },
      [201],
      { 'Idempotency-Key': idempotencyKey },
    ),
  operations: (callId: string, params: { page?: number; per_page?: number } = {}) =>
    fetchJson<{ data: CallOperation[]; pagination: CallPagination }>(`/api/v1/calls/${callId}/operations${paginatedQuery(params)}`),
  submitOperation: (
    callId: string,
    operationType: string,
    targetLegId: string | null,
    payload: Record<string, unknown>,
    idempotencyKey: string,
  ) => postJson<{ data: CallOperation }>(
    `/api/v1/calls/${callId}/operations`,
    { operation_type: operationType, target_leg_id: targetLegId, payload },
    [202],
    { 'Idempotency-Key': idempotencyKey },
  ),
  timeline: (callId: string, params: { page?: number; per_page?: number } = {}) =>
    fetchJson<{ data: CallTimelineEntry[]; pagination: CallPagination }>(`/api/v1/calls/${callId}/timeline${paginatedQuery(params)}`),
  isApiRequestError: (error: unknown): error is ApiRequestError => error instanceof ApiRequestError,
}

export const referenceDialerApi = {
  isApiRequestError: (error: unknown): error is ApiRequestError => error instanceof ApiRequestError,
  bootstrap: (options: ApiRequestOptions = {}) => fetchJson<ReferenceDialerBootstrap>('/api/v1/reference-dialer/bootstrap', [200], options),
  createTelephonySession: (idempotencyKey: string) =>
    postJson<{ telephony_session: TelephonySessionSummary }>(
      '/api/v1/telephony/sessions',
      {},
      [201],
      { 'Idempotency-Key': idempotencyKey },
    ),
  issueSignalingCredential: (telephonySessionId: string) =>
    postJson<{ credential: OneTimeSignalingCredential }>(
      `/api/v1/telephony/sessions/${telephonySessionId}/signaling-credential`,
      {},
      [201],
    ),
  joinConference: (conferenceId: string, idempotencyKey: string, options: ApiRequestOptions = {}) =>
    postJson<ConferenceAdmission>(
      `/api/v1/conferences/${conferenceId}/participants/self`,
      {},
      [201],
      { 'Idempotency-Key': idempotencyKey },
      options,
    ),
  leaveConference: async (conferenceId: string): Promise<{ participant: ConferenceParticipant | null }> => {
    try {
      return await deleteJson<{ participant: ConferenceParticipant }>(`/api/v1/conferences/${conferenceId}/participants/self`)
    } catch (errorValue) {
      if (errorValue instanceof ApiRequestError && errorValue.status === 404) return { participant: null }
      throw errorValue
    }
  },
}
