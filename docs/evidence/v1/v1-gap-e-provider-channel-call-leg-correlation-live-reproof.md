# V1 Gap E — Provider-Channel to CallLeg Correlation Natural Live Re-Proof

Current-State-Impact: yes

Date: 2026-08-30

Exact HEAD: `acca1935fd87ff1a148cbe5ac024fe19e0a5a5f1`
(`fix(v1): correlate provider channel to call leg`)

## Verdict

`V1_GAP_E_PROVIDER_CHANNEL_CALL_LEG_CORRELATION_LIVE_PROVEN`

The provider-facing PJSIP `ChannelDestroyed` now normalizes with
`subject_id` equal to the canonical CallLeg ID, while the provider PJSIP
uniqueid is preserved as raw evidence and the CallLeg's canonical Local `;1`
runtime channel is left unchanged. The uncorrelated `runtime:<uniqueid>`
fallback was not used.

Combined with the already-proven provider fact authority, Gap E's remaining
problem is now solely the canonical failure taxonomy.

## Deployment

The exact correlation-repair commit was promoted and deployed through the
canonical lifecycle only.

```text
Native k3s Images (head_sha acca1935fd…)   completed / success
image-lock artifact 9727409192             present, not expired
```

```text
UTCP_SERVER_SOURCE_COMMIT  acca1935fd87ff1a148cbe5ac024fe19e0a5a5f1
UTCP_SERVER_IMAGE_TAG      sha-acca1935fd87ff1a148cbe5ac024fe19e0a5a5f1
UTCP_SERVER_API_IMAGE      ghcr.io/grimange/utcp-api@sha256:ad84e63e…3338
UTCP_SERVER_ASTERISK_IMAGE ghcr.io/grimange/utcp-asterisk@sha256:b8c1730e…cc26
```

Promotion used the supported explicit repository input
(`GH_REPO=grimange/unified-telephony-control-plane`) because the recorded
`scripts/native-k3s/image-sync` `.git` origin-parsing defect remains open and was
deliberately not repaired here. `server-config-check` and
`server-image-preflight` passed; `server-apply` completed on the first run.

Platform rollout:

```text
api          api-5c6784bcf6-bnr8v            -> api-7d586dc886-bdfl5
ari-events   asterisk-ari-events-76bc544d46-x5dgc -> asterisk-ari-events-9868b9fb4-rjkh8
running digest = promoted digest (sha256:ad84e63e…3338)
```

### Managed runtime convergence

The Asterisk image digest also changed, because the generated `ari.conf` lives in
the Asterisk image entrypoint. `server-apply` does not itself re-image an
RNP-provisioned managed RuntimeNode: immediately after apply, RuntimeNode
`102d58ba-…` still had `desired_execution_image` pinned to the previous Asterisk
digest and its Pod still generated an `ari.conf` **without** `channelvars`.

No manual intervention was applied. `AsteriskRuntimeNodeReconciler` resolves the
managed image from `UTCP_MANAGED_ASTERISK_IMAGE`, which the newly deployed
workers already carried as `sha256:b8c1730e…cc26`, and converged the node
automatically through `ensureManagedExecutionImage()` and the normal
`runtime.node.workload.converge` operation:

```text
managed Pod  …-55ffbgblm7 (sha256:1a3007d4…3ee8)  ->  …-f857f9jsbz (sha256:b8c1730e…cc26)
```

This is recorded because the correlation gate depends on it: the proof would have
produced a false negative had it been run in the window before convergence.

## Effective `ari.conf` — the correlation gate

Managed V1-A runtime `asterisk-v1a-outbound-reproof-asterisk-1787-5fced085-f857f9jsbz`,
`/tmp/utcp-asterisk/ari.conf`:

```ini
[general]
enabled = yes
pretty = no
allowed_origins =
channelvars = UTCP_CALL_LEG_ID
```

Broad exposure check: occurrences of `channelvars = *` — **0**. Only the single
canonical variable is projected.

## Live correlation code

Verified read-only inside the running ARI worker
(`asterisk-ari-events-9868b9fb4-rjkh8`, digest `sha256:ad84e63e…3338`):

```text
AsteriskAriClient.php:1086   'channelvars' => $this->sanitizeAriChannelvars(...)
AsteriskAriClient.php:1098   sanitizeAriChannelvars() accepts only a string
                             channelvars['UTCP_CALL_LEG_ID'], trims and lowercases it
AsteriskAriEventListener.php:233-237  reduces it to $payload['call_leg_id']
AsteriskAriEventNormalizer.php:177    explicit call_leg_id lookup after the
                                      direct runtime_channel_id match misses
```

## Baseline

All `utcp-platform` Pods Ready. Trunk `3a9bf028-…` `active` / `ready`;
registration `registered` (last success 06:12:25); RuntimeNode `102d58ba-…`
`active` / `ready`; non-terminal Calls `0`, CallLegs `0`.

The existing canonical 97002 routing objects were reused, not recreated:
TelephonyAddress `7927dda1-…` (`sip:97002@38.146.161.46`, active), outbound route
`005b1270-…` (`gape-97002-failure`, active), and
`kamailio_external_trunk_route_view` carrying both `97002` and `97001`.

The operator-proven fixture remains authoritative: `utcp-v1-in` contains only
`97001`, so `97002` is absent and the expected pre-answer result is
`SIP 404 Not Found`.

## The proof Call

Exactly one canonical outbound Call, `runtime_node_id` omitted.

| Fact | Value |
| --- | --- |
| Call | `15a4ab6b-32b2-4a25-a02a-03ba913bc187` |
| CallLeg | `4b89b7c1-75d9-47b3-a79f-7aa20c40d868` |
| RouteDecision | `67c11e9f-5488-4f3d-91d6-69929daf0d8a` |
| Originate RuntimeOperation | `b7e1a532a6a962cc750b4d5f859fa284` (succeeded) |
| RuntimeNode (auto-selected) | `102d58ba-93ec-4601-a2a3-81f95801440f` |
| CallLeg.runtime_channel_id | `utcp-call-leg-4b89b7c1-75d9-47b3-a79f-7aa20c40d868` |
| ExternalTrunk / TrunkEndpoint | `3a9bf028-…` / `ad7a95f4-…` |
| CallerIdentity | `f11a46e5-…` |
| Provider SIP Call-ID | `2699dc37-d36e-43b…` |

Kamailio recorded `INVITE sip:97002@…` at 06:12:36.163 and `ACK` at 06:12:36.583
— the ACK to a non-2xx final response. No `401`/`407` challenge on this
transaction.

```text
expected provider result: 404 Not Found
actual provider result:   404 Not Found     (tech_cause 404, Q.850 cause 1)
expected vs actual:       MATCH
```

## Asterisk topology and raw termination facts

All three channels destroyed at 06:12:36.583.

| Role | Channel ID | Channel name | cause | cause_txt | tech_cause |
| --- | --- | --- | --- | --- | --- |
| provider PJSIP | `1788070356.2` | `PJSIP_kamailio-edge-00000000` | `1` | `Unallocated (unassigned) number` | **`404`** |
| Local `;2` | `utcp-call-leg-4b89b7c1-…;2` | `Local_97002_utcp-outbound-00000000_2` | `1` | `Unallocated (unassigned) number` | ABSENT |
| Local `;1` | `utcp-call-leg-4b89b7c1-…` | `Local_97002_utcp-outbound-00000000_1` | `1` | `Unallocated (unassigned) number` | ABSENT |

Identical to the previous proof: the Q.850 cause propagates to all three
channels, the SIP-specific `tech_cause` exists only on the provider-facing leg.
Local channels remain non-authoritative for provider SIP detail (ADR-030 §11).

## Correlation identity chain — the central criterion

Each value observed independently:

```text
fresh CallLeg ID                          4b89b7c1-75d9-47b3-a79f-7aa20c40d868
provider receipt payload.call_leg_id      4b89b7c1-75d9-47b3-a79f-7aa20c40d868
provider observation subject_id           4b89b7c1-75d9-47b3-a79f-7aa20c40d868
provider observation subject_type         call_leg
```

All equal. The receipt's `call_leg_id` is the reduced form of the provider
channel's ARI `channelvars.UTCP_CALL_LEG_ID`; the listener stores only that
reduction, so the ARI-side value is evidenced through it rather than persisted
separately.

Provider receipt `442e89140a1a3bd440886fc4eef6a75e`:

```json
{"ari_event_type":"ChannelDestroyed","call_leg_id":"4b89b7c1-75d9-47b3-a79f-7aa20c40d868",
 "cause":1,"cause_txt":"Unallocated (unassigned) number","channel_id":"1788070356.2",
 "channel_name":"PJSIP_kamailio-edge-00000000","channel_state":"Down",
 "remote_identity":"97002","tech_cause":404}
```

Provider observation `16f0f3f2a4618b49dcb62bed2ba47364`:

```json
{"ari_event_type":"ChannelDestroyed","cause":1,
 "cause_txt":"Unallocated (unassigned) number","remote_identity":"97002",
 "runtime_channel_id":"1788070356.2","tech_cause":404}
```

### Roles remain distinct

```text
observation.subject_id              4b89b7c1-…            canonical CallLeg (subject)
observation.payload.runtime_channel_id  1788070356.2      provider PJSIP uniqueid (fact source)
CallLeg.runtime_channel_id         utcp-call-leg-4b89b7c1-…  Local ;1 (unchanged)
```

The provider event did **not** rebind the CallLeg's canonical runtime channel.
Uncorrelated `runtime:%` subjects in the proof window: **0** — the fallback was
not used for any of the three channels.

## Downstream persistence

All three receipts and all three observations retained their raw facts; the
provider observation retained `cause`, `cause_txt`, and `tech_cause 404`. No
downstream preservation defect.

## Canonical outcome — unchanged, taxonomy still pending

```text
Call    15a4ab6b-…   completed / remote / remote
CallLeg 4b89b7c1-…   completed / remote / remote
answered_at    NULL
terminated_at  2026-08-30 06:12:51+00
failure_class  NULL
failure_code   NULL
```

`answered_at` is NULL as required for a pre-answer failure. `failure_class` and
`failure_code` remain NULL because Gap E taxonomy is deliberately not implemented
here. Origination timeout did not interfere — the failure settled about 42 s
inside the 60 s deadline. `runtime_lost` was correctly not applied: an orderly
`ChannelDestroyed` exists, so ADR-030 §7 excludes runtime loss.

The canonical result remaining `completed` rather than `failed` for a pre-answer
provider rejection is the next Gap E taxonomy question, not a defect of this
correlation proof.

## Cleanup and regression

Managed Asterisk reported `0 active channels`, `0 active calls`,
`1 call processed`. Non-terminal Calls `0`, CallLegs `0`. Trunk `active`/`ready`,
RuntimeNode `active`/`ready`. No dialplan, `ari.conf`, Kamailio, provider, or
schema change was made by this packet, and no taxonomy was implemented.

## Next bounded target

```text
pre-answer provider final failure
+ provider-facing correlated termination fact
+ answered_at NULL
-> canonical failed state with resolved failure_class / failure_code
```

preserving answered calls as `completed`, explicit UTCP cancellation as
`requested / control_plane`, origination timeout as
`origination_timeout / system`, runtime loss as `runtime_lost / runtime`, and
unknown provider outcomes as raw facts without invented classification.

## Recorded separate debt

`scripts/native-k3s/image-sync` still retains the `.git` suffix when deriving the
GitHub repository from a default clone origin. Unchanged here; the supported
`GH_REPO` input was used.

## Boundary

Gap A, B, C, and D remain closed. Gap E remains open with provider failure
authority and provider-channel correlation both live-proven and only the
canonical taxonomy pending. Gap F remains `PROOF_GAP_ONLY`. ADR-031
stable-public-edge acceptance remains `DEFERRED_BY_ENVIRONMENT`, not abandoned.
No K5 or RMA work was started. No production source was modified.
