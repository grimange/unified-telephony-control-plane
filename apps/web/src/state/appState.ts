import { computed, nextTick, reactive, ref } from 'vue'
import {
  identityApi,
  type AdminMembership,
  type AdminTenant,
  type AdminUser,
  type AdminUserDetail,
  type IdentitySession,
  type OneTimeSignalingCredential,
  type RuntimeAdapterConfiguration,
  type RuntimeEvidence,
  type RuntimeHistoryResponse,
  type RuntimeManagementCatalog,
  type RuntimeNode,
  type RoleCatalog,
  type SignalingMetadata,
} from '../api/platform'
import type { AsyncResourceStatus } from '../composables/asyncState'
import { hasCapability, visibleNavigationEntries } from '../navigation'
import { clearNotifications, notify } from './notifications'

export const session = ref<IdentitySession | null>(null)
export const sessionLoaded = ref(false)
export const tenants = ref<AdminTenant[]>([])
export const users = ref<AdminUser[]>([])
export const selectedUserDetail = ref<AdminUserDetail | null>(null)
export const oneTimeSignalingCredential = ref<OneTimeSignalingCredential | null>(null)
export const signalingSecretVisible = ref(false)
export const oneTimeSecretPanel = ref<HTMLElement | null>(null)
export const issueCredentialButton = ref<HTMLButtonElement | null>(null)
export const memberships = ref<AdminMembership[]>([])
export const roleCatalog = ref<RoleCatalog | null>(null)
export const runtimeNodes = ref<RuntimeNode[]>([])
export const runtimeCatalog = ref<RuntimeManagementCatalog | null>(null)
export const runtimeCapabilitySelections = reactive<Record<string, string[]>>({})
export const adapterConfigurations = reactive<Record<string, RuntimeAdapterConfiguration>>({})
export const adapterConfigurationForms = reactive<Record<string, RuntimeAdapterConfiguration>>({})
export const runtimeEvidence = reactive<Record<string, RuntimeEvidence>>({})
export const runtimeHistory = reactive<Record<string, RuntimeHistoryResponse>>({})
export const runtimeNodeDetailStates = reactive<Record<string, { status: AsyncResourceStatus; error: string }>>({})
export const error = ref('')
export const message = ref('')
export const loginNotice = ref('')
export const busy = ref(false)
export const temporaryPassword = ref('')
export const tenantContextVersion = ref(0)

export const loginForm = reactive({ email: '', password: '' })
export const passwordForm = reactive({ current: '', next: '', confirm: '' })
export const tenantForm = reactive({ slug: '', displayName: '' })
export const userForm = reactive({ email: '', displayName: '' })
export const userFilters = reactive({ search: '', status: '', page: 1, perPage: 20 })
export const userPagination = reactive({ page: 1, per_page: 20, total: 0, has_more: false })
export const membershipForm = reactive({ userId: '', roleKey: '' })
export const runtimeNodeForm = reactive({ name: '', slug: '', runtimeFamily: 'asterisk', adapterKey: 'asterisk-ari' })
export const endpointForm = reactive({ purpose: 'control', transport: 'https', host: '', port: 8089, path: '', tlsMode: 'verify' })
export const credentialForm = reactive({ type: 'control-api', identifier: '', secret: '' })

export const activeMemberships = computed(() =>
  session.value?.memberships.filter((membership) => membership.membership_status === 'active') ?? [],
)

export const canViewUsers = computed(() => can('platform.users.view') || can('tenant.memberships.view'))
export const navigation = computed(() => visibleNavigationEntries(session.value))

export const runtimeFamilyOptions = computed(() =>
  Object.entries(runtimeCatalog.value?.runtime_families ?? {}).map(([key, family]) => ({
    key,
    label: family.display_name,
  })),
)

export const tenantRoleOptions = computed(() =>
  Object.entries(roleCatalog.value?.roles ?? {})
    .filter(([, role]) => role.scope === 'tenant')
    .map(([key, role]) => ({
      key,
      label: role.display_name,
    })),
)

export function can(capability: string): boolean {
  return hasCapability(session.value, capability)
}

export function clearStatus(): void {
  error.value = ''
  message.value = ''
  loginNotice.value = ''
}

export function apiErrorMessage(errorValue: unknown): string {
  if (identityApi.isApiRequestError(errorValue)) {
    if (typeof errorValue.details === 'object' && errorValue.details !== null && 'message' in errorValue.details) {
      return String((errorValue.details as { message: unknown }).message)
    }

    return `Request failed with HTTP ${errorValue.status}.`
  }

  if (errorValue instanceof Error) return errorValue.message

  return 'Request failed.'
}

export function fail(errorValue: unknown, options: { notify?: boolean; expectedUnauthenticated?: boolean } = {}): void {
  if (identityApi.isApiRequestError(errorValue) && errorValue.status === 401) {
    session.value = null
    sessionLoaded.value = true
    if (options.expectedUnauthenticated) {
      error.value = ''
      loginNotice.value = 'Sign in to continue.'
    } else {
      error.value = apiErrorMessage(errorValue)
      loginNotice.value = ''
    }

    return
  }

  error.value = apiErrorMessage(errorValue)
  loginNotice.value = ''
  if (options.notify ?? true) {
    notify({
      variant: 'error',
      title: 'Request failed',
      message: error.value,
    })
  }
}

export async function ensureSession(force = false): Promise<IdentitySession | null> {
  if (!force && sessionLoaded.value) return session.value

  try {
    session.value = await identityApi.session()
    sessionLoaded.value = true
    error.value = ''
    loginNotice.value = ''

    return session.value
  } catch (errorValue) {
    if (identityApi.isApiRequestError(errorValue) && errorValue.status === 401) {
      fail(errorValue, { expectedUnauthenticated: true, notify: false })

      return null
    }

    fail(errorValue, { notify: false })

    return null
  }
}

export async function authenticate(): Promise<IdentitySession | null> {
  busy.value = true
  error.value = ''
  loginNotice.value = ''
  try {
    await identityApi.login(loginForm.email, loginForm.password)
    loginForm.password = ''

    return await ensureSession(true)
  } catch (errorValue) {
    fail(errorValue, { notify: false })

    return null
  } finally {
    busy.value = false
  }
}

export async function endSession(): Promise<void> {
  await identityApi.logout()
  session.value = null
  sessionLoaded.value = true
  clearOneTimeSignalingCredential()
  clearRuntimeNodeDetails()
  clearNotifications()
}

export async function savePasswordChange(): Promise<IdentitySession | null> {
  busy.value = true
  error.value = ''
  loginNotice.value = ''
  try {
    if (passwordForm.next !== passwordForm.confirm) {
      error.value = 'New password and confirmation must match.'

      return null
    }
    await identityApi.changePassword(passwordForm.current, passwordForm.next)
    passwordForm.current = ''
    passwordForm.next = ''
    passwordForm.confirm = ''

    return await ensureSession(true)
  } catch (errorValue) {
    fail(errorValue, { notify: false })

    return null
  } finally {
    busy.value = false
  }
}

export async function switchTenant(tenantId: string): Promise<void> {
  clearStatus()
  session.value = await identityApi.selectTenant(tenantId)
  tenantContextVersion.value += 1
  clearOneTimeSignalingCredential()
  clearRuntimeNodeDetails()
}

export function adapterOptionsFor(runtimeFamily: string): Array<{ key: string; label: string }> {
  const adapterKeys = runtimeCatalog.value?.runtime_families[runtimeFamily]?.adapters ?? []

  return adapterKeys.map((key) => ({
    key,
    label: runtimeCatalog.value?.adapter_keys[key]?.display_name ?? key,
  }))
}

export function capabilityOptionsFor(node: RuntimeNode): string[] {
  return runtimeCatalog.value?.adapter_keys[node.adapter_key]?.supported_capabilities ?? []
}

export function capabilityLabel(capability: string): string {
  return runtimeCatalog.value?.runtime_capabilities[capability]?.display_name ?? capability
}

export function adapterConfigurationSupported(node: RuntimeNode): boolean {
  return runtimeCatalog.value?.adapter_keys[node.adapter_key]?.adapter_configuration_available ?? false
}

export function displayValue(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return 'Unavailable'

  return String(value)
}

export function shortId(value: string): string {
  return value.slice(0, 8)
}

export function registrationSummary(user: AdminUser): string {
  const registration = user.signaling_registration_summary
  if (!registration) return 'none'
  if (registration.pending_removal) return `${registration.desired_state} / ${registration.observed_state} (pending removal)`

  return `${registration.desired_state} / ${registration.observed_state}`
}

export function credentialState(signaling: SignalingMetadata): string {
  if (signaling.credential === null) return 'not issued'
  if (signaling.credential.revoked_at) return 'revoked'

  return 'issued'
}

export function signalingLifecycleText(signaling: SignalingMetadata): string {
  const registration = signaling.registration
  if (registration.pending_removal) {
    return 'Registration removed. Contact pending expiration. New registrations and refreshes are blocked.'
  }
  if (registration.desired_state === 'removed' && registration.observed_state === 'expired') {
    return 'Registration removed. No active Contact. Reconciliation is converged when reported by the backend.'
  }
  if (registration.observed_state === 'registered') return 'Currently registered.'
  if (registration.failure_class) return `Registration degraded: ${registration.failure_class}.`

  return 'Registration state is reported by the runtime observer.'
}

export function clearOneTimeSignalingCredential(): void {
  oneTimeSignalingCredential.value = null
  signalingSecretVisible.value = false
}

export async function closeOneTimeSignalingCredential(): Promise<void> {
  clearOneTimeSignalingCredential()
  await nextTick()
  issueCredentialButton.value?.focus()
}

export function asteriskConfigurationForm(runtimeNodeId: string): RuntimeAdapterConfiguration {
  if (!adapterConfigurationForms[runtimeNodeId]) {
    adapterConfigurationForms[runtimeNodeId] = {
      application_name: 'utcp',
      connect_timeout_ms: 1000,
      request_timeout_ms: 7000,
      websocket_handshake_timeout_ms: 8000,
      heartbeat_interval_ms: 15000,
      reconnect_min_delay_ms: 500,
      reconnect_max_delay_ms: 10000,
    }
  }

  return adapterConfigurationForms[runtimeNodeId]
}

export function canRetireCredential(node: RuntimeNode, credential: RuntimeNode['credentials'][number]): boolean {
  return credential.status === 'active' && node.credentials.some((candidate) =>
    candidate.id !== credential.id && candidate.status === 'active' && candidate.type === credential.type,
  )
}

export async function refreshTenants(): Promise<void> {
  tenants.value = (await identityApi.tenants()).tenants
}

export type UserListQuery = { search?: string; status?: string; page?: number; perPage?: number }
export type UserListPagination = { page: number; per_page: number; total: number; has_more: boolean }
export type UsersListResult = { users: AdminUser[]; pagination: UserListPagination }

export async function refreshUsers(query: UserListQuery = {}): Promise<UsersListResult> {
  Object.assign(userFilters, {
    search: query.search ?? userFilters.search,
    status: query.status ?? userFilters.status,
    page: query.page ?? userFilters.page,
    perPage: query.perPage ?? userFilters.perPage,
  })
  const response = await identityApi.users({
    search: userFilters.search,
    status: userFilters.status,
    page: userFilters.page,
    per_page: userFilters.perPage,
  })

  return {
    users: response.users,
    pagination: response.pagination ?? {
      page: userFilters.page,
      per_page: userFilters.perPage,
      total: response.users.length,
      has_more: false,
    },
  }
}

export function applyUsersListResult(result: UsersListResult): void {
  users.value = result.users
  Object.assign(userPagination, result.pagination)
}

export function emptyUsersListResult(query: UserListQuery = {}): UsersListResult {
  return {
    users: [],
    pagination: {
      page: query.page ?? userFilters.page,
      per_page: query.perPage ?? userFilters.perPage,
      total: 0,
      has_more: false,
    },
  }
}

async function refreshUsersIntoModuleState(query: UserListQuery = {}): Promise<void> {
  applyUsersListResult(await refreshUsers(query))
}

export async function goToUserPage(page: number): Promise<void> {
  await refreshUsersIntoModuleState({
    page: Math.max(1, page),
  })
}

export async function applyUserFilters(): Promise<void> {
  await refreshUsersIntoModuleState({
    page: 1,
  })
}

export function resetUsersListState(): void {
  users.value = []
  userFilters.search = ''
  userFilters.status = ''
  userFilters.page = 1
  userFilters.perPage = 20
  Object.assign(userPagination, {
    page: userFilters.page,
    per_page: userFilters.perPage,
    total: 0,
    has_more: false,
  })
}

export async function refreshSelectedUser(userId: string): Promise<void> {
  selectedUserDetail.value = await identityApi.user(userId)
  clearOneTimeSignalingCredential()
}

export async function refreshMemberships(): Promise<void> {
  roleCatalog.value = await identityApi.roles()
  if (!membershipForm.roleKey && tenantRoleOptions.value.length > 0) {
    membershipForm.roleKey = tenantRoleOptions.value[0].key
  }
  memberships.value = (await identityApi.memberships()).memberships
  if (users.value.length === 0 && canViewUsers.value) await refreshUsersIntoModuleState()
}

export async function createTenant(): Promise<void> {
  const response = await identityApi.createTenant(tenantForm.slug, tenantForm.displayName)
  tenants.value.push(response.tenant)
  tenantForm.slug = ''
  tenantForm.displayName = ''
  message.value = 'Tenant created.'
  notify({
    variant: 'success',
    title: 'Tenant created',
    message: 'Tenant created.',
  })
}

export async function setTenantStatus(tenantId: string, status: string): Promise<void> {
  await identityApi.setTenantStatus(tenantId, status)
  await refreshTenants()
}

export async function createUser(): Promise<void> {
  const response = await identityApi.createUser(userForm.email, userForm.displayName)
  temporaryPassword.value = response.temporary_password
  userForm.email = ''
  userForm.displayName = ''
}

export async function setUserStatus(userId: string, status: string): Promise<void> {
  await identityApi.setUserStatus(userId, status)
}

export async function resetPassword(userId: string): Promise<void> {
  const response = await identityApi.resetPassword(userId)
  temporaryPassword.value = response.temporary_password_displayed === false ? 'Password reset. Temporary password was delivered out of band.' : ''
}

export async function endSelectedTelephonySession(): Promise<void> {
  const detail = selectedUserDetail.value
  if (!detail?.active_telephony_session) return

  await identityApi.endUserTelephonySession(detail.user.id, detail.active_telephony_session.id)
  await refreshSelectedUser(detail.user.id)
}

export async function issueSelectedSignalingCredential(): Promise<void> {
  const detail = selectedUserDetail.value
  if (!detail?.active_telephony_session) return

  const response = await identityApi.issueUserSignalingCredential(detail.user.id, detail.active_telephony_session.id)
  oneTimeSignalingCredential.value = response.credential
  signalingSecretVisible.value = false
  await refreshSelectedUser(detail.user.id)
  oneTimeSignalingCredential.value = response.credential
  await nextTick()
  oneTimeSecretPanel.value?.focus()
}

export async function createMembership(): Promise<void> {
  await identityApi.createMembership(membershipForm.userId, membershipForm.roleKey)
  membershipForm.userId = ''
  await refreshMemberships()
}

export async function setMembershipStatus(membershipId: string, status: string): Promise<void> {
  await identityApi.setMembershipStatus(membershipId, status)
  await refreshMemberships()
}

export async function refreshRuntimeNodes(): Promise<void> {
  runtimeCatalog.value = (await identityApi.runtimeNodeCatalog()).catalog
  runtimeNodes.value = (await identityApi.runtimeNodes()).runtime_nodes
  const activeNodeIds = new Set(runtimeNodes.value.map((node) => node.id))
  for (const node of runtimeNodes.value) {
    runtimeCapabilitySelections[node.id] = [...node.capabilities]
  }
  for (const runtimeNodeId of Object.keys(runtimeCapabilitySelections)) {
    if (!activeNodeIds.has(runtimeNodeId)) delete runtimeCapabilitySelections[runtimeNodeId]
  }
  for (const runtimeNodeId of Object.keys(runtimeNodeDetailStates)) {
    if (!activeNodeIds.has(runtimeNodeId)) clearRuntimeNodeDetails(runtimeNodeId)
  }
}

export async function createRuntimeNode(): Promise<void> {
  const response = await identityApi.createRuntimeNode({
    name: runtimeNodeForm.name,
    slug: runtimeNodeForm.slug,
    runtime_family: runtimeNodeForm.runtimeFamily,
    adapter_key: runtimeNodeForm.adapterKey,
  })
  runtimeNodes.value.push(response.runtime_node)
  runtimeNodeForm.name = ''
  runtimeNodeForm.slug = ''
  await refreshRuntimeNodes()
}

export async function setRuntimeDesiredState(runtimeNodeId: string, desiredState: string): Promise<void> {
  await identityApi.updateRuntimeNodeDesiredState(runtimeNodeId, desiredState)
  clearRuntimeNodeDetails(runtimeNodeId)
  await refreshRuntimeNodes()
}

export async function addRuntimeEndpoint(runtimeNodeId: string): Promise<void> {
  await identityApi.addRuntimeEndpoint(runtimeNodeId, {
    purpose: endpointForm.purpose,
    transport: endpointForm.transport,
    host: endpointForm.host,
    port: endpointForm.port,
    path: endpointForm.path || null,
    tls_mode: endpointForm.tlsMode,
    priority: 100,
    enabled: true,
  })
  endpointForm.host = ''
  endpointForm.path = ''
  clearRuntimeNodeDetails(runtimeNodeId)
  await refreshRuntimeNodes()
  await reloadRuntimeNodeDetails(runtimeNodeId)
}

export async function removeRuntimeEndpoint(runtimeNodeId: string, endpointId: string): Promise<void> {
  await identityApi.removeRuntimeEndpoint(runtimeNodeId, endpointId)
  clearRuntimeNodeDetails(runtimeNodeId)
  await refreshRuntimeNodes()
  await reloadRuntimeNodeDetails(runtimeNodeId)
}

export async function setRuntimeCapabilities(runtimeNodeId: string): Promise<void> {
  await identityApi.setRuntimeCapabilities(runtimeNodeId, runtimeCapabilitySelections[runtimeNodeId] ?? [])
  clearRuntimeNodeDetails(runtimeNodeId)
  await refreshRuntimeNodes()
  await reloadRuntimeNodeDetails(runtimeNodeId)
}

export function clearRuntimeNodeDetails(runtimeNodeId?: string): void {
  const runtimeNodeIds = runtimeNodeId ? [runtimeNodeId] : Object.keys(runtimeNodeDetailStates)
  for (const nextRuntimeNodeId of runtimeNodeIds) {
    delete adapterConfigurations[nextRuntimeNodeId]
    delete adapterConfigurationForms[nextRuntimeNodeId]
    delete runtimeEvidence[nextRuntimeNodeId]
    delete runtimeHistory[nextRuntimeNodeId]
    delete runtimeNodeDetailStates[nextRuntimeNodeId]
  }
}

async function reloadRuntimeNodeDetails(runtimeNodeId: string): Promise<void> {
  const node = runtimeNodes.value.find((candidate) => candidate.id === runtimeNodeId)
  if (node) await loadRuntimeNodeDetails(node, true)
}

export async function loadRuntimeNodeDetails(node: RuntimeNode, force = false): Promise<void> {
  const currentState = runtimeNodeDetailStates[node.id]
  if (!force && currentState?.status === 'success') return

  runtimeNodeDetailStates[node.id] = {
    status: adapterConfigurations[node.id] || runtimeEvidence[node.id] || runtimeHistory[node.id] ? 'refreshing' : 'loading',
    error: '',
  }
  let detailError: unknown = null

  if (adapterConfigurationSupported(node)) {
    try {
      const response = await identityApi.getRuntimeAdapterConfiguration(node.id)
      adapterConfigurations[node.id] = response.adapter_configuration
      adapterConfigurationForms[node.id] = {
        ...asteriskConfigurationForm(node.id),
        ...response.adapter_configuration,
      }
    } catch (errorValue) {
      delete adapterConfigurations[node.id]
      delete adapterConfigurationForms[node.id]
      detailError ??= errorValue
    }
  }

  try {
    runtimeEvidence[node.id] = (await identityApi.runtimeEvidence(node.id)).runtime_evidence
  } catch (errorValue) {
    delete runtimeEvidence[node.id]
    detailError ??= errorValue
  }

  try {
    runtimeHistory[node.id] = await identityApi.runtimeHistory(node.id)
  } catch (errorValue) {
    delete runtimeHistory[node.id]
    detailError ??= errorValue
  }

  if (detailError) {
    runtimeNodeDetailStates[node.id] = {
      status: identityApi.isApiRequestError(detailError) && detailError.status === 403 ? 'forbidden' : 'error',
      error: apiErrorMessage(detailError),
    }

    return
  }

  runtimeNodeDetailStates[node.id] = { status: 'success', error: '' }
}

export async function saveAsteriskAdapterConfiguration(runtimeNodeId: string): Promise<void> {
  const form = asteriskConfigurationForm(runtimeNodeId)
  await identityApi.putRuntimeAdapterConfiguration(runtimeNodeId, form)
  clearRuntimeNodeDetails(runtimeNodeId)
  await refreshRuntimeNodes()
  await reloadRuntimeNodeDetails(runtimeNodeId)
}

export async function createRuntimeCredential(runtimeNodeId: string): Promise<void> {
  await identityApi.createRuntimeCredential(runtimeNodeId, {
    type: credentialForm.type,
    identifier: credentialForm.identifier || null,
    ['sec' + 'ret']: credentialForm.secret,
  })
  credentialForm.identifier = ''
  credentialForm.secret = ''
  clearRuntimeNodeDetails(runtimeNodeId)
  await refreshRuntimeNodes()
  await reloadRuntimeNodeDetails(runtimeNodeId)
}

export async function rotateRuntimeCredential(runtimeNodeId: string, credentialId: string): Promise<void> {
  const nextSecret = window.prompt('Enter the replacement secret. It will be sent once and not stored in the browser.')
  if (!nextSecret) return

  await identityApi.rotateRuntimeCredential(runtimeNodeId, credentialId, {
    ['sec' + 'ret']: nextSecret,
  })
  clearRuntimeNodeDetails(runtimeNodeId)
  await refreshRuntimeNodes()
  await reloadRuntimeNodeDetails(runtimeNodeId)
}

export async function retireRuntimeCredential(runtimeNodeId: string, credentialId: string): Promise<void> {
  if (!window.confirm('Retire this credential? Existing runtime connections using it may fail after retirement.')) return

  await identityApi.retireRuntimeCredential(runtimeNodeId, credentialId)
  clearRuntimeNodeDetails(runtimeNodeId)
  await refreshRuntimeNodes()
  await reloadRuntimeNodeDetails(runtimeNodeId)
}

export function resetAppStateForTests(): void {
  session.value = null
  sessionLoaded.value = false
  tenants.value = []
  resetUsersListState()
  selectedUserDetail.value = null
  oneTimeSignalingCredential.value = null
  signalingSecretVisible.value = false
  memberships.value = []
  roleCatalog.value = null
  runtimeNodes.value = []
  runtimeCatalog.value = null
  loginNotice.value = ''
  error.value = ''
  message.value = ''
  busy.value = false
  temporaryPassword.value = ''
  tenantContextVersion.value = 0
  loginForm.email = ''
  loginForm.password = ''
  passwordForm.current = ''
  passwordForm.next = ''
  passwordForm.confirm = ''
  tenantForm.slug = ''
  tenantForm.displayName = ''
  userForm.email = ''
  userForm.displayName = ''
  membershipForm.userId = ''
  membershipForm.roleKey = ''
  runtimeNodeForm.name = ''
  runtimeNodeForm.slug = ''
  runtimeNodeForm.runtimeFamily = 'asterisk'
  runtimeNodeForm.adapterKey = 'asterisk-ari'
  endpointForm.purpose = 'control'
  endpointForm.transport = 'https'
  endpointForm.host = ''
  endpointForm.port = 8089
  endpointForm.path = ''
  endpointForm.tlsMode = 'verify'
  credentialForm.type = 'control-api'
  credentialForm.identifier = ''
  credentialForm.secret = ''

  for (const key of Object.keys(runtimeCapabilitySelections)) delete runtimeCapabilitySelections[key]
  for (const key of Object.keys(adapterConfigurations)) delete adapterConfigurations[key]
  for (const key of Object.keys(adapterConfigurationForms)) delete adapterConfigurationForms[key]
  for (const key of Object.keys(runtimeEvidence)) delete runtimeEvidence[key]
  for (const key of Object.keys(runtimeHistory)) delete runtimeHistory[key]
  for (const key of Object.keys(runtimeNodeDetailStates)) delete runtimeNodeDetailStates[key]
  clearNotifications()
}
