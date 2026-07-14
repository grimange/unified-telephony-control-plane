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
  users: () => fetchJson<{ users: AdminUser[] }>('/api/v1/admin/users'),
  createUser: (email: string, displayName: string) =>
    postJson<{ user: AdminUser; temporary_password: string }>(
      '/api/v1/admin/users',
      { email, display_name: displayName },
      [201],
    ),
  setUserStatus: (userId: string, status: string) =>
    patchJson<{ message: string }>(`/api/v1/admin/users/${userId}`, { status }),
  resetPassword: (userId: string) =>
    postJson<{ temporary_password: string }>(`/api/v1/admin/users/${userId}/password-reset`, {}),
  memberships: () => fetchJson<{ memberships: AdminMembership[] }>('/api/v1/admin/memberships'),
  createMembership: (userId: string, roleKey: string) =>
    postJson<{ membership_id: string }>('/api/v1/admin/memberships', { user_id: userId, role_key: roleKey }, [201]),
  setMembershipStatus: (membershipId: string, status: string) =>
    patchJson<{ message: string }>(`/api/v1/admin/memberships/${membershipId}`, { status }),
  roles: () => fetchJson<RoleCatalog>('/api/v1/admin/roles'),
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
  isApiRequestError: (error: unknown): error is ApiRequestError => error instanceof ApiRequestError,
}
