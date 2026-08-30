# V1 Registration/NAT BYE Dialog-Termination Correction

Current-State-Impact: yes

Date: 2026-08-30

## Classification

The Kamailio registration/NAT fallback-BYE dialog-cleanup correction is
implemented and repository-tested. Gap B remains open pending controlled live
re-proof; no deployment or V1 Call was performed in this packet.

## Defect boundary

The preceding controlled proof established that a provider BYE arriving on the
registration/NAT socket, without a Route header, passed the trusted active
dialog predicates, was retargeted by `dlg_set_ruri()` to managed Asterisk,
relayed successfully, and terminalized the canonical CallLeg as
`completed / remote / remote`. Kamailio nevertheless left the matched dialog
active until `dlg_ontimeout()` because the fallback relay did not pass the BYE
through the dialog module's sequential-request lifecycle.

## Correction

Only the already-authorized fallback branch in `route[WITHINDLG]` now calls
`dlg_manage()` after the existing provider socket, BYE, `has_totag()`,
`is_known_dlg()`, and provider-source trust predicates have passed. The order
is:

```text
loose_route() -> false
provider socket + BYE + has_totag() + is_known_dlg()
provider-source projection trust match
dlg_set_ruri()
dlg_manage()
MEDIA_DELETE
t_relay()
```

`dlg_set_ruri()` remains first so the matched dialog's managed-runtime target
is recovered before sequential dialog handling. `dlg_manage()` is the normal
Kamailio 6.0.7 request-route dialog lifecycle entrypoint; for an in-dialog
request it handles sequential processing and permits SIP-element matching. A
terminating BYE is therefore consumed as `DLG_EVENT_REQBYE`, allowing normal
transition toward `DLG_STATE_DELETED`, timer removal, and callbacks while the
same request continues to `t_relay()`.

The change does not alter global `dlg_match_mode`: the Kamailio 6.0.7
sequential-request path provides the required matching behavior locally. It
does not use `dlg_set_state()`, RPC/manual deletion, timeout tuning, Contact
rewriting, application dialog lookup, or provider-facing Record-Route in
registration/NAT mode.

## Preserved boundaries

`loose_route()` remains first authority. The fallback remains BYE-only and
requires provider ingress `$Rp == 5060`, `has_totag()`, `is_known_dlg()`, and an
exact active outbound provider-source projection match on `$si`. `MEDIA_DELETE`
still precedes `t_relay()`. Stable-edge Route/`loose_route()` behavior,
registration/NAT absence of provider Record-Route, runtime socket selection,
authentication/CSeq tracking, CallerIdentity, canonical termination, and
RuntimeNode selection are unchanged.

## Repository proof

The focused registration-dialog regression verifies the exact route ordering,
trust-before-`dlg_manage` negative case, and rejection of unconditional
`dlg_manage` broadening. The existing signaling and native provider-facing
configuration tests verify stable-edge and registration/NAT rendering,
Kamailio 6.0.7 parser acceptance, socket and Record-Route behavior, and the
unchanged runtime/provider boundaries. The existing CSeq regression remains
the proof of the authenticated retry delta `4242 -> 4243`.

Controlled live re-proof is intentionally pending and belongs to the next
`CONTROLLED_LIVE_PROOF` packet. The observed state-3 timeout timing is not
reopened or tuned here.
