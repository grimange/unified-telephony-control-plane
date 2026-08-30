# V1 Gap E — Asterisk ARI Termination Fact Preservation

Current-State-Impact: yes

Date: 2026-08-30

## Verdict

`RAW_FACT_PRESERVATION_IMPLEMENTED_AND_TESTED`

The Asterisk ARI client now preserves the typed `ChannelDestroyed` Layer-2
facts that ADR-030 §9 already requires the listener and normalizer to retain.
The repair is intentionally limited to the existing sanitizer allow-list; it
does not assign canonical termination or provider-failure meaning.

## Exact defect and bounded repair

`AsteriskAriClient::sanitizeAriEvent()` rebuilt every event from a closed
allow-list that omitted `cause`, `cause_txt`, and `tech_cause`. The listener's
existing preservation block was therefore unreachable for raw ARI WebSocket
events.

The sanitizer now adds only these fields for `ChannelDestroyed`:

```text
cause       valid integer only
cause_txt   bounded string, at most 120 characters
tech_cause  valid integer only, optional
```

Absent or invalid fields are not synthesized. Non-`ChannelDestroyed` events do
not gain these fields, and the existing bounded known-field payload design is
unchanged.

The historical normalizer fixture that used `tech_cause = "SIP 200"` was
corrected to the official integer contract. The test asserts transport
preservation only; it does not infer SIP or Q.850 meaning.

## Proof

The client-level regression sends raw JSON through a WebSocket text frame and
calls the externally reachable `readEvent()` path, which invokes the
sanitizer. It covers valid typed fields, absent optional `tech_cause`, invalid
types and bounded `cause_txt`, and unrelated `ChannelStateChange` events.

The existing listener and normalizer tests retain the facts as receipt and
normalized observation payload fields, with no synthesized canonical
`termination_reason`. FreeSWITCH preservation and ADR-030 canonical
termination semantics remain unchanged.

The focused Asterisk, FreeSWITCH, and domain regression command passed with
`142 passed (719 assertions)`. The full containerized API suite passed with
`634 passed, 9 skipped, 5213 assertions`. The phase-status consistency check,
repository hygiene check, and `git diff --check` also passed.

## Explicit boundaries

No `failure_class` or `failure_code` writer, SIP/Q.850 taxonomy, provider
channel correlation, dialplan or `ari.conf` change, Kamailio path, runtime
cancellation, orphan-channel cleanup, feature gate, manual control, browser
proof, or live environment mutation was added. Controlled provider-failure
fact-binding proof remains pending and is the next action; taxonomy follows
that proof.
