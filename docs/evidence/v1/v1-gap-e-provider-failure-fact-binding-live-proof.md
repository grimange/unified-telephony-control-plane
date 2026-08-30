# V1 Gap E — Provider-Failure Fact-Binding Live Proof

Current-State-Impact: yes

Date: 2026-08-30

Exact HEAD: `39698ec3f1355f4bb03a966ad73ed133c09b4815`
(`fix(native-k3s): promote immutable image locks`)

## Verdict

`V1_GAP_E_PROVIDER_FAILURE_FACT_BINDING_PROVEN`

```text
deterministic fixture established before origination:  YES
canonical proof Calls placed:                          1
expected SIP 404 observed:                             YES
provider-facing PJSIP facts captured:                  cause 1 / "Unallocated (unassigned) number" / tech_cause 404
Local ;2 and Local ;1 facts captured separately:       YES
raw facts persisted downstream:                        YES
failure_class / failure_code:                          NULL / NULL (no taxonomy implemented)
provider-channel -> CallLeg correlation:               EXISTING_CORRELATION_INSUFFICIENT
```

The provider-outcome fact authority is now live-proven: **`tech_cause` carries the
provider SIP final response and appears only on the provider-facing PJSIP
channel.** The Q.850 `cause` propagates to all three channels, but the
SIP-specific detail does not — a direct confirmation of ADR-030 §11.

Correlation is the one remaining gap, and its missing binding is exact.

## Attempt chronology

### Attempt 1 — 2026-08-30, HEAD `129970300f…`

Stopped before any Call. `make server-apply` succeeded while deploying a stale
immutable digest: `.runtime/native-k3s/image-lock.env` pinned source commit
`1423bcfaf0…` / `utcp-api@sha256:45e1ed40…`, written 2026-08-29 08:08, which
predated the sanitizer repair committed 2026-08-30 03:19. The ARI worker Pod was
never replaced and the running image still stripped
`cause`/`cause_txt`/`tech_cause`, verified inside the Pod. A second, independent
blocker also applied: the provider fixture could not be made deterministic.

### Attempt 2 — 2026-08-30, HEAD `39698ec3f1…` (this record)

The deployment blocker is resolved through the new canonical
`make server-image-sync` promotion path. The provider-fixture blocker persists
unchanged.

## Image-lock promotion

An exact `native-k3s-image-lock-<commit>` artifact exists for current HEAD
(artifact `9726141269`, created 2026-08-30T04:04:05Z, not expired), so the §7
**Preferred** rule applied and current HEAD was promoted rather than the earlier
repair commit.

```text
selected source commit   39698ec3f1355f4bb03a966ad73ed133c09b4815  (current HEAD)
UTCP_SERVER_IMAGE_TAG    sha-39698ec3f1355f4bb03a966ad73ed133c09b4815
UTCP_SERVER_API_IMAGE    ghcr.io/grimange/utcp-api@sha256:2a8d495a…1981
```

Before promotion the active lock read source commit `1423bcfaf0…`, tag
`sha-1423bcfaf0…`, API digest `sha256:45e1ed40…`. `make server-image-sync`
reported `promoted immutable image lock for 39698ec3f1…`, and
`make server-image-preflight` then passed its target, immutable-lock, registry,
and image-manifest checks against the new digest.

No lock file was edited by hand, no image was rebuilt or pushed from the
workstation, no mutable tag was used, and no `kubectl set image` or Deployment
patch was applied.

### Defect found in the canonical promotion path

`make server-image-sync` failed on its first invocation with
`could not retrieve exact image-lock artifact metadata`. The cause is a
repository defect at `scripts/native-k3s/image-sync:23`: the origin-URL
derivation

```text
s#^(https://github\.com/|git@github\.com:)([^/]+/[^/]+)(\.git)?$#\2#p
```

is greedy — `[^/]+` also matches the `.git` suffix, so `\2` captures
`unified-telephony-control-plane.git` and the optional `(\.git)?` matches empty.
The API call therefore targets
`repos/grimange/unified-telephony-control-plane.git/actions/artifacts` and
returns HTTP 404. Any clone whose `origin` URL ends in `.git` — the default for
`git clone` — hits this.

The script's own documented `GH_REPO` input (`image-sync:20`, whose failure text
is literally "set GH_REPO") was used to supply the repository identity:

```text
GH_REPO=grimange/unified-telephony-control-plane make server-image-sync
```

This is the script's supported configuration seam, not a bypass. The regex
defect is recorded as a bounded follow-up; it was **not** repaired in this
packet, which authorises no source changes.

## Canonical deployment

```text
make server-config-check      native k3s configuration check passed
make server-image-preflight   passed against sha256:2a8d495a…1981
make server-apply             first run: timed out waiting on jobs/utcp-migrate
                              (first pull of the new ~1 GB digest across 16 Pods)
                              natural convergence, then idempotent rerun: applied
make server-status            all workloads Running / Ready
```

The first `server-apply` timeout was an image-pull timing condition, not a
defect: the node was pulling `utcp-api@sha256:2a8d495a…` for the first time
while sixteen Pods were being recreated. Convergence was allowed to happen
naturally — not-ready Pods fell 16 → 0 over roughly 8 minutes — and
`jobs/utcp-migrate` reached `Complete 1/1` in 7 m 28 s. No Pod was manually
restarted, deleted, or patched.

## Rollout and live repair verification

```text
                 old Pod                                  new Pod
api              api-64cd65b6bc-ht4ht                     api-5c6784bcf6-bnr8v
asterisk-ari     asterisk-ari-events-6db9c6fd6c-vh4z5     asterisk-ari-events-76bc544d46-x5dgc

desired (lock)   ghcr.io/grimange/utcp-api@sha256:2a8d495a…1981
running api      ghcr.io/grimange/utcp-api@sha256:2a8d495a…1981
running ari      ghcr.io/grimange/utcp-api@sha256:2a8d495a…1981
desired vs running: MATCH
```

Read-only in-Pod inspection of the running ARI worker
(`asterisk-ari-events-76bc544d46-x5dgc`) proves the repaired sanitizer is live —
not merely the expected digest:

```php
if ($type === 'ChannelDestroyed') {
    if (is_int($event['cause'] ?? null))        { $sanitized['cause'] = $event['cause']; }
    if (is_string($event['cause_txt'] ?? null)) { $sanitized['cause_txt'] = mb_substr($event['cause_txt'], 0, 120); }
    if (is_int($event['tech_cause'] ?? null))   { $sanitized['tech_cause'] = $event['tech_cause']; }
```

```text
cause       preserved, ChannelDestroyed-gated, int only
cause_txt   preserved, ChannelDestroyed-gated, bounded to 120 chars
tech_cause  preserved when present, int only
```

The identical check against the pre-promotion Pod returned
`NO CAUSE PRESERVATION IN DEPLOYED IMAGE`. ADR-030 §9 Layer-2 preservation is
therefore live for the first time.

## Runtime health baseline

All `utcp-platform` and `utcp-runtime` workloads Running and Ready. ExternalTrunk
`3a9bf028-…` `active` / `ready` / `registration_endpoint_registered`;
registration `registered`, naturally refreshed at 04:20:38. Eligible RuntimeNode
`102d58ba-…` `active` / `ready`; `7322e6e1-…` remains `draft` / `unobserved` and
correctly ineligible. Non-terminal Calls `0`, non-terminal CallLegs `0`.

## Provider-fixture gate — still blocked

The provider is an independent external PBX at `38.146.161.46`
(telecom-MCP target `lab-remote-asterisk-01`). The `utcp-v1` endpoint's inbound
context is `utcp-v1-in`, confirmed read-only.

```text
dialplan show utcp-v1-in        NOT_ALLOWED — outside the read-only CLI allowlist
                                (allowlist exposes only bridge/core/pjsip listings
                                and dialplan show telecom-mcp-test)
telecom.list_probes             NOT_ALLOWED — observability-only capability policy
dialplan show telecom-mcp-test  readable, but a Stasis context (97888), not utcp-v1-in
repository evidence             documents only 97001, which answers; no
                                deterministic pre-answer failure destination
```

`pjsip show endpoints` on the PBX lists `6001`, `6002`, `6003` (all
`Unavailable`, 0 contacts) and `utcp-v1`. Their unavailability makes them
*plausible* failure targets, but nothing proves `utcp-v1-in` routes to them, what
it does on failure, or that no catch-all pattern exists. Asserting an expected
result from endpoint state alone would be inference, which the proof contract
forbids.

The PBX cannot be modified — it is independent and outside UTCP's authority. The
repository `external-sip-peer` fixture is not an alternative: its dialplan
answers every extension (`[from-utcp] _. -> Answer`), and the proof must use the
existing registration-based trunk.

## Not performed

No Call was created. No Asterisk channel topology, provider outcome, per-channel
`cause`/`cause_txt`/`tech_cause`, Local-versus-PJSIP comparison, downstream
raw-fact persistence check, or PJSIP→CallLeg correlation classification could be
captured; all require a deterministic provider failure.

No taxonomy, no correlation implementation, no dialplan change, no `ari.conf`
`channelvars` change, no Kamailio change, no provider mutation. Per §45 the
repaired runtime is left deployed; the stale pre-repair image was **not**
restored.

## Remaining prerequisite

One operator action unblocks the proof:

```text
Run the read-only external-PBX command:

dialplan show utcp-v1-in

and provide the output,

OR provide the exact existing destination/extension and expected deterministic
pre-answer failure result for the independent external PBX.
```

Once supplied, the proof re-runs on the already-repaired runtime with no further
deployment work: place one canonical outbound Call to that destination, identify
the provider-facing PJSIP channel, capture `cause` / `cause_txt` / `tech_cause`
separately for Local `;1`, Local `;2`, and the PJSIP leg, verify downstream
persistence, and classify PJSIP→CallLeg correlation.

## Bounded follow-up recorded

`scripts/native-k3s/image-sync:23` — the origin-URL regex does not strip a
`.git` suffix, so `server-image-sync` fails with a 404 on any default clone
unless `GH_REPO` is supplied. Smallest correction: make the repository capture
non-greedy or strip `.git` explicitly, with a focused case in
`scripts/native-k3s/image-sync-test`.

## Attempt 3 — 2026-08-30, HEAD `bfbc570148…` — SUCCESSFUL

### Fixture resolution

The operator supplied read-only PBX evidence for the previously unreadable
context:

```text
sudo asterisk -rx "dialplan show utcp-v1-in"

Context 'utcp-v1-in'
  97001:  NoOp, Log, TIMEOUT(absolute)=60, Answer, Wait, Playback(beep), Echo, Hangup
  1 extension total
```

`97001` is the only extension; there is no wildcard, catch-all, or alternate
pattern. Therefore `97002` is absent and its expected pre-answer outcome is
`SIP 404 Not Found`, **established before origination**.

### Preflight

The repaired runtime was still live and was not redeployed. Current Pods
`api-5c6784bcf6-bnr8v` and `asterisk-ari-events-76bc544d46-x5dgc` both run
`ghcr.io/grimange/utcp-api@sha256:2a8d495a…1981`, matching the active lock, and
in-Pod inspection re-confirmed the ChannelDestroyed-gated
`cause` / `cause_txt` / `tech_cause` preservation. Trunk `active` / `ready`,
registration `registered`, RuntimeNode `102d58ba-…` `active` / `ready`,
non-terminal Calls `0` and CallLegs `0`.

### Routable destination

`destination_ref` must resolve to a TelephonyAddress
(`C7bService::addressIdFromDestination` rejects any other type), so the 97002
destination was created through the canonical Admin API only — no direct SQL and
no topology change:

```text
TelephonyAddress  7927dda1-b710-450f-9e73-a61ee23219ae  sip:97002@38.146.161.46  active
trunk association 3a9bf028-…  direction outbound
outbound route    005b1270-6eb6-4d56-a325-1d8de071fcc0  slug gape-97002-failure  priority 10
                  caller identity f11a46e5-…  activated, then projected automatically
```

Automatic projection was awaited, not forced: `kamailio_external_trunk_route_view`
converged to carry both `97001` and `97002`.

### The proof Call

Exactly one canonical outbound Call, `runtime_node_id` omitted.

| Fact | Value |
| --- | --- |
| Call | `e7719adc-5a9f-4c1a-a880-e0907fdcd8b4` |
| CallLeg | `3f91f5ab-c0a7-4958-bfa8-6dfc9f90617c` |
| RouteDecision | `e2597079-eff0-49a5-b60b-caa61f15ba58` |
| Outbound route | `005b1270-6eb6-4d56-a325-1d8de071fcc0` |
| Originate RuntimeOperation | `b715ec0ab64f4a32b04fa4f49554fd20` (succeeded) |
| RuntimeNode (auto-selected) | `102d58ba-93ec-4601-a2a3-81f95801440f` |
| Canonical runtime_channel_id | `utcp-call-leg-3f91f5ab-c0a7-4958-bfa8-6dfc9f90617c` |
| ExternalTrunk / TrunkEndpoint | `3a9bf028-…` / `ad7a95f4-…` |
| CallerIdentity | `f11a46e5-…` |
| Provider SIP Call-ID | `1fa43147-ba3f-427a-a389-e1ed9b1e1364` |

Kamailio recorded the provider-bound `INVITE sip:97002@…` at 04:54:08.520 and an
`ACK` at 04:54:08.965 — the ACK to a non-2xx final response. No `401`/`407`
challenge appeared on this transaction.

### Asterisk channel topology and raw termination facts

All three channels emitted `ChannelDestroyed` at 04:54:08.965.

| Role | Channel ID | Channel name | cause | cause_txt | tech_cause |
| --- | --- | --- | --- | --- | --- |
| provider PJSIP | `1788065648.2` | `PJSIP_kamailio-edge-00000000` | `1` | `Unallocated (unassigned) number` | **`404`** |
| Local `;2` | `utcp-call-leg-3f91f5ab-…;2` | `Local_97002_utcp-outbound-00000000_2` | `1` | `Unallocated (unassigned) number` | **ABSENT** |
| Local `;1` | `utcp-call-leg-3f91f5ab-…` | `Local_97002_utcp-outbound-00000000_1` | `1` | `Unallocated (unassigned) number` | **ABSENT** |

```text
actual provider SIP final response: 404 Not Found
expected:                           404 Not Found
expected vs actual:                 MATCH
```

`tech_cause` vs provider SIP result: **`OBSERVED_MATCH`** on this sample. The
running Asterisk 20.20.1 ARI schema documents `tech_cause` as the
"technology-specific off-nominal cause", which for PJSIP is the SIP response
code; this observation is consistent with that, and one sample is recorded as
one sample, not promoted to a universal contract.

Q.850 `cause = 1` propagated to **all three** channels, so the Local channels do
carry the generic cause. The SIP-specific `tech_cause` did **not** propagate and
exists only on the provider-facing leg. ADR-030 §11 therefore holds concretely:
the Local control channel is not the provider failure-detail authority, even
though it is the canonical `runtime_channel_id`.

### Downstream raw-fact persistence — proven

The repaired facts survive past the ARI sanitizer into
`runtime_observations.payload`:

```text
obs 917191f9aa5a3e2fe5c292db32676638  subject_id runtime:1788065648.2
  cause 1, cause_txt "Unallocated (unassigned) number", tech_cause 404
obs 53949f4e5c382a0df4081a8046e44485  subject_id runtime:utcp-call-leg-3f91f5ab-…;2
  cause 1, cause_txt "Unallocated (unassigned) number"
obs 868dbb662f4a259fa9082035892a858f  subject_id 3f91f5ab-c0a7-4958-bfa8-6dfc9f90617c
  cause 1, cause_txt "Unallocated (unassigned) number"
```

No downstream preservation defect. This is the first time provider failure
detail has existed anywhere in UTCP.

### Correlation analysis — insufficient, with an exact missing binding

```text
Local ;1  -> CallLeg   DIRECT: subject_id is the CallLeg ID; channel id is the
                       canonical runtime_channel_id
Local ;2  -> CallLeg   DURABLE: channel id is the canonical runtime_channel_id
                       plus the Asterisk ";2" suffix, derived from UTCP's own
                       deterministic identifier
PJSIP     -> CallLeg   NONE
```

The provider observation's `subject_id` remains `runtime:1788065648.2`. The only
other persisted fields on it are `remote_identity` (`97002`, the destination) and
the timestamp — both explicitly non-durable correlation authority.

`linkedid` is **not available at all**: the running Asterisk's own ARI `Channel`
model (`/var/lib/asterisk/rest-api/channels.json`) declares exactly

```text
id, protocol_id, name, state, caller, connected, accountcode,
dialplan, creationtime, language, channelvars, caller_rdnis, tenantid
```

There is no `linkedid` property, so no sanitizer change can recover one. The
`1788065648.2` suffix relationship to the Local pair is an Asterisk uniqueid
convention, not a persisted fact, and is rejected as correlation authority.

```text
EXISTING_CORRELATION_INSUFFICIENT
```

**Exact missing binding:** the provider-facing PJSIP channel's ARI event carries
no UTCP identity. `__UTCP_CALL_LEG_ID` is already inherited onto that channel —
the originate sets it with the double-underscore inheritance prefix and the
`utcp-outbound-predial` handler reads `${UTCP_CALL_LEG_ID}` there to stamp the
outbound SIP header — but ARI exposes channel variables only through the optional
`channelvars` object, which is not populated (`ari.conf` is generated by
`infrastructure/docker/asterisk/entrypoint` with no `channelvars=`) and would in
any case be discarded by `AsteriskAriClient::sanitizeAriObject()`, whose
allow-list keeps only `id`, `name`, `state`, `caller`, `connected`, `media_uri`,
and `channels`.

The smallest repair is therefore to surface that already-inherited identifier:
populate `channelvars` for `UTCP_CALL_LEG_ID` and retain it, bounded and
allow-listed, through the sanitizer. No dialplan change is required — the
variable already reaches the provider channel. No Kamailio path is required —
the provider outcome is read inside the runtime.

```text
dialplan change required:        NO   (variable already inherited and proven in use)
ari.conf channelvars required:   YES  (the only ARI-exposed carrier; linkedid does not exist)
Kamailio correlation required:   NO   (facts already arrive via ARI)
```

### Canonical outcome

```text
Call    e7719adc-…   completed / remote / remote
CallLeg 3f91f5ab-…   completed / remote / remote
answered_at    NULL
terminated_at  2026-08-30 04:54:24+00
failure_class  NULL
failure_code   NULL
```

`answered_at` is NULL as required for a pre-answer failure. `failure_class` and
`failure_code` remain NULL because Gap E taxonomy is deliberately not
implemented here. Origination timeout did not interfere — the provider failure
settled about 18 s before the 60 s deadline and the terminal fact was applied
first. `runtime_lost` was correctly not applied: an orderly `ChannelDestroyed`
existed, so ADR-030 §7 does not classify this as runtime loss.

Recorded without action: the canonical result is `completed`, not `failed`,
because ADR-030 §6 maps an orderly terminal fact with no local intent to
`remote`. Whether a pre-answer provider rejection should instead be `Failed` is
precisely the taxonomy question Gap E's next packet owns; it is **not** decided
or changed here.

### Regression and cleanup

Managed Asterisk reported `0 active channels`, `0 active calls`,
`1 call processed`. Non-terminal Calls `0`, CallLegs `0`. Trunk remained
`active` / `ready`; registration `registered`, refreshed 04:56:07; RuntimeNode
`102d58ba-…` `active` / `ready`. No dialplan, `ari.conf`, Kamailio, provider, or
schema change was made, and no taxonomy or correlation repair was implemented.

## Boundary

Gap A, B, C, and D remain closed. Gap E remains open: the provider-failure raw
fact authority is now **live-proven** and the exact provider-channel-to-CallLeg
correlation seam is isolated. Gap F remains `PROOF_GAP_ONLY`. ADR-031
stable-public-edge acceptance remains `DEFERRED_BY_ENVIRONMENT`, not abandoned.
No K5 or RMA work was started. ADR-030 semantics were not changed. No repository
source was modified by this packet.
