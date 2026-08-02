# T3-S2C FreeSWITCH Runtime Parity Adapter

## Status

Repository implementation is complete and awaiting focused live parity proof. Starting commit: `9ca15fa`. `UTCP_PHASE=T1`. No Kubernetes resource was applied and no live media proof was run.

## Bounded adapter

FreeSWITCH is added as a minimal application-runtime adapter behind the existing selected-runtime Service. The local-only overlay selects exactly one FreeSWITCH workload and scales the Asterisk deployment to zero; the default local overlay continues to select the existing Asterisk runtime. No second selector, environment gate, manual activation command, dual delivery, or fallback to Asterisk was added.

The image is pinned to `docker.io/safarov/freeswitch:1.10.12@sha256:b31c743f4c911a19687c61e3214968f2a24f93f9d3d667cc26284192e158ffc6`. The workload runs as UID/GID `1000`, has a read-only root filesystem, and exposes only the internal ClusterIP SIP Service. The minimal configuration loads the required SIP, XML dialplan, and application modules, renders PCMU, and provides extension `9900` with Answer, Echo, and Hangup.

The adapter uses RTP UDP `21000-21099`, which does not overlap Asterisk `10000-20000` or rtpengine `40000-40099`. The four reciprocal FreeSWITCH media rules use destination-port semantics: rtpengine-to-FreeSWITCH targets `21000-21099`, while FreeSWITCH-to-rtpengine targets `40000-40099`. SIP rules are exact UDP `5060` peers between Kamailio and FreeSWITCH. Namespace and Pod selectors are combined; there is no `ipBlock`, Pod-CIDR, namespace-wide media access, all-UDP rule, direct prover access, or public SIP/media exposure.

## Generic contracts

Kamailio now relays the initial authenticated application request to `application-runtime-sip.utcp-runtime.svc.cluster.local:5060` through the existing `APPLICATION_DIALOG`, `MEDIA_OFFER`, `MEDIA_ANSWER`, `MEDIA_DELETE`, and `APPLICATION_RUNTIME_MEDIA_REPLY` routes. REGISTER, WebSocket alias handling, and WITHINDLG remain unchanged. The selected Service has no Asterisk fallback: when the FreeSWITCH overlay is selected, it has only the canonical FreeSWITCH selector and the Asterisk deployment is disabled in that overlay. The provider-neutral prover is unchanged.

If the selected runtime has no ready SIP endpoint, the existing application-runtime unavailable response and offer cleanup contract apply. No FreeSWITCH-specific control or ESL authority was added; ESL is not exposed or required for normal routing.

## Validation and coverage

`scripts/freeswitch/config-check` validates the immutable image pin, workload identity and security context, ClusterIP Services, explicit RTP range, Echo fixture, required module set, selected-runtime projection, exact SIP and RTP policies, generic Kamailio routes, default-deny containment, and absence of public or provider-specific authority. `scripts/freeswitch/config-check-test` covers mutable images, missing listener, identity and fixture failures, RTP range drift and overlap, and missing Echo steps. Existing Kamailio, media, security, Kubernetes, and prover checks remain active. No production Asterisk behavior or generic media route was duplicated or replaced.

## PRODUCT_DEFECT-16

`PRODUCT_DEFECT-16` remains historical and open for this repository-only slice. It was not reproduced across two clean committed Asterisk scenarios; no rtpengine workaround or teardown change was introduced. The next live proof must compare rtpengine Pod UID and restart count before and after each clean FreeSWITCH scenario.

## Remaining proof

The focused live proof must select FreeSWITCH through the canonical runtime projection and run the unchanged committed Scenario A and Scenario B prover. It must prove SIP dialog parity, SDP offer/answer mediation, ICE, DTLS, reciprocal RTP and Echo, positive inbound audio energy, both BYE cleanup paths, selected-runtime unavailable behavior, and zero fallback to Asterisk. External browser media exposure remains T3-S3.
