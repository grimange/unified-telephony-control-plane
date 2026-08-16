# V0-C4 Asterisk Conference Entry Pattern Fix

## Status

Implemented and repository-tested. This bounded fix changes only the canonical
`[from-kamailio]` Asterisk conference-entry pattern. Kamailio routing,
conference admission, RuntimeNode provisioning, and the C4 application/ARI
authority are unchanged.

## Defect and correction

The former `_conf-.` extension was evaluated by Asterisk as `_CONF-.`.
Asterisk treats pattern letters case-insensitively and interprets `N` as the
digit class `[2-9]`, so the rule did not match literal `conf-<participantId>`
destinations. They fell through to the fail-closed `_.` extension and received
`Hangup(21)`.

The corrected extension is:

```asterisk
exten => _[c]o[n]f-.,1,NoOp(UTCP canonical conference admission destination=${EXTEN})
```

The bracketed single-character classes represent literal `c` and `n`, while
`.` preserves the canonical `conf-` namespace suffix. The existing entry still
executes `Answer()`, `Stasis(utcp-t0-observation,${EXTEN})`, and `Hangup()`.

## Resolver evidence

`make asterisk-conference-dialplan-check` runs the actual Asterisk resolver in
the repository Asterisk image and proves:

```text
conf-<valid UUID>  -> _[c]o[n]f-. -> existing Stasis entry
9900               -> existing T3 Echo route
arbitrary-invalid  -> _. -> Hangup(21)
```

No browser or natural conference proof was performed. The next step is the
narrow V0-C6 live reproof from the Asterisk entry onward.

## V0 sequence

V0-C5 is complete. The V0-C6 live proof was blocked at this Asterisk pattern;
the bounded fix is now implemented and repository-tested. V0 remains in
progress pending narrow natural reproof. RT-1 remains planned and unimplemented.
