# V1 Gap E — Controlled Provider-Failure Fact-Binding Live Proof (Blocked)

Current-State-Impact: yes

Date: 2026-08-30

Exact HEAD: `129970300fc5e3c4fcf1c1ea2f6b0ecda5633ee6`
(`fix(v1): preserve Asterisk termination facts`)

## Verdict

`V1_GAP_E_LIVE_PROOF_BLOCKED_DEPLOYMENT_CANNOT_CARRY_EXACT_HEAD`

The proof was stopped before any Call was placed. Two independent blockers were
established, both by direct live evidence. The first is prior and decisive: the
canonical native-k3s lifecycle **cannot deliver exact HEAD's application code**,
so any provider-failure Call run now would observe pre-repair behaviour and
prove nothing about Gap E fact binding.

Secondary and independent:
`V1_GAP_E_PROVIDER_FAILURE_FIXTURE_NOT_DETERMINISTIC` — the provider-side
expected outcome cannot be established before origination with the tooling
available, which §11 requires.

No taxonomy was implemented, no correlation repair was attempted, no dialplan,
Kamailio, provider, or ARI configuration was changed, and no Call was created.

## Blocker 1 — the canonical lifecycle cannot carry exact HEAD

`make server-apply` deploys images pinned by **immutable digest** in
`.runtime/native-k3s/image-lock.env` (`scripts/native-k3s/lib:13`, enforced at
`lib:134` by an `^…@sha256:[0-9a-f]{64}$` immutable-reference check that
`server-image-preflight` validates).

```text
UTCP_SERVER_API_IMAGE = ghcr.io/grimange/utcp-api@sha256:45e1ed40…8f3a
image-lock.env mtime  = 2026-08-29 08:08:46 +0000
repair commit 1299703 = 2026-08-30 03:19:37 +0000   (~19 h later)
```

The pinned digest therefore predates the repair. The full canonical sequence was
run — `server-config-check`, `server-image-preflight`, `server-apply` — and all
three passed, but the API Deployment's image was unchanged and the ARI worker Pod
was **not replaced** (`asterisk-ari-events-6db9c6fd6c-vh4z5`, 161 min old,
`imageID` still `…45e1ed40…`).

Direct verification inside the running Pod:

```text
sed -n '/function sanitizeAriEvent/,/^    }/p' \
  /var/www/html/app/RuntimeAdapters/Asterisk/AsteriskAriClient.php | grep cause
-> NO CAUSE PRESERVATION IN DEPLOYED IMAGE
```

The repair exists at HEAD in source and its regressions pass (below), but the
running runtime path is the pre-repair build. `sanitizeAriEvent()` in the
deployed image still strips `cause`, `cause_txt`, and `tech_cause`.

Delivering exact HEAD would require all three of:

```text
1. build a new API image from HEAD                 (make image-build-api)
2. publish it to ghcr.io/grimange                  external registry push
3. rewrite the immutable digest in
   .runtime/native-k3s/image-lock.env              defeats the preflight lock
```

This packet authorises only
`server-config-check → server-image-preflight → server-apply → server-status`.
Step 2 publishes an artifact to an external registry and step 3 deliberately
breaks the immutable-lock guarantee the preflight exists to enforce. Neither was
performed. No `kubectl set image`, `patch`, `edit`, Pod deletion, manual
manifest, or source mount was used.

`image-lock.env` is untracked local runtime state (mode 0600), not a repository
file, so this is an environment-lifecycle gap rather than a committed-configuration
defect.

## Blocker 2 — the provider failure fixture is not deterministic

§11 requires the expected provider-side result to be established **before**
originating, and §10 forbids choosing an arbitrary destination and inferring the
result afterwards.

The provider is an **independent external PBX** at `38.146.161.46`
(`docs/evidence/v1/v1a-registration-external-trunk-implementation.md`), not a
repository fixture. It is a configured telecom-MCP target
(`lab-remote-asterisk-01`), and read-only observation confirmed the live
registration and that the `utcp-v1` endpoint's inbound context is `utcp-v1-in`.

Every avenue for establishing an expected pre-answer failure was exhausted:

```text
dialplan show utcp-v1-in     NOT_ALLOWED — outside the read-only CLI allowlist
                             (allowlist exposes only bridge/core/pjsip listings
                             and dialplan show telecom-mcp-test)
telecom.list_probes          NOT_ALLOWED — runtime policy permits only the
                             "observability" capability class; validation and
                             active probes are refused
dialplan show telecom-mcp-test  readable, but it is a Stasis test context
                             (97888 -> Stasis), not a failure fixture, and not
                             the utcp-v1-in context
repository evidence          records only 97001, which answers; no evidence
                             documents any deterministic pre-answer failure
                             destination on this PBX
```

The PBX cannot be modified — it is independent, real, and outside UTCP's
authority. The repository's disposable `external-sip-peer` fixture is not an
alternative: its dialplan answers every extension
(`[from-utcp] _. -> Answer`), and §14 requires the proof to use the existing
canonical registration-based trunk.

Because `utcp-v1-in` cannot be enumerated, it is not possible to rule out a
catch-all pattern, so no destination can be asserted in advance to produce a
specific pre-answer failure. Choosing one and reading the result afterwards is
exactly what §10 prohibits.

## What was verified

Exact-head preflight, all passing:

```text
make phase-status-consistency-check   PASS (changed=4, evidence=1, status-impact=yes)
make repository-hygiene               passed
make server-config-check              native k3s configuration check passed
git diff --check                      clean
AsteriskAriAdapterTest                78 passed (402 assertions), including
  channel destroyed preserves native terminal facts without canonical meaning
  raw channel destroyed websocket event preserves required facts
  raw non channel destroyed event does not gain termination facts
```

Canonical environment confirmed: native k3s, context `default`, node
`utcp-dev01` (`192.168.254.124`); k3d `utcp-local` `0/1` — stopped and
non-canonical throughout.

Post-apply health, unchanged: all `utcp-platform` and `utcp-runtime` workloads
Running and Ready; ExternalTrunk `3a9bf028-…` `active` / `ready` /
`registration_endpoint_registered`; registration `registered` and naturally
refreshed; RuntimeNode `102d58ba-…` `active` / `ready`; non-terminal Calls `0`
and CallLegs `0`.

The repair's committed shape at HEAD, for the record — `cause` accepted only as
`int`, `cause_txt` as bounded string, `tech_cause` only as `int`:

```php
if ($type === 'ChannelDestroyed') {
    if (is_int($event['cause'] ?? null))       { $sanitized['cause'] = $event['cause']; }
    if (is_string($event['cause_txt'] ?? null)) { $sanitized['cause_txt'] = mb_substr($event['cause_txt'], 0, 120); }
    if (is_int($event['tech_cause'] ?? null))   { $sanitized['tech_cause'] = $event['tech_cause']; }
```

The `is_int` gate on `tech_cause` is noted as an open observation only: the
running Asterisk 20.20.1 ARI schema types `tech_cause` as `int`, while the
pre-existing normalizer fixture used the string `'SIP 200'`. Which shape the
provider path actually emits remains exactly what the blocked proof was meant to
establish, and is not decided here.

## Not performed

No Call was created. No Asterisk channel topology, provider outcome, per-channel
`cause`/`cause_txt`/`tech_cause`, Local-versus-PJSIP comparison, or
PJSIP→CallLeg correlation classification could be captured, because all of them
require the repaired runtime path to be live. Those questions remain exactly as
the Gap E audit left them.

No `ari.conf` `channelvars` change, no dialplan change, no Kamailio change, no
taxonomy, no correlation implementation.

## Smallest deterministic corrections

**For blocker 1** — one bounded, operator-authorised environment lifecycle step:
build the API image from exact HEAD, publish it, and update
`.runtime/native-k3s/image-lock.env` to the new digest, then rerun
`server-image-preflight` and `server-apply` and confirm the ARI worker Pod is
replaced and carries the repair. This is an external registry publish plus an
immutable-lock rotation and requires explicit authorisation; it is not a
repository code change.

A durable follow-up worth considering separately: the native lifecycle has no
repository-owned target that builds, publishes, and re-pins the application
image, so "deploy exact HEAD" is not currently expressible through `make`. That
gap is what allowed a green `server-apply` to silently deploy pre-repair code.

**For blocker 2** — one bounded provider-evidence step: obtain a deterministic
pre-answer failure destination on `lab-remote-asterisk-01`, either by extending
the telecom-MCP read-only CLI allowlist to include `dialplan show utcp-v1-in`
(observability-class, no mutation), or by an operator recording the expected
outcome for one specific extension. Either makes the expected result assertable
in advance without modifying the PBX.

Both corrections are independent; blocker 1 must be resolved first, since the
proof is meaningless on a pre-repair runtime.

## Boundary

Gap A, B, C, and D remain closed. Gap E remains open with raw-fact preservation
implemented and tested but **not live-deployed**. Gap F remains
`PROOF_GAP_ONLY`. ADR-031 stable-public-edge acceptance remains
`DEFERRED_BY_ENVIRONMENT`, not abandoned. No K5 or RMA work was started. ADR-030
semantics were not changed. No repository source was modified by this packet.
