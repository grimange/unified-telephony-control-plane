# V1 — CallerIdentity Provider-Wire Trust-Boundary Controlled Live Re-Proof

Current-State-Impact: yes

Date: 2026-08-30

Starting HEAD: `c9adc15dd4de7ce094ab205788bb20095ff8f59f`
(`docs(k5): align guided host enrollment boundary`)

Production repair under proof: `e4fe41d8e5aca2b0b662625d31de2833cacf86c7`
(`fix(v1): strip caller identity id at provider boundary`)

## Verdict

`V1_CALLER_IDENTITY_PROVIDER_WIRE_TRUST_BOUNDARY_LIVE_PROVEN`

One natural canonical outbound Call to `97001` proved the complete boundary:

```text
                                internal            provider initial   provider auth retry
X-UTCP-Call-Leg-ID              exact CallLeg ID    ABSENT             ABSENT
X-UTCP-Route-Decision-ID        exact RD ID         ABSENT             ABSENT
X-UTCP-Trunk-Endpoint-ID        exact endpoint ID   ABSENT             ABSENT
X-UTCP-Caller-Identity-ID       exact identity ID   ABSENT             ABSENT
```

Each of the four appears **exactly once** in the whole 73 562-byte capture — on
the trusted runtime → Kamailio INVITE only. Normal provider-visible caller
identity is preserved and unchanged. The provider answered `200 OK`.

With this, the last recorded V1 acceptance item is proven and **V1 is complete**.

## Deployment

The live Kamailio was verified **before** deployment to be the pre-repair
three-strip configuration, so deployment was genuinely required rather than
assumed:

```text
pre-deploy live provider path:  remove_hf Call-Leg-ID / Route-Decision-ID / Trunk-Endpoint-ID
committed configmap line 368:   remove_hf("X-UTCP-Caller-Identity-ID")
deployment annotation bump:     utcp.io/kamailio-config-sha256 dfbc0952… -> c599c3a6…
```

Current HEAD is documentation-only relative to the repair, so its published
artifact carries identical application code. HEAD was promoted so rendered
manifests and pinned images come from the same commit:

```text
UTCP_SERVER_SOURCE_COMMIT   c9adc15dd4de7ce094ab205788bb20095ff8f59f
UTCP_SERVER_IMAGE_TAG       sha-c9adc15dd4de7ce094ab205788bb20095ff8f59f
UTCP_SERVER_API_IMAGE       ghcr.io/grimange/utcp-api@sha256:17e06383…c7b3
```

Promotion used the established explicit `GH_REPO` workaround for the recorded
`scripts/native-k3s/image-sync` `.git` origin-parsing defect, which was **not**
repaired here. Canonical lifecycle only: `server-image-sync`,
`server-config-check`, `server-image-preflight`, `server-apply`. No
`kubectl patch`, `set image`, `edit`, or manual Pod replacement.

## Deployment acceptance — four-header strip live

Kamailio rolled from `kamailio-6f984fbf7b-xxpbf` to
`kamailio-7c77c77bb7-b27pg` (config-sha256 annotation
`3c1b871b…` → `2260e803…`). Read from the running Pod,
`route[RUNTIME_EXTERNAL_TRUNK]`:

```text
27  $fsn = "provider";
28  remove_hf("X-UTCP-Call-Leg-ID");
29  remove_hf("X-UTCP-Route-Decision-ID");
30  remove_hf("X-UTCP-Trunk-Endpoint-ID");
31  remove_hf("X-UTCP-Caller-Identity-ID");
32  dlg_manage();
34  if (!t_relay()) { ... }
```

All four removals precede `t_relay()`.

## Attempt chronology

### Attempt 1 — invalidated by managed-runtime re-imaging

Call `4b3bf27d-0036-49a5-8d72-3b9ed471db7e` failed as
`origination_failed / system` before any provider INVITE was emitted. Cause was
transient and unrelated to the repair:

```text
originate operation      terminal_failed
last_failure_class       conflict
last_failure_code        adapter_not_available
managed Asterisk Pod     recreated 2026-08-30T08:41:15Z
Call originated          2026-08-30T08:41:14Z
```

The promotion changed the Asterisk image digest, and `server-apply` does not
itself re-image an RNP-provisioned managed RuntimeNode — the
`AsteriskRuntimeNodeReconciler` does so afterwards. The Call landed inside that
replacement window. No manual intervention was applied; convergence was awaited
until RuntimeNode `102d58ba-…` returned `active` / `ready` with the trunk `ready`
and registration `registered`. Both attempts are recorded; the proof Call count
for acceptance purposes is one.

### Attempt 2 — successful, this record

## Baseline

All `utcp-platform` Pods Ready; ExternalTrunk `3a9bf028-…` `active`/`ready`;
registration `registered`; RuntimeNode `102d58ba-…` `active`/`ready`;
non-terminal Calls `0`, CallLegs `0`. Existing canonical `97001` routing objects
reused, not recreated.

Expected provider-visible caller identity read from canonical state before
origination:

```text
CallerIdentity   f11a46e5-fbdc-4eb0-b28d-9c002491a80a  "V1A Reproof Caller"  active
its address      c81c040b-16e2-4d6d-873f-ff9c72520aee  sip_uri  sip:utcp-v1@38.146.161.46
```

## Capture method

```text
wrapper   /usr/local/sbin/utcp-gap-f-capture   (start | stop, sudo -n)
pid       1930279
output    /run/user/1000/utcp-gap-f-capture.txt
filter    udp and (port 5062 or (host 38.146.161.46 and port 5060))
process   /usr/bin/tcpdump -i any -p -nn -s 0 -A -l -U -Z grimange <filter>
size      73 562 bytes
```

Ephemeral, watchdog-terminated, fixed filter, no arbitrary arguments, no Pod,
sidecar, DaemonSet, or persistent service. A stale capture was stopped
idempotently and its output cleared before the proof.

## The proof Call

Exactly one canonical outbound Call, `runtime_node_id` omitted.

| Fact | Value |
| --- | --- |
| Call | `c219570d-a63c-4d8d-b9ab-a51572db539d` |
| CallLeg | `c4fa1694-5659-47f0-8feb-cd125dc7c6c2` |
| RouteDecision | `be444f7b-0c3e-4bf4-b91a-e0123fd86916` |
| Originate RuntimeOperation | `aeb947bde23ec117d5210b77f9de89f6` (succeeded) |
| RuntimeNode (auto-selected) | `102d58ba-93ec-4601-a2a3-81f95801440f` |
| TrunkEndpoint | `ad7a95f4-388c-445e-9259-edd30b5137a2` |
| CallerIdentity | `f11a46e5-fbdc-4eb0-b28d-9c002491a80a` |
| Destination TelephonyAddress | `c537a4a7-af3d-474f-bf19-4be4aeaae2cf` (`sip:97001@38.146.161.46`) |
| CallLeg.runtime_channel_id | `utcp-call-leg-c4fa1694-5659-47f0-8feb-cd125dc7c6c2` |
| SIP Call-ID | `d430291b-003d-4714-b634-7dc5d57c5740` |

## Internal trusted INVITE

```text
08:42:30.098612  10.42.0.25:5060 -> 10.42.0.11:5062   (managed Asterisk -> Kamailio ingress)
INVITE sip:97001@kamailio-sip-internal.utcp-platform.svc.cluster.local:5060
Call-ID: d430291b-003d-4714-b634-7dc5d57c5740
CSeq: 8913 INVITE
From: "utcp-v1" <sip:utcp-v1@10.42.0.25>;tag=28b87365-1faa-4529-93ce-b7adb2045890
X-UTCP-Call-Leg-ID:       c4fa1694-5659-47f0-8feb-cd125dc7c6c2
X-UTCP-Route-Decision-ID: be444f7b-0c3e-4bf4-b91a-e0123fd86916
X-UTCP-Trunk-Endpoint-ID: ad7a95f4-388c-445e-9259-edd30b5137a2
X-UTCP-Caller-Identity-ID: f11a46e5-fbdc-4eb0-b28d-9c002491a80a
```

All four values equal canonical application state exactly.

## Provider-facing INVITEs

Two logical provider INVITEs for this one Call, each observed at three capture
points including the real egress `wlp4s0`:

```text
08:42:30.105805  192.168.254.124:48260 -> 38.146.161.46:5060  (initial, CSeq 8913)
  X-UTCP-*: NONE

08:42:30.312077  192.168.254.124:48260 -> 38.146.161.46:5060  (authenticated retry, CSeq 8914)
  Authorization: PRESENT
  X-UTCP-*: NONE
```

Whole-capture occurrence counts are conclusive:

```text
X-UTCP-Call-Leg-ID          1
X-UTCP-Route-Decision-ID    1
X-UTCP-Trunk-Endpoint-ID    1
X-UTCP-Caller-Identity-ID   1
```

One each — the internal INVITE only.

## Mandatory comparison

```text
Header                       Internal runtime→Kamailio               Provider initial   Provider auth retry
-----------------------------------------------------------------------------------------------------------
X-UTCP-Call-Leg-ID           c4fa1694-5659-47f0-8feb-cd125dc7c6c2    ABSENT             ABSENT
X-UTCP-Route-Decision-ID     be444f7b-0c3e-4bf4-b91a-e0123fd86916    ABSENT             ABSENT
X-UTCP-Trunk-Endpoint-ID     ad7a95f4-388c-445e-9259-edd30b5137a2    ABSENT             ABSENT
X-UTCP-Caller-Identity-ID    f11a46e5-fbdc-4eb0-b28d-9c002491a80a    ABSENT             ABSENT

SIP Call-ID                  d430291b-003d-4714-b634-7dc5d57c5740  (identical both sides)
provider                     38.146.161.46:5060
destination                  97001
provider-visible caller      From: "utcp-v1" <sip:utcp-v1@10.42.0.25>;tag=28b87365-…
expected canonical caller    CallerIdentity f11a46e5-… -> sip:utcp-v1@38.146.161.46  (user utcp-v1)
```

Same-transaction identity rests on the shared `Call-ID`, the shared `From` tag
`28b87365-…`, and CSeq continuity `8913` → `8914` — not on timing.

## Normal caller identity preserved

The current implementation conveys provider-visible caller identity through the
SIP `From` header; no `P-Asserted-Identity` or `Remote-Party-ID` is emitted by
this route, and none is invented here. On both the internal and the
provider-facing INVITEs:

```text
From: "utcp-v1" <sip:utcp-v1@10.42.0.25>;tag=28b87365-1faa-4529-93ce-b7adb2045890
```

The display name and user part `utcp-v1` match the canonical CallerIdentity
address `sip:utcp-v1@38.146.161.46`. The host part is the managed runtime Pod
address, which is pre-existing behavior byte-identical in shape to the earlier
Gap F proof (`sip:utcp-v1@10.42.0.4` there) and therefore **unchanged by this
UUID-stripping fix**. The provider authenticated the request as `utcp-v1` and
answered, which independently confirms the identity remained usable.

```text
caller identity: PRESENT, EXPECTED, UNCHANGED BY THE FIX
```

## Provider delivery positive control

```text
08:42:30.310875  38.146.161.46 -> UTCP   SIP/2.0 401 Unauthorized
08:42:30.514878  38.146.161.46 -> UTCP   SIP/2.0 100 Trying
08:42:30.523597  38.146.161.46 -> UTCP   SIP/2.0 200 OK      <- provider answered
```

Authentication challenge: **401**, followed by a normal authenticated retry that
was accepted. The Call answered at `08:42:43`.

## Cleanup and final state

Terminated through the canonical `call.hangup` API — no AMI, ARI, CLI, or
provider-side hangup.

```text
Call c219570d-…   completed / requested / control_plane   answered_at 2026-08-30 08:42:43+00
Leg  c4fa1694-…   completed / requested / control_plane   failure_class NULL  failure_code NULL
non-terminal Calls 0    non-terminal CallLegs 0    RuntimeNode active / ready
```

The control-plane hangup won intent classification under ADR-030 §5 on this run,
which is correct behavior and not a change of authority. The temporary capture
output was removed after analysis and no capture PID remains.

## V1 phase reconciliation

All recorded V1 acceptance items are now proven:

```text
Gap A CLOSED   Gap B CLOSED   Gap C CLOSED
Gap D CLOSED   Gap E CLOSED   Gap F CLOSED
CallerIdentity provider-wire boundary: LIVE-PROVEN
```

No remaining V1 acceptance blocker exists in repository authority. ADR-031
stable-public-edge live acceptance remains `DEFERRED_BY_ENVIRONMENT`, not
abandoned, and the ledger already records that it "does not block the
registration/NAT acceptance corridor" — a non-blocking environmental deferral,
explicitly preserved. The accidental synthetic fixture slug
`v1-canonical-peer-1787783954` remains known cleanup debt already deferred to a
separate bounded packet, and external PBX prerequisites remain separate; neither
is a V1 acceptance requirement.

```text
V1: COMPLETE
```

## Boundary

No production source was changed by this proof; the only repository changes are
this evidence record and the current-state ledger. No Kamailio, Asterisk, or
external-PBX configuration was modified beyond deploying the already-committed
correction through the canonical lifecycle. No permanent diagnostic
infrastructure was created. K5A–K5E ordering, the K5F post-K5E classification,
and `K5E -> RMA` are untouched. No K5, K5F, A0, or RMA work was started. The
`scripts/native-k3s/image-sync` `.git` defect and the broad `Quality` CI failures
remain unchanged separate debt.
