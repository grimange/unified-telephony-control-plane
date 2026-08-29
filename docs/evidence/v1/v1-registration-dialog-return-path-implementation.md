# V1 Registration Dialog Return Path Implementation

Status: repository implementation complete; controlled live proof pending.

## Contract

The provider-facing in-dialog return path preserves `loose_route()` as its
first authority. Only after that route-set lookup fails can a BYE received on
the provider socket use the existing outbound external-trunk projection as a
provider-source trust predicate. The fallback additionally requires a To-tag
and a known Kamailio dialog. `dlg_set_ruri()` then selects the caller leg,
which is the managed runtime Contact for a provider-originated request.

## Implementation

The bounded change is in `route[WITHINDLG]` in the Kamailio ConfigMap. A
successful fallback performs the existing `MEDIA_DELETE` operation and relays
the BYE. Unknown dialogs, missing tags, untrusted ingress, source mismatches,
trust-query failures, and dialog-target failures fall through to the existing
fail-closed behavior. No application Call/CallLeg lookup, correlation header,
Contact rewriting, dialog-mode change, or new mode/feature gate was added.

ADR-031 remains unchanged: stable public-edge dialogs continue through
`loose_route()`, while the registration/NAT path handles only the demonstrated
no-Route provider BYE case. Double Record-Route behavior and the private
runtime Contact remain untouched.

## Proof boundary

Focused source-contract and mutation regressions cover precedence, BYE-only
scope, provider ingress/source predicates, known-dialog targeting, runtime-leg
direction, media teardown, and fail-closed behavior. Existing parser-backed
Kamailio signaling checks remain required. A real registration/NAT provider
proof is deferred until deployment values and the canonical live environment
are available; it is not claimed here.

Remaining live status: `ADR-031 stable-edge live proof: DEFERRED_BY_ENVIRONMENT
NOT_ABANDONED`.
