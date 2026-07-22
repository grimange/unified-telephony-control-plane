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
  endpoint_transports: Record<string, { display_name: string; description: string | null }>
  endpoint_tls_modes: Record<string, { display_name: string; description: string | null }>
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

class ApiRequestError extends Error {
  status: number
  details: unknown

  constructor(status: number, details: unknown = null) {
    super('API request failed')
    this.name = 'ApiRequestError'
    this.status = status
    this.details = details
  }
}

const apiBaseUrl = (): string => import.meta.env.VITE_UTCP_API_BASE_URL ?? ''

async function fetchJson<T>(
  path: string,
  allowStatuses: number[] = [200],
  options: RequestInit = {},
): Promise<T> {
  const response = await fetch(`${apiBaseUrl()}${path}`, {
    credentials: 'same-origin',
    ...options,
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
}

let csrfToken: string | null = null

async function csrf(): Promise<string> {
  if (csrfToken === null) {
    const response = await fetchJson<{ csrf_token: string }>('/api/v1/auth/csrf')
    csrfToken = response.csrf_token
  }

  return csrfToken
}

async function postJson<T>(path: string, payload: Record<string, unknown>, allowStatuses: number[] = [200]): Promise<T> {
  const token = await csrf()

  return fetchJson<T>(path, allowStatuses, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': token,
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
  runtimeNodes: () => fetchJson<{ runtime_nodes: RuntimeNode[] }>('/api/v1/admin/runtime-nodes'),
  createRuntimeNode: (payload: Record<string, unknown>) =>
    postJson<{ runtime_node: RuntimeNode }>('/api/v1/admin/runtime-nodes', payload, [201]),
  updateRuntimeNodeDesiredState: (runtimeNodeId: string, desiredState: string) =>
    postJson<{ runtime_node: RuntimeNode }>(`/api/v1/admin/runtime-nodes/${runtimeNodeId}/desired-state`, { desired_state: desiredState }),
  addRuntimeEndpoint: (runtimeNodeId: string, payload: Record<string, unknown>) =>
    postJson<{ endpoint: RuntimeNode['endpoints'][number]; runtime_node: RuntimeNode }>(
      `/api/v1/admin/runtime-nodes/${runtimeNodeId}/endpoints`,
      payload,
      [201],
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
  runtimeHistory: (runtimeNodeId: string) =>
    fetchJson<RuntimeHistoryResponse>(`/api/v1/admin/runtime-nodes/${runtimeNodeId}/history?limit=10`),
  isApiRequestError: (error: unknown): error is ApiRequestError => error instanceof ApiRequestError,
}
