/// <reference types="node" />

import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createMemoryHistory } from 'vue-router'
import App from './App.vue'
import type { ExternalTrunk, IdentitySession, RuntimeManagementCatalog, TelephonyAddress } from './api/platform'
import {
  buildRuntimeNodeEchoOptions,
  disconnectRuntimeNodeRealtime,
  isPermittedPusherTransportCache,
  resetRuntimeNodeRealtimeClientFactory,
  setRuntimeNodeRealtimeClientFactory,
  type EchoChannel,
  type EchoClient,
  type RuntimeNodeRealtimeConfig,
} from './realtime/runtimeNodeRealtime'
import { createUtcpRouter, router } from './router'
import { resetAppStateForTests } from './state/appState'
import { appearanceStorageKey, resetAppearanceForTests } from './state/theme'
import { assertNoSeriousAxeViolations } from './test/accessibility'
import appShellSource from './layouts/AppShell.vue?raw'
import auditRecordsViewSource from './views/AuditRecordsView.vue?raw'
import changePasswordViewSource from './views/ChangePasswordView.vue?raw'
import conferenceOperationsViewSource from './views/ConferenceOperationsView.vue?raw'
import dashboardViewSource from './views/DashboardView.vue?raw'
import loginViewSource from './views/LoginView.vue?raw'
import membershipsViewSource from './views/MembershipsView.vue?raw'
import appStateSource from './state/appState.ts?raw'
import runtimeNodesViewSource from './views/RuntimeNodesView.vue?raw'
import runtimeOperationsViewSource from './views/RuntimeOperationsView.vue?raw'
import runtimeReconciliationsViewSource from './views/RuntimeReconciliationsView.vue?raw'
import tenantsViewSource from './views/TenantsView.vue?raw'
import userDetailViewSource from './views/UserDetailView.vue?raw'
import usersViewSource from './views/UsersView.vue?raw'

const styleSource = readFileSync('src/style.css', 'utf-8')
const tokenSource = readFileSync('src/styles/tokens.css', 'utf-8')

type ThemeName = 'light' | 'dark'
type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger'
type CssDeclarations = Record<string, string>

function escapeSelectorForRegExp(selector: string): string {
  return selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

function parseDeclarations(source: string): CssDeclarations {
  return Object.fromEntries(
    [...source.matchAll(/(--[\w-]+|[\w-]+)\s*:\s*([^;]+);/g)].map((match) => [match[1], match[2].trim()]),
  )
}

function cssRule(selector: string): CssDeclarations {
  const ruleMatch = styleSource.match(new RegExp(`${escapeSelectorForRegExp(selector)}\\s*\\{([^}]*)\\}`, 's'))
  if (!ruleMatch) throw new Error(`Missing CSS rule for ${selector}`)

  return parseDeclarations(ruleMatch[1])
}

function themeTokens(theme: ThemeName): CssDeclarations {
  const rootMatch = tokenSource.match(/:root\s*\{([^}]*)\}/s)
  if (!rootMatch) throw new Error('Missing light theme tokens')

  const tokens = parseDeclarations(rootMatch[1])
  if (theme === 'dark') {
    const darkMatch = tokenSource.match(/:root\[data-theme='dark'\]\s*\{([^}]*)\}/s)
    if (!darkMatch) throw new Error('Missing dark theme tokens')
    Object.assign(tokens, parseDeclarations(darkMatch[1]))
  }

  return tokens
}

function resolveCssValue(value: string, tokens: CssDeclarations): string {
  const variableMatch = value.match(/^var\((--[\w-]+)\)$/)
  if (!variableMatch) return value

  const resolved = tokens[variableMatch[1]]
  if (!resolved) throw new Error(`Missing CSS token ${variableMatch[1]}`)

  return resolved
}

function hexToRgb(hex: string): [number, number, number] {
  const normalized = hex.replace('#', '')
  if (normalized.length !== 6) throw new Error(`Unsupported color literal ${hex}`)

  return [0, 2, 4].map((offset) => Number.parseInt(normalized.slice(offset, offset + 2), 16)) as [number, number, number]
}

function relativeLuminance(hex: string): number {
  const [red, green, blue] = hexToRgb(hex).map((channel) => {
    const value = channel / 255
    return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4
  })

  return 0.2126 * red + 0.7152 * green + 0.0722 * blue
}

function contrastRatio(foreground: string, background: string): number {
  const foregroundLuminance = relativeLuminance(foreground)
  const backgroundLuminance = relativeLuminance(background)
  const lighter = Math.max(foregroundLuminance, backgroundLuminance)
  const darker = Math.min(foregroundLuminance, backgroundLuminance)

  return (lighter + 0.05) / (darker + 0.05)
}

function hoverRuleForVariant(variant: ButtonVariant): CssDeclarations {
  if (variant === 'primary') {
    return cssRule(".ui-button--primary:hover:not(:disabled):not([aria-disabled='true'])")
  }

  return cssRule(`.ui-button--${variant}:hover:not(:disabled):not([aria-disabled='true'])`)
}

const session = {
  user: {
    id: 'user-1',
    email: 'admin@utcp.local.test',
    display_name: 'Local Admin',
    status: 'active',
    password_change_required: false,
  },
  active_tenant: {
    tenant_id: 'tenant-1',
    slug: 'local',
    display_name: 'Local Tenant',
  },
  memberships: [
    {
      membership_id: 'membership-1',
      tenant_id: 'tenant-1',
      slug: 'local',
      display_name: 'Local Tenant',
      status: 'active',
      membership_status: 'active',
    },
  ],
  capabilities: [
    'platform.tenants.view',
    'platform.users.view',
    'tenant.memberships.view',
    'tenant.memberships.manage',
    'telephony.sessions.manage',
    'telephony.signaling.manage',
    'runtime.nodes.view',
    'runtime.nodes.manage',
    'runtime.credentials.rotate',
    'telephony.conferences.view',
    'telephony.conferences.participants.manage',
    'telephony.external_connectivity.view',
    'telephony.external_connectivity.manage',
    'telephony.routing.view',
    'telephony.routing.manage',
  ],
  catalog_version: 'c2.test',
  expires_at: '2026-07-14T10:00:00Z',
}

const limitedSession = {
  ...session,
  capabilities: [],
}

const noActiveTenantAuditSession = {
  ...session,
  active_tenant: null,
  capabilities: ['tenant.memberships.manage'],
}

const adminUser = {
  id: 'user-2',
  email: 'operator@utcp.local.test',
  display_name: 'Operator User',
  status: 'active',
  password_change_required: false,
  updated_at: '2026-07-16T10:00:00Z',
  membership_summary: { total: 1, active: 1, suspended: 0 },
  role_summary: { platform: [], tenant: ['tenant-member'] },
  active_telephony_session: {
    id: '11111111-2222-3333-4444-555555555555',
    tenant_id: 'tenant-1',
    status: 'active',
    issued_at: '2026-07-16T10:00:00Z',
    expires_at: '2026-07-16T11:00:00Z',
    ended_at: null,
  },
  signaling_registration_summary: {
    desired_state: 'eligible',
    observed_state: 'registered',
    observed_at: '2026-07-16T10:01:00Z',
    observed_expires_at: '2026-07-16T10:03:00Z',
    pending_removal: false,
  },
}

const adminUserDetail = {
  user: {
    ...adminUser,
    created_at: '2026-07-16T09:00:00Z',
    last_login_at: null,
    password_changed_at: null,
  },
  memberships: [
    {
      id: 'membership-2',
      tenant_id: 'tenant-1',
      tenant_slug: 'local',
      tenant_display_name: 'Local Tenant',
      status: 'active',
      roles: ['tenant-member'],
      created_at: '2026-07-16T09:00:00Z',
      updated_at: '2026-07-16T09:00:00Z',
    },
  ],
  platform_roles: [],
  effective_capabilities: {
    platform: [],
    tenant: ['telephony.signaling.issue_own', 'telephony.signaling.view_own'],
  },
  active_telephony_session: adminUser.active_telephony_session,
  signaling: {
    signaling_identity: 'ts-11111111222233334444555555555555',
    credential: {
      username: 'ts-11111111222233334444555555555555',
      realm: 'sip.utcp.local.test',
      algorithm: 'MD5',
      issued_at: '2026-07-16T10:00:00Z',
      expires_at: '2026-07-16T10:02:00Z',
      revoked_at: null,
      wss_uri: 'wss://sip.utcp.local.test/ws',
    },
    registration: {
      desired_state: 'eligible',
      observed_state: 'registered',
      observed_at: '2026-07-16T10:01:00Z',
      observed_expires_at: '2026-07-16T10:03:00Z',
      last_event_type: 'registration.accepted',
      failure_class: null,
      pending_removal: false,
      reconciliation_status: 'converged',
      reconciliation_reason: null,
    },
  },
}

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

function deferredResponse(): { promise: Promise<Response>; resolve: (response: Response) => void } {
  let resolve!: (response: Response) => void
  const promise = new Promise<Response>((next) => {
    resolve = next
  })

  return { promise, resolve }
}

const runtimeCatalog = {
  catalog_version: 'runtime-management.v1',
  runtime_families: {
    asterisk: { display_name: 'Asterisk', description: null, adapters: ['asterisk-ari'] },
    freeswitch: { display_name: 'FreeSWITCH', description: null, adapters: ['freeswitch-esl'] },
    simulator: { display_name: 'Simulator', description: null, adapters: ['simulator-deterministic'] },
  },
  adapter_keys: {
    'asterisk-ari': {
      runtime_family: 'asterisk',
      display_name: 'Asterisk ARI',
      description: null,
      supported_capabilities: ['event.stream', 'runtime.observation'],
      required_capabilities: ['event.stream', 'runtime.observation'],
      endpoint_requirements: [],
      credentials_required: true,
      adapter_configuration_available: true,
      adapter_configuration: {
        fields: [
          {
            key: 'application_name',
            label: 'ARI application name',
            help: 'Stasis application name subscribed by the Asterisk ARI listener.',
            input_type: 'text',
            required: true,
            read_only: false,
            write_only: false,
            default: 'utcp-t0-observation',
            order: 10,
            validation: { min_length: 3, max_length: 80 },
          },
          {
            key: 'connect_timeout_ms',
            label: 'Connect timeout',
            help: 'HTTP connection timeout for Asterisk ARI requests, in milliseconds.',
            input_type: 'integer',
            required: true,
            read_only: false,
            write_only: false,
            default: 2000,
            order: 20,
            validation: { min: 250, max: 30000, step: 1 },
          },
          {
            key: 'request_timeout_ms',
            label: 'Request timeout',
            help: 'Total timeout for Asterisk ARI HTTP requests, in milliseconds.',
            input_type: 'integer',
            required: true,
            read_only: false,
            write_only: false,
            default: 4000,
            order: 30,
            validation: { min: 250, max: 60000, step: 1 },
          },
          {
            key: 'websocket_handshake_timeout_ms',
            label: 'WebSocket handshake timeout',
            help: 'Timeout for establishing the Asterisk ARI event WebSocket, in milliseconds.',
            input_type: 'integer',
            required: true,
            read_only: false,
            write_only: false,
            default: 4000,
            order: 40,
            validation: { min: 250, max: 60000, step: 1 },
          },
          {
            key: 'heartbeat_interval_ms',
            label: 'Heartbeat interval',
            help: 'Interval for ARI event connection heartbeat checks, in milliseconds.',
            input_type: 'integer',
            required: true,
            read_only: false,
            write_only: false,
            default: 15000,
            order: 50,
            validation: { min: 1000, max: 120000, step: 1 },
          },
          {
            key: 'reconnect_min_delay_ms',
            label: 'Minimum reconnect delay',
            help: 'Minimum backoff delay before reconnecting the ARI event stream, in milliseconds.',
            input_type: 'integer',
            required: true,
            read_only: false,
            write_only: false,
            default: 1000,
            order: 60,
            validation: { min: 100, max: 120000, step: 1 },
          },
          {
            key: 'reconnect_max_delay_ms',
            label: 'Maximum reconnect delay',
            help: 'Maximum backoff delay before reconnecting the ARI event stream, in milliseconds.',
            input_type: 'integer',
            required: true,
            read_only: false,
            write_only: false,
            default: 30000,
            order: 70,
            validation: { min: 100, max: 300000, step: 1 },
          },
        ],
      },
    },
    'freeswitch-esl': {
      runtime_family: 'freeswitch',
      display_name: 'FreeSWITCH ESL',
      description: null,
      supported_capabilities: [],
      required_capabilities: [],
      endpoint_requirements: [],
      credentials_required: true,
      adapter_configuration_available: false,
    },
    'simulator-deterministic': {
      runtime_family: 'simulator',
      display_name: 'Deterministic simulator',
      description: null,
      supported_capabilities: ['event.stream', 'runtime.observation', 'runtime.configuration'],
      required_capabilities: ['event.stream', 'runtime.observation', 'runtime.configuration'],
      endpoint_requirements: [],
      credentials_required: false,
      adapter_configuration_available: true,
      adapter_configuration: {
        fields: [
          {
            key: 'scenario_key',
            label: 'Scenario key',
            help: 'Deterministic simulator scenario key from the server simulator catalog.',
            input_type: 'text',
            required: true,
            read_only: false,
            write_only: false,
            default: null,
            order: 10,
            validation: { min_length: 1, max_length: 32 },
          },
          {
            key: 'scenario_version',
            label: 'Scenario version',
            help: 'Deterministic simulator scenario contract version.',
            input_type: 'integer',
            required: true,
            read_only: false,
            write_only: false,
            default: 1,
            order: 20,
            validation: { min: 1, max: 1, step: 1 },
          },
          {
            key: 'seed',
            label: 'Seed',
            help: 'Stable deterministic seed used by the simulator profile.',
            input_type: 'text',
            required: true,
            read_only: false,
            write_only: false,
            default: 'local',
            order: 30,
            validation: { min_length: 1, max_length: 120 },
          },
          {
            key: 'parameters',
            label: 'Parameters',
            help: 'Optional scalar simulator parameters keyed by the selected scenario.',
            input_type: 'json',
            required: true,
            read_only: false,
            write_only: false,
            default: [],
            order: 40,
          },
        ],
      },
    },
  },
  runtime_capabilities: {
    'event.stream': { display_name: 'Event stream', description: null },
    'runtime.observation': { display_name: 'Runtime observation', description: null },
    'runtime.configuration': { display_name: 'Runtime configuration', description: null },
    'conference.execution': { display_name: 'Conference execution', description: null },
  },
  desired_states: {},
  endpoint_purposes: {},
  endpoint_transports: ['http', 'https', 'tcp', 'tls', 'udp', 'ws', 'wss'],
  endpoint_tls_modes: ['disabled', 'opportunistic', 'required', 'verify'],
} satisfies RuntimeManagementCatalog

const roleCatalog = {
  catalog_version: 'roles.v1',
  roles: {
    'tenant-member': { scope: 'tenant', display_name: 'Tenant member', capabilities: [] },
    'tenant-admin': { scope: 'tenant', display_name: 'Tenant admin', capabilities: [] },
    'platform-admin': { scope: 'platform', display_name: 'Platform admin', capabilities: [] },
  },
  capabilities: [],
}

const runtimeNode = {
  id: 'runtime-1',
  tenant_id: 'tenant-1',
  name: 'Proof Runtime',
  slug: 'proof-runtime',
  runtime_family: 'asterisk',
  adapter_key: 'asterisk-ari',
  desired_state: 'draft',
  observed_state: 'unobserved',
  configuration_version: 1,
  placement: { region: null, zone: null, priority: 100, capacity_weight: 10, labels: {} },
  endpoints: [{ id: 'endpoint-1', purpose: 'control', transport: 'https', host: 'runtime.local.test', port: 8089, path: '/ari', tls_mode: 'verify', priority: 100, enabled: true }],
  credentials: [
    { id: 'credential-1', type: 'control-api', identifier: 'old', fingerprint: '1234567890abcdef', version: 1, status: 'active', rotated_at: '2026-07-14T10:00:00Z', expires_at: null },
    { id: 'credential-2', type: 'control-api', identifier: 'new', fingerprint: 'abcdef1234567890', version: 2, status: 'active', rotated_at: '2026-07-14T11:00:00Z', expires_at: null },
  ],
  capabilities: ['event.stream', 'runtime.observation'],
}

const conference = {
  id: 'conference-1',
  tenant_id: 'tenant-1',
  slug: 'daily-ops',
  display_name: 'Daily Ops',
  runtime_node_id: 'runtime-1',
  active_runtime_binding_id: 'binding-1',
  active_binding_runtime_node_id: 'runtime-1',
  runtime_binding_lifecycle_status: 'active',
  last_runtime_binding_retirement_reason: null,
  last_runtime_binding_retired_at: null,
  desired_state: 'open',
  observed_state: 'active',
  failover_state: null,
  failover_binding_id: null,
  failover_generation: null,
  failover_started_at: null,
  configuration_generation: 2,
  observed_generation: 2,
  observed_at: '2026-07-22T10:00:00Z',
  opened_at: '2026-07-22T09:55:00Z',
  draining_at: null,
  closed_at: null,
  created_at: '2026-07-22T09:50:00Z',
  updated_at: '2026-07-22T10:00:00Z',
}

const secondConference = {
  ...conference,
  id: 'conference-2',
  slug: 'support-room',
  display_name: 'Support Room',
  observed_state: 'ready',
}

const conferenceParticipant = {
  id: 'participant-1',
  tenant_id: 'tenant-1',
  conference_id: 'conference-1',
  telephony_session_id: '11111111-2222-3333-4444-555555555555',
  user_id: 'user-1',
  desired_state: 'admitted',
  observed_state: 'joined',
  role: 'host',
  admission_reason: 'operator',
  joined_at: '2026-07-22T10:01:00Z',
  left_at: null,
  failure_class: null,
  failure_code: null,
  created_at: '2026-07-22T10:00:30Z',
  updated_at: '2026-07-22T10:01:00Z',
}

const runtimeOperation = {
  id: 'operation-1',
  runtime_node_id: 'runtime-1',
  runtime_node: {
    id: 'runtime-1',
    name: 'Proof Runtime',
    slug: 'proof-runtime',
    runtime_family: 'asterisk',
    adapter_key: 'asterisk-ari',
  },
  operation_type: 'runtime.node.inspect',
  aggregate: { type: 'runtime_node', id: 'runtime-1' },
  status: 'running',
  attempt: { count: 1, max: 3 },
  priority: 100,
  correlation_id: 'correlation-1',
  failure: null,
  available_at: '2026-07-23T10:00:00Z',
  started_at: '2026-07-23T10:01:00Z',
  completed_at: null,
  cancelled_at: null,
  created_at: '2026-07-23T09:59:00Z',
  updated_at: '2026-07-23T10:01:00Z',
}

const secondRuntimeOperation = {
  ...runtimeOperation,
  id: 'operation-2',
  operation_type: 'runtime.node.restore',
  status: 'pending',
  correlation_id: 'correlation-2',
  started_at: null,
}

const runtimeOperationDetail = {
  ...runtimeOperation,
  payload_version: 1,
  causation_id: null,
  request_id: 'request-1',
  expires_at: '2026-07-23T10:30:00Z',
  reconciliation: {
    id: 'reconciliation-1',
    target_type: 'runtime_node',
    target_id: 'runtime-1',
    status: 'waiting',
  },
}

const runtimeReconciliation = {
  id: 'reconciliation-1',
  target: { type: 'runtime_node', id: 'runtime-1' },
  runtime_node: {
    id: 'runtime-1',
    name: 'Proof Runtime',
    slug: 'proof-runtime',
    runtime_family: 'asterisk',
    adapter_key: 'asterisk-ari',
  },
  status: 'operation_required',
  desired_generation: 4,
  observed_generation: 3,
  has_drift: true,
  attempt_count: 2,
  last_checked_at: '2026-07-24T10:01:00Z',
  next_check_at: '2026-07-24T10:05:00Z',
  last_operation_id: 'operation-1',
  runtime_operation: {
    id: 'operation-1',
    operation_type: 'runtime.node.inspect',
    status: 'running',
    created_at: '2026-07-24T10:00:00Z',
    completed_at: null,
  },
  failure: {
    category: 'runtime_unavailable',
    code: 'profile_missing',
    summary: 'runtime_unavailable:profile_missing',
    occurred_at: '2026-07-24T10:01:00Z',
  },
  created_at: '2026-07-24T09:50:00Z',
  updated_at: '2026-07-24T10:01:00Z',
}

const secondRuntimeReconciliation = {
  ...runtimeReconciliation,
  id: 'reconciliation-2',
  status: 'waiting',
  desired_generation: 2,
  observed_generation: 2,
  has_drift: false,
  last_operation_id: null,
  runtime_operation: null,
  failure: null,
}

const auditRecord = {
  id: 'audit-1',
  action: 'runtime_node.created',
  actor: { type: 'user', id: 'deleted-user-1' },
  subject: { type: 'runtime_node', id: 'deleted-runtime-1' },
  outcome: { status: 'succeeded', code: null, summary: 'succeeded' },
  correlation_id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
  request_id: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
  occurred_at: '2026-07-24T10:00:00Z',
  created_at: '2026-07-24T10:00:01Z',
}

const secondAuditRecord = {
  ...auditRecord,
  id: 'audit-2',
  action: 'runtime_node.updated',
  actor: { type: 'system', id: null },
  subject: { type: 'runtime_node', id: 'runtime-2' },
  outcome: { status: 'failed', code: 'blocked', summary: 'failed:blocked' },
  correlation_id: 'cccccccccccccccccccccccccccccccc',
  request_id: 'dddddddddddddddddddddddddddddddd',
  occurred_at: '2026-07-24T09:00:00Z',
}

const auditRecordDetail = {
  ...auditRecord,
  reason: 'routine operator review',
  metadata: {
    safe: 'visible',
    count: 2,
    nested: { approved: true, password: '[redacted]' },
    password: '[redacted]',
    api_token: '[redacted]',
    authorization: '[redacted]',
    request_body: '[redacted]',
    desired_state: '[redacted]',
    observed_state: '[redacted]',
    provider_response: '[redacted]',
  },
}

function mockRuntimeAdminFetch(calls: Array<{ url: string; body?: unknown }>): void {
  vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
    const url = input.toString()
    calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
    if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
    if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
    if (url.endsWith('/api/v1/admin/runtime-node-catalog')) return Promise.resolve(jsonResponse({ catalog: runtimeCatalog }))
    if (url.endsWith('/api/v1/admin/runtime-nodes')) return Promise.resolve(jsonResponse({ runtime_nodes: [runtimeNode] }))
    if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration')) {
      if (init?.method === 'PUT') {
        return Promise.resolve(jsonResponse({ adapter_configuration: { configured: true, profile: JSON.parse(String(init.body)) } }))
      }

      return Promise.resolve(jsonResponse({
        adapter_configuration: {
          configured: true,
          profile: {
            configuration_version: 1,
            application_name: 'utcp',
            connect_timeout_ms: 1000,
            request_timeout_ms: 7000,
            websocket_handshake_timeout_ms: 8000,
            heartbeat_interval_ms: 15000,
            reconnect_min_delay_ms: 500,
            reconnect_max_delay_ms: 10000,
          },
        },
      }))
    }
    if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/runtime-evidence')) {
      return Promise.resolve(jsonResponse({
        runtime_evidence: {
          desired_state: 'draft',
          observed_state: 'unobserved',
          observed_at: null,
          desired_configuration_generation: 1,
          observed_configuration_generation: null,
          listener: { status: null, lease_freshness: null, last_claimed_at: null, last_renewed_at: null },
          connection: { state: 'closed', latest_epoch_opened_at: '2026-07-14T10:00:00Z', latest_epoch_closed_at: null, latest_event_at: null, latest_disconnect_class: null },
          reconciliation: { state: 'blocked', last_evaluated_at: null, next_retry_at: '2026-07-14T10:05:00Z', sanitized_failure_class: 'runtime_unavailable', sanitized_failure_code: 'profile_missing', sanitized_message: 'runtime_unavailable:profile_missing' },
          inspection: { last_success_at: null, last_failure_at: null, failure_class: null },
        },
      }))
    }
    if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/history?limit=10')) {
      return Promise.resolve(jsonResponse({
        history: [{ id: 'audit-1', timestamp: '2026-07-14T10:00:00Z', action: 'runtime_node.created', actor: 'user', summary: 'Node created for asterisk-ari.' }],
        pagination: { limit: 10, has_more: false, next_before: null },
      }))
    }
    if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/capabilities')) {
      return Promise.resolve(jsonResponse({ runtime_node: runtimeNode }))
    }
    if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/credentials/credential-1/retire')) {
      return Promise.resolve(jsonResponse({ credential: runtimeNode.credentials[0], runtime_node: runtimeNode }))
    }

    return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
  })
}

function mockConferenceAdminFetch(calls: Array<{ url: string; body?: unknown }>): void {
  vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
    const url = input.toString()
    calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
    if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
    if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
    if (url.endsWith('/api/v1/admin/conferences')) return Promise.resolve(jsonResponse({ conferences: [conference, secondConference] }))
    if (url.endsWith('/api/v1/admin/conferences/conference-1')) return Promise.resolve(jsonResponse({ conference }))
    if (url.endsWith('/api/v1/admin/conferences/conference-1/participants')) return Promise.resolve(jsonResponse({ participants: [conferenceParticipant] }))
    if (url.includes('/api/v1/admin/conferences/conference-2/')) return Promise.resolve(jsonResponse({ message: 'Unselected Conference detail was requested.' }, 500))

    return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
  })
}

function mockRuntimeOperationAdminFetch(calls: Array<{ url: string; body?: unknown }>, options: { listStatus?: number; detailStatus?: number; empty?: boolean } = {}): void {
  vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
    const url = input.toString()
    calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
    if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
    if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
    if (url.includes('/api/v1/admin/runtime-operations/operation-1')) {
      if (options.detailStatus) return Promise.resolve(jsonResponse({ message: 'detail unavailable' }, options.detailStatus))

      return Promise.resolve(jsonResponse({ runtime_operation: runtimeOperationDetail }))
    }
    if (url.includes('/api/v1/admin/runtime-operations/operation-2')) {
      return Promise.resolve(jsonResponse({ message: 'Unselected Runtime Operation detail was requested.' }, 500))
    }
    if (url.includes('/api/v1/admin/runtime-operations')) {
      if (options.listStatus) return Promise.resolve(jsonResponse({ message: 'list unavailable' }, options.listStatus))

      const params = new URL(url, 'http://utcp.local.test').searchParams
      return Promise.resolve(jsonResponse({
        runtime_operations: options.empty ? [] : [runtimeOperation, secondRuntimeOperation],
        pagination: {
          page: params.get('page') === '2' ? 2 : 1,
          per_page: params.get('per_page') === '10' ? 10 : 20,
          total: options.empty ? 0 : 2,
          has_more: params.get('page') !== '2' && !options.empty,
        },
      }))
    }

    return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
  })
}

function mockRuntimeReconciliationAdminFetch(calls: Array<{ url: string; body?: unknown }>, options: { listStatus?: number; detailStatus?: number; empty?: boolean } = {}): void {
  vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
    const url = input.toString()
    calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
    if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
    if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
    if (url.includes('/api/v1/admin/runtime-reconciliations/reconciliation-1')) {
      if (options.detailStatus) return Promise.resolve(jsonResponse({ message: 'detail unavailable' }, options.detailStatus))

      return Promise.resolve(jsonResponse({ runtime_reconciliation: runtimeReconciliation }))
    }
    if (url.includes('/api/v1/admin/runtime-reconciliations/reconciliation-2')) {
      return Promise.resolve(jsonResponse({ message: 'Unselected Runtime Reconciliation detail was requested.' }, 500))
    }
    if (url.includes('/api/v1/admin/runtime-reconciliations')) {
      if (options.listStatus) return Promise.resolve(jsonResponse({ message: 'list unavailable' }, options.listStatus))

      const params = new URL(url, 'http://utcp.local.test').searchParams
      return Promise.resolve(jsonResponse({
        runtime_reconciliations: options.empty ? [] : [runtimeReconciliation, secondRuntimeReconciliation],
        pagination: {
          page: params.get('page') === '2' ? 2 : 1,
          per_page: params.get('per_page') === '10' ? 10 : 20,
          total: options.empty ? 0 : 2,
          has_more: params.get('page') !== '2' && !options.empty,
        },
      }))
    }

    return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
  })
}

function mockAuditRecordAdminFetch(calls: Array<{ url: string; body?: unknown }>, options: { listStatus?: number; detailStatus?: number; empty?: boolean } = {}): void {
  vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
    const url = input.toString()
    calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
    if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
    if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
    if (url.includes('/api/v1/admin/audit-records/audit-1')) {
      if (options.detailStatus) return Promise.resolve(jsonResponse({ message: 'detail unavailable' }, options.detailStatus))

      return Promise.resolve(jsonResponse({ audit_record: auditRecordDetail }))
    }
    if (url.includes('/api/v1/admin/audit-records/audit-2')) {
      return Promise.resolve(jsonResponse({ message: 'Unselected Audit record detail was requested.' }, 500))
    }
    if (url.includes('/api/v1/admin/audit-records')) {
      if (options.listStatus) return Promise.resolve(jsonResponse({ message: 'list unavailable' }, options.listStatus))

      const params = new URL(url, 'http://utcp.local.test').searchParams
      return Promise.resolve(jsonResponse({
        audit_records: options.empty ? [] : [auditRecord, secondAuditRecord],
        pagination: {
          page: params.get('page') === '2' ? 2 : 1,
          per_page: params.get('per_page') === '10' ? 10 : 20,
          total: options.empty ? 0 : 2,
          has_more: params.get('page') !== '2' && !options.empty,
        },
      }))
    }

    return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
  })
}

function mockUserAdminFetch(calls: Array<{ url: string; body?: unknown }>): void {
  vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
    const url = input.toString()
    calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
    if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
    if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
    if (url.endsWith('/api/v1/auth/tenant-context')) return Promise.resolve(jsonResponse(session))
    if (url.includes('/api/v1/admin/users?')) {
      return Promise.resolve(jsonResponse({
        users: [adminUser],
        pagination: { page: 1, per_page: 20, total: 1, has_more: false },
      }))
    }
    if (url.endsWith('/api/v1/admin/users/user-2')) {
      return Promise.resolve(jsonResponse(adminUserDetail))
    }
    if (url.endsWith('/api/v1/admin/users/user-2/telephony-sessions/11111111-2222-3333-4444-555555555555/signaling-credential')) {
      return Promise.resolve(jsonResponse({
        credential: {
          username: 'ts-11111111222233334444555555555555',
          realm: 'sip.utcp.local.test',
          algorithm: 'MD5',
          sip_secret: 'temporary-sip-secret-test-value',
          wss_uri: 'wss://sip.utcp.local.test/ws',
          issued_at: '2026-07-16T10:05:00Z',
          expires_at: '2026-07-16T10:07:00Z',
        },
      }))
    }
    if (url.endsWith('/api/v1/admin/users/user-2/telephony-sessions/11111111-2222-3333-4444-555555555555/end')) {
      return Promise.resolve(jsonResponse({ telephony_session: { ...adminUser.active_telephony_session, status: 'ended', ended_at: '2026-07-16T10:06:00Z' } }))
    }

    return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
  })
}

function mockPrimaryRouteFetch(
  calls: Array<{ url: string; body?: unknown }>,
  activeSession: IdentitySession = session,
  externalTrunks: ExternalTrunk[] = [],
  telephonyAddresses: TelephonyAddress[] = [],
): void {
  vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
    const url = input.toString()
    calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
    if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(activeSession))
    if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
    if (url.endsWith('/api/v1/admin/tenants')) {
      return Promise.resolve(jsonResponse({
        tenants: [{ id: 'tenant-1', slug: 'local', display_name: 'Local Tenant', status: 'active' }],
      }))
    }
    if (url.includes('/api/v1/admin/users?') || url.endsWith('/api/v1/admin/users')) {
      return Promise.resolve(jsonResponse({
        users: [adminUser],
        pagination: { page: 1, per_page: 20, total: 1, has_more: false },
      }))
    }
    if (url.endsWith('/api/v1/admin/memberships')) {
      return Promise.resolve(jsonResponse({
        memberships: [{
          id: 'membership-2',
          user_id: adminUser.id,
          email: adminUser.email,
          display_name: adminUser.display_name,
          status: 'active',
        }],
      }))
    }
    if (url.endsWith('/api/v1/admin/roles')) {
      return Promise.resolve(jsonResponse({
        catalog_version: 'roles.v1',
        roles: {
          'tenant-member': {
            scope: 'tenant',
            display_name: 'Tenant member',
            capabilities: ['telephony.signaling.view_own'],
          },
        },
        capabilities: ['telephony.signaling.view_own'],
      }))
    }
    if (url.endsWith('/api/v1/admin/runtime-node-catalog')) return Promise.resolve(jsonResponse({ catalog: runtimeCatalog }))
    if (url.endsWith('/api/v1/admin/runtime-nodes')) return Promise.resolve(jsonResponse({ runtime_nodes: [runtimeNode] }))
    if (url.endsWith('/api/v1/admin/conferences')) return Promise.resolve(jsonResponse({ conferences: [conference] }))
    if (url.endsWith('/api/v1/admin/external-trunks')) return Promise.resolve(jsonResponse({ external_trunks: externalTrunks }))
    if (url.endsWith('/api/v1/admin/telephony-addresses')) return Promise.resolve(jsonResponse({ telephony_addresses: telephonyAddresses }))
    if (url.includes('/api/v1/admin/runtime-operations')) {
      return Promise.resolve(jsonResponse({
        runtime_operations: [runtimeOperation],
        pagination: { page: 1, per_page: 20, total: 1, has_more: false },
      }))
    }
    if (url.includes('/api/v1/admin/runtime-reconciliations')) {
      return Promise.resolve(jsonResponse({
        runtime_reconciliations: [runtimeReconciliation],
        pagination: { page: 1, per_page: 20, total: 1, has_more: false },
      }))
    }
    if (url.includes('/api/v1/admin/audit-records')) {
      return Promise.resolve(jsonResponse({
        audit_records: [auditRecord],
        pagination: { page: 1, per_page: 20, total: 1, has_more: false },
      }))
    }

    return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
  })
}

function createMockRealtimeEcho() {
  const connectionCallbacks: Record<string, Array<(payload?: unknown) => void>> = {}
  const channelErrorCallbacks: Record<string, Array<(error: unknown) => void>> = {}
  const channelSubscribedCallbacks: Record<string, Array<() => void>> = {}
  const notificationCallbacks: Record<string, Record<string, (payload: unknown) => void>> = {}
  const privateChannels: string[] = []
  const leftChannels: string[] = []
  const createdConfigs: RuntimeNodeRealtimeConfig[] = []
  let disconnected = false

  function createChannel(channelName: string): EchoChannel {
    const channel: EchoChannel = {
      listen(event, callback) {
        notificationCallbacks[channelName] = {
          ...(notificationCallbacks[channelName] ?? {}),
          [event]: callback,
        }

        return channel
      },
      stopListening(event) {
        if (notificationCallbacks[channelName]) delete notificationCallbacks[channelName][event]

        return channel
      },
      error(callback) {
        channelErrorCallbacks[channelName] = [...(channelErrorCallbacks[channelName] ?? []), callback]

        return channel
      },
      subscribed(callback) {
        channelSubscribedCallbacks[channelName] = [...(channelSubscribedCallbacks[channelName] ?? []), callback]

        return channel
      },
    }

    return channel
  }

  const client: EchoClient = {
    private(channelName) {
      privateChannels.push(channelName)

      return createChannel(channelName)
    },
    leave(channelName) {
      leftChannels.push(channelName)
    },
    disconnect() {
      disconnected = true
    },
    connector: {
      pusher: {
        connection: {
          bind(event, callback) {
            connectionCallbacks[event] = [...(connectionCallbacks[event] ?? []), callback]
          },
        },
      },
    },
  }

  setRuntimeNodeRealtimeClientFactory((config) => {
    createdConfigs.push(config)

    return client
  })

  return {
    createdConfigs,
    privateChannels,
    leftChannels,
    get disconnected() {
      return disconnected
    },
    emitConnection(event: string, payload?: unknown) {
      for (const callback of connectionCallbacks[event] ?? []) callback(payload)
    },
    emitAuthError(error: unknown, channelName = privateChannels.at(-1) ?? '') {
      for (const callback of channelErrorCallbacks[channelName] ?? []) callback(error)
    },
    emitSubscriptionSucceeded(channelName = privateChannels.at(-1) ?? '') {
      for (const callback of channelSubscribedCallbacks[channelName] ?? []) callback()
    },
    emitRuntimeOperationNotification(channelName: string, payload: unknown) {
      notificationCallbacks[channelName]?.['.runtime-operation.operational-state.changed']?.(payload)
    },
    emitRuntimeReconciliationNotification(channelName: string, payload: unknown) {
      notificationCallbacks[channelName]?.['.runtime-reconciliation.operational-state.changed']?.(payload)
    },
  }
}

describe('C1 App shell', () => {
  const mountedWrappers: VueWrapper[] = []

  beforeEach(() => {
    resetAppStateForTests()
    resetAppearanceForTests()
    window.localStorage.clear()
    window.history.replaceState({}, '', '/login')
  })

  afterEach(() => {
    for (const wrapper of mountedWrappers.splice(0)) wrapper.unmount()
    vi.restoreAllMocks()
    disconnectRuntimeNodeRealtime()
    resetRuntimeNodeRealtimeClientFactory()
    resetAppearanceForTests()
    vi.unstubAllEnvs()
    window.localStorage.clear()
  })

  function routeLocationFor(path: string) {
    const url = new URL(path, 'http://utcp.local.test')
    const query: Record<string, string> = {}
    for (const [key, value] of url.searchParams.entries()) query[key] = value

    return { path: url.pathname, query }
  }

  async function mountApp(path = '/login') {
    await router.replace({ path: '/login', query: {} })
    await router.isReady()
    await router.push(routeLocationFor(path))
    await router.isReady()
    const wrapper = mount(App, {
      global: {
        plugins: [router],
      },
    })
    mountedWrappers.push(wrapper)
    await flushPromises()
    await flushPromises()
    await flushPromises()

    return wrapper
  }

  function attachWrapperToDocument(wrapper: VueWrapper): void {
    if (!wrapper.element.isConnected) document.body.append(wrapper.element)
  }

  it('renders the natural login form without client-side tokens', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ message: 'Unauthenticated.' }, 401))

    const wrapper = await mountApp('/login')

    expect(wrapper.findAll('h1').map((heading) => heading.text())).toEqual(['Unified Telephony Control Plane'])
    expect(wrapper.text()).toContain('Operate tenant access, telephony runtime nodes, lifecycle operations, reconciliation, and audit evidence from one control-plane workspace.')
    expect(wrapper.text()).toContain('Sign in')
    expect(wrapper.find('input[type="email"]').exists()).toBe(true)
    expect(wrapper.find('input[type="password"]').exists()).toBe(true)
    expect(wrapper.html()).not.toContain('localStorage')
  })

  it('has no serious or critical axe violations on the login route', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ message: 'Unauthenticated.' }, 401))

    const wrapper = await mountApp('/login')

    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.text()).toContain('Sign in')
    await assertNoSeriousAxeViolations(wrapper.element)
  })

  it('renders forced password change as a UTCP account-security task', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({
      ...session,
      user: { ...session.user, password_change_required: true },
    }))

    const wrapper = await mountApp('/change-password')

    expect(wrapper.findAll('h1').map((heading) => heading.text())).toEqual(['Secure your UTCP account'])
    expect(wrapper.text()).toContain('Set a new password before entering the UTCP control plane.')
    expect(wrapper.text()).toContain('Change password')
    expect(wrapper.find('label[for="current-password"]').text()).toContain('Current password')
    expect(wrapper.find('label[for="new-password"]').text()).toContain('New password')
    expect(wrapper.find('label[for="confirm-password"]').text()).toContain('Confirm new password')
    expect(wrapper.findAll('button').some((button) => button.text() === 'Save password')).toBe(true)
    await assertNoSeriousAxeViolations(wrapper.element)
  })

  it.each([
    ['/dashboard', 'Dashboard'],
    ['/admin/users', 'Operator User'],
    ['/admin/tenants', 'Local Tenant'],
    ['/admin/memberships', 'Operator User'],
    ['/admin/runtime-nodes', 'Proof Runtime'],
    ['/operations/conferences', 'Daily Ops'],
    ['/operations/runtime-operations', 'runtime.node.inspect'],
    ['/operations/runtime-reconciliations', 'Runtime reconciliations'],
    ['/admin/audit-records', 'runtime_node.created'],
  ])('has no serious or critical axe violations on %s', async (path, expectedText) => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockPrimaryRouteFetch(calls)

    const wrapper = await mountApp(path)

    expect(wrapper.text()).toContain(expectedText)
    await assertNoSeriousAxeViolations(wrapper.element)
  })

  it('renders capability-gated administration from the server session', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) {
        return Promise.resolve(jsonResponse(session))
      }
      if (url.endsWith('/api/v1/admin/tenants')) {
        return Promise.resolve(jsonResponse({ tenants: [{ id: 'tenant-1', slug: 'local', display_name: 'Local Tenant', status: 'active' }] }))
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })
    const wrapper = await mountApp('/admin/tenants')

    expect(wrapper.text()).toContain('Local Admin')
    expect(wrapper.text()).toContain('Tenants')
    expect(wrapper.text()).toContain('Users')
    expect(wrapper.text()).toContain('Memberships')
    expect(wrapper.text()).toContain('Telephony Nodes')
    expect(wrapper.text()).toContain('Local Tenant')
  })

  it('groups primary navigation conceptually while preserving link order and active state', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockPrimaryRouteFetch(calls)

    const wrapper = await mountApp('/dashboard')
    const nav = wrapper.find('nav[aria-label="Primary"]')
    const groups = nav.findAll('.side-nav__group')

    expect(groups.map((group) => group.find('.side-nav__group-label').text())).toEqual([
      'Overview',
      'External Connectivity',
      'Routing',
      'Calls',
      'Telephony Infrastructure',
      'System',
    ])
    expect(groups.map((group) => group.findAll('a').map((link) => link.text()))).toEqual([
      ['Dashboard'],
      ['External Trunks', 'Numbers & Addresses'],
      ['Routes', 'Caller Identities'],
      ['Conferences'],
      ['Telephony Nodes'],
      ['Tenants', 'Users', 'Memberships', 'Audit', 'Advanced operations', 'Runtime reconciliations'],
    ])
    expect(nav.findAll('a').map((link) => link.text())).toEqual([
      'Dashboard',
      'External Trunks',
      'Numbers & Addresses',
      'Routes',
      'Caller Identities',
      'Conferences',
      'Telephony Nodes',
      'Tenants',
      'Users',
      'Memberships',
      'Audit',
      'Advanced operations',
      'Runtime reconciliations',
    ])
    expect(nav.find('a[aria-current="page"]').text()).toBe('Dashboard')
    for (const groupLabel of ['Overview', 'External Connectivity', 'Calls', 'Telephony Infrastructure', 'Routing', 'System']) {
      expect(nav.findAll('a').some((link) => link.text() === groupLabel)).toBe(false)
      expect(nav.findAll('button').some((button) => button.text() === groupLabel)).toBe(false)
    }

    await wrapper.find('.compact-nav-toggle').trigger('click')
    await nextTick()
    expect(wrapper.find('#primary-navigation').classes()).toContain('open')
    expect(wrapper.find('nav[aria-label="Primary"]').findAll('.side-nav__group').map((group) => group.find('.side-nav__group-label').text())).toEqual([
      'Overview',
      'External Connectivity',
      'Routing',
      'Calls',
      'Telephony Infrastructure',
      'System',
    ])
    await assertNoSeriousAxeViolations(wrapper.element)
  })

  it('keeps the reference telephony client direct route without promoting it to navigation', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockPrimaryRouteFetch(calls)

    const wrapper = await mountApp('/dashboard')
    const navText = wrapper.find('nav[aria-label="Primary"]').text()
    const referenceRoute = router.resolve('/dialer')

    expect(navText).not.toContain('Reference Telephony Client')
    expect(referenceRoute.name).toBe('reference-dialer')
    expect(referenceRoute.path).toBe('/dialer')
    expect(referenceRoute.meta.capability).toBe('telephony.sessions.view_own')
    expect(referenceRoute.meta.requiresActiveTenant).toBe(true)
  })

  it('renders the External Trunks empty state through the canonical tenant-scoped read', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockPrimaryRouteFetch(calls)

    const wrapper = await mountApp('/external-connectivity/trunks')

    expect(wrapper.find('h2').text()).toBe('External Trunks')
    expect(wrapper.text()).toContain('No External Trunks configured')
    expect(wrapper.text()).toContain('Add External Trunk')
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/external-trunks'))).toBe(true)
    await assertNoSeriousAxeViolations(wrapper.element)
  })

  it('renders safe registration observation status for a canonical trunk', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    const trunk: ExternalTrunk = {
      id: 'trunk-1',
      tenant_id: 'tenant-1',
      name: 'Primary Provider',
      slug: 'primary-provider',
      description: 'Primary SIP provider',
      supported_directions: ['inbound', 'outbound'],
      capabilities: [],
      desired_state: 'active',
      observed_health: 'ready',
      observed_health_reason: null,
      configuration_version: 1,
      ready: true,
      eligible_for_future_use: true,
      endpoints: [{
        id: 'endpoint-1',
        external_trunk_id: 'trunk-1',
        endpoint_uri: 'sip:provider.example',
        signaling_mode: 'outbound_registration',
        transport: 'udp',
        authentication_mode: 'credentials',
        credential_reference_id: 'credential-1',
        registration_target: 'provider.example',
        registration_realm: 'provider.example',
        registration_identity: 'utcp-v1',
        capabilities: [],
        desired_state: 'active',
        priority: 10,
        registration_observation: {
          state: 'registered',
          failure_category: null,
          last_attempt_at: '2026-08-27T06:00:00Z',
          last_success_at: '2026-08-27T06:01:00Z',
          expires_at: '2026-08-27T06:11:00Z',
          observed_at: '2026-08-27T06:01:00Z',
          observation_version: 2,
        },
      }],
      credential_references: [],
      addresses: [],
    }
    mockPrimaryRouteFetch(calls, session, [trunk])

    const wrapper = await mountApp('/external-connectivity/trunks')

    expect(wrapper.text()).toContain('Primary Provider')
    expect(wrapper.text()).toContain('SIP Registration')
    expect(wrapper.text()).toContain('Registered')
    expect(wrapper.text()).not.toContain('Reference Telephony Client')
    await assertNoSeriousAxeViolations(wrapper.element)
  })

  it('renders canonical Numbers & Addresses data without routing controls', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    const address: TelephonyAddress = {
      id: 'address-1',
      tenant_id: 'tenant-1',
      type: 'e164',
      value: '+15550100',
      desired_state: 'active',
    }
    mockPrimaryRouteFetch(calls, session, [], [address])

    const wrapper = await mountApp('/external-connectivity/addresses')

    expect(wrapper.find('h2').text()).toBe('Numbers & Addresses')
    expect(wrapper.text()).toContain('+15550100')
    expect(wrapper.text()).toContain('active')
    expect(wrapper.text()).not.toContain('Add Route')
    await assertNoSeriousAxeViolations(wrapper.element)
  })

  it('suppresses capability-empty navigation groups', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockPrimaryRouteFetch(calls, {
      ...noActiveTenantAuditSession,
      capabilities: ['telephony.conferences.view'],
    })

    const wrapper = await mountApp('/dashboard')
    const nav = wrapper.find('nav[aria-label="Primary"]')

    expect(nav.findAll('.side-nav__group-label').map((label) => label.text())).toEqual(['Overview', 'Calls'])
    expect(nav.findAll('a').map((link) => link.text())).toEqual(['Dashboard', 'Conferences'])
    expect(nav.text()).not.toContain('Telephony Infrastructure')
    expect(nav.text()).not.toContain('System')
  })

  it('builds production RuntimeNode Reverb options for the canonical WSS route', () => {
    const appKey = 'test-public-key'
    const options = buildRuntimeNodeEchoOptions({
      appKey,
      wsHost: 'app.utcp.local.test',
      wsPort: 443,
      wsScheme: 'wss',
      wsPath: '/app',
      authEndpoint: '/api/broadcasting/auth',
    })

    expect(options.broadcaster).toBe('reverb')
    expect(options.key).toBe(appKey)
    expect(options.wsHost).toBe('app.utcp.local.test')
    expect(options.wsPort).toBe(443)
    expect(options.wssPort).toBe(443)
    expect(options.forceTLS).toBe(true)
    expect(options.enabledTransports).toEqual(['ws', 'wss'])
    const enabledTransports = options.enabledTransports.map(String)
    expect(enabledTransports).not.toContain('xhr_polling')
    expect(enabledTransports).not.toContain('xhr_streaming')
    expect(options.authEndpoint).toBe('/api/broadcasting/auth')
    expect(options.auth.headers['X-Requested-With']).toBe('XMLHttpRequest')
    expect(options).not.toHaveProperty('wsPath')
    expect(Object.keys(options)).not.toContain('secret')
    expect(Object.values(options)).not.toContain(6001)

    const pusherRouteTemplate = `${String((options as { wsPath?: string }).wsPath ?? '')}/app/{key}`
    expect(pusherRouteTemplate).toBe('/app/{key}')
    expect(pusherRouteTemplate.match(/\/app\//g)).toHaveLength(1)
  })

  it('keeps RuntimeNode realtime disconnected when required browser transport coordinates are missing', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeAdminFetch(calls)
    vi.stubEnv('VITE_UTCP_REVERB_APP_KEY', 'test-public-key')
    vi.stubEnv('VITE_UTCP_WS_HOST', '')
    vi.stubEnv('VITE_UTCP_WS_PORT', '443')
    vi.stubEnv('VITE_UTCP_WS_SCHEME', 'wss')
    vi.stubEnv('VITE_UTCP_WS_PATH', '/app')
    const realtime = createMockRealtimeEcho()
    const wrapper = await mountApp('/admin/runtime-nodes')

    expect(wrapper.text()).toContain('Proof Runtime')
    expect(wrapper.text()).toContain('Live updates disconnected — displayed data may be stale')
    expect(realtime.createdConfigs).toEqual([])
    expect(realtime.privateChannels).toEqual([])
    expect(calls.filter((call) => call.url.endsWith('/api/v1/admin/runtime-node-catalog'))).toHaveLength(1)
    expect(calls.filter((call) => call.url.endsWith('/api/v1/admin/runtime-nodes'))).toHaveLength(1)
    expect(calls.some((call) => call.url.includes('/api/broadcasting/auth'))).toBe(false)
  })

  it('keeps in-app RuntimeNode navigation to one catalog and one list request', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.endsWith('/api/v1/admin/tenants')) return Promise.resolve(jsonResponse({ tenants: [{ id: 'tenant-1', slug: 'local', display_name: 'Local Tenant', status: 'active' }] }))
      if (url.endsWith('/api/v1/admin/runtime-node-catalog')) return Promise.resolve(jsonResponse({ catalog: runtimeCatalog }))
      if (url.endsWith('/api/v1/admin/runtime-nodes')) return Promise.resolve(jsonResponse({ runtime_nodes: [runtimeNode] }))
      if (url.includes('/api/v1/admin/runtime-nodes/runtime-1/')) return Promise.resolve(jsonResponse({ message: 'Initial detail fan-out was requested.' }, 500))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })
    vi.stubEnv('VITE_UTCP_REVERB_APP_KEY', 'public-reverb-key')
    vi.stubEnv('VITE_UTCP_WS_HOST', 'app.utcp.local.test')
    vi.stubEnv('VITE_UTCP_WS_PORT', '443')
    vi.stubEnv('VITE_UTCP_WS_SCHEME', 'wss')
    vi.stubEnv('VITE_UTCP_WS_PATH', '/app')
    const realtime = createMockRealtimeEcho()
    const wrapper = await mountApp('/admin/tenants')
    const catalogCallsBeforeNavigation = calls.filter((call) => call.url.endsWith('/api/v1/admin/runtime-node-catalog')).length
    const listCallsBeforeNavigation = calls.filter((call) => call.url.endsWith('/api/v1/admin/runtime-nodes')).length

    await wrapper.findAll('a').find((link) => link.text() === 'Telephony Nodes')?.trigger('click')
    await flushPromises()
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/admin/runtime-nodes')
    expect(wrapper.text()).toContain('Proof Runtime')
    expect(realtime.privateChannels).toEqual(['tenant.tenant-1.runtime-nodes'])
    expect(calls.filter((call) => call.url.endsWith('/api/v1/admin/runtime-node-catalog'))).toHaveLength(catalogCallsBeforeNavigation + 1)
    expect(calls.filter((call) => call.url.endsWith('/api/v1/admin/runtime-nodes'))).toHaveLength(listCallsBeforeNavigation + 1)
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration'))).toBe(false)
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/runtime-evidence'))).toBe(false)
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/history?limit=10'))).toBe(false)
  })

  it('renders runtime-node administration without exposing credential secrets', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeAdminFetch(calls)
    vi.stubEnv('VITE_UTCP_REVERB_APP_KEY', 'public-reverb-key')
    vi.stubEnv('VITE_UTCP_WS_HOST', 'app.utcp.local.test')
    vi.stubEnv('VITE_UTCP_WS_PORT', '443')
    vi.stubEnv('VITE_UTCP_WS_SCHEME', 'wss')
    vi.stubEnv('VITE_UTCP_WS_PATH', '/app')
    const realtime = createMockRealtimeEcho()
    const wrapper = await mountApp('/admin/runtime-nodes')

    expect(wrapper.text()).toContain('Proof Runtime')
    expect(wrapper.text()).toContain('Creating')
    expect(wrapper.text()).toContain('Live updates connecting')
    const runtimeHeading = wrapper.find('.section-heading')
    expect(runtimeHeading.exists()).toBe(true)
    expect(runtimeHeading.find('.live-updates-badge').text()).toContain('Live updates connecting')
    expect(runtimeHeading.findAll('button').some((button) => button.text() === 'Refresh')).toBe(true)
    expect(realtime.createdConfigs).toEqual([{
      appKey: 'public-reverb-key',
      wsHost: 'app.utcp.local.test',
      wsPort: 443,
      wsScheme: 'wss',
      wsPath: '/app',
      authEndpoint: '/api/broadcasting/auth',
    }])
    expect(realtime.privateChannels).toEqual(['tenant.tenant-1.runtime-nodes'])
    expect(calls.filter((call) => call.url.endsWith('/api/v1/admin/runtime-node-catalog'))).toHaveLength(1)
    expect(calls.filter((call) => call.url.endsWith('/api/v1/admin/runtime-nodes'))).toHaveLength(1)
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration'))).toBe(false)
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/runtime-evidence'))).toBe(false)
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/history?limit=10'))).toBe(false)

    realtime.emitConnection('state_change', { current: 'connected' })
    realtime.emitSubscriptionSucceeded('tenant.tenant-1.runtime-nodes')
    await nextTick()
    expect(wrapper.text()).toContain('Live updates connected')

    realtime.emitConnection('state_change', { current: 'disconnected' })
    await nextTick()
    expect(wrapper.text()).toContain('Live updates reconnecting')

    realtime.emitConnection('unavailable')
    await nextTick()
    expect(wrapper.text()).toContain('Live updates disconnected — displayed data may be stale')
    expect(wrapper.text()).toContain('Details')

    realtime.emitAuthError({ status: 403 })
    await nextTick()
    expect(wrapper.text()).toContain('Live updates unavailable for this session')

    await wrapper.findAll('button').find((button) => button.text() === 'Details')?.trigger('click')
    await flushPromises()

    await wrapper.findAll('button').find((button) => button.text() === 'Register Existing Runtime')?.trigger('click')
    await nextTick()

    expect(wrapper.text()).toContain('Secrets are write-only')
    expect(wrapper.text()).toContain('Asterisk ARI')
    expect(wrapper.text()).toContain('Event stream')
    expect(wrapper.text()).toContain('Runtime observation')
    expect(wrapper.text()).not.toContain('Conference execution')
    const adapterFieldLabels = wrapper.findAll('label')
      .map((label) => label.text().replace(/\s*required\s*/g, '').trim())
      .filter((label) => [
        'ARI application name',
        'Connect timeout',
        'Request timeout',
        'WebSocket handshake timeout',
        'Heartbeat interval',
        'Minimum reconnect delay',
        'Maximum reconnect delay',
      ].includes(label))
    expect(adapterFieldLabels).toEqual([
      'ARI application name',
      'Connect timeout',
      'Request timeout',
      'WebSocket handshake timeout',
      'Heartbeat interval',
      'Minimum reconnect delay',
      'Maximum reconnect delay',
    ])
    expect(wrapper.find('#runtime-node-runtime-1-adapter-field-application_name').exists()).toBe(true)
    expect(wrapper.find('#runtime-node-runtime-1-adapter-field-connect_timeout_ms').attributes('type')).toBe('number')
    expect(wrapper.text()).toContain('Desired state: draft')
    expect(wrapper.text()).toContain('Observed state: unobserved')
    expect(wrapper.text()).toContain('runtime_node.created')
    expect(wrapper.text()).toContain('Retire')
    expect(wrapper.text()).not.toContain('super-secret')
    expect(wrapper.text()).not.toContain('Start Listener')
    expect(wrapper.findAll('button').some((button) => button.text() === 'Connect')).toBe(false)
    expect(wrapper.text()).not.toContain('Retry')
    expect(wrapper.text()).not.toContain('Reconcile')
    expect(wrapper.text()).not.toContain('Mark Ready')

    const detailCallCountAfterFirstOpen = calls.filter((call) =>
      call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration') ||
      call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/runtime-evidence') ||
      call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/history?limit=10'),
    ).length
    await wrapper.findAll('button').find((button) => button.text() === 'Hide details')?.trigger('click')
    await wrapper.findAll('button').find((button) => button.text() === 'Details')?.trigger('click')
    await flushPromises()
    expect(calls.filter((call) =>
      call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration') ||
      call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/runtime-evidence') ||
      call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/history?limit=10'),
    )).toHaveLength(detailCallCountAfterFirstOpen + 3)
  })

  it('renders Conference operations with bounded list, detail, and participant request budgets', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockConferenceAdminFetch(calls)
    vi.stubEnv('VITE_UTCP_REVERB_APP_KEY', 'public-reverb-key')
    vi.stubEnv('VITE_UTCP_WS_HOST', 'app.utcp.local.test')
    vi.stubEnv('VITE_UTCP_WS_PORT', '443')
    vi.stubEnv('VITE_UTCP_WS_SCHEME', 'wss')
    vi.stubEnv('VITE_UTCP_WS_PATH', '/app')
    const realtime = createMockRealtimeEcho()

    const wrapper = await mountApp('/operations/conferences')

    expect(router.currentRoute.value.path).toBe('/operations/conferences')
    expect(wrapper.text()).toContain('Conferences')
    expect(wrapper.text()).toContain('Daily Ops')
    expect(wrapper.text()).toContain('Support Room')
    expect(wrapper.text()).toContain('Live updates connecting')
    expect(wrapper.text()).toContain('Conference operation list')
    expect(realtime.createdConfigs).toEqual([{
      appKey: 'public-reverb-key',
      wsHost: 'app.utcp.local.test',
      wsPort: 443,
      wsScheme: 'wss',
      wsPath: '/app',
      authEndpoint: '/api/broadcasting/auth',
    }])
    expect(realtime.privateChannels).toEqual(['tenant.tenant-1.conferences'])
    expect(calls.filter((call) => call.url.endsWith('/api/v1/admin/conferences'))).toHaveLength(1)
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/conferences/conference-1'))).toBe(false)
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/conferences/conference-1/participants'))).toBe(false)
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/conferences/conference-2'))).toBe(false)
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/conferences/conference-2/participants'))).toBe(false)

    realtime.emitConnection('state_change', { current: 'connected' })
    realtime.emitSubscriptionSucceeded('tenant.tenant-1.conferences')
    await nextTick()
    expect(wrapper.text()).toContain('Live updates connected')

    await wrapper.findAll('button').find((button) => button.text() === 'Details')?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Runtime node')
    expect(wrapper.text()).toContain('Binding')
    expect(wrapper.text()).toContain('Participants')
    expect(wrapper.text()).toContain('host')
    expect(calls.filter((call) => call.url.endsWith('/api/v1/admin/conferences'))).toHaveLength(1)
    expect(calls.filter((call) => call.url.endsWith('/api/v1/admin/conferences/conference-1'))).toHaveLength(1)
    expect(calls.filter((call) => call.url.endsWith('/api/v1/admin/conferences/conference-1/participants'))).toHaveLength(1)
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/conferences/conference-2'))).toBe(false)
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/conferences/conference-2/participants'))).toBe(false)
  })

  it('renders Runtime Operations as a read-only capability-gated route with bounded request budgets', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeOperationAdminFetch(calls)
    vi.stubEnv('VITE_UTCP_REVERB_APP_KEY', 'public-reverb-key')
    vi.stubEnv('VITE_UTCP_WS_HOST', 'app.utcp.local.test')
    vi.stubEnv('VITE_UTCP_WS_PORT', '443')
    vi.stubEnv('VITE_UTCP_WS_SCHEME', 'wss')
    vi.stubEnv('VITE_UTCP_WS_PATH', '/app')
    const realtime = createMockRealtimeEcho()

    const wrapper = await mountApp('/operations/runtime-operations')

    expect(router.currentRoute.value.path).toBe('/operations/runtime-operations')
    expect(wrapper.text()).toContain('Runtime operations')
    expect(wrapper.text()).toContain('Runtime operation list')
    expect(wrapper.text()).toContain('runtime.node.inspect')
    expect(wrapper.text()).toContain('Proof Runtime')
    expect(wrapper.text()).toContain('Live updates connecting')
    expect(wrapper.text()).toContain('Page 1 of 1')
    expect(wrapper.text()).not.toContain('Retry')
    expect(wrapper.text()).not.toContain('Cancel')
    expect(wrapper.text()).not.toContain('Reconcile')
    expect(wrapper.text()).not.toContain('must-not-leak')
    expect(realtime.privateChannels).toEqual(['tenant.tenant-1.runtime-operations'])
    expect(calls.filter((call) => call.url.includes('/api/v1/admin/runtime-operations') && !call.url.includes('/operation-'))).toHaveLength(1)
    expect(calls.some((call) => call.url.includes('/api/v1/admin/runtime-operations/operation-1'))).toBe(false)
    expect(calls.some((call) => call.url.includes('/api/v1/admin/runtime-operations/operation-2'))).toBe(false)

    realtime.emitConnection('state_change', { current: 'connected' })
    realtime.emitSubscriptionSucceeded('tenant.tenant-1.runtime-operations')
    await nextTick()
    expect(wrapper.text()).toContain('Live updates connected')

    await wrapper.findAll('button').find((button) => button.text() === 'Details')?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Payload version')
    expect(wrapper.text()).toContain('Reconciliation')
    expect(wrapper.text()).toContain('request')
    expect(calls.filter((call) => call.url.includes('/api/v1/admin/runtime-operations') && !call.url.includes('/operation-'))).toHaveLength(1)
    expect(calls.filter((call) => call.url.includes('/api/v1/admin/runtime-operations/operation-1'))).toHaveLength(1)
    expect(calls.some((call) => call.url.includes('/api/v1/admin/runtime-operations/operation-2'))).toBe(false)
  })

  it('maps Runtime Operation filters and pagination to canonical query parameters', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeOperationAdminFetch(calls)
    const wrapper = await mountApp('/operations/runtime-operations?page=2&per_page=10&status=running&runtime_node_id=runtime-1&operation_type=runtime.node.inspect&correlation_id=correlation-1&created_from=2026-07-23T10:00:00Z')
    await flushPromises()

    expect(calls.some((call) =>
      call.url.includes('/api/v1/admin/runtime-operations?')
      && call.url.includes('page=2')
      && call.url.includes('per_page=10')
      && call.url.includes('status=running')
      && call.url.includes('runtime_node_id=runtime-1')
      && call.url.includes('operation_type=runtime.node.inspect')
      && call.url.includes('correlation_id=correlation-1')
      && call.url.includes('created_from=2026-07-23T10%3A00%3A00Z'),
    )).toBe(true)

    await wrapper.find('#runtime-operation-status-filter').setValue('succeeded')
    await wrapper.find('form[role="search"]').trigger('submit.prevent')
    await flushPromises()

    expect(router.currentRoute.value.query.page).toBeUndefined()
    expect(router.currentRoute.value.query.status).toBe('succeeded')
    expect(calls.at(-1)?.url).toContain('status=succeeded')
    expect(calls.at(-1)?.url).not.toContain('page=2')

    await wrapper.find('.ui-pagination button[aria-label="Go to next page"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query.page).toBe('2')
    expect(calls.at(-1)?.url).toContain('status=succeeded')
    expect(calls.at(-1)?.url).toContain('page=2')
  })

  it('guards Runtime Operation pagination while the canonical list request is pending', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    const pendingListResponses: Array<ReturnType<typeof deferredResponse>> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.includes('/api/v1/admin/runtime-operations')) {
        const pending = pendingListResponses.shift()
        if (pending) return pending.promise

        const params = new URL(url, 'http://utcp.local.test').searchParams
        return Promise.resolve(jsonResponse({
          runtime_operations: [runtimeOperation, secondRuntimeOperation],
          pagination: {
            page: params.get('page') === '2' ? 2 : 1,
            per_page: params.get('per_page') === '10' ? 10 : 20,
            total: 40,
            has_more: params.get('page') !== '2',
          },
        }))
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })
    const wrapper = await mountApp('/operations/runtime-operations?per_page=10&status=running')
    attachWrapperToDocument(wrapper)
    const listCalls = () => calls.filter((call) => call.url.includes('/api/v1/admin/runtime-operations'))
    const firstListCount = listCalls().length
    const paginationPending = deferredResponse()
    pendingListResponses.push(paginationPending)
    const nextButton = wrapper.find('.ui-pagination button[aria-label="Go to next page"]')
    const nextButtonElement = nextButton.element as HTMLButtonElement

    nextButtonElement.focus()
    nextButtonElement.click()
    await flushPromises()

    expect(listCalls()).toHaveLength(firstListCount + 1)
    expect(document.activeElement).toBe(nextButtonElement)
    expect(nextButton.attributes('disabled')).toBeUndefined()
    expect(nextButton.attributes('aria-disabled')).toBe('true')
    expect(nextButton.attributes('aria-busy')).toBe('true')
    expect(router.currentRoute.value.query).toMatchObject({ page: '2', per_page: '10', status: 'running' })

    await nextButton.trigger('keydown.enter')
    nextButtonElement.click()
    await nextButton.trigger('keydown.space')
    nextButtonElement.click()
    await flushPromises()

    expect(listCalls()).toHaveLength(firstListCount + 1)
    expect(router.currentRoute.value.query).toMatchObject({ page: '2', per_page: '10', status: 'running' })
    paginationPending.resolve(jsonResponse({
      runtime_operations: [runtimeOperation, secondRuntimeOperation],
      pagination: { page: 2, per_page: 10, total: 40, has_more: false },
    }))
    await flushPromises()

    expect(nextButton.attributes('aria-disabled')).toBeUndefined()
    expect(nextButton.attributes('aria-busy')).toBeUndefined()
    expect(wrapper.text()).toContain('Page 2')
    expect(calls.at(-1)?.url).toContain('per_page=10')
    expect(calls.at(-1)?.url).toContain('status=running')
  })

  it('preserves Runtime Operation pagination and filters during notification rereads', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeOperationAdminFetch(calls)
    vi.stubEnv('VITE_UTCP_REVERB_APP_KEY', 'public-reverb-key')
    vi.stubEnv('VITE_UTCP_WS_HOST', 'app.utcp.local.test')
    vi.stubEnv('VITE_UTCP_WS_PORT', '443')
    vi.stubEnv('VITE_UTCP_WS_SCHEME', 'wss')
    vi.stubEnv('VITE_UTCP_WS_PATH', '/app')
    const realtime = createMockRealtimeEcho()
    const wrapper = await mountApp('/operations/runtime-operations?page=2&per_page=10&status=running')

    await wrapper.findAll('button').find((button) => button.text() === 'Details')?.trigger('click')
    await flushPromises()
    const listCountAfterSelect = calls.filter((call) => call.url.includes('/api/v1/admin/runtime-operations') && !call.url.includes('/operation-')).length
    const detailCountAfterSelect = calls.filter((call) => call.url.includes('/api/v1/admin/runtime-operations/operation-1')).length

    realtime.emitRuntimeOperationNotification('tenant.tenant-1.runtime-operations', {
      event_type: 'runtime_operation.status_changed',
      aggregate_type: 'runtime_operation',
      aggregate_id: 'operation-1',
      runtime_operation_id: 'operation-1',
      tenant_id: 'tenant-1',
      occurred_at: '2026-07-23T10:02:00Z',
      status: 'terminal_failed',
      payload: { secret: 'must-not-use' },
    })
    await flushPromises()

    expect(calls.filter((call) => call.url.includes('/api/v1/admin/runtime-operations') && !call.url.includes('/operation-'))).toHaveLength(listCountAfterSelect + 1)
    expect(calls.filter((call) => call.url.includes('/api/v1/admin/runtime-operations/operation-1'))).toHaveLength(detailCountAfterSelect + 1)
    expect(calls.at(-2)?.url).toContain('page=2')
    expect(calls.at(-2)?.url).toContain('per_page=10')
    expect(calls.at(-2)?.url).toContain('status=running')

    realtime.emitRuntimeOperationNotification('tenant.tenant-1.runtime-operations', {
      event_type: 'runtime_operation.status_changed',
      aggregate_type: 'runtime_operation',
      aggregate_id: 'operation-2',
      runtime_operation_id: 'operation-2',
      tenant_id: 'tenant-1',
      occurred_at: '2026-07-23T10:03:00Z',
    })
    await flushPromises()

    expect(calls.filter((call) => call.url.includes('/api/v1/admin/runtime-operations') && !call.url.includes('/operation-'))).toHaveLength(listCountAfterSelect + 2)
    expect(calls.filter((call) => call.url.includes('/api/v1/admin/runtime-operations/operation-1'))).toHaveLength(detailCountAfterSelect + 1)
    expect(calls.some((call) => call.url.includes('/api/v1/admin/runtime-operations/operation-2'))).toBe(false)
    expect(JSON.stringify(window.localStorage)).not.toContain('operation-1')
    expect(JSON.stringify(window.sessionStorage)).not.toContain('must-not-use')
  })

  it('handles Runtime Operation empty, forbidden, validation, and not-found states', async () => {
    const emptyCalls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeOperationAdminFetch(emptyCalls, { empty: true })
    const emptyWrapper = await mountApp('/operations/runtime-operations')
    expect(emptyWrapper.text()).toContain('No runtime operations')
    emptyWrapper.unmount()

    const forbiddenCalls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeOperationAdminFetch(forbiddenCalls, { listStatus: 403 })
    const forbiddenWrapper = await mountApp('/operations/runtime-operations')
    expect(forbiddenWrapper.text()).toContain('Runtime operations forbidden')
    forbiddenWrapper.unmount()

    const validationCalls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeOperationAdminFetch(validationCalls, { listStatus: 422 })
    const validationWrapper = await mountApp('/operations/runtime-operations?operation_type=invalid.operation')
    expect(validationWrapper.text()).toContain('Runtime operations unavailable')
    validationWrapper.unmount()

    const notFoundCalls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeOperationAdminFetch(notFoundCalls, { detailStatus: 404 })
    const notFoundWrapper = await mountApp('/operations/runtime-operations')
    await notFoundWrapper.findAll('button').find((button) => button.text() === 'Details')?.trigger('click')
    await flushPromises()
    expect(notFoundWrapper.text()).toContain('Runtime operation detail unavailable')
  })

  it('renders Runtime Reconciliations as a read-only capability-gated route with bounded request budgets', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeReconciliationAdminFetch(calls)
    vi.stubEnv('VITE_UTCP_REVERB_APP_KEY', 'public-reverb-key')
    vi.stubEnv('VITE_UTCP_WS_HOST', 'app.utcp.local.test')
    vi.stubEnv('VITE_UTCP_WS_PORT', '443')
    vi.stubEnv('VITE_UTCP_WS_SCHEME', 'wss')
    vi.stubEnv('VITE_UTCP_WS_PATH', '/app')
    const realtime = createMockRealtimeEcho()

    const wrapper = await mountApp('/operations/runtime-reconciliations')

    expect(router.currentRoute.value.path).toBe('/operations/runtime-reconciliations')
    expect(wrapper.text()).toContain('Runtime reconciliations')
    expect(wrapper.text()).toContain('Runtime reconciliation list')
    expect(wrapper.text()).toContain('Proof Runtime')
    expect(wrapper.text()).toContain('operation required')
    expect(wrapper.text()).toContain('Drift detected')
    expect(wrapper.text()).toContain('Desired 4')
    expect(wrapper.text()).toContain('Observed 3')
    expect(wrapper.text()).toContain('runtime_unavailable:profile_missing')
    expect(wrapper.text()).toContain('Live updates connecting')
    expect(wrapper.text()).toContain('Page 1 of 1')
    expect(wrapper.text()).not.toContain('Retry')
    expect(wrapper.text()).not.toContain('Reconcile now')
    expect(wrapper.text()).not.toContain('Repair')
    expect(wrapper.text()).not.toContain('raw-desired-secret')
    expect(realtime.privateChannels).toEqual(['tenant.tenant-1.runtime-reconciliations'])
    expect(calls.filter((call) => call.url.includes('/api/v1/admin/runtime-reconciliations') && !call.url.includes('/reconciliation-'))).toHaveLength(1)
    expect(calls.some((call) => call.url.includes('/api/v1/admin/runtime-reconciliations/reconciliation-1'))).toBe(false)
    expect(calls.some((call) => call.url.includes('/api/v1/admin/runtime-reconciliations/reconciliation-2'))).toBe(false)

    realtime.emitConnection('state_change', { current: 'connected' })
    realtime.emitSubscriptionSucceeded('tenant.tenant-1.runtime-reconciliations')
    await nextTick()
    expect(wrapper.text()).toContain('Live updates connected')

    await wrapper.findAll('button').find((button) => button.text() === 'Details')?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Next check')
    expect(wrapper.text()).toContain('Last operation')
    expect(wrapper.text()).toContain('runtime.node.inspect')
    expect(calls.filter((call) => call.url.includes('/api/v1/admin/runtime-reconciliations') && !call.url.includes('/reconciliation-'))).toHaveLength(1)
    expect(calls.filter((call) => call.url.includes('/api/v1/admin/runtime-reconciliations/reconciliation-1'))).toHaveLength(1)
    expect(calls.some((call) => call.url.includes('/api/v1/admin/runtime-reconciliations/reconciliation-2'))).toBe(false)
  })

  it('maps Runtime Reconciliation filters and pagination to canonical query parameters', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeReconciliationAdminFetch(calls)
    const wrapper = await mountApp('/operations/runtime-reconciliations?page=2&per_page=10&status=operation_required&runtime_node_id=runtime-1&target_type=runtime_node&runtime_operation_id=operation-1&updated_from=2026-07-24T10:00:00Z')
    await flushPromises()

    expect(calls.some((call) =>
      call.url.includes('/api/v1/admin/runtime-reconciliations?')
      && call.url.includes('page=2')
      && call.url.includes('per_page=10')
      && call.url.includes('status=operation_required')
      && call.url.includes('runtime_node_id=runtime-1')
      && call.url.includes('target_type=runtime_node')
      && call.url.includes('runtime_operation_id=operation-1')
      && call.url.includes('updated_from=2026-07-24T10%3A00%3A00Z'),
    )).toBe(true)

    await wrapper.find('#runtime-reconciliation-status-filter').setValue('converged')
    await wrapper.find('form[role="search"]').trigger('submit.prevent')
    await flushPromises()

    expect(router.currentRoute.value.query.page).toBeUndefined()
    expect(router.currentRoute.value.query.status).toBe('converged')
    expect(calls.at(-1)?.url).toContain('status=converged')
    expect(calls.at(-1)?.url).not.toContain('page=2')

    await wrapper.find('.ui-pagination button[aria-label="Go to next page"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query.page).toBe('2')
    expect(calls.at(-1)?.url).toContain('status=converged')
    expect(calls.at(-1)?.url).toContain('page=2')
  })

  it('preserves Runtime Reconciliation pagination and filters during notification rereads', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeReconciliationAdminFetch(calls)
    vi.stubEnv('VITE_UTCP_REVERB_APP_KEY', 'public-reverb-key')
    vi.stubEnv('VITE_UTCP_WS_HOST', 'app.utcp.local.test')
    vi.stubEnv('VITE_UTCP_WS_PORT', '443')
    vi.stubEnv('VITE_UTCP_WS_SCHEME', 'wss')
    vi.stubEnv('VITE_UTCP_WS_PATH', '/app')
    const realtime = createMockRealtimeEcho()
    const wrapper = await mountApp('/operations/runtime-reconciliations?page=2&per_page=10&status=operation_required')

    await wrapper.findAll('button').find((button) => button.text() === 'Details')?.trigger('click')
    await flushPromises()
    const listCountAfterSelect = calls.filter((call) => call.url.includes('/api/v1/admin/runtime-reconciliations') && !call.url.includes('/reconciliation-')).length
    const detailCountAfterSelect = calls.filter((call) => call.url.includes('/api/v1/admin/runtime-reconciliations/reconciliation-1')).length

    realtime.emitRuntimeReconciliationNotification('tenant.tenant-1.runtime-reconciliations', {
      event_type: 'runtime_reconciliation.converged',
      aggregate_type: 'runtime_reconciliation',
      aggregate_id: 'reconciliation-1',
      runtime_reconciliation_id: 'reconciliation-1',
      tenant_id: 'tenant-1',
      occurred_at: '2026-07-24T10:02:00Z',
      status: 'converged',
      desired_state: { secret: 'must-not-use' },
      observed_state: { secret: 'must-not-use' },
    })
    await flushPromises()

    expect(calls.filter((call) => call.url.includes('/api/v1/admin/runtime-reconciliations') && !call.url.includes('/reconciliation-'))).toHaveLength(listCountAfterSelect + 1)
    expect(calls.filter((call) => call.url.includes('/api/v1/admin/runtime-reconciliations/reconciliation-1'))).toHaveLength(detailCountAfterSelect + 1)
    expect(calls.at(-2)?.url).toContain('page=2')
    expect(calls.at(-2)?.url).toContain('per_page=10')
    expect(calls.at(-2)?.url).toContain('status=operation_required')

    realtime.emitRuntimeReconciliationNotification('tenant.tenant-1.runtime-reconciliations', {
      event_type: 'runtime_reconciliation.converged',
      aggregate_type: 'runtime_reconciliation',
      aggregate_id: 'reconciliation-2',
      runtime_reconciliation_id: 'reconciliation-2',
      tenant_id: 'tenant-1',
      occurred_at: '2026-07-24T10:03:00Z',
    })
    await flushPromises()

    expect(calls.filter((call) => call.url.includes('/api/v1/admin/runtime-reconciliations') && !call.url.includes('/reconciliation-'))).toHaveLength(listCountAfterSelect + 2)
    expect(calls.filter((call) => call.url.includes('/api/v1/admin/runtime-reconciliations/reconciliation-1'))).toHaveLength(detailCountAfterSelect + 1)
    expect(calls.some((call) => call.url.includes('/api/v1/admin/runtime-reconciliations/reconciliation-2'))).toBe(false)
    expect(JSON.stringify(window.localStorage)).not.toContain('reconciliation-1')
    expect(JSON.stringify(window.sessionStorage)).not.toContain('must-not-use')
  })

  it('handles Runtime Reconciliation empty, forbidden, validation, and not-found states', async () => {
    const emptyCalls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeReconciliationAdminFetch(emptyCalls, { empty: true })
    const emptyWrapper = await mountApp('/operations/runtime-reconciliations')
    expect(emptyWrapper.text()).toContain('No runtime reconciliations')
    emptyWrapper.unmount()

    const forbiddenCalls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeReconciliationAdminFetch(forbiddenCalls, { listStatus: 403 })
    const forbiddenWrapper = await mountApp('/operations/runtime-reconciliations')
    expect(forbiddenWrapper.text()).toContain('Runtime reconciliations forbidden')
    forbiddenWrapper.unmount()

    const validationCalls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeReconciliationAdminFetch(validationCalls, { listStatus: 422 })
    const validationWrapper = await mountApp('/operations/runtime-reconciliations?status=invalid')
    expect(validationWrapper.text()).toContain('Runtime reconciliations unavailable')
    validationWrapper.unmount()

    const notFoundCalls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeReconciliationAdminFetch(notFoundCalls, { detailStatus: 404 })
    const notFoundWrapper = await mountApp('/operations/runtime-reconciliations')
    await notFoundWrapper.findAll('button').find((button) => button.text() === 'Details')?.trigger('click')
    await flushPromises()
    expect(notFoundWrapper.text()).toContain('Runtime reconciliation detail unavailable')
  })

  it('renders Audit records as a read-only capability-gated route with bounded request budgets', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockAuditRecordAdminFetch(calls)

    const wrapper = await mountApp('/admin/audit-records')

    expect(router.currentRoute.value.path).toBe('/admin/audit-records')
    expect(wrapper.text()).toContain('Audit records')
    expect(wrapper.text()).toContain('Audit record list')
    expect(wrapper.text()).toContain('runtime_node.created')
    expect(wrapper.text()).toContain('Actor user deleted-user-1')
    expect(wrapper.text()).toContain('Subject runtime_node deleted-runtime-1')
    expect(wrapper.text()).toContain('succeeded')
    expect(wrapper.text()).toContain('Page 1 of 1')
    expect(wrapper.text()).not.toContain('Delete')
    expect(wrapper.text()).not.toContain('Redact')
    expect(wrapper.text()).not.toContain('Replay')
    expect(wrapper.text()).not.toContain('Export')
    expect(wrapper.text()).not.toContain('must-not-leak')
    expect(calls.filter((call) => call.url.includes('/api/v1/admin/audit-records') && !call.url.includes('/api/v1/admin/audit-records/audit-'))).toHaveLength(1)
    expect(calls.some((call) => call.url.includes('/api/v1/admin/audit-records/audit-1'))).toBe(false)
    expect(calls.some((call) => call.url.includes('/api/v1/admin/audit-records/audit-2'))).toBe(false)

    await wrapper.findAll('button').find((button) => button.text() === 'Details')?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('routine operator review')
    expect(wrapper.text()).toContain('Safe metadata')
    expect(wrapper.text()).toContain('visible')
    expect(wrapper.text()).toContain('deleted-user-1')
    expect(wrapper.text()).toContain('deleted-runtime-1')
    expect(calls.filter((call) => call.url.includes('/api/v1/admin/audit-records') && !call.url.includes('/api/v1/admin/audit-records/audit-'))).toHaveLength(1)
    expect(calls.filter((call) => call.url.includes('/api/v1/admin/audit-records/audit-1'))).toHaveLength(1)
    expect(calls.some((call) => call.url.includes('/api/v1/admin/audit-records/audit-2'))).toBe(false)
    expect(wrapper.text()).not.toContain('password')
    expect(wrapper.text()).not.toContain('api_token')
    expect(wrapper.text()).not.toContain('authorization')
    expect(wrapper.text()).not.toContain('request_body')
    expect(wrapper.text()).not.toContain('desired_state')
    expect(wrapper.text()).not.toContain('observed_state')
    expect(wrapper.text()).not.toContain('provider_response')
  })

  it('maps every Audit filter and pagination control to canonical query parameters', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockAuditRecordAdminFetch(calls)
    const wrapper = await mountApp('/admin/audit-records?page=2&per_page=10&actor_type=user&actor_id=deleted-user-1&action=runtime_node.created&subject_type=runtime_node&subject_id=deleted-runtime-1&correlation_id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa&request_id=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb&occurred_from=2026-07-24T10:00:00Z&occurred_to=2026-07-24T11:00:00Z')
    await flushPromises()

    expect(calls.some((call) =>
      call.url.includes('/api/v1/admin/audit-records?')
      && call.url.includes('page=2')
      && call.url.includes('per_page=10')
      && call.url.includes('actor_type=user')
      && call.url.includes('actor_id=deleted-user-1')
      && call.url.includes('action=runtime_node.created')
      && call.url.includes('subject_type=runtime_node')
      && call.url.includes('subject_id=deleted-runtime-1')
      && call.url.includes('correlation_id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
      && call.url.includes('request_id=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')
      && call.url.includes('occurred_from=2026-07-24T10%3A00%3A00Z')
      && call.url.includes('occurred_to=2026-07-24T11%3A00%3A00Z'),
    )).toBe(true)

    await wrapper.find('#audit-action-filter').setValue('runtime_node.updated')
    await wrapper.find('form[role="search"]').trigger('submit.prevent')
    await flushPromises()

    expect(router.currentRoute.value.query.page).toBeUndefined()
    expect(router.currentRoute.value.query.action).toBe('runtime_node.updated')
    expect(calls.at(-1)?.url).toContain('action=runtime_node.updated')
    expect(calls.at(-1)?.url).not.toContain('page=2')
    expect(calls.filter((call) => call.url.includes('/api/v1/admin/audit-records/audit-'))).toHaveLength(0)

    await wrapper.find('.ui-pagination button[aria-label="Go to next page"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query.page).toBe('2')
    expect(calls.at(-1)?.url).toContain('action=runtime_node.updated')
    expect(calls.at(-1)?.url).toContain('page=2')
    expect(calls.filter((call) => call.url.includes('/api/v1/admin/audit-records/audit-'))).toHaveLength(0)

    await wrapper.find('form[role="search"]').trigger('reset')
    await flushPromises()

    expect(router.currentRoute.value.query.action).toBeUndefined()
    expect(router.currentRoute.value.query.page).toBeUndefined()
    expect(calls.at(-1)?.url).not.toContain('action=')
  })

  it('preserves Audit pagination and filters during explicit refresh without detail fan-out', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockAuditRecordAdminFetch(calls)
    const wrapper = await mountApp('/admin/audit-records?page=2&per_page=10&actor_type=user&action=runtime_node.created')
    await flushPromises()

    await wrapper.findAll('button').find((button) => button.text() === 'Details')?.trigger('click')
    await flushPromises()
    const listCountAfterSelect = calls.filter((call) => call.url.includes('/api/v1/admin/audit-records') && !call.url.includes('/api/v1/admin/audit-records/audit-')).length
    const detailCountAfterSelect = calls.filter((call) => call.url.includes('/api/v1/admin/audit-records/audit-1')).length

    await wrapper.findAll('button').find((button) => button.text() === 'Refresh')?.trigger('click')
    await flushPromises()

    expect(calls.filter((call) => call.url.includes('/api/v1/admin/audit-records') && !call.url.includes('/api/v1/admin/audit-records/audit-'))).toHaveLength(listCountAfterSelect + 1)
    expect(calls.filter((call) => call.url.includes('/api/v1/admin/audit-records/audit-1'))).toHaveLength(detailCountAfterSelect)
    expect(calls.at(-1)?.url).toContain('page=2')
    expect(calls.at(-1)?.url).toContain('per_page=10')
    expect(calls.at(-1)?.url).toContain('actor_type=user')
    expect(calls.at(-1)?.url).toContain('action=runtime_node.created')
  })

  it('preserves Audit UiButton focus and request budgets during pending loading actions', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    const pendingListResponses: Array<ReturnType<typeof deferredResponse>> = []
    const pendingDetailResponses: Array<ReturnType<typeof deferredResponse>> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.includes('/api/v1/admin/audit-records/audit-1')) {
        const pending = pendingDetailResponses.shift()
        if (pending) return pending.promise

        return Promise.resolve(jsonResponse({ audit_record: auditRecordDetail }))
      }
      if (url.includes('/api/v1/admin/audit-records')) {
        const pending = pendingListResponses.shift()
        if (pending) return pending.promise

        const params = new URL(url, 'http://utcp.local.test').searchParams
        return Promise.resolve(jsonResponse({
          audit_records: [auditRecord, secondAuditRecord],
          pagination: {
            page: params.get('page') === '2' ? 2 : 1,
            per_page: params.get('per_page') === '10' ? 10 : 20,
            total: 2,
            has_more: params.get('page') !== '2',
          },
        }))
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })
    const wrapper = await mountApp('/admin/audit-records')
    attachWrapperToDocument(wrapper)
    const listCalls = () => calls.filter((call) => call.url.includes('/api/v1/admin/audit-records') && !call.url.includes('/api/v1/admin/audit-records/audit-'))
    const detailCalls = () => calls.filter((call) => call.url.includes('/api/v1/admin/audit-records/audit-1'))

    expect(listCalls()).toHaveLength(1)

    const refreshPending = deferredResponse()
    pendingListResponses.push(refreshPending)
    const refreshButton = wrapper.findAll('button').find((button) => button.text() === 'Refresh')
    expect(refreshButton).toBeDefined()
    refreshButton?.element.focus()
    ;(refreshButton?.element as HTMLButtonElement).click()
    await nextTick()

    expect(document.activeElement).toBe(refreshButton?.element)
    expect(listCalls()).toHaveLength(2)

    await refreshButton?.trigger('keydown.enter')
    ;(refreshButton?.element as HTMLButtonElement).click()
    await refreshButton?.trigger('keydown.space')
    ;(refreshButton?.element as HTMLButtonElement).click()

    expect(listCalls()).toHaveLength(2)
    refreshPending.resolve(jsonResponse({
      audit_records: [auditRecord, secondAuditRecord],
      pagination: { page: 1, per_page: 20, total: 2, has_more: true },
    }))
    await flushPromises()
    expect(document.activeElement).toBe(refreshButton?.element)

    const detailPending = deferredResponse()
    pendingDetailResponses.push(detailPending)
    const detailButton = wrapper.findAll('button').find((button) => button.text() === 'Details')
    expect(detailButton).toBeDefined()
    detailButton?.element.focus()
    ;(detailButton?.element as HTMLButtonElement).click()
    await nextTick()

    expect(document.activeElement).toBe(detailButton?.element)
    expect(document.activeElement).not.toBe(document.body)
    expect(detailCalls()).toHaveLength(1)

    await detailButton?.trigger('keydown.enter')
    ;(detailButton?.element as HTMLButtonElement).click()
    expect(detailCalls()).toHaveLength(1)
    detailPending.resolve(jsonResponse({ audit_record: auditRecordDetail }))
    await flushPromises()
    expect(wrapper.text()).toContain('routine operator review')
    expect(document.activeElement).toBe(detailButton?.element)

    const filterPending = deferredResponse()
    pendingListResponses.push(filterPending)
    await wrapper.find('#audit-action-filter').setValue('runtime_node.updated')
    const applyButton = wrapper.findAll('button').find((button) => button.text() === 'Apply')
    expect(applyButton).toBeDefined()
    applyButton?.element.focus()
    ;(applyButton?.element as HTMLButtonElement).click()
    await flushPromises()

    expect(document.activeElement).toBe(applyButton?.element)
    expect(listCalls()).toHaveLength(3)
    filterPending.resolve(jsonResponse({
      audit_records: [auditRecord, secondAuditRecord],
      pagination: { page: 1, per_page: 20, total: 2, has_more: true },
    }))
    await flushPromises()

    const paginationPending = deferredResponse()
    pendingListResponses.push(paginationPending)
    const nextButton = wrapper.find('.ui-pagination button[aria-label="Go to next page"]')
    const nextButtonElement = nextButton.element as HTMLButtonElement
    nextButtonElement.focus()
    nextButtonElement.click()
    await flushPromises()

    expect(document.activeElement).toBe(nextButtonElement)
    expect(nextButton.attributes('disabled')).toBeUndefined()
    expect(nextButton.attributes('aria-disabled')).toBe('true')
    expect(nextButton.attributes('aria-busy')).toBe('true')
    expect(listCalls()).toHaveLength(4)

    await nextButton.trigger('keydown.enter')
    nextButtonElement.click()
    await nextButton.trigger('keydown.space')
    nextButtonElement.click()
    await flushPromises()

    expect(document.activeElement).toBe(nextButtonElement)
    expect(router.currentRoute.value.query.page).toBe('2')
    expect(listCalls()).toHaveLength(4)
    paginationPending.resolve(jsonResponse({
      audit_records: [auditRecord, secondAuditRecord],
      pagination: { page: 2, per_page: 20, total: 2, has_more: false },
    }))
    await flushPromises()
    expect(nextButton.attributes('aria-disabled')).toBeUndefined()
    expect(nextButton.attributes('aria-busy')).toBeUndefined()
  })

  it('returns Audit detail close focus to the originating Details trigger without rereading the list', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockAuditRecordAdminFetch(calls)
    const wrapper = await mountApp('/admin/audit-records')
    attachWrapperToDocument(wrapper)
    const listCountAfterMount = calls.filter((call) => call.url.includes('/api/v1/admin/audit-records') && !call.url.includes('/api/v1/admin/audit-records/audit-')).length
    const detailButton = wrapper.findAll('button').find((button) => button.text() === 'Details')
    expect(detailButton).toBeDefined()

    detailButton?.element.focus()
    ;(detailButton?.element as HTMLButtonElement).click()
    await flushPromises()

    expect(wrapper.text()).toContain('routine operator review')
    const closeButton = wrapper.findAll('button').find((button) => button.text() === 'Close')
    expect(closeButton).toBeDefined()
    closeButton?.element.focus()
    ;(closeButton?.element as HTMLButtonElement).click()
    await nextTick()

    expect(wrapper.text()).not.toContain('routine operator review')
    expect(document.activeElement).toBe(detailButton?.element)
    expect(calls.filter((call) => call.url.includes('/api/v1/admin/audit-records') && !call.url.includes('/api/v1/admin/audit-records/audit-'))).toHaveLength(listCountAfterMount)
  })

  it('updates Audit detail focus return when switching selection', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.includes('/api/v1/admin/audit-records/audit-1')) return Promise.resolve(jsonResponse({ audit_record: auditRecordDetail }))
      if (url.includes('/api/v1/admin/audit-records/audit-2')) {
        return Promise.resolve(jsonResponse({ audit_record: { ...auditRecordDetail, ...secondAuditRecord, reason: 'second operator review' } }))
      }
      if (url.includes('/api/v1/admin/audit-records')) {
        return Promise.resolve(jsonResponse({
          audit_records: [auditRecord, secondAuditRecord],
          pagination: { page: 1, per_page: 20, total: 2, has_more: true },
        }))
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })
    const wrapper = await mountApp('/admin/audit-records')
    attachWrapperToDocument(wrapper)

    const firstDetails = wrapper.findAll('button').filter((button) => button.text() === 'Details')[0]
    firstDetails.element.focus()
    ;(firstDetails.element as HTMLButtonElement).click()
    await flushPromises()

    const secondDetails = wrapper.findAll('button').filter((button) => button.text() === 'Details')[0]
    secondDetails.element.focus()
    ;(secondDetails.element as HTMLButtonElement).click()
    await flushPromises()

    expect(wrapper.text()).toContain('second operator review')
    const closeButton = wrapper.findAll('button').find((button) => button.text() === 'Close')
    closeButton?.element.focus()
    ;(closeButton?.element as HTMLButtonElement).click()
    await nextTick()

    expect(document.activeElement).toBe(secondDetails.element)
    expect(calls.filter((call) => call.url.includes('/api/v1/admin/audit-records/audit-1'))).toHaveLength(1)
    expect(calls.filter((call) => call.url.includes('/api/v1/admin/audit-records/audit-2'))).toHaveLength(1)
  })

  it('does not focus a detached Audit detail opener when closing', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockAuditRecordAdminFetch(calls)
    const wrapper = await mountApp('/admin/audit-records')
    attachWrapperToDocument(wrapper)
    const detailButton = wrapper.findAll('button').find((button) => button.text() === 'Details')
    expect(detailButton).toBeDefined()

    detailButton?.element.focus()
    ;(detailButton?.element as HTMLButtonElement).click()
    await flushPromises()
    detailButton?.element.remove()

    const closeButton = wrapper.findAll('button').find((button) => button.text() === 'Close')
    closeButton?.element.focus()
    ;(closeButton?.element as HTMLButtonElement).click()
    await nextTick()

    expect(wrapper.text()).not.toContain('routine operator review')
    expect(document.activeElement).not.toBe(detailButton?.element)
    expect(calls.filter((call) => call.url.includes('/api/v1/admin/audit-records') && !call.url.includes('/api/v1/admin/audit-records/audit-'))).toHaveLength(1)
  })

  it('handles Audit empty, forbidden, validation, refresh failure, and not-found states', async () => {
    const emptyCalls: Array<{ url: string; body?: unknown }> = []
    mockAuditRecordAdminFetch(emptyCalls, { empty: true })
    const emptyWrapper = await mountApp('/admin/audit-records')
    expect(emptyWrapper.text()).toContain('No audit records')
    emptyWrapper.unmount()

    const forbiddenCalls: Array<{ url: string; body?: unknown }> = []
    mockAuditRecordAdminFetch(forbiddenCalls, { listStatus: 403 })
    const forbiddenWrapper = await mountApp('/admin/audit-records')
    expect(forbiddenWrapper.text()).toContain('Audit records forbidden')
    forbiddenWrapper.unmount()

    const validationCalls: Array<{ url: string; body?: unknown }> = []
    mockAuditRecordAdminFetch(validationCalls, { listStatus: 422 })
    const validationWrapper = await mountApp('/admin/audit-records?correlation_id=not-a-correlation')
    expect(validationWrapper.text()).toContain('Audit records unavailable')
    expect(validationWrapper.text()).toContain('list unavailable')
    validationWrapper.unmount()

    const notFoundCalls: Array<{ url: string; body?: unknown }> = []
    mockAuditRecordAdminFetch(notFoundCalls, { detailStatus: 404 })
    const notFoundWrapper = await mountApp('/admin/audit-records')
    await notFoundWrapper.findAll('button').find((button) => button.text() === 'Details')?.trigger('click')
    await flushPromises()
    expect(notFoundWrapper.text()).toContain('Audit record detail unavailable')
    expect(notFoundWrapper.text()).toContain('runtime_node.created')
    notFoundWrapper.unmount()

    const refreshCalls: Array<{ url: string; body?: unknown }> = []
    let failRefresh = false
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      refreshCalls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.includes('/api/v1/admin/audit-records')) {
        if (failRefresh) return Promise.resolve(jsonResponse({ message: 'refresh failed' }, 500))

        return Promise.resolve(jsonResponse({
          audit_records: [auditRecord],
          pagination: { page: 1, per_page: 20, total: 1, has_more: false },
        }))
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })
    const refreshWrapper = await mountApp('/admin/audit-records')
    expect(refreshWrapper.text()).toContain('runtime_node.created')
    failRefresh = true
    await refreshWrapper.findAll('button').find((button) => button.text() === 'Refresh')?.trigger('click')
    await flushPromises()
    expect(refreshWrapper.text()).toContain('Audit records unavailable')
    expect(refreshWrapper.text()).toContain('refresh failed')
    expect(refreshWrapper.text()).toContain('runtime_node.created')
  })

  it('hides Audit navigation and blocks the route without tenant membership management capability', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(limitedSession))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/dashboard')
    expect(wrapper.text()).not.toContain('Audit records')

    await router.push('/admin/audit-records')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/forbidden')
  })

  it('blocks the Audit route when the session has no active tenant', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(noActiveTenantAuditSession))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    await mountApp('/admin/audit-records')

    expect(router.currentRoute.value.path).toBe('/forbidden')
  })

  it('keeps Audit source free of realtime, polling, mutation controls, raw payload rendering, and browser persistence', () => {
    expect(auditRecordsViewSource).not.toContain('setInterval')
    expect(auditRecordsViewSource).not.toContain('setTimeout')
    expect(auditRecordsViewSource).not.toContain('visibilitychange')
    expect(auditRecordsViewSource).not.toContain('Echo')
    expect(auditRecordsViewSource).not.toContain('subscribe')
    expect(auditRecordsViewSource).not.toContain('localStorage')
    expect(auditRecordsViewSource).not.toContain('sessionStorage')
    expect(auditRecordsViewSource).not.toContain('indexedDB')
    expect(auditRecordsViewSource).not.toContain('JSON.stringify')
    expect(auditRecordsViewSource).not.toContain('raw')
    expect(auditRecordsViewSource).not.toContain('credential')
    expect(auditRecordsViewSource).not.toContain('token')
    expect(auditRecordsViewSource).not.toContain('cookie')
    expect(auditRecordsViewSource).not.toContain('request_body')
    expect(auditRecordsViewSource).not.toContain('desired_state')
    expect(auditRecordsViewSource).not.toContain('observed_state')
    expect(auditRecordsViewSource).not.toContain('provider_response')
  })

  it('hides Runtime Reconciliations navigation and blocks the route without runtime.nodes.view', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(limitedSession))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/dashboard')
    expect(wrapper.text()).not.toContain('Runtime reconciliations')

    await router.push('/operations/runtime-reconciliations')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/forbidden')
  })

  it('hides Runtime Operations navigation and blocks the route without runtime.nodes.view', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(limitedSession))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/dashboard')
    expect(wrapper.text()).not.toContain('Runtime operations')

    await router.push('/operations/runtime-operations')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/forbidden')
  })

  it('preserves current capabilities and submits adapter configuration through canonical APIs', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockRuntimeAdminFetch(calls)
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = await mountApp('/admin/runtime-nodes')
    await wrapper.findAll('button').find((button) => button.text() === 'Details')?.trigger('click')
    await flushPromises()

    await wrapper.find('form.inline-form input[type="checkbox"]').setValue(false)
    await wrapper.findAll('form.inline-form').find((form) => form.text().includes('Set capabilities'))?.trigger('submit.prevent')
    await flushPromises()

    expect(calls.some((call) =>
      call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/capabilities') &&
      JSON.stringify(call.body) === JSON.stringify({ capabilities: ['runtime.observation'] }),
    )).toBe(true)

    await wrapper.findAll('form.inline-form').find((form) => form.text().includes('Save adapter configuration'))?.trigger('submit.prevent')
    await flushPromises()

    const configurationSave = calls.find((call) =>
      call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration') &&
      (call.body as { application_name?: string })?.application_name === 'utcp')
    expect(configurationSave?.body).toEqual({
      application_name: 'utcp',
      connect_timeout_ms: 1000,
      request_timeout_ms: 7000,
      websocket_handshake_timeout_ms: 8000,
      heartbeat_interval_ms: 15000,
      reconnect_min_delay_ms: 500,
      reconnect_max_delay_ms: 10000,
    })

    await wrapper.findAll('button').find((button) => button.text() === 'Retire')?.trigger('click')
    await flushPromises()

    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/credentials/credential-1/retire'))).toBe(true)
  })

  it('renders simulator JSON configuration from catalog descriptors and blocks invalid JSON', async () => {
    const simulatorNode = {
      ...runtimeNode,
      id: 'runtime-sim',
      name: 'Simulator Runtime',
      slug: 'simulator-runtime',
      runtime_family: 'simulator',
      adapter_key: 'simulator-deterministic',
      credentials: [],
      capabilities: ['event.stream', 'runtime.observation', 'runtime.configuration'],
    }
    const calls: Array<{ url: string; body?: unknown }> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.endsWith('/api/v1/admin/runtime-node-catalog')) return Promise.resolve(jsonResponse({ catalog: runtimeCatalog }))
      if (url.endsWith('/api/v1/admin/runtime-nodes')) return Promise.resolve(jsonResponse({ runtime_nodes: [runtimeNode, simulatorNode] }))
      if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-sim/adapter-configuration')) {
        if (init?.method === 'PUT') {
          return Promise.resolve(jsonResponse({ adapter_configuration: { configured: true, profile: JSON.parse(String(init.body)) } }))
        }

        return Promise.resolve(jsonResponse({
          adapter_configuration: {
            configured: true,
            profile: {
              scenario_key: 'happy_path',
              scenario_version: 1,
              seed: 'fixture',
              parameters: { calls: 2, enabled: true },
            },
          },
        }))
      }
      if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-sim/runtime-evidence')) {
        return Promise.resolve(jsonResponse({ runtime_evidence: { desired_state: 'draft', observed_state: 'unobserved', observed_at: null, desired_configuration_generation: 1, observed_configuration_generation: null, listener: { status: null, lease_freshness: null, last_claimed_at: null, last_renewed_at: null }, connection: { state: 'closed', latest_epoch_opened_at: null, latest_epoch_closed_at: null, latest_event_at: null, latest_disconnect_class: null }, reconciliation: { state: 'blocked', last_evaluated_at: null, next_retry_at: null, sanitized_failure_class: null, sanitized_failure_code: null, sanitized_message: null }, inspection: { last_success_at: null, last_failure_at: null, failure_class: null } } }))
      }
      if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-sim/history?limit=10')) return Promise.resolve(jsonResponse({ history: [], pagination: { limit: 10, has_more: false, next_before: null } }))
      if (url.includes('/api/v1/admin/runtime-nodes/runtime-1/')) return Promise.resolve(jsonResponse({ message: 'not opened' }, 500))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/admin/runtime-nodes')
    await wrapper.findAll('button').filter((button) => button.text() === 'Details')[1]?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Scenario key')
    expect(wrapper.text()).toContain('Parameters')
    const jsonField = wrapper.find('#runtime-node-runtime-sim-adapter-field-parameters')
    expect(jsonField.element.tagName).toBe('TEXTAREA')
    expect((jsonField.element as HTMLTextAreaElement).value).toContain('"calls": 2')

    await jsonField.setValue('{bad json')
    await wrapper.findAll('form.inline-form').find((form) => form.text().includes('Save adapter configuration'))?.trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.text()).toContain('Parameters must contain valid JSON.')
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-sim/adapter-configuration') && call.body !== undefined)).toBe(false)

    await jsonField.setValue('{"calls":0,"enabled":false,"tags":["a"],"nothing":null}')
    await wrapper.findAll('form.inline-form').find((form) => form.text().includes('Save adapter configuration'))?.trigger('submit.prevent')
    await flushPromises()

    const saveCall = calls.find((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-sim/adapter-configuration') && call.body !== undefined)
    expect(saveCall?.body).toMatchObject({
      scenario_key: 'happy_path',
      scenario_version: 1,
      seed: 'fixture',
      parameters: { calls: 0, enabled: false, tags: ['a'], nothing: null },
    })
  })

  it('omits read-only and blank write-only descriptor values while preserving entered replacements', async () => {
    const writeOnlyCatalog = structuredClone(runtimeCatalog) as RuntimeManagementCatalog
    writeOnlyCatalog.adapter_keys['asterisk-ari'].adapter_configuration = {
      fields: [
        {
          key: 'application_name',
          label: 'ARI application name',
          help: 'Synthetic writable text fixture.',
          input_type: 'text',
          required: true,
          read_only: false,
          write_only: false,
          default: 'catalog-default',
          order: 10,
        },
        {
          key: 'connect_timeout_ms',
          label: 'Connect timeout',
          help: 'Synthetic writable integer fixture.',
          input_type: 'integer',
          required: true,
          read_only: false,
          write_only: false,
          default: 0,
          order: 20,
        },
        {
          key: 'read_only_note',
          label: 'Read-only note',
          help: 'Synthetic read-only fixture.',
          input_type: 'text',
          required: false,
          read_only: true,
          write_only: false,
          default: null,
          order: 30,
        },
        {
          key: 'replacement_secret',
          label: 'Replacement secret',
          help: 'Synthetic write-only fixture.',
          input_type: 'text',
          required: false,
          read_only: false,
          write_only: true,
          default: null,
          order: 40,
        },
      ],
    }
    const calls: Array<{ url: string; body?: unknown }> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.endsWith('/api/v1/admin/runtime-node-catalog')) return Promise.resolve(jsonResponse({ catalog: writeOnlyCatalog }))
      if (url.endsWith('/api/v1/admin/runtime-nodes')) return Promise.resolve(jsonResponse({ runtime_nodes: [runtimeNode] }))
      if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration')) {
        if (init?.method === 'PUT') return Promise.resolve(jsonResponse({ adapter_configuration: { configured: true, profile: JSON.parse(String(init.body)) } }))

        return Promise.resolve(jsonResponse({
          adapter_configuration: {
            configured: true,
            profile: {
              application_name: 'utcp',
              connect_timeout_ms: 0,
              read_only_note: 'server-owned',
              replacement_secret: 'synthetic-readback-secret',
            },
          },
        }))
      }
      if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/runtime-evidence')) {
        return Promise.resolve(jsonResponse({ runtime_evidence: { desired_state: 'draft', observed_state: 'unobserved', observed_at: null, desired_configuration_generation: 1, observed_configuration_generation: null, listener: { status: null, lease_freshness: null, last_claimed_at: null, last_renewed_at: null }, connection: { state: 'closed', latest_epoch_opened_at: null, latest_epoch_closed_at: null, latest_event_at: null, latest_disconnect_class: null }, reconciliation: { state: 'blocked', last_evaluated_at: null, next_retry_at: null, sanitized_failure_class: null, sanitized_failure_code: null, sanitized_message: null }, inspection: { last_success_at: null, last_failure_at: null, failure_class: null } } }))
      }
      if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/history?limit=10')) return Promise.resolve(jsonResponse({ history: [], pagination: { limit: 10, has_more: false, next_before: null } }))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/admin/runtime-nodes')
    await wrapper.findAll('button').find((button) => button.text() === 'Details')?.trigger('click')
    await flushPromises()

    const writeOnlyInput = wrapper.find('#runtime-node-runtime-1-adapter-field-replacement_secret')
    expect(writeOnlyInput.attributes('type')).toBe('password')
    expect((writeOnlyInput.element as HTMLInputElement).value).toBe('')
    expect(wrapper.text()).not.toContain('synthetic-readback-secret')

    await wrapper.findAll('form.inline-form').find((form) => form.text().includes('Save adapter configuration'))?.trigger('submit.prevent')
    await flushPromises()

    const firstSave = calls.find((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration') && call.body !== undefined)
    expect(firstSave?.body).toEqual({
      application_name: 'utcp',
      connect_timeout_ms: 0,
    })

    await wrapper.find('#runtime-node-runtime-1-adapter-field-replacement_secret').setValue('synthetic-replacement')
    await wrapper.findAll('form.inline-form').find((form) => form.text().includes('Save adapter configuration'))?.trigger('submit.prevent')
    await flushPromises()

    const saveBodies = calls
      .filter((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration') && call.body !== undefined)
      .map((call) => call.body)
    expect(saveBodies[1]).toEqual({
      application_name: 'utcp',
      connect_timeout_ms: 0,
      replacement_secret: 'synthetic-replacement',
    })
    expect((wrapper.find('#runtime-node-runtime-1-adapter-field-replacement_secret').element as HTMLInputElement).value).toBe('')
    expect(wrapper.text()).not.toContain('synthetic-replacement')
    expect(wrapper.text()).not.toContain('synthetic-readback-secret')
  })

  it('blocks required unsupported RuntimeNode descriptors without affecting the list', async () => {
    const unsupportedCatalog = structuredClone(runtimeCatalog) as RuntimeManagementCatalog
    unsupportedCatalog.adapter_keys['asterisk-ari'].adapter_configuration = {
      fields: [
        {
          key: 'application_name',
          label: 'ARI application name',
          help: 'Unsupported fixture.',
          input_type: 'unsupported' as 'text',
          required: true,
          read_only: false,
          write_only: false,
          default: null,
          order: 10,
        },
      ],
    }
    const calls: Array<{ url: string; body?: unknown }> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.endsWith('/api/v1/admin/runtime-node-catalog')) return Promise.resolve(jsonResponse({ catalog: unsupportedCatalog }))
      if (url.endsWith('/api/v1/admin/runtime-nodes')) return Promise.resolve(jsonResponse({ runtime_nodes: [runtimeNode] }))
      if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration')) {
        if (init?.method === 'PUT') return Promise.resolve(jsonResponse({ message: 'should not save' }, 500))

        return Promise.resolve(jsonResponse({ adapter_configuration: { configured: true, profile: { application_name: 'utcp' } } }))
      }
      if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/runtime-evidence')) {
        return Promise.resolve(jsonResponse({ runtime_evidence: { desired_state: 'draft', observed_state: 'unobserved', observed_at: null, desired_configuration_generation: 1, observed_configuration_generation: null, listener: { status: null, lease_freshness: null, last_claimed_at: null, last_renewed_at: null }, connection: { state: 'closed', latest_epoch_opened_at: null, latest_epoch_closed_at: null, latest_event_at: null, latest_disconnect_class: null }, reconciliation: { state: 'blocked', last_evaluated_at: null, next_retry_at: null, sanitized_failure_class: null, sanitized_failure_code: null, sanitized_message: null }, inspection: { last_success_at: null, last_failure_at: null, failure_class: null } } }))
      }
      if (url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/history?limit=10')) return Promise.resolve(jsonResponse({ history: [], pagination: { limit: 10, has_more: false, next_before: null } }))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/admin/runtime-nodes')
    await wrapper.findAll('button').find((button) => button.text() === 'Details')?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Proof Runtime')
    expect(wrapper.text()).toContain('Required field application_name uses unsupported type unsupported.')
    expect(wrapper.find('#runtime-node-runtime-1-adapter-field-application_name').exists()).toBe(false)
    const saveButton = wrapper.findAll('button').find((button) => button.text() === 'Save adapter configuration')
    expect(saveButton?.attributes('disabled')).toBeDefined()
    await wrapper.findAll('form.inline-form').find((form) => form.text().includes('Save adapter configuration'))?.trigger('submit.prevent')
    await flushPromises()

    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/runtime-nodes/runtime-1/adapter-configuration') && call.body !== undefined)).toBe(false)
  })

  it('keeps repeated RuntimeNode credential field IDs unique and scoped to labels', async () => {
    const secondRuntimeNode = { ...runtimeNode, id: 'runtime-2', name: 'Second Runtime', slug: 'second-runtime' }
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/admin/runtime-node-catalog')) return Promise.resolve(jsonResponse({ catalog: runtimeCatalog }))
      if (url.endsWith('/api/v1/admin/runtime-nodes')) return Promise.resolve(jsonResponse({ runtime_nodes: [runtimeNode, secondRuntimeNode] }))
      if (url.includes('/api/v1/admin/runtime-nodes/runtime-')) return Promise.resolve(jsonResponse({ message: 'Detail unavailable.' }, 500))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/admin/runtime-nodes')
    for (const button of wrapper.findAll('button').filter((candidate) => candidate.text() === 'Details')) {
      await button.trigger('click')
    }
    await flushPromises()

    const credentialInputs = wrapper.findAll('input[type="password"][placeholder="Write-only secret"]')
    const credentialIds = credentialInputs.map((input) => input.attributes('id'))
    expect(credentialIds).toEqual(['credential-secret-runtime-1', 'credential-secret-runtime-2'])
    expect(new Set(credentialIds).size).toBe(credentialIds.length)
    for (const id of credentialIds) {
      expect(wrapper.find(`label[for="${id}"]`).exists()).toBe(true)
      expect(id).not.toContain('super-secret')
    }
  })

  it('redirects a protected page to login when the session endpoint rejects it', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ message: 'Unauthenticated.' }, 401))
    const wrapper = await mountApp('/admin/users')

    expect(router.currentRoute.value.path).toBe('/login')
    expect(wrapper.text()).toContain('Sign in to continue.')
  })

  it('renders canonical user detail and keeps signaling secrets transient', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    mockUserAdminFetch(calls)
    const wrapper = await mountApp('/admin/users')

    expect(wrapper.text()).toContain('Operator User')
    expect(wrapper.text()).toContain('Telephony session: active')
    expect(wrapper.text()).toContain('Signaling: eligible / registered')
    expect(wrapper.find('label[for="user-search"]').text()).toContain('Search')
    expect(wrapper.find('.ui-status-badge--success').text()).toBe('active')

    await wrapper.findAll('a').find((link) => link.text() === 'Details')?.trigger('click')
    await flushPromises()
    await flushPromises()

    expect(wrapper.text()).toContain('User detail')
    expect(wrapper.text()).toContain('Tenant memberships')
    expect(wrapper.text()).toContain('Active telephony session')
    expect(wrapper.text()).toContain('Signaling registration')
    expect(wrapper.text()).toContain('Desired registration state')
    expect(wrapper.text()).toContain('Observed runtime state')
    expect(wrapper.text()).toContain('Reconciliation state')
    expect(wrapper.text()).toContain('Currently registered')
    expect(wrapper.text()).not.toContain('ha1')
    expect(wrapper.text()).not.toContain('Contact:')
    expect(wrapper.text()).not.toContain('ruid')
    expect(wrapper.text()).not.toContain('Assign provider node')
    expect(wrapper.text()).not.toContain('Assign PBX')
    expect(wrapper.text()).not.toContain('Register now')
    expect(wrapper.text()).not.toContain('Remove Contact')
    expect(wrapper.text()).not.toContain('Run observer')
    expect(wrapper.text()).not.toContain('Run projection')
    expect(wrapper.text()).not.toContain('Retry reconciliation now')

    const focusSpy = vi.spyOn(HTMLElement.prototype, 'focus')

    await wrapper.findAll('button').find((button) => button.text() === 'Reissue signaling credential')?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Temporary SIP credential issued')
    expect(wrapper.text()).toContain('hidden')
    expect(wrapper.text()).not.toContain('temporary-sip-secret-test-value')
    expect(wrapper.find('.one-time-secret').attributes('tabindex')).toBe('-1')
    expect(focusSpy).toHaveBeenCalled()

    await wrapper.findAll('button').find((button) => button.text() === 'Reveal secret')?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('temporary-sip-secret-test-value')

    focusSpy.mockClear()
    await wrapper.findAll('button').find((button) => button.text() === 'Close credential')?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).not.toContain('temporary-sip-secret-test-value')
    expect(focusSpy).toHaveBeenCalled()
    focusSpy.mockRestore()

    await wrapper.find('select').setValue('tenant-1')
    await flushPromises()

    expect(wrapper.text()).not.toContain('temporary-sip-secret-test-value')
    expect(calls.some((call) => call.url.endsWith('/signaling-credential'))).toBe(true)
  })

  it('navigates the user list to the next and previous page through canonical pagination controls', async () => {
    const pageTwoUser = { ...adminUser, id: 'user-3', email: 'second-page@utcp.local.test', display_name: 'Second Page User' }
    const calls: Array<{ url: string; body?: unknown }> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.includes('/api/v1/admin/users?') && new URL(url, 'http://localhost').searchParams.get('page') === '2') {
        return Promise.resolve(jsonResponse({
          users: [pageTwoUser],
          pagination: { page: 2, per_page: 20, total: 21, has_more: false },
        }))
      }
      if (url.includes('/api/v1/admin/users?')) {
        return Promise.resolve(jsonResponse({
          users: [adminUser],
          pagination: { page: 1, per_page: 20, total: 21, has_more: true },
        }))
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })
    const wrapper = await mountApp('/admin/users')

    expect(wrapper.text()).toContain('Operator User')
    expect(wrapper.text()).toContain('Page 1 · 21 users')
    const nextButton = wrapper.findAll('button').find((button) => button.text() === 'Next')
    const previousButton = wrapper.findAll('button').find((button) => button.text() === 'Previous')
    expect(previousButton?.attributes('disabled')).toBeDefined()

    await nextButton?.trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({ page: '2' })
    expect(calls.some((call) => call.url.includes('/api/v1/admin/users?') && new URL(call.url, 'http://localhost').searchParams.get('page') === '2')).toBe(true)
    expect(wrapper.text()).toContain('Second Page User')
    expect(wrapper.text()).toContain('Page 2 · 21 users')
    expect(wrapper.findAll('button').find((button) => button.text() === 'Next')?.attributes('disabled')).toBeDefined()

    await wrapper.findAll('button').find((button) => button.text() === 'Previous')?.trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({})
    expect(wrapper.text()).toContain('Operator User')
    expect(wrapper.text()).toContain('Page 1 · 21 users')
  })

  it('restores Users search, status, page, and page size from the URL-backed query state', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.includes('/api/v1/admin/users')) {
        const params = new URL(url, 'http://localhost').searchParams
        const email = params.get('search') === 'bob' ? 'bob@utcp.local.test' : 'alice@utcp.local.test'

        return Promise.resolve(jsonResponse({
          users: [{ ...adminUser, email, display_name: params.get('search') === 'bob' ? 'Bob User' : 'Alice User', status: params.get('status') ?? 'active' }],
          pagination: {
            page: Number(params.get('page') ?? '1'),
            per_page: Number(params.get('per_page') ?? '20'),
            total: 37,
            has_more: true,
          },
        }))
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/admin/users?page=2&per_page=10&search=alice&status=active')
    const initialUserCall = calls.find((call) => call.url.includes('/api/v1/admin/users'))
    const initialParams = new URL(initialUserCall?.url ?? '', 'http://localhost').searchParams

    expect(initialParams.get('search')).toBe('alice')
    expect(initialParams.get('status')).toBe('active')
    expect(initialParams.get('page')).toBe('2')
    expect(initialParams.get('per_page')).toBe('10')
    expect((wrapper.find('#user-search').element as HTMLInputElement).value).toBe('alice')
    expect((wrapper.find('#user-status-filter').element as HTMLSelectElement).value).toBe('active')
    expect(wrapper.text()).toContain('Alice User')

    const callCountBeforeUnchangedApply = calls.length
    await wrapper.find('form[role="search"]').trigger('submit')
    await flushPromises()
    expect(calls).toHaveLength(callCountBeforeUnchangedApply)

    await wrapper.find('#user-search').setValue('bob')
    await wrapper.find('form[role="search"]').trigger('submit')
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({ search: 'bob', status: 'active', per_page: '10' })
    const latestUserCall = [...calls].reverse().find((call) => call.url.includes('/api/v1/admin/users'))
    const latestParams = new URL(latestUserCall?.url ?? '', 'http://localhost').searchParams
    expect(latestParams.get('search')).toBe('bob')
    expect(latestParams.get('page')).toBe('1')
    expect(wrapper.text()).toContain('Bob User')
  })

  it('keeps rendered Users rows and pagination bound to the newer query when an older response resolves last', async () => {
    const activeUser = { ...adminUser, id: 'active-user', email: 'active@utcp.local.test', display_name: 'Active Query User', status: 'active' }
    const suspendedUser = { ...adminUser, id: 'suspended-user', email: 'suspended@utcp.local.test', display_name: 'Suspended Query User', status: 'suspended' }
    const activeRequest = deferredResponse()
    const suspendedRequest = deferredResponse()
    const calls: string[] = []

    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      calls.push(url)
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.includes('/api/v1/admin/users')) {
        const params = new URL(url, 'http://localhost').searchParams
        if (params.get('status') === 'active') return activeRequest.promise
        if (params.get('status') === 'suspended') return suspendedRequest.promise
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/admin/users?status=active')

    expect(calls.some((url) => url.includes('/api/v1/admin/users') && url.includes('status=active'))).toBe(true)

    await wrapper.find('#user-status-filter').setValue('suspended')
    await wrapper.find('form[role="search"]').trigger('submit')
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({ status: 'suspended' })
    expect(calls.some((url) => url.includes('/api/v1/admin/users') && url.includes('status=suspended'))).toBe(true)

    suspendedRequest.resolve(jsonResponse({
      users: [suspendedUser],
      pagination: { page: 1, per_page: 20, total: 1, has_more: false },
    }))
    await flushPromises()
    await flushPromises()

    expect(wrapper.text()).toContain('Suspended Query User')
    expect(wrapper.text()).toContain('Page 1 · 1 users')
    expect(wrapper.text()).not.toContain('Active Query User')
    expect(wrapper.findAll('button').find((button) => button.text() === 'Next')?.attributes('disabled')).toBeDefined()

    activeRequest.resolve(jsonResponse({
      users: [activeUser],
      pagination: { page: 1, per_page: 20, total: 206, has_more: true },
    }))
    await flushPromises()
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({ status: 'suspended' })
    expect(wrapper.text()).toContain('Suspended Query User')
    expect(wrapper.text()).toContain('Page 1 · 1 users')
    expect(wrapper.text()).not.toContain('Active Query User')
    expect(wrapper.text()).not.toContain('Page 1 · 206 users')
    expect(wrapper.findAll('button').find((button) => button.text() === 'Next')?.attributes('disabled')).toBeDefined()
  })

  it('keeps rendered Users rows tenant-scoped when a prior-tenant response resolves after tenant switch', async () => {
    const tenantAUser = { ...adminUser, id: 'tenant-a-user', email: 'tenant-a@utcp.local.test', display_name: 'Tenant A User' }
    const tenantBUser = { ...adminUser, id: 'tenant-b-user', email: 'tenant-b@utcp.local.test', display_name: 'Tenant B User' }
    const twoTenantSession = {
      ...session,
      active_tenant: { tenant_id: 'tenant-a', slug: 'tenant-a', display_name: 'Tenant A' },
      memberships: [
        {
          membership_id: 'membership-a',
          tenant_id: 'tenant-a',
          slug: 'tenant-a',
          display_name: 'Tenant A',
          status: 'active',
          membership_status: 'active',
        },
        {
          membership_id: 'membership-b',
          tenant_id: 'tenant-b',
          slug: 'tenant-b',
          display_name: 'Tenant B',
          status: 'active',
          membership_status: 'active',
        },
      ],
    }
    const tenantBSession = {
      ...twoTenantSession,
      active_tenant: { tenant_id: 'tenant-b', slug: 'tenant-b', display_name: 'Tenant B' },
    }
    const tenantARequest = deferredResponse()
    const tenantBRequest = deferredResponse()
    let currentTenant = 'tenant-a'

    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(twoTenantSession))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.endsWith('/api/v1/auth/tenant-context')) {
        currentTenant = String(JSON.parse(String(init?.body)).tenant_id)

        return Promise.resolve(jsonResponse(tenantBSession))
      }
      if (url.includes('/api/v1/admin/users')) {
        return currentTenant === 'tenant-a' ? tenantARequest.promise : tenantBRequest.promise
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/admin/users')
    await wrapper.find('#active-tenant').setValue('tenant-b')
    await flushPromises()

    tenantBRequest.resolve(jsonResponse({
      users: [tenantBUser],
      pagination: { page: 1, per_page: 20, total: 1, has_more: false },
    }))
    await flushPromises()
    await flushPromises()

    expect(wrapper.text()).toContain('Tenant B User')
    expect(wrapper.text()).toContain('Page 1 · 1 users')
    expect(wrapper.text()).not.toContain('Tenant A User')

    tenantARequest.resolve(jsonResponse({
      users: [tenantAUser],
      pagination: { page: 1, per_page: 20, total: 44, has_more: true },
    }))
    await flushPromises()
    await flushPromises()

    expect(wrapper.text()).toContain('Tenant B User')
    expect(wrapper.text()).toContain('Page 1 · 1 users')
    expect(wrapper.text()).not.toContain('Tenant A User')
    expect(wrapper.text()).not.toContain('Page 1 · 44 users')
  })

  it('shows pending-removal wording for an ended session and hides mutation actions', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.endsWith('/api/v1/admin/users/user-2')) {
        return Promise.resolve(jsonResponse({
          ...adminUserDetail,
          active_telephony_session: { ...adminUser.active_telephony_session, status: 'ended', ended_at: '2026-07-16T10:10:00Z' },
          signaling: {
            ...adminUserDetail.signaling,
            credential: null,
            registration: { ...adminUserDetail.signaling.registration, desired_state: 'removed', observed_state: 'registered', pending_removal: true },
          },
        }))
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })
    const wrapper = await mountApp('/admin/users/user-2')

    expect(wrapper.text()).toContain('Registration removed. Contact pending expiration. New registrations and refreshes are blocked.')
    expect(wrapper.findAll('button').find((button) => button.text() === 'End telephony session')).toBeUndefined()
    expect(wrapper.findAll('button').find((button) => /issue|reissue/i.test(button.text()))).toBeUndefined()
  })

  it('shows converged-removal wording once the Contact has fully expired, not the not-issued fallback', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.endsWith('/api/v1/admin/users/user-2')) {
        return Promise.resolve(jsonResponse({
          ...adminUserDetail,
          active_telephony_session: { ...adminUser.active_telephony_session, status: 'ended', ended_at: '2026-07-16T10:10:00Z' },
          signaling: {
            ...adminUserDetail.signaling,
            credential: null,
            registration: { ...adminUserDetail.signaling.registration, desired_state: 'removed', observed_state: 'expired', pending_removal: false },
          },
        }))
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })
    const wrapper = await mountApp('/admin/users/user-2')

    expect(wrapper.text()).toContain('Registration removed. No active Contact. Reconciliation is converged when reported by the backend.')
    expect(wrapper.text()).not.toContain('No signaling credential has been issued.')
  })

  it('uses the dashboard as the authenticated root and login destination', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/admin/runtime-nodes')) return Promise.resolve(jsonResponse({ runtime_nodes: [] }))
      if (url.includes('/api/v1/admin/users')) return Promise.resolve(jsonResponse({ users: [], pagination: { page: 1, per_page: 5, total: 0, has_more: false } }))
      if (url.endsWith('/api/v1/admin/memberships')) return Promise.resolve(jsonResponse({ memberships: [] }))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const rootWrapper = await mountApp('/')
    expect(router.currentRoute.value.path).toBe('/dashboard')
    expect(rootWrapper.text()).toContain('Overview')
    expect(rootWrapper.text()).toContain('No Telephony Nodes configured')
    expect(rootWrapper.text()).toContain('Manage Telephony Nodes')
    expect(rootWrapper.text()).toContain('No current operational issues were returned by the services available to your account.')
    expect(rootWrapper.text()).not.toContain('Everything is healthy')

    const loginWrapper = await mountApp('/login')
    expect(router.currentRoute.value.path).toBe('/dashboard')
    expect(loginWrapper.text()).toContain('Local Admin')
  })

  it('renders explicit forbidden and not-found routes through Vue Router', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(limitedSession))
      if (url.endsWith('/api/v1/admin/runtime-nodes')) return Promise.resolve(jsonResponse({ runtime_nodes: [] }))
      if (url.includes('/api/v1/admin/users')) return Promise.resolve(jsonResponse({ users: [] }, 403))
      if (url.endsWith('/api/v1/admin/memberships')) return Promise.resolve(jsonResponse({ memberships: [] }, 403))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const forbiddenWrapper = await mountApp('/admin/runtime-nodes')
    expect(router.currentRoute.value.path).toBe('/forbidden')
    expect(forbiddenWrapper.text()).toContain('Forbidden')
    expect(forbiddenWrapper.text()).toContain('Back to dashboard')

    const notFoundWrapper = await mountApp('/missing-route')
    expect(router.currentRoute.value.name).toBe('not-found')
    expect(notFoundWrapper.text()).toContain('Not found')
  })

  it('keeps capability navigation useful for a limited normal user without role-name authority', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(limitedSession))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/dashboard')

    expect(wrapper.text()).toContain('Dashboard')
    expect(wrapper.text()).toContain('Available tools')
    expect(wrapper.text()).not.toContain('Tenants')
    expect(wrapper.text()).not.toContain('Runtime nodes')
    expect(wrapper.text()).not.toContain('Reference Telephony Client')
    expect(wrapper.find('a[href="/admin/runtime-nodes"]').exists()).toBe(false)
    expect(calls.every((call) => !String(call.body ?? '').includes('role'))).toBe(true)
  })

  it('turns degraded Telephony Node observations into operator-facing attention items', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/admin/runtime-nodes')) {
        return Promise.resolve(jsonResponse({ runtime_nodes: [{ ...runtimeNode, observed_state: 'degraded', desired_state: 'ready' }] }))
      }
      if (url.includes('/api/v1/admin/users')) return Promise.resolve(jsonResponse({ users: [], pagination: { page: 1, per_page: 5, total: 0, has_more: false } }))
      if (url.endsWith('/api/v1/admin/memberships')) return Promise.resolve(jsonResponse({ memberships: [] }))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/dashboard')

    expect(wrapper.text()).toContain('Proof Runtime is degraded')
    expect(wrapper.text()).not.toContain('observed degraded')
  })

  it('renders the local theme control for a limited user without API persistence', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(limitedSession))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/dashboard')
    const callCountBeforeThemeChange = calls.length
    const appearanceControl = wrapper.find('select[aria-label="Appearance"]')

    expect(appearanceControl.exists()).toBe(true)
    expect(appearanceControl.text()).toContain('System')
    expect(appearanceControl.text()).toContain('Light')
    expect(appearanceControl.text()).toContain('Dark')

    await appearanceControl.setValue('dark')
    await flushPromises()

    expect(document.documentElement.dataset.theme).toBe('dark')
    expect(window.localStorage.getItem(appearanceStorageKey)).toBe('dark')
    expect(calls).toHaveLength(callCountBeforeThemeChange)
  })

  it('classifies only appearance as application storage and bounds the vendor Pusher transport cache', () => {
    window.localStorage.setItem(appearanceStorageKey, 'dark')
    window.localStorage.setItem('pusherTransportTLS', JSON.stringify({ timestamp: 1770000000000, transport: 'wss' }))

    const keys = Object.keys(window.localStorage)
    expect(keys).toEqual(expect.arrayContaining([appearanceStorageKey, 'pusherTransportTLS']))
    expect(isPermittedPusherTransportCache(window.localStorage.getItem('pusherTransportTLS'))).toBe(true)
    expect(window.localStorage.getItem('pusherTransportTLS')).not.toContain('tenant-1')
    expect(window.localStorage.getItem('pusherTransportTLS')).not.toContain('user-1')
    expect(window.localStorage.getItem('pusherTransportTLS')).not.toContain('private-tenant')
    expect(window.localStorage.getItem('pusherTransportTLS')).not.toContain('auth')
    expect(window.localStorage.getItem('pusherTransportTLS')).not.toContain('socket')
    expect(window.localStorage.getItem('pusherTransportTLS')).not.toContain('public-reverb-key')
    expect(window.sessionStorage.length).toBe(0)

    const storageKeysArePermitted = () => Object.keys(window.localStorage).every((key) =>
      key === appearanceStorageKey
      || (key === 'pusherTransportTLS' && isPermittedPusherTransportCache(window.localStorage.getItem(key))),
    )

    expect(storageKeysArePermitted()).toBe(true)
    window.localStorage.setItem('unexpected', 'value')
    expect(storageKeysArePermitted()).toBe(false)
    window.localStorage.removeItem('unexpected')
    window.localStorage.setItem('pusherTransportTLS', JSON.stringify({ timestamp: 1770000000000, transport: 'wss', tenant_id: 'tenant-1' }))
    expect(storageKeysArePermitted()).toBe(false)
  })

  it('loads dashboard summaries from existing APIs and preserves partial failures', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/admin/runtime-nodes')) return Promise.resolve(jsonResponse({ message: 'Runtime summary unavailable.' }, 500))
      if (url.includes('/api/v1/admin/users')) {
        return Promise.resolve(jsonResponse({
          users: [adminUser],
          pagination: { page: 1, per_page: 5, total: 1, has_more: false },
        }))
      }
      if (url.endsWith('/api/v1/admin/memberships')) return Promise.resolve(jsonResponse({ memberships: [] }))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/dashboard')

    expect(wrapper.text()).toContain('Runtime summary unavailable.')
    expect(wrapper.text()).toContain('Users and telephony sessions')
    expect(wrapper.text()).toContain('Operator User')
    expect(wrapper.text()).toContain('No memberships were returned.')
    expect(wrapper.text()).not.toContain('Runtime nodes 0')

    await wrapper.findAll('button').find((button) => button.text() === 'Refresh')?.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Runtime summary unavailable.')
  })

  it('binds Dashboard Refresh to the canonical loading state and blocks duplicate activation', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    const pendingRuntimeRefresh = deferredResponse()
    let runtimeSummaryCalls = 0
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      calls.push({ url })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/admin/runtime-nodes')) {
        runtimeSummaryCalls += 1
        if (runtimeSummaryCalls === 2) return pendingRuntimeRefresh.promise

        return Promise.resolve(jsonResponse({ runtime_nodes: [runtimeNode] }))
      }
      if (url.includes('/api/v1/admin/users')) {
        return Promise.resolve(jsonResponse({
          users: [adminUser],
          pagination: { page: 1, per_page: 5, total: 1, has_more: false },
        }))
      }
      if (url.endsWith('/api/v1/admin/memberships')) return Promise.resolve(jsonResponse({ memberships: [] }))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/dashboard')
    attachWrapperToDocument(wrapper)
    const refreshButton = wrapper.findAll('button').find((button) => button.text() === 'Refresh')
    if (!refreshButton) throw new Error('Dashboard Refresh button not found')
    const requestCountBeforeRefresh = calls.length

    refreshButton.element.focus()
    await refreshButton.trigger('click')
    await nextTick()

    expect(refreshButton.classes()).toContain('ui-button--secondary')
    expect(document.activeElement).toBe(refreshButton.element)
    expect(refreshButton.attributes('disabled')).toBeUndefined()
    expect(refreshButton.attributes('aria-disabled')).toBe('true')
    expect(refreshButton.attributes('aria-busy')).toBe('true')
    expect(calls).toHaveLength(requestCountBeforeRefresh + 3)

    await refreshButton.trigger('click')
    await refreshButton.trigger('keydown.enter')
    await nextTick()

    expect(calls).toHaveLength(requestCountBeforeRefresh + 3)
    pendingRuntimeRefresh.resolve(jsonResponse({ runtime_nodes: [runtimeNode] }))
    await flushPromises()

    expect(refreshButton.attributes('aria-disabled')).toBeUndefined()
    expect(refreshButton.attributes('aria-busy')).toBeUndefined()
  })

  it('preserves router-level browser history across current direct URLs', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.includes('/api/v1/admin/users')) {
        return Promise.resolve(jsonResponse({
          users: [adminUser],
          pagination: { page: 1, per_page: 20, total: 1, has_more: false },
        }))
      }
      if (url.endsWith('/api/v1/admin/runtime-nodes')) return Promise.resolve(jsonResponse({ runtime_nodes: [] }))
      if (url.endsWith('/api/v1/admin/memberships')) return Promise.resolve(jsonResponse({ memberships: [] }))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/admin/users')
    expect(router.currentRoute.value.path).toBe('/admin/users')
    expect(wrapper.text()).toContain('Operator User')

    const historyRouter = createUtcpRouter(createMemoryHistory())
    await historyRouter.push('/admin/users')
    await historyRouter.isReady()
    expect(historyRouter.currentRoute.value.path).toBe('/admin/users')

    await historyRouter.push('/dashboard')
    await flushPromises()
    expect(historyRouter.currentRoute.value.path).toBe('/dashboard')

    historyRouter.back()
    await new Promise((resolve) => setTimeout(resolve, 0))
    await flushPromises()
    await flushPromises()
    expect(historyRouter.currentRoute.value.path).toBe('/admin/users')
  })

  it('keeps login errors associated and preserves intended redirects without credential persistence', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse({ message: 'Unauthenticated.' }, 401))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.endsWith('/api/v1/auth/login')) return Promise.resolve(jsonResponse({ message: 'Invalid credentials.' }, 422))

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/login?redirect=/admin/users')
    expect(wrapper.text()).toContain('Sign in to continue.')
    expect(wrapper.text()).not.toContain('Authentication failed')
    expect(wrapper.find('#login-password').attributes('aria-invalid')).toBeUndefined()

    await wrapper.find('#login-email').setValue('admin@utcp.local.test')
    await wrapper.find('#login-password').setValue('wrong-password')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.find('#login-password').attributes('aria-describedby')).toContain('login-password-error')
    expect(wrapper.find('#login-password').attributes('aria-invalid')).toBe('true')
    expect(wrapper.find('[role="alert"]').text()).toContain('Invalid credentials.')
    expect(window.localStorage.getItem('wrong-password')).toBeNull()
    expect(window.sessionStorage.getItem('wrong-password')).toBeNull()
    expect(calls.some((call) => call.url.endsWith('/api/v1/auth/login') && JSON.stringify(call.body).includes('wrong-password'))).toBe(true)

    wrapper.unmount()
    resetAppStateForTests()
    vi.restoreAllMocks()
    let authenticated = false
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) {
        return authenticated
          ? Promise.resolve(jsonResponse(session))
          : Promise.resolve(jsonResponse({ message: 'Unauthenticated.' }, 401))
      }
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.endsWith('/api/v1/auth/login')) {
        authenticated = true

        return Promise.resolve(jsonResponse({ message: 'Authenticated.' }))
      }
      if (url.includes('/api/v1/admin/users?')) {
        return Promise.resolve(jsonResponse({ users: [], pagination: { page: 1, per_page: 20, total: 0, has_more: false } }))
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const successWrapper = await mountApp('/login?redirect=/admin/users')
    await successWrapper.find('#login-email').setValue('admin@utcp.local.test')
    await successWrapper.find('#login-password').setValue('correct-password')
    await successWrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/admin/users')
    expect(window.localStorage.getItem('correct-password')).toBeNull()
    expect(window.sessionStorage.getItem('correct-password')).toBeNull()
  })

  it('keeps change-password validation and redirect behavior component-backed', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(session))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.endsWith('/api/v1/auth/change-password')) {
        const body = JSON.parse(String(init?.body))
        if (body.new_password === 'too-short') {
          return Promise.resolve(jsonResponse({ message: 'The new password must be at least 12 characters.' }, 422))
        }

        return Promise.resolve(jsonResponse({ message: 'Password changed.' }))
      }
      if (url.includes('/api/v1/admin/users?')) {
        return Promise.resolve(jsonResponse({ users: [], pagination: { page: 1, per_page: 20, total: 0, has_more: false } }))
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const wrapper = await mountApp('/change-password?redirect=/admin/users')
    await wrapper.find('#current-password').setValue('current-password')
    await wrapper.find('#new-password').setValue('new-valid-password')
    await wrapper.find('#confirm-password').setValue('different-password')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.find('#confirm-password').attributes('aria-describedby')).toContain('confirm-password-error')
    expect(wrapper.text()).toContain('New password and confirmation must match.')

    await wrapper.find('#new-password').setValue('too-short')
    await wrapper.find('#confirm-password').setValue('too-short')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.text()).toContain('The new password must be at least 12 characters.')

    await wrapper.find('#new-password').setValue('new-valid-password')
    await wrapper.find('#confirm-password').setValue('new-valid-password')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/admin/users')
  })

  it('renders tenants, memberships, and server role catalog controls through shared components', async () => {
    const calls: Array<{ url: string; body?: unknown }> = []
    const managementSession = {
      ...session,
      capabilities: [...session.capabilities, 'platform.tenants.manage'],
    }
    vi.spyOn(globalThis, 'fetch').mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = input.toString()
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
      if (url.endsWith('/api/v1/auth/session')) return Promise.resolve(jsonResponse(managementSession))
      if (url.endsWith('/api/v1/auth/csrf')) return Promise.resolve(jsonResponse({ csrf_token: 'csrf' }))
      if (url.endsWith('/api/v1/admin/tenants') && init?.method === 'POST') {
        return Promise.resolve(jsonResponse({ tenant: { id: 'tenant-2', slug: 'proof', display_name: 'Proof Tenant', status: 'active' } }, 201))
      }
      if (url.endsWith('/api/v1/admin/tenants')) return Promise.resolve(jsonResponse({ tenants: [] }))
      if (url.endsWith('/api/v1/admin/roles')) return Promise.resolve(jsonResponse(roleCatalog))
      if (url.endsWith('/api/v1/admin/memberships') && init?.method === 'POST') {
        return Promise.resolve(jsonResponse({ membership_id: 'membership-3' }, 201))
      }
      if (url.endsWith('/api/v1/admin/memberships')) {
        return Promise.resolve(jsonResponse({ memberships: [{ id: 'membership-2', user_id: 'user-2', email: adminUser.email, display_name: adminUser.display_name, status: 'active' }] }))
      }
      if (url.includes('/api/v1/admin/users?')) {
        return Promise.resolve(jsonResponse({ users: [adminUser], pagination: { page: 1, per_page: 20, total: 1, has_more: false } }))
      }

      return Promise.resolve(jsonResponse({ message: 'not found' }, 404))
    })

    const tenantsWrapper = await mountApp('/admin/tenants')
    expect(tenantsWrapper.find('#tenant-slug').exists()).toBe(true)
    expect(tenantsWrapper.findComponent({ name: 'UiEmptyState' }).exists()).toBe(true)
    await tenantsWrapper.find('#tenant-slug').setValue('proof')
    await tenantsWrapper.find('#tenant-display-name').setValue('Proof Tenant')
    await tenantsWrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/tenants') && JSON.stringify(call.body) === JSON.stringify({ slug: 'proof', display_name: 'Proof Tenant' }))).toBe(true)

    const membershipsWrapper = await mountApp('/admin/memberships')
    expect(membershipsWrapper.find('#membership-role').text()).toContain('Tenant member')
    expect(membershipsWrapper.find('#membership-role').text()).toContain('Tenant admin')
    expect(membershipsWrapper.find('#membership-role').text()).not.toContain('Platform admin')
    await membershipsWrapper.find('#membership-user').setValue('user-2')
    await membershipsWrapper.find('#membership-role').setValue('tenant-admin')
    await membershipsWrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(calls.some((call) => call.url.endsWith('/api/v1/admin/memberships') && JSON.stringify(call.body) === JSON.stringify({ user_id: 'user-2', role_key: 'tenant-admin' }))).toBe(true)
  })

  it('keeps UI-B2 static adoption boundaries and the Users narrow metadata layout contract', () => {
    const viewSources = {
      'LoginView.vue': loginViewSource,
      'ChangePasswordView.vue': changePasswordViewSource,
      'AuditRecordsView.vue': auditRecordsViewSource,
      'TenantsView.vue': tenantsViewSource,
      'MembershipsView.vue': membershipsViewSource,
      'ConferenceOperationsView.vue': conferenceOperationsViewSource,
      'RuntimeNodesView.vue': runtimeNodesViewSource,
      'RuntimeOperationsView.vue': runtimeOperationsViewSource,
      'RuntimeReconciliationsView.vue': runtimeReconciliationsViewSource,
      'UserDetailView.vue': userDetailViewSource,
    }

    for (const source of Object.values(viewSources)) {
      expect(source).toContain("components/ui/Ui")
      expect(source).not.toMatch(/<button[\s>]/)
      expect(source).not.toMatch(/<select[\s>]/)
    }
    expect(viewSources['RuntimeNodesView.vue'].match(/<input[\s>]/g)).toHaveLength(1)
    expect(viewSources['RuntimeNodesView.vue']).toContain('type="checkbox"')
    for (const source of Object.entries(viewSources).filter(([viewName]) => viewName !== 'RuntimeNodesView.vue').map(([, source]) => source)) {
      expect(source).not.toMatch(/<input[\s>]/)
    }

    const membershipSource = viewSources['MembershipsView.vue']
    expect(membershipSource).not.toContain('<option value="tenant-member">')
    expect(membershipSource).not.toContain('<option value="tenant-admin">')
    expect(membershipSource).toContain('tenantRoleOptions')

    const runtimeSource = viewSources['RuntimeNodesView.vue']
    expect(runtimeSource).not.toContain("adapter_key === 'asterisk-ari'")
    expect(runtimeSource).not.toContain('saveAsteriskAdapterConfiguration')
    expect(runtimeSource).not.toContain('asteriskConfigurationForm')
    expect(viewSources['RuntimeOperationsView.vue']).not.toContain('localStorage')
    expect(viewSources['RuntimeOperationsView.vue']).not.toContain('sessionStorage')
    expect(viewSources['RuntimeOperationsView.vue']).not.toContain('event.payload')
    expect(viewSources['RuntimeOperationsView.vue']).not.toContain('notification.payload')
    expect(viewSources['RuntimeOperationsView.vue']).not.toContain('payload:')
    expect(viewSources['RuntimeOperationsView.vue']).not.toContain('lease_id')
    expect(viewSources['RuntimeOperationsView.vue']).not.toContain('leased_by')
    expect(viewSources['RuntimeOperationsView.vue']).not.toContain('lease_expires_at')
    expect(viewSources['RuntimeReconciliationsView.vue']).not.toContain('localStorage')
    expect(viewSources['RuntimeReconciliationsView.vue']).not.toContain('sessionStorage')
    expect(viewSources['RuntimeReconciliationsView.vue']).not.toContain('event.payload')
    expect(viewSources['RuntimeReconciliationsView.vue']).not.toContain('notification.payload')
    expect(viewSources['RuntimeReconciliationsView.vue']).not.toContain('desired_state')
    expect(viewSources['RuntimeReconciliationsView.vue']).not.toContain('observed_state')
    expect(viewSources['RuntimeReconciliationsView.vue']).not.toContain('audit')
    expect(viewSources['RuntimeReconciliationsView.vue']).not.toContain('outbox')
    expect(viewSources['RuntimeReconciliationsView.vue']).not.toContain('credentials')
    expect(viewSources['RuntimeReconciliationsView.vue']).not.toContain('endpoint')
    expect(viewSources['AuditRecordsView.vue']).not.toContain('localStorage')
    expect(viewSources['AuditRecordsView.vue']).not.toContain('sessionStorage')
    expect(viewSources['AuditRecordsView.vue']).not.toContain('setInterval')
    expect(viewSources['AuditRecordsView.vue']).not.toContain('visibilitychange')
    expect(viewSources['AuditRecordsView.vue']).not.toContain('subscribe')
    expect(appStateSource).not.toContain('saveAsteriskAdapterConfiguration')
    expect(appStateSource).not.toContain('asteriskConfigurationForm')
    expect(appStateSource).toContain('saveRuntimeAdapterConfiguration')
    expect(runtimeSource).toContain('RuntimeNodeCatalogField')
    expect(runtimeSource).not.toContain('feature gate')

    expect(usersViewSource).toContain('class="subgrid"')
    expect(usersViewSource).toContain('Memberships:')
    expect(usersViewSource).toContain('Telephony session:')
  })

  it('codifies UI-E12 route-purpose descriptions without replacing the section-heading hook', () => {
    const primaryRouteSources = [
      {
        routeName: 'Overview',
        source: dashboardViewSource,
        h2: 'Overview',
        description: 'Review the current operational observations available to your account and move into the right management workflow.',
      },
      {
        routeName: 'Users',
        source: usersViewSource,
        h2: 'Users',
        description: 'Manage operator identities that can access UTCP.',
      },
      {
        routeName: 'Tenants',
        source: tenantsViewSource,
        h2: 'Tenants',
        description: 'Manage tenant workspaces represented in the control plane.',
      },
      {
        routeName: 'Memberships',
        source: membershipsViewSource,
        h2: 'Memberships',
        description: 'Assign users to tenants and manage tenant-scoped access.',
      },
      {
        routeName: 'Telephony Nodes',
        source: runtimeNodesViewSource,
        h2: 'Telephony Nodes',
        description: 'Register and inspect telephony engines managed by the control plane.',
      },
      {
        routeName: 'Conferences',
        source: conferenceOperationsViewSource,
        h2: 'Conferences',
        description: 'Inspect conference lifecycle operations and their execution state.',
      },
      {
        routeName: 'Runtime operations',
        source: runtimeOperationsViewSource,
        h2: 'Runtime operations',
        description: 'Track control-plane operations issued to telephony runtimes.',
      },
      {
        routeName: 'Runtime reconciliations',
        source: runtimeReconciliationsViewSource,
        h2: 'Runtime reconciliations',
        description: 'Compare desired state with observed state and review reconciliation outcomes.',
      },
      {
        routeName: 'Audit records',
        source: auditRecordsViewSource,
        h2: 'Audit records',
        description: 'Review recorded administrative and runtime control-plane activity.',
      },
    ]

    for (const { routeName, source, h2, description } of primaryRouteSources) {
      expect(source, `${routeName} must preserve the literal page-header hook`).toContain('class="section-heading"')
      expect(source, `${routeName} must preserve its H2`).toContain(`<h2 id=`)
      expect(source, `${routeName} must include ${h2}`).toContain(h2)
      expect(source, `${routeName} must include route-purpose copy`).toContain(description)
    }
  })

  it('rejects known visible PascalCase terminology leaks in scoped view templates', () => {
    const viewSources = {
      AuditRecordsView: auditRecordsViewSource,
      ConferenceOperationsView: conferenceOperationsViewSource,
      DashboardView: dashboardViewSource,
      RuntimeNodesView: runtimeNodesViewSource,
      RuntimeOperationsView: runtimeOperationsViewSource,
      RuntimeReconciliationsView: runtimeReconciliationsViewSource,
      UserDetailView: userDetailViewSource,
      UsersView: usersViewSource,
    }

    for (const [viewName, source] of Object.entries(viewSources)) {
      const templateSource = source.match(/<template>([\s\S]*?)<\/template>/)?.[1] ?? ''
      expect(templateSource, `${viewName} must not render RuntimeNodes`).not.toContain('RuntimeNodes')
      expect(templateSource, `${viewName} must not render TelephonySessions`).not.toContain('TelephonySessions')
      expect(templateSource, `${viewName} must not render Runtime Operations in sentence copy`).not.toContain('Runtime Operations')
      expect(templateSource, `${viewName} must not render Runtime Reconciliations in sentence copy`).not.toContain('Runtime Reconciliations')
    }
  })

  it('codifies the shared responsive layout contract for stable UI routes', () => {
    const primaryRouteSources = {
      Dashboard: dashboardViewSource,
      Users: usersViewSource,
      Tenants: tenantsViewSource,
      Memberships: membershipsViewSource,
      'Runtime Nodes': runtimeNodesViewSource,
      'Conference Operations': conferenceOperationsViewSource,
      'Runtime Operations': runtimeOperationsViewSource,
      'Runtime Reconciliations': runtimeReconciliationsViewSource,
      'Audit Records': auditRecordsViewSource,
    }

    expect(loginViewSource).toContain('class="app-shell"')
    expect(appShellSource).toContain('class="app-shell app-shell--wide"')
    expect(appShellSource).toContain('class="topbar app-topbar"')
    expect(appShellSource).toContain('class="topbar-actions"')
    expect(appShellSource).toContain('class="shell-grid"')
    expect(appShellSource).toContain('class="shell-content"')

    for (const [routeName, source] of Object.entries(primaryRouteSources)) {
      expect(source, `${routeName} must use the shared page container`).toContain('class="workspace')
      expect(source, `${routeName} must use the shared wrapping page heading`).toContain('class="section-heading"')
      expect(source, `${routeName} must use shared panels or data lists`).toMatch(/<Ui(?:Panel|DataList)\b/)
    }

    for (const [routeName, source] of Object.entries({
      Users: usersViewSource,
      'Runtime Operations': runtimeOperationsViewSource,
      'Runtime Reconciliations': runtimeReconciliationsViewSource,
      'Audit Records': auditRecordsViewSource,
    })) {
      expect(source, `${routeName} filters must use the shared responsive filter bar`).toContain('<UiFilterBar')
    }

    for (const [routeName, source] of Object.entries({
      Users: usersViewSource,
      'Runtime Nodes': runtimeNodesViewSource,
      'Conference Operations': conferenceOperationsViewSource,
      'Runtime Operations': runtimeOperationsViewSource,
      'Runtime Reconciliations': runtimeReconciliationsViewSource,
      'Audit Records': auditRecordsViewSource,
    })) {
      expect(source, `${routeName} row actions must use the shared wrapping action group`).toContain('class="row-actions"')
      expect(source, `${routeName} data rows must use the bounded data-list row contract`).toContain('class="data-row')
      expect(source, `${routeName} long identifiers must be placed in shared wrapping row/detail text containers`).toMatch(/class="(?:subgrid|badge-row|definition-grid|inline-record)/)
    }

    for (const [routeName, source] of Object.entries({
      Users: usersViewSource,
      'Runtime Operations': runtimeOperationsViewSource,
      'Runtime Reconciliations': runtimeReconciliationsViewSource,
      'Audit Records': auditRecordsViewSource,
    })) {
      expect(source, `${routeName} pagination must remain outside the data-list local container`).toMatch(/<\/UiDataList>[\s\S]*<UiPagination\b/)
      expect(source, `${routeName} pagination must forward loading state`).toContain(':loading=')
    }

    expect(conferenceOperationsViewSource).toContain('conference-detail-grid')
    expect(runtimeOperationsViewSource).toContain('runtime-operation-detail-grid')
    expect(runtimeReconciliationsViewSource).toContain('runtime-reconciliation-detail-grid')
    expect(auditRecordsViewSource).toContain('audit-record-detail-grid')
    expect(runtimeNodesViewSource).toContain('class="subgrid"')
  })

  it('keeps responsive layout source contracts enforceable without root overflow clipping', () => {
    expect(styleSource).not.toMatch(/overflow-x\s*:\s*hidden/)

    expect(styleSource).toMatch(/#app\s*\{[^}]*min-width\s*:\s*0/s)
    expect(styleSource).toMatch(/\.app-shell\s*\{[^}]*width\s*:\s*min\([^}]*min-width\s*:\s*0/s)
    expect(styleSource).toMatch(/\.workspace\s*\{[^}]*width\s*:\s*100%[^}]*max-width\s*:\s*100%/s)
    expect(styleSource).toMatch(/\.shell-grid\s*\{[^}]*grid-template-columns\s*:\s*minmax\(220px,\s*0\.24fr\)\s*minmax\(0,\s*1fr\)[^}]*min-width\s*:\s*0/s)
    expect(styleSource).toMatch(/\.shell-content\s*\{[^}]*min-width\s*:\s*0/s)
    expect(styleSource).toMatch(/\.topbar,\s*[\s\S]*?\.section-heading\s*\{[^}]*flex-wrap\s*:\s*wrap/s)
    expect(styleSource).toMatch(/\.topbar-actions\s*\{[^}]*flex-wrap\s*:\s*wrap[^}]*max-width\s*:\s*100%/s)
    expect(styleSource).toMatch(/\.ui-filter-bar__controls\s*\{[^}]*grid-template-columns\s*:\s*repeat\(2,\s*minmax\(0,\s*1fr\)\)[^}]*min-width\s*:\s*0/s)
    expect(styleSource).toMatch(/\.inline-form\s*\{[^}]*min-width\s*:\s*0/s)
    expect(styleSource).toMatch(/\.data-row strong,\s*[\s\S]*?code\s*\{[^}]*overflow-wrap\s*:\s*anywhere/s)
    expect(styleSource).toMatch(/\.ui-panel,\s*[\s\S]*?\.detail-section\s*\{[^}]*min-width\s*:\s*0[^}]*max-width\s*:\s*100%/s)
    expect(styleSource).toMatch(/\.conference-detail-grid,\s*[\s\S]*?\.runtime-reconciliation-detail-grid,\s*[\s\S]*?\.audit-record-detail-grid\s*\{[^}]*grid-template-columns\s*:\s*minmax\(0,\s*0\.9fr\)\s*minmax\(0,\s*1\.1fr\)/s)
    expect(styleSource).toMatch(/@media \(max-width:\s*720px\)\s*\{[\s\S]*\.conference-detail-grid,\s*[\s\S]*?\.runtime-reconciliation-detail-grid,\s*[\s\S]*?\.definition-grid\s*\{[\s\S]*?grid-template-columns\s*:\s*1fr/s)
    expect(styleSource).toMatch(/\.data-table\s*\{[^}]*max-width\s*:\s*100%/s)
    expect(styleSource).toMatch(/\.ui-pagination\s*\{[^}]*flex-wrap\s*:\s*wrap[^}]*max-width\s*:\s*100%/s)
  })

  it('keeps button hover colors owned by each variant instead of a shared conflicting hover rule', () => {
    expect(styleSource).not.toMatch(/button:hover:not\(:disabled\),\s*\.ui-button:hover:not\(:disabled\)\s*\{[^}]*background\s*:/s)
    expect(styleSource).not.toMatch(/\.ui-button:hover:not\(:disabled\)\s*\{[^}]*background\s*:/s)

    for (const variant of ['primary', 'secondary', 'ghost', 'danger'] satisfies ButtonVariant[]) {
      const declarations = hoverRuleForVariant(variant)

      expect(declarations.background, `${variant} hover must own a background`).toBeDefined()
      expect(declarations.color, `${variant} hover must own a foreground`).toBeDefined()
    }

    expect(cssRule(".ui-button--primary:hover:not(:disabled):not([aria-disabled='true'])")).toMatchObject({
      background: 'var(--color-primary-hover)',
      color: 'var(--color-surface)',
    })
    expect(cssRule(".ui-button--secondary:hover:not(:disabled):not([aria-disabled='true'])")).toMatchObject({
      background: 'var(--color-primary-muted)',
      color: 'var(--color-text)',
    })
    expect(cssRule(".ui-button--ghost:hover:not(:disabled):not([aria-disabled='true'])")).toMatchObject({
      background: 'var(--color-primary)',
      color: 'var(--color-surface)',
    })
    expect(cssRule(".ui-button--danger:hover:not(:disabled):not([aria-disabled='true'])")).toMatchObject({
      background: 'var(--color-danger)',
      color: 'var(--color-surface)',
    })
  })

  it('keeps all variant-owned hover color pairs above the normal text contrast threshold in both themes', () => {
    const ratios: Record<ThemeName, Record<ButtonVariant, number>> = {
      light: { primary: 0, secondary: 0, ghost: 0, danger: 0 },
      dark: { primary: 0, secondary: 0, ghost: 0, danger: 0 },
    }

    for (const theme of ['light', 'dark'] satisfies ThemeName[]) {
      const tokens = themeTokens(theme)

      for (const variant of ['primary', 'secondary', 'ghost', 'danger'] satisfies ButtonVariant[]) {
        const declarations = hoverRuleForVariant(variant)
        const foreground = resolveCssValue(declarations.color, tokens)
        const background = resolveCssValue(declarations.background, tokens)
        ratios[theme][variant] = contrastRatio(foreground, background)

        expect(
          ratios[theme][variant],
          `${theme} ${variant} hover contrast for ${foreground} on ${background}`,
        ).toBeGreaterThanOrEqual(4.5)
      }
    }

    expect(ratios.light.secondary).toBeGreaterThan(15)
    expect(ratios.dark.secondary).toBeGreaterThan(10)
    expect(ratios.light.ghost).toBeGreaterThan(7)
    expect(ratios.dark.ghost).toBeGreaterThan(7)
  })

  it('accepts runtime adapter configuration descriptors in the catalog contract and cuts over rendering authority', () => {
    const asteriskFields = runtimeCatalog.adapter_keys['asterisk-ari'].adapter_configuration?.fields ?? []
    expect(asteriskFields.map((field) => field.key)).toEqual([
      'application_name',
      'connect_timeout_ms',
      'request_timeout_ms',
      'websocket_handshake_timeout_ms',
      'heartbeat_interval_ms',
      'reconnect_min_delay_ms',
      'reconnect_max_delay_ms',
    ])
    expect(asteriskFields.map((field) => field.order)).toEqual([10, 20, 30, 40, 50, 60, 70])
    expect(asteriskFields.map((field) => field.input_type)).toEqual([
      'text',
      'integer',
      'integer',
      'integer',
      'integer',
      'integer',
      'integer',
    ])

    const simulatorFields = runtimeCatalog.adapter_keys['simulator-deterministic'].adapter_configuration?.fields ?? []
    expect(simulatorFields.map((field) => field.key)).toEqual(['scenario_key', 'scenario_version', 'seed', 'parameters'])
    expect(simulatorFields.map((field) => field.input_type)).toEqual(['text', 'integer', 'text', 'json'])
    expect('adapter_configuration' in runtimeCatalog.adapter_keys['freeswitch-esl']).toBe(false)

    const serializedCatalog = JSON.stringify(runtimeCatalog)
    expect(serializedCatalog).not.toContain('credential-secret')
    expect(serializedCatalog).not.toContain('encrypted_secret')
    expect(serializedCatalog).not.toContain('fencing_token')
    expect(runtimeNodesViewSource).not.toContain("adapter_key === 'asterisk-ari'")
    expect(runtimeNodesViewSource).not.toContain('saveAsteriskAdapterConfiguration')
    expect(runtimeNodesViewSource).not.toContain('asteriskNumberFields')
    expect(appStateSource).not.toContain('saveAsteriskAdapterConfiguration')
    expect(appStateSource).not.toContain('asteriskConfigurationForm')
  })
})
