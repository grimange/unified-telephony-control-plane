# V1-A duplicate provider origination repair

The live V1-A corridor showed two provider-bound INVITEs for one
`call.leg.originate`: the first carried the selected CallerIdentity and was
answered, while a second Local-channel execution entered `utcp-outbound`,
used the destination as caller identity, and destabilized the answered leg.

The cause was an overlapping ARI originate contract. `endpoint=Local/...@
utcp-outbound` already owns entry to the canonical outbound dialplan, while
the same request also supplied `context`, `extension`, and `priority`. The
adapter now keeps the Local endpoint as the dialing authority and supplies
only the ARI `extension` value required by the Asterisk request contract;
`context` and `priority` are absent. The `utcp-outbound` Dial() and predial
correlation seam are unchanged.

The exact managed Asterisk semantic harness uses the baked-in UTCP image, a
disposable Docker network, and a synthetic SIP peer. It proves one originate
produces one provider INVITE, the From user is `utcp-v1`, and the peer's
`200 OK` does not trigger duplicate-leg teardown. The repaired fixture was
run in three consecutive successful executions with one INVITE each.

Preserved contracts include `formats=ulaw`, absent `originator`, the four
JSON correlation variables, CallerIdentity projection, Kamailio routing and
authentication, and CSeq tracking. Canonical answer observation remains a
separate live proof gap.
