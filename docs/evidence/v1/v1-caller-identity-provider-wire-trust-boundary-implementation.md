# V1 — CallerIdentity Provider-Wire Trust-Boundary Implementation

Current-State-Impact: yes

## Bounded implementation

Starting at `4bf7ab13dcfe24a8da34ffdd4aa6a84cedb6eb65` (`docs(v1): close
provider wire trust gap`), the Gap F live proof had established that
`X-UTCP-Caller-Identity-ID` was present on the trusted Asterisk-to-Kamailio
hop but was also forwarded on provider-facing INVITEs. The other three
correlation headers were already stripped at the canonical provider route.

The CallerIdentity UUID is tenant-scoped internal UTCP execution metadata. It
is not provider-facing caller identity, which continues to use the existing
normal SIP identity/address behavior.

The bounded repair adds `remove_hf("X-UTCP-Caller-Identity-ID")` to
`route[RUNTIME_EXTERNAL_TRUNK]`, alongside the existing three removals and
before `t_relay()`. The internal Asterisk predial header application and
provider authentication flow are unchanged. This single provider-boundary
operation naturally covers the initial external INVITE and its authenticated
retry.

## Regression proof

`scripts/kamailio-signaling/config-check-test` now requires the complete
four-header stripping contract in `route[RUNTIME_EXTERNAL_TRUNK]`, in order,
before relay. It continues to cover the existing external-ingress stripping
contract independently. The assertion is structural and fails against the
previous three-header route.

## Deliberate scope preservation

No schema, Asterisk, dialplan, ARI, Kamailio routing authority, authentication,
caller-number semantics, external-PBX, feature-gate, fallback, or manual-mode
change was made. No live provider-wire proof was performed and no capture
wrapper or authorization was removed. Gap F remains closed; this separate
CallerIdentity item is implemented and tested, pending controlled native-k3s
natural re-proof.

## Validation

The focused Kamailio configuration regression, native server configuration
checks, repository hygiene, phase-status consistency check, and `git diff
--check` were run for this packet. Results are recorded in the implementation
handoff.
