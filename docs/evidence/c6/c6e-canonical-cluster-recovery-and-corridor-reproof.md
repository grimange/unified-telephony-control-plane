# C6E — Canonical Cluster Authority + Corridor Reproof (fourth attempt)

Date: 2026-08-22

## Verdict

    C6E_ASTERISK_NATURAL_FRONTEND_LIVE_PROOF_FOUND_BLOCKER

The claimed Kubernetes environment failure did not exist. `utcp-local` was
running and healthy the whole time; the previous run simply read the ambient
`kubectl` context instead of the repository kubeconfig. No recovery was
required and nothing was restarted to obtain access.

Three of the five C6 corrections are now **verified live**: deterministic
runtime-channel reservation, the 60-second origination deadline, and pending
cancellation. The reservation correctly does **not** fabricate lifecycle state.
The previous packet's stranded-CallLeg defect is closed, and the historical
stranded Call was converged automatically by the newly deployed reconciler.

The call corridor is still blocked, now by **one exact, newly reachable
implementation defect**: `AsteriskRuntimeAdapter::conferenceOwnsChannel()`
queries a `runtime_node_id` column that does not exist on
`conference_participants`, so **every** Asterisk generic call operation throws
`SQLSTATE[42703]`. This defect was latent before and became reachable precisely
*because* correction #3 now reserves the channel id up front.

Two further conditions block the answering-destination path.

No production source was modified.

## Canonical Cluster Authority

    REPOSITORY-DECLARED CLUSTER:  utcp-local
    KUBECTL CONTEXT:              k3d-utcp-local
    LAST RUN CONTEXT:             k3d-apntalk-local  (ambient shell context)
    MISMATCH:                     YES — but expected and documented
    RESOLUTION:                   use the repository kubeconfig, as every
                                  canonical script already does

Authority sources, all agreeing:

    CLAUDE.md:619-627           cluster: utcp-local / context: k3d-utcp-local
    AGENTS.md:222               "utcp-local is the canonical cluster"
    scripts/kubernetes/lib:16   K3D_CLUSTER_NAME=utcp-local
    scripts/kubernetes/lib:17   K3D_CONTEXT_NAME=k3d-utcp-local
    scripts/kubernetes/lib:21   KUBECONFIG_FILE=.runtime/kubeconfig/utcp-local.yaml
    ADR-011                     one-cluster standard local edge; UTCP scripts
                                never start, stop, delete or mutate apntalk-local

No ADR, runbook or evidence file transfers authority to `apntalk-local`.

### Why the last run used k3d-apntalk-local

The ambient global context has **always** been `k3d-apntalk-local`, and that is
the documented normal condition, not a fault:

    docs/evidence/k3/kubernetes-network-security.md:11
      "Global Kubernetes context remained k3d-apntalk-local; repository
       commands used .runtime/kubeconfig/utcp-local.yaml."
    docs/evidence/local-runtime-authority-cutoff.md:34
      "kubectl config current-context remained k3d-apntalk-local."

Every canonical command routes through `kube()` at `scripts/kubernetes/lib:67`:

    KUBECONFIG="$KUBECONFIG_FILE" kubectl --context "$K3D_CONTEXT_NAME" "$@"

so the canonical lifecycle structurally cannot reach APNTalk. The previous run's
`https://0.0.0.0:46229` connection-refused was the **stopped APNTalk** API
endpoint, reached only because a bare `kubectl` was used. It was a read attempt
against the wrong cluster, not a UTCP outage.

## Environment Recovery

    CLUSTER:            utcp-local  (already running: 1 server, 2 agents, all Ready)
    API ENDPOINT:       https://127.0.0.1:6550  — reachable
    RECOVERY ACTION:    NONE REQUIRED
    DESTRUCTIVE ACTION: NO
    ALTERNATE CLUSTER:  NO

Verification:

    kubectl --context k3d-utcp-local cluster-info
      -> control plane running at https://127.0.0.1:6550
    kubectl --context k3d-utcp-local get nodes
      -> k3d-utcp-local-{agent-0,agent-1,server-0}  all Ready  v1.35.3+k3s1

`apntalk-local` remained stopped (`0/1` servers) and was neither started,
stopped, inspected for workloads, nor used. The only APNTalk-touching commands
in this run were the read-only enumerations `k3d cluster list` and
`kubectl config get-contexts`, used solely to answer the authority question.

## Deployment Freshness

Deployed images predated the C6 corrections, so the canonical workflow was used:

    make k8s-config-check   -> K1 Kubernetes config check passed
    make k8s-image-build    -> api, web, gateway, asterisk-ari, rtpengine built
    make k8s-image-push     -> pushed to 127.0.0.1:5001
    make k8s-apply          -> applied; migration Complete 1/1 (6 s)

`scripts/kubernetes/apply` performs its own rollout restarts for the fifteen
platform Deployments and `utcp-runtime/asterisk-ari`. **No separate manual
rollout restart was issued.**

    API / WORKER / COMMAND-WORKER / NORMALIZER / ARI-EVENTS:
        utcp/api @sha256:f7eaea876cbad783f3bbddb80e1d93b290a67024992ecf68c4319190468bebc8
    WEB:
        utcp/web @sha256:279cbcd619f0108eb39c0f9b2a7d7d88d6f354b3c1261837c121cc2e37afae6c
    MIGRATION:  utcp-migrate Complete 1/1
    RUNTIME NODES:
        c7e6f4ba-b925-462f-aff4-71c9fa9a4157  active / ready
        d4539d79-432d-48dc-8def-d52e0d0ca5e2  active / ready

## Correction Reverification

### #1 Managed generic fixture — **FAILED**

Required: `c6-generic-proof` present on the *actual managed RuntimeNode*
Asterisk Pod. Live `dialplan show from-kamailio` after the apply:

    asterisk-ari-c7bfd5f94-npmc7                        (K1-base, Kustomize)
      9900              NoOp, Answer, Echo, Hangup                 PRESENT
      c6-generic-proof  NoOp, Answer, Stasis(utcp-t0-observation)  PRESENT

    asterisk-rnp6-readiness-reproof-20260809-…          (RNP-managed RuntimeNode)
    asterisk-v0c6-conference-runtime-20260815-…         (RNP-managed RuntimeNode)
      _[c]o[n]f-.  conference admission
      _.           Hangup(21)
      -> c6-generic-proof ABSENT on both

Root cause, confirmed in source. The correction moved the fixture into the
shared component `infrastructure/kubernetes/components/asterisk-sip-fixtures`
and wired it into two Kustomize consumers:

* `overlays/local/runtime` → the K1-base `asterisk-ari` Deployment, which is
  **not a registered RuntimeNode**;
* `overlays/local-two-asterisk` → the T5 staged A/B overlay, which the canonical
  apply never deploys (`K8S_RUNTIME_OVERLAY=$K8S_OVERLAY/runtime` only).

RNP-managed Deployments are not Kustomize objects at all. They are built inline
by `ManagedAsteriskProvisioningOperationHandler::deployment()`
(`apps/api/app/RuntimeProvisioning/…:93-98`), whose container spec has **no
`volumes` and no `volumeMounts`** — only `envFrom` for the credential Secret.
`grep -rn "asterisk-local-sip-fixtures\|extensions.local" apps/api/app/` returns
nothing. A Kustomize component therefore can never reach a managed node.

### #2 Backend-owned runtime endpoint catalog — **PARTIAL / DEFECTIVE**

Backend side is correct. `GET /api/v1/admin/runtime-node-catalog` returns:

    endpoint_transports: ["http","https","tcp","tls","udp","ws","wss"]
    endpoint_tls_modes:  ["disabled","opportunistic","required","verify"]
    endpoint_purposes:   ["control","events","health","sip"]

The frontend no longer hardcodes transports — `RuntimeNodesView.vue:530` now
iterates `endpointTransportOptions`. But the adapter shape is wrong, so the
rendered control is unusable. Live from the real Admin UI:

    <select id="endpoint-transport-…">
      value="0" text="0"   value="1" text="1"   …   value="6" text="6"

Root cause at `apps/web/src/views/RuntimeNodesView.vue:1102-1110`:

    const endpointTransportOptions = computed(() => catalogOptions(runtimeCatalog.value?.endpoint_transports))
    const endpointTlsModeOptions   = computed(() => catalogOptions(runtimeCatalog.value?.endpoint_tls_modes))

    function catalogOptions(catalog: Record<string, { display_name?: string }> | undefined) {
      return Object.entries(catalog ?? {}).map(([key, metadata]) => ({
        key, label: metadata.display_name ?? key,
      }))
    }

`catalogOptions()` expects a **keyed object**, but these two catalog fields are
**plain string arrays**. `Object.entries(["http","https",…])` yields
`[["0","http"],…]`, so `key` becomes the array index and
`metadata.display_name` is `undefined` on a string, leaving `label = "0"`.
Transport and TLS mode are the only two array-shaped catalog fields; the
object-shaped ones (runtime families, adapters) render correctly.

Consequence: an external Asterisk runtime still cannot be registered through the
Admin UI, because the transport control cannot emit a valid value.

### #3 Deterministic runtime channel reservation — **VERIFIED LIVE**

Immediately after UI creation, before any provider event:

    call_id            43b86d98-1d28-49f7-86ed-0dc29cb80864
    leg_id             22450655-b87f-4f50-9388-b393ca62c168
    runtime_channel_id utcp-call-leg-22450655-b87f-4f50-9388-b393ca62c168
    call observed_state  requested
    leg  observed_state  requested

The reservation matches `utcp-call-leg-<CallLeg ID>` exactly and **fabricated no
lifecycle state** — no RINGING, EARLY_MEDIA, ANSWERED, HELD or COMPLETED. The UI
displayed the reserved channel while still showing `requested` / `Not terminal`.
`runtime_observations` contained **zero** `call%` rows throughout.

### #4 Origination failure reconciliation — **VERIFIED LIVE**

    created     2026-08-21 22:00:37Z
    terminated  2026-08-21 22:01:33Z   (56 s later, within the 60 s deadline)
    call  observed_state = failed
    leg   observed_state = failed
    termination_reason   = origination_failed

Configured deadline: `config/telephony_domain.php:14`
`origination_timeout_seconds => 60`.

The previous packet's stranded Call was **also** converged automatically by the
newly deployed reconciler, with the distinct deadline reason:

    041e3949-aeef-4e8e-b04d-2ae49922ed70
      created 2026-08-21 20:54:24Z → terminated 2026-08-21 21:43:04Z
      termination_reason = origination_timeout

Both reasons are distinguished correctly: `origination_failed` when the operation
reaches terminal failure, `origination_timeout` when the deadline elapses with no
observation. **The previous packet's Defect 4 is closed.**

### #5 Pending cancellation — **VERIFIED LIVE**

While the leg was in origination phase the Calls UI rendered
**`Cancel origination`** in place of the established-leg `Hang up` control, and
reverted to `Hang up` once the leg reached a terminal state.

## Blocking Defect — conference ownership guard queries a missing column

    CLASS:    IMPLEMENTATION
    SEVERITY: blocking — breaks EVERY Asterisk generic call operation
    FILE:     apps/api/app/RuntimeAdapters/Asterisk/AsteriskRuntimeAdapter.php:123-130

Live failure on the originate operation, all three attempts:

    operation      call.leg.originate
    status         terminal_failed      attempt_count 3 / 3
    failure_class  internal_error
    failure_code   worker_exception
    message        SQLSTATE[42703]: Undefined column: 7
                   ERROR: column "runtime_node_id" does not exist
                   LINE 1: …"conference_participants" where "tenant_id" = $1
                           and "runtime_n…

The guard:

    private function conferenceOwnsChannel(string $tenantId, string $runtimeNodeId, string $channelId): bool
    {
        return DB::table('conference_participants')
            ->where('tenant_id', $tenantId)
            ->where('runtime_node_id', $runtimeNodeId)      // <- no such column
            ->where('runtime_channel_id', $channelId)
            ->exists();
    }

Actual PostgreSQL columns on `conference_participants`:

    id, tenant_id, conference_id, telephony_session_id, user_id,
    desired_state, observed_state, role, admission_reason, joined_at,
    left_at, failure_class, failure_code, created_at, updated_at,
    runtime_channel_id, runtime_channel_lost_at

There is no `runtime_node_id`. Participant→node ownership is reachable only
through `conferences.runtime_node_id` via `conference_id`.

### Why this is newly reachable

`callOperationMatch()` guards the call with

    if ((string) ($leg->runtime_channel_id ?? '') !== '' && $this->conferenceOwnsChannel(...))

Before correction #3, an originating leg had an **empty** `runtime_channel_id`,
so the `&&` short-circuited and the broken query never executed. Correction #3
reserves the channel id up front, so the query now runs on every operation —
`call.leg.originate`, `hangup`, `hold`, `resume`, `send_dtmf`, `answer`,
and every `relationship` leg. The defect is latent-turned-live.

This is the third instance in C6 of the proof substrate being more permissive
than PostgreSQL (previously: SQLite vs PostgreSQL, and empty vs already-migrated
database). Repository tests pass because they do not exercise this guard against
the real PostgreSQL schema.

## Outbound Call

    CALL:                43b86d98-1d28-49f7-86ed-0dc29cb80864
    CALL LEG:            22450655-b87f-4f50-9388-b393ca62c168
    RUNTIME NODE:        c7e6f4ba-b925-462f-aff4-71c9fa9a4157
    DESTINATION:         sip:anonymous/sip:c6-generic-proof@127.0.0.1:5060
    ORIGINATE OPERATION: terminal_failed after 3 attempts, worker_exception

    RESERVED CHANNEL:        utcp-call-leg-22450655-b87f-4f50-9388-b393ca62c168
    ACTUAL ASTERISK CHANNEL: none — no channel was ever created
    MATCH:                   reservation correct; provider side never reached
    DUPLICATE INBOUND CALL:  NO
    DUPLICATE CALL LEG:      NO

Two independent reasons no channel appeared: the blocking defect above aborted
the adapter before any ARI request, and the chosen managed node lacks the
`c6-generic-proof` fixture (Correction #1), so the loopback INVITE would have
fallen through `_.` to `Hangup(21)` regardless.

## Observation Corridor / Command vs Observation

    RINGING / EARLY_MEDIA / ANSWERED:  none — no channel existed
    runtime_observations `call%` rows: 0

The negative assertion holds and is meaningful: canonical terminal state came
from the **origination-deadline reconciliation**, not from operation success and
not from a fabricated observation. Reservation of the channel id did not advance
lifecycle state.

Hold/Resume, DTMF, Answer and the provider terminal corridor were not reachable.

## Timeline UI

    AUDIT              audit.call_leg.terminated   call_leg.terminated  06:01:33
    AUDIT              audit.call.terminated       call.terminated      06:01:33
    AUDIT              audit.call.created          call.created         06:00:37
    RUNTIME_OPERATION  operation.terminal_failed   call.leg.originate   06:00:37

    COMMAND / OBSERVATION / AUDIT distinction: present and correct
    OBSERVATION rows: none — correctly, because none exist
    RAW ARI PAYLOAD:        NO
    SECRETS:                NO
    WORKER LEASE INTERNALS: NO

## Inbound Fixture / Source / Adoption

    NOT ATTEMPTED — blocked by the defect above and by Correction #1.

No inbound Call was created, no observation injected, no DB row inserted.

## Conference Isolation

Not disturbed. No conference created, no participant admitted, no
conference-owned channel controlled, and the `conf-` loopback shortcut was
deliberately not used. All three Asterisk Pods ended with 0 active channels.

## Security / Authority

    DIRECT ARI CONTROL:          NO
    DB MUTATION:                 NO
    SESSION INJECTION:           NO
    OBSERVATION INJECTION:       NO
    FEATURE GATE:                NO
    MANUAL RECONCILE:            NO
    MANUAL APPLICATION ROLLOUT:  NO  (apply performs its own rollouts)
    SOURCE PATCHED:              NO
    APNTALK TOUCHED:             NO

## Cleanup

    Outbound Call:  already terminal via the deadline reconciler; retained as
                    evidence. No non-terminal Calls remain.
    Proof nodes:    c6e-generic-proof-20260821 and c6e-local-asterisk-20260822
                    both `disabled` through the normal UI control; history
                    retained, nothing hard-deleted.
    Session:        logged out through the normal UI.
    Asterisk:       0 active channels on all three Pods.
    RuntimeNodes:   rnp6-readiness-reproof-20260809 and
                    v0c6-conference-runtime-20260815 both active / ready.

## Repository Verification

    git diff --check          clean
    make k8s-config-check     passed
    make repository-hygiene   passed
    make secret-scan          passed

## Code Changes

    NONE.

## Recommended Bounded Corrections

In dependency order.

1. **Fix `conferenceOwnsChannel()`** — resolve participant→node ownership
   through `conferences.runtime_node_id` (join on `conference_id`) instead of the
   non-existent `conference_participants.runtime_node_id`. Add a regression test
   that exercises the guard against the real PostgreSQL schema; the current
   suite cannot detect this class of defect.

2. **Fix `catalogOptions()`** — accept both catalog shapes, or have the backend
   emit `endpoint_transports` / `endpoint_tls_modes` in the same keyed-object
   shape as the other catalog fields. Without this, external runtime
   registration remains impossible through the Admin UI.

3. **Deliver the fixture to managed nodes** — a Kustomize component cannot reach
   an inline-built Deployment. Either add the ConfigMap volume/volumeMount to
   `ManagedAsteriskProvisioningOperationHandler::deployment()`, or accept that
   the proof runs against an externally registered Kustomize node (which
   requires item 2 first).

Items 1 and 3 together unblock the outbound corridor; items 1, 2 and 3 unblock
both. Item 1 is required regardless — without it no Asterisk call operation of
any kind can succeed.

## C6 Status

    C6A-D:                 IMPLEMENTED / TESTED
    C6E IMPLEMENTATION:    IMPLEMENTED / TESTED
    REFERENCE CALL UI:     IMPLEMENTED / TESTED / PARTIALLY LIVE PROVEN
    CORRECTION #1:         NOT SATISFIED on managed RuntimeNodes
    CORRECTION #2:         BACKEND CORRECT / FRONTEND DEFECTIVE
    CORRECTION #3:         VERIFIED LIVE
    CORRECTION #4:         VERIFIED LIVE
    CORRECTION #5:         VERIFIED LIVE
    C6E LIVE:              FOUND_BLOCKER

    C6:                    NOT LIVE PROVEN

## Recommended Next Step

    BOUNDED CODEX CORRECTION — items 1-3, then re-run C6E.

T4 must not start. No further C6 audit is required; all three corrections are
exact and localized.
