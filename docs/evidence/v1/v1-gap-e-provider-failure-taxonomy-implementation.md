# V1 Gap E — Minimal Provider-Failure Taxonomy Implementation

Current-State-Impact: yes

Date: 2026-08-30

## Result

The live-proven provider-failure authority and provider-channel to CallLeg
correlation are now consumed by the canonical observation processor. The
bounded adopted mapping is:

```text
correlated Asterisk ChannelDestroyed
tech_cause = 404
answered_at is NULL
  -> Failed / remote / remote
  -> failure_class = unreachable
  -> failure_code = destination_not_found
```

No other SIP/Q.850 or FreeSWITCH mapping was adopted.

## Authority and implementation

`CallObservationProcessor` recognizes the correlated provider terminal fact by
the canonical CallLeg subject and `ChannelDestroyed` raw facts. It delegates
the atomic terminal write to `CallDomainService`, which enforces tenant,
runtime-node, row-lock, terminal write-once, requested-intent, answered-call,
and origination-timeout authority. The provider PJSIP channel remains only in
observation evidence; the canonical Local `;1` runtime channel is not rebound.

Generic pre-answer Asterisk Local termination is deferred so a generic Local
fact cannot win over a correlated provider failure. Provider-first and
Local-first permutations converge to the same canonical result. Raw
`cause`, `cause_txt`, `tech_cause`, and provider `runtime_channel_id` remain in
`runtime_observations`.

Answered calls remain Completed with null failure metadata. Requested
termination and origination timeout remain authoritative, runtime loss stays
separate, and unmapped correlated provider failure remains Failed with null
taxonomy rather than an invented code. Call and CallLeg failure metadata are
projected together by the existing domain transaction.

## Proof

Focused processor regressions cover the adopted 404 result, provider-first and
Local-first multi-channel ordering, answered and requested precedence,
timeout-first behavior, duplicate observations, unmapped failure behavior,
raw-fact retention, and canonical runtime-channel preservation. Existing
domain, Asterisk, FreeSWITCH, lifecycle, and full API regressions remain
passing. Repository/static gates also pass.

No schema, Asterisk configuration, dialplan, Kamailio, provider, feature gate,
manual control, or live deployment was added. No live taxonomy re-proof was
performed in this repository packet; it remains the next controlled action.
