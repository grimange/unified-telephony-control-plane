# V1 Gap E — Provider-Failure Fact-Binding Live Proof

Current-State-Impact: yes

Date: 2026-08-30

Exact HEAD: `39698ec3f1355f4bb03a966ad73ed133c09b4815`
(`fix(native-k3s): promote immutable image locks`)

## Verdict

`V1_GAP_E_REPAIRED_RUNTIME_LIVE_PROVIDER_FIXTURE_BLOCKED`

```text
image lock promoted:        YES
repair deployed:            YES
running sanitizer corrected: YES
provider Call placed:       NO
remaining blocker:          deterministic external-PBX pre-answer failure fixture
```

The deployment blocker recorded in the first attempt is **resolved and proven
live**. The repaired Asterisk ARI sanitizer now runs on the canonical native-k3s
environment. No Call was placed, because the provider-side expected outcome
still cannot be established before origination, which the proof contract
requires. The repaired runtime is deliberately left deployed.

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

## Boundary

Gap A, B, C, and D remain closed. Gap E remains open: Asterisk raw-fact
preservation is now **live-deployed**; provider-failure fact binding is blocked
only on the external-PBX fixture. Gap F remains `PROOF_GAP_ONLY`. ADR-031
stable-public-edge acceptance remains `DEFERRED_BY_ENVIRONMENT`, not abandoned.
No K5 or RMA work was started. ADR-030 semantics were not changed. No repository
source was modified by this packet.
