# T3-S2C FreeSWITCH Invite Authentication Correction

Date: 2026-08-02

Starting commit: `5fdce35` (`docs(t3): reconcile local baseline and record freeswitch 407`)

Phase marker: `UTCP_PHASE=T1`

Verdict: `T3_S2C_FREESWITCH_INVITE_AUTH_CORRECTION_COMPLETE`

## PRODUCT_DEFECT-22

The committed FreeSWITCH parity runtime was Ready and reachable on UDP 5060,
but a proxy-relayed authenticated browser INVITE received `407 Proxy
Authentication Required`. The established browser authentication route verified
the request but left the digest credential header on the request sent to the
selected application runtime. FreeSWITCH 1.10.12 therefore entered its own
digest challenge path even though the trusted internal profile retains
`auth-calls=false`.

## Correction

`route[APPLICATION_DIALOG]` now calls Kamailio's `consume_credentials()` once,
after successful `www_authorize()` and the `$au`/`$fU` identity check, and before
WebSocket alias preparation, media offer handling, Record-Route, and generic
application-runtime relay. Failed authentication still exits before the call.
REGISTER remains on its existing authentication and `save("location", "0x04")`
lifecycle and does not call `consume_credentials()`.

No FreeSWITCH-specific authentication route, retry, downstream credential,
blind-auth option, or Pod-IP ACL was added. FreeSWITCH keeps `auth-calls=false`
and remains a trusted internal application peer selected through the existing
NetworkPolicy and runtime Service boundaries.

The `utcp-internal` profile now uses `alias="false" parse="false"`, matching an
application peer rather than a registered-endpoint domain. Context `utcp`, XML
dialplan, UDP 5060, PCMU, and extension `9900` remain unchanged.

## Image-Level INVITE Proof

The disposable startup smoke test now sends one plain SIP INVITE with a PCMU
SDP offer and no `Authorization` or `Proxy-Authorization` header. It requires a
`200 OK` with an SDP answer, sends ACK using the response Contact, observes the
9900/Echo channel, sends BYE, requires `200 OK`, and waits for the channel count
to return to zero. It rejects `401`, `407`, and `403` responses and does not
accept Sofia `RUNNING` alone as call proof.

Observed in the repository image smoke run: FreeSWITCH loaded the required
modules, `utcp-internal` was `RUNNING` on UDP 5060, the plain INVITE smoke
completed, and cleanup returned the active channel count to zero. No
Kubernetes resource was applied and no browser Scenario A or Scenario B was
run.

## Validation

Static and mutation coverage now protects credential consumption ordering,
REGISTER isolation, downstream/blind-auth prohibitions, application-peer
domain semantics, real INVITE/SDP/200 response requirements, ACK/BYE behavior,
9900/Echo reachability, and zero residual channels. The image build and
startup smoke use the pinned FreeSWITCH 1.10.12 image. PRODUCT_DEFECT-17
through PRODUCT_DEFECT-21 remain closed; PRODUCT_DEFECT-22 is corrected.

## Remaining Proof

FreeSWITCH live parity remains pending. The next proof must use the reconciled
canonical local baseline and the committed parity overlay, then run the
unchanged Scenario A and Scenario B prover without scratchpad changes.
