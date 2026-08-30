# V1 Gap E — Minimal Provider-Failure Taxonomy Natural Live Proof

Current-State-Impact: yes

Date: 2026-08-30

Exact HEAD: `8964e936b60d8b6d62529624e132e723844bc52f`
(`fix(v1): classify provider call failures`)

## Verdict

`V1_GAP_E_MINIMAL_PROVIDER_FAILURE_TAXONOMY_LIVE_PROVEN`

```text
provider failure fact authority:                 LIVE-PROVEN
provider-channel -> CallLeg correlation:         LIVE-PROVEN
minimal canonical provider-failure taxonomy:     IMPLEMENTED + TESTED + LIVE-PROVEN
```

**Gap E is CLOSED** on the adopted minimum deterministic taxonomy contract. This
is not a claim that every conceivable SIP/Q.850 provider failure is classified;
unmapped provider outcomes continue to preserve raw evidence and may remain
canonical `Failed` with NULL taxonomy until explicitly adopted.

## Image publication and promotion

`Native k3s Images` for `head_sha 8964e936b6…` was `in_progress` at packet start
and was awaited rather than worked around; it completed **success** at 06:51.
Artifact `native-k3s-image-lock-8964e936b6…` id `9727409192`… (`9727973799`),
`expired=false`, created `2026-08-30T06:51:26Z`.

```text
UTCP_SERVER_SOURCE_COMMIT   8964e936b60d8b6d62529624e132e723844bc52f
UTCP_SERVER_IMAGE_TAG       sha-8964e936b60d8b6d62529624e132e723844bc52f
UTCP_SERVER_API_IMAGE       ghcr.io/grimange/utcp-api@sha256:839feab9…6de6
UTCP_SERVER_ASTERISK_IMAGE  ghcr.io/grimange/utcp-asterisk@sha256:4ab5a3b2…f1f5
```

Promotion used the supported explicit `GH_REPO` input because the recorded
`scripts/native-k3s/image-sync` `.git` origin-parsing defect remains open and was
deliberately not repaired here. `server-config-check` and
`server-image-preflight` passed; `server-apply` completed on the first run.

## Deployment and convergence

```text
                  pre-deploy                              post-deploy
api               api-7d586dc886-bdfl5  (ad84e63e…)  ->   api-5cf7ffb8b4-7fmth  (839feab9…)
asterisk-ari      …-9868b9fb4-rjkh8     (ad84e63e…)  ->   …-b97db798d-bwrwl     (839feab9…)
managed Asterisk  …-f857f9jsbz          (b8c1730e…)  ->   …-5c44cw7t9x          (4ab5a3b2…)
```

Running API and `asterisk-ari-events` digests both equal the promoted API digest.

**Managed Asterisk digest changed: YES.** The taxonomy commit made no Asterisk
configuration change, but CI republishes the Asterisk image per commit, so a new
digest was produced. Per the convergence gate established by the prior
correlation proof, no manual intervention was applied: the normal
`AsteriskRuntimeNodeReconciler` → `desired_execution_image` →
`runtime.node.workload.converge` path re-imaged the managed RuntimeNode on its
own, completing within roughly one minute of apply.

## Live taxonomy code

Verified read-only inside the running API Pod (digest `839feab9…`):

```text
CallDomainService.php:591           terminalizeObservedProviderFailure(...)
CallObservationProcessor.php:77     $payload['provider_terminal_failure']
CallObservationProcessor.php:78     $payload['defer_pre_answer_terminalization']
CallObservationProcessor.php:87     ['unreachable', 'destination_not_found']
AsteriskAriEventNormalizer.php:247  $safe['provider_terminal_failure'] = true
AsteriskAriEventNormalizer.php:249  $safe['defer_pre_answer_terminalization'] = true
```

## Correlation configuration regression guard

Managed runtime `/tmp/utcp-asterisk/ari.conf` still contains exactly
`channelvars = UTCP_CALL_LEG_ID`; broad-exposure (`channelvars = *`) count **0**.

## Baseline

All `utcp-platform` Pods Ready. Trunk `3a9bf028-…` `active`/`ready`;
registration `registered` (06:55:13); RuntimeNode `102d58ba-…` `active`/`ready`;
non-terminal Calls `0`, CallLegs `0`. Existing canonical 97002 objects reused,
not recreated: TelephonyAddress `7927dda1-…` active, outbound route
`005b1270-…` (`gape-97002-failure`) active, route view carrying `97002` and
`97001`.

## The proof Call

Exactly one canonical outbound Call, `runtime_node_id` omitted.

| Fact | Value |
| --- | --- |
| Call | `2facde5c-cd30-4e12-a9a8-7b75775dfd45` |
| CallLeg | `b3dd5d9c-74c5-4fbf-9988-02a1a8e79779` |
| RouteDecision | `000c0195-2d4d-4fc9-a678-48f2bd22c408` |
| Originate RuntimeOperation | `04a3569a04c3dfb641edeb54fd8ca4fd` (succeeded) |
| RuntimeNode (auto-selected) | `102d58ba-93ec-4601-a2a3-81f95801440f` |
| CallLeg.runtime_channel_id | `utcp-call-leg-b3dd5d9c-74c5-4fbf-9988-02a1a8e79779` |
| ExternalTrunk / TrunkEndpoint | `3a9bf028-…` / `ad7a95f4-…` |
| CallerIdentity | `f11a46e5-…` |

Kamailio recorded `INVITE sip:97002@…` then `ACK` — the ACK to a non-2xx final.

```text
expected provider result: 404 Not Found
actual provider result:   404 Not Found   (tech_cause 404, Q.850 cause 1)
expected vs actual:       MATCH
```

## Provider evidence and correlation

Provider-facing PJSIP `1788072922.2` / `PJSIP_kamailio-edge-00000000`,
receipt `4a01204fbdb8147117366d260a07bfd8`:

```json
{"ari_event_type":"ChannelDestroyed","call_leg_id":"b3dd5d9c-74c5-4fbf-9988-02a1a8e79779",
 "cause":1,"cause_txt":"Unallocated (unassigned) number","channel_id":"1788072922.2",
 "channel_name":"PJSIP_kamailio-edge-00000000","remote_identity":"97002","tech_cause":404}
```

Normalized observation `ecb110e1a58d7db0baddb7bd0d947b26`:

```json
{"ari_event_type":"ChannelDestroyed","cause":1,
 "cause_txt":"Unallocated (unassigned) number","defer_pre_answer_terminalization":true,
 "provider_terminal_failure":true,"remote_identity":"97002",
 "runtime_channel_id":"1788072922.2","tech_cause":404}
```

```text
fresh CallLeg ID            b3dd5d9c-74c5-4fbf-9988-02a1a8e79779
= receipt call_leg_id       b3dd5d9c-74c5-4fbf-9988-02a1a8e79779
= observation subject_id    b3dd5d9c-74c5-4fbf-9988-02a1a8e79779   (subject_type call_leg)
observation payload.runtime_channel_id  1788072922.2   (provider PJSIP uniqueid, raw evidence)
CallLeg.runtime_channel_id  utcp-call-leg-b3dd5d9c-…   (Local ;1, unchanged)
```

Uncorrelated `runtime:%` subjects in the proof window: **0**.

## Local channel facts and natural event order

| Role | Channel | cause | cause_txt | tech_cause | flags |
| --- | --- | --- | --- | --- | --- |
| provider PJSIP | `1788072922.2` | `1` | `Unallocated (unassigned) number` | **`404`** | `provider_terminal_failure`, `defer_pre_answer_terminalization` |
| Local `;2` | `utcp-call-leg-b3dd5d9c-…;2` | `1` | `Unallocated (unassigned) number` | ABSENT | `defer_pre_answer_terminalization` |
| Local `;1` | `utcp-call-leg-b3dd5d9c-…` | `1` | `Unallocated (unassigned) number` | ABSENT | `defer_pre_answer_terminalization` |

Natural ordering, ARI `occurred_at`: provider `06:55:23.296`, Local `;2`
`06:55:23.297`, Local `;1` `06:55:23.297`. Processing order followed the same
sequence (observations at `06:55:35`, `06:55:39`, `06:55:40`), so this run
naturally exercised **provider-first**. Local-first remains covered by the
repository regressions; no additional Call was placed to force it.

## Central taxonomy acceptance

**CallLeg `b3dd5d9c-…`**

```text
observed_state      failed
termination_reason  remote
termination_party   remote
failure_class       unreachable
failure_code        destination_not_found
answered_at         NULL
terminated_at       2026-08-30 06:55:35+00
```

**Call `2facde5c-…`**

```text
observed_state      failed
termination_reason  remote
termination_party   remote
failure_class       unreachable
failure_code        destination_not_found
answered_at         NULL
terminated_at       2026-08-30 06:55:35+00
```

## Write-once authority preserved

The audit trail contains exactly one terminal record per aggregate, and each
already carries the failed state — there is no `completed` record that was later
rewritten:

```text
call_leg.terminated audits  1   {"party":"remote","reason":"remote","state":"failed"}
call_leg.failed audits      0
call.terminated audits      1   {"party":"remote","reason":"remote","state":"failed"}
```

The generic Local pre-answer terminalization did **not** win authority: every
Local observation carried `defer_pre_answer_terminalization`, and the terminal
write occurred at the provider observation's processing instant (06:55:35).
Canonical terminal metadata remained write-once.

## Layer separation preserved

The provider observation still carries `cause`, `cause_txt`, `tech_cause`, and
`runtime_channel_id` unchanged. Taxonomy did not consume, delete, or replace the
raw provider facts:

```text
Layer 2  cause / cause_txt / tech_cause / provider runtime_channel_id
Layer 3  failure_class / failure_code
```

## Negative criteria

```text
origination-timeout interference        NO   (terminal at 06:55:35, ~46 s inside the 60 s deadline)
runtime_lost incorrectly applied        NO   (orderly ChannelDestroyed exists; ADR-030 §7 excludes loss)
canonical runtime channel rebound       NO
new taxonomy mappings added             NONE (only the adopted 404 row)
production source changed               NO
```

Managed Asterisk reported `0 active channels`, `0 active calls`,
`1 call processed`. Non-terminal Calls `0`, CallLegs `0`. Trunk and RuntimeNode
remained `active`/`ready`.

## Preserved precedence

Unchanged and not reinterpreted by this proof: answered calls remain
`completed` with NULL taxonomy; qualifying UTCP termination intent remains
`completed / requested / control_plane` with NULL taxonomy; origination timeout
remains `failed / origination_timeout / system`; runtime loss remains a separate
authority.

## Gap E closure

```text
Gap E: CLOSED
```

Closure rests on the adopted minimum deterministic contract — provider
`tech_cause 404` with `answered_at NULL` maps to
`failed / remote / remote / unreachable / destination_not_found`. Codes such as
`486`, `480`, `408`, `500`, `502`, `503`, `600`, `603`, and `403` are explicitly
outside the adopted row and were neither implemented nor proven here.

## Recorded separate debt

`scripts/native-k3s/image-sync` `.git` origin parsing, and the broad `Quality` CI
failures observed on recent commits, both remain unchanged separate debt. Neither
is a Gap E blocker.

## Boundary

Gap A, B, C, D, and E are closed. Gap F remains `PROOF_GAP_ONLY`. ADR-031
stable-public-edge acceptance remains `DEFERRED_BY_ENVIRONMENT`, not abandoned.
No K5 or RMA work was started. ADR-030 semantics were not changed. No production
source was modified by this packet.
