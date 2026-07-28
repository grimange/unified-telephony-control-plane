# UI-E12 - Portfolio Finish Implementation

## Verdict

UI_E_PORTFOLIO_FINISH_IMPLEMENTATION_COMPLETE

## Evidence Authority

- Starting evidence: [`ui-e11-portfolio-finish-preparation-audit.md`](ui-e11-portfolio-finish-preparation-audit.md)
- Starting commit: `6d9f619`
- Phase marker: `UTCP_PHASE=T1`

## UI-E11 Findings Addressed

UI-E11 identified four must-fix presentation gaps. This repository slice addresses them without changing backend production code, API contracts, routes, capabilities, tenant behavior, domain state, realtime behavior, Kubernetes, or the phase marker.

1. Login and the application entry experience did not explain what UTCP is.
2. Eight of nine authenticated primary routes had no route-purpose description.
3. Visible domain terminology mixed sentence case, title case, and implementation-style PascalCase.
4. Dashboard Refresh appeared as the primary action and did not expose the shared loading contract.

The bounded information-architecture improvement from UI-E11 is also implemented by grouping existing navigation entries conceptually while reusing the existing route and capability predicates.

## Login Product Orientation

The Login view now exposes one page-level heading:

```text
Unified Telephony Control Plane
```

The supporting copy orients a first-time reviewer to UTCP as one operator workspace for tenant access, telephony runtime nodes, lifecycle operations, reconciliation, and audit evidence. The existing sign-in task, form labels, validation, request flow, break-glass behavior, and password-management behavior are preserved.

## Forced-Password-Change Hierarchy

The forced password-change view now exposes one task-oriented page-level heading:

```text
Secure your UTCP account
```

The supporting copy explains that the operator must set a new password before entering the UTCP control plane. The password validation, submission, loading state, error handling, and redirect behavior remain owned by the existing view logic.

## Route-Purpose Descriptions

Each authenticated primary route retains the literal `class="section-heading"` source hook and now includes concise purpose copy next to the existing H2:

| Route | Purpose copy |
| --- | --- |
| Dashboard | Review current control-plane state and move into management, runtime, reconciliation, and audit workflows. |
| Users | Manage operator identities that can access UTCP. |
| Tenants | Manage tenant workspaces represented in the control plane. |
| Memberships | Assign users to tenants and manage tenant-scoped access. |
| Runtime nodes | Register and inspect telephony runtime nodes managed by the control plane. |
| Conference operations | Inspect conference lifecycle operations and their execution state. |
| Runtime operations | Track control-plane operations issued to telephony runtimes. |
| Runtime reconciliations | Compare desired state with observed state and review reconciliation outcomes. |
| Audit records | Review recorded administrative and runtime control-plane activity. |

No `UiPageHeader` abstraction was introduced. Existing action ordering, live-status placement, heading levels, and responsive wrapping remain in the same view-local structure.

## Terminology Convention

Visible route copy now follows sentence-case domain terminology:

- `runtime node` / `runtime nodes`
- `conference operation` / `conference operations`
- `runtime operation` / `runtime operations`
- `runtime reconciliation` / `runtime reconciliations`
- `telephony session` / `telephony sessions`
- `audit record` / `audit records`
- `desired state` / `observed state`
- `tenant` / `tenants`
- `user` / `users`
- `membership` / `memberships`

`UTCP`, `API`, `WSS`, UUID values, proper product names, and code/protocol values shown as data retain their canonical casing.

## Exact Terminology Corrections

The implementation removes visible implementation-style leaks and inconsistent count labels from scoped view templates:

- `RuntimeNodes` -> `runtime nodes`
- `RuntimeNode` sentence copy -> `runtime node`
- `TelephonySessions` -> `telephony sessions`
- `TelephonySession` sentence copy -> `telephony session`
- `Runtime Operation(s)` in sentence copy and count summaries -> `runtime operation(s)`
- `Runtime Reconciliation(s)` in sentence copy and count summaries -> `runtime reconciliation(s)`
- `Audit records` count and loading copy -> `audit records`
- `Conferences` route and list framing -> `Conference operations` / `conference operations`

The adjacent User Detail telephony-session copy was corrected because it is user-visible route-adjacent terminology. TypeScript identifiers, imports, component names, API field names, fixture keys, and UUID/protocol data were not renamed.

## Dashboard Refresh Correction

Dashboard Refresh now uses the maintenance-action hierarchy:

```text
variant = secondary
loading = attentionLoading
```

`attentionLoading` is the existing canonical Dashboard data-loading state derived from the three established summary requests. No new request, endpoint, fake aggregate, timer, or second loading state was added.

While refresh is pending, the shared `UiButton` contract keeps native disabled false, exposes `aria-disabled="true"` and `aria-busy="true"`, retains focus, and blocks duplicate activation.

## Navigation Grouping

`AppShell` renders the existing navigation entries in four conceptual groups while preserving the UI-E11 route traversal order:

```text
Overview
  Dashboard

Access and tenancy
  Tenants
  Users
  Memberships

Evidence
  Audit records

Runtime control
  Runtime nodes
  Runtime operations
  Runtime reconciliations
  Conference operations
```

The navigation registry remains capability-driven. Each entry keeps its existing route, capability predicate, active-state behavior, tenant requirement, and keyboard order. Group headings are non-interactive text inside the existing primary navigation landmark, and their rendered order follows the first visible route in the preserved navigation sequence.

## Capability-Empty Group Suppression

Navigation groups are computed after the existing capability and active-tenant filtering. A group heading renders only when at least one existing route in that group is visible, so capability-empty headings are suppressed on both desktop and mobile.

## Empty-State Copy Corrections

No new empty-state actions were required. Existing empty-state copy was corrected where it leaked implementation terminology or inconsistent casing for runtime nodes, conference operations, runtime operations, runtime reconciliations, and audit records.

## Automated Coverage

Focused frontend coverage in `apps/web/src/App.test.ts` now verifies:

- Login has one H1 with the full product name, product-purpose copy, the sign-in task, existing form labels, and green axe assertions.
- Forced password change has one UTCP account-security H1, contextual copy, the existing form contract, and green axe assertions.
- Each primary authenticated route keeps its H2, a non-empty purpose description, and the literal `class="section-heading"` source contract.
- Known visible PascalCase terminology leaks are absent from scoped view template text.
- Dashboard Refresh is secondary, binds to the canonical loading state, retains focus while pending, exposes `aria-disabled` and `aria-busy`, blocks duplicate activation, and uses the existing three-request refresh budget.
- Navigation group order, link order, active state, capability-empty suppression, desktop and mobile hierarchy, non-interactive group headings, and axe assertions remain valid.

## Preserved Completed Contracts

The slice does not change button styling, color tokens, hover contrast tokens, breakpoints, API clients, domain workflows, query parameters, pagination components, realtime subscriptions, authentication, authorization, tenant context, or the phase marker. The only CSS addition is an AppShell-local group-label separator style for the existing sidebar navigation hierarchy.

## Deferred Final Natural-Browser Proof

Repository tests prove the static and component contracts. They do not claim real browser rendering, visual coherence, console hygiene, network hygiene, storage behavior, or natural responsive interaction.

The remaining proof gap is:

```text
focused natural Playwright MCP proof of Login/product framing, route-purpose
copy, terminology consistency, Dashboard Refresh loading behavior, navigation
grouping and capability suppression, desktop/narrow portfolio coherence, and
bounded accessibility/console/network/storage regression sanity
```
