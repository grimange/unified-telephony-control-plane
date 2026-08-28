# V1-A CallerIdentity provider projection

The provider-facing `From` identity defect is bounded to the execution
contract. C7B selects `caller_identity_id`, but the pre-repair
`call.leg.originate` payload did not include the selected identity's resolved
canonical address. The Asterisk adapter therefore had no business-neutral
value from which to project ARI caller identity and the provider observed
`anonymous`.

The repair resolves the active, tenant-owned CallerIdentity and active linked
TelephonyAddress in `C7bService` while building the normalized originate
operation. The operation carries both `caller_identity_id` and
`caller_identity_address`. `AsteriskAriClient` remains the transport owner and
derives the Asterisk representation from a canonical SIP URI. For
`sip:utcp-v1@synthetic.example`, it sends `callerId=utcp-v1 <utcp-v1>` so the
actual PJSIP `From` URI user is `utcp-v1`, rather than `anonymous` or the
Asterisk default.

The existing `formats=ulaw`, JSON correlation variables, Kamailio Request-URI,
dialog CSeq tracking, HA1 credentials, and provider authentication ownership
are unchanged. Focused API tests cover C7B payload resolution, ARI query/body
contracts, and fail-closed missing identity behavior. The exact disposable
UTCP Asterisk image plus the synthetic SIP peer also verifies the emitted SIP
`From` user. The runtime semantic harness passed three consecutive runs against
the exact local `utcp-asterisk-ari:dev` image and reported
`from_user=utcp-v1` for each run. Provider authentication, trust-boundary
header capture, and canonical post-provider observation remain live-proof gaps.
