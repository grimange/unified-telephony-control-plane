import {
  createRouter,
  createWebHistory,
  type RouteLocationRaw,
  type Router,
  type RouterHistory,
  type RouteRecordRaw,
} from 'vue-router'
import AppShell from '../layouts/AppShell.vue'
import AuditRecordsView from '../views/AuditRecordsView.vue'
import ChangePasswordView from '../views/ChangePasswordView.vue'
import ConferenceOperationsView from '../views/ConferenceOperationsView.vue'
import DashboardView from '../views/DashboardView.vue'
import ForbiddenView from '../views/ForbiddenView.vue'
import LoginView from '../views/LoginView.vue'
import MembershipsView from '../views/MembershipsView.vue'
import NotFoundView from '../views/NotFoundView.vue'
import RuntimeNodesView from '../views/RuntimeNodesView.vue'
import RuntimeOperationsView from '../views/RuntimeOperationsView.vue'
import RuntimeReconciliationsView from '../views/RuntimeReconciliationsView.vue'
import TenantsView from '../views/TenantsView.vue'
import UserDetailView from '../views/UserDetailView.vue'
import UsersView from '../views/UsersView.vue'
import { can, ensureSession, session } from '../state/appState'

declare module 'vue-router' {
  interface RouteMeta {
    requiresAuth?: boolean
    guestOnly?: boolean
    title?: string
    capability?: string
    capabilityAny?: string[]
    requiresActiveTenant?: boolean
  }
}

export const routes = [
  { path: '/', redirect: '/dashboard' },
  { path: '/login', name: 'login', component: LoginView, meta: { guestOnly: true, title: 'Sign in' } },
  { path: '/change-password', name: 'change-password', component: ChangePasswordView, meta: { requiresAuth: true, title: 'Change password' } },
  {
    path: '/',
    component: AppShell,
    meta: { requiresAuth: true },
    children: [
      { path: 'dashboard', name: 'dashboard', component: DashboardView, meta: { requiresAuth: true, title: 'Dashboard' } },
      { path: 'admin/tenants', name: 'admin-tenants', component: TenantsView, meta: { requiresAuth: true, title: 'Tenants', capability: 'platform.tenants.view' } },
      { path: 'admin/memberships', name: 'admin-memberships', component: MembershipsView, meta: { requiresAuth: true, title: 'Memberships', capability: 'tenant.memberships.view' } },
      { path: 'admin/audit-records', name: 'admin-audit-records', component: AuditRecordsView, meta: { requiresAuth: true, title: 'Audit records', capability: 'tenant.memberships.manage', requiresActiveTenant: true } },
      { path: 'admin/users', name: 'admin-users', component: UsersView, meta: { requiresAuth: true, title: 'Users', capabilityAny: ['platform.users.view', 'tenant.memberships.view'] } },
      { path: 'admin/users/:id', name: 'admin-user-detail', component: UserDetailView, meta: { requiresAuth: true, title: 'User detail', capabilityAny: ['platform.users.view', 'tenant.memberships.view'] } },
      { path: 'admin/runtime-nodes', name: 'admin-runtime-nodes', component: RuntimeNodesView, meta: { requiresAuth: true, title: 'Runtime nodes', capability: 'runtime.nodes.view' } },
      { path: 'operations/runtime-operations', name: 'operations-runtime-operations', component: RuntimeOperationsView, meta: { requiresAuth: true, title: 'Runtime operations', capability: 'runtime.nodes.view' } },
      { path: 'operations/runtime-reconciliations', name: 'operations-runtime-reconciliations', component: RuntimeReconciliationsView, meta: { requiresAuth: true, title: 'Runtime reconciliations', capability: 'runtime.nodes.view' } },
      { path: 'operations/conferences', name: 'operations-conferences', component: ConferenceOperationsView, meta: { requiresAuth: true, title: 'Conference operations', capability: 'telephony.conferences.view' } },
      { path: 'forbidden', name: 'forbidden', component: ForbiddenView, meta: { requiresAuth: true, title: 'Forbidden' } },
      { path: ':pathMatch(.*)*', name: 'not-found', component: NotFoundView, meta: { requiresAuth: true, title: 'Not found' } },
    ],
  },
]

function hasRouteAccess(meta: { capability?: string; capabilityAny?: string[] }): boolean {
  if (meta.capability && !can(meta.capability)) return false
  if (meta.capabilityAny && !meta.capabilityAny.some((capability) => can(capability))) return false

  return true
}

function hasActiveTenantAccess(meta: { requiresActiveTenant?: boolean }): boolean {
  if (!meta.requiresActiveTenant) return true

  return typeof session.value?.active_tenant?.tenant_id === 'string' && session.value.active_tenant.tenant_id !== ''
}

export function authorizedRedirectTarget(rawTarget: unknown): RouteLocationRaw {
  const target = typeof rawTarget === 'string' && rawTarget.startsWith('/') ? rawTarget : '/dashboard'
  const resolved = router.resolve(target)
  const protectedTarget = resolved.matched.some((record) => record.meta.requiresAuth)
  if (!protectedTarget) return '/dashboard'
  if (resolved.name === 'login' || resolved.name === 'change-password') return '/dashboard'
  if (!resolved.matched.every((record) => hasRouteAccess(record.meta) && hasActiveTenantAccess(record.meta))) return '/dashboard'

  return target
}

function attachGuards(utcpRouter: Router): Router {
  utcpRouter.beforeEach(async (to) => {
    const requiresAuth = to.matched.some((record) => record.meta.requiresAuth)
    const guestOnly = to.matched.some((record) => record.meta.guestOnly)

    if (requiresAuth || guestOnly) {
      await ensureSession()
    }

    if (requiresAuth && session.value === null) {
      return { path: '/login', query: { redirect: to.fullPath } }
    }

    if (session.value?.user.password_change_required && to.name !== 'change-password') {
      return { path: '/change-password', query: { redirect: to.fullPath } }
    }

    if (guestOnly && session.value !== null) {
      if (session.value.user.password_change_required) return { path: '/change-password' }

      return { path: '/dashboard' }
    }

    if (requiresAuth && !to.matched.every((record) => hasRouteAccess(record.meta) && hasActiveTenantAccess(record.meta))) {
      return { path: '/forbidden' }
    }

    return true
  })

  utcpRouter.afterEach((to) => {
    const title = to.matched.slice().reverse().find((record) => record.meta.title)?.meta.title
    document.title = title ? `${title} - UTCP` : 'UTCP'
  })

  return utcpRouter
}

export function createUtcpRouter(history: RouterHistory = createWebHistory()): Router {
  return attachGuards(createRouter({
    history,
    routes: routes as RouteRecordRaw[],
  }))
}

export const router = createUtcpRouter()
