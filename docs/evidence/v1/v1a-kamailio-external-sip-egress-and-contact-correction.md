# V1-A Kamailio External SIP Egress and Contact Correction

## Scope

This bounded repair gives the canonical Kamailio SIP edge the outbound
capability required by V1-A registration-based ExternalTrunks and corrects the
installed Kamailio UAC contact-address syntax. It does not activate the V1-B
static external SIP publication, use external credentials, or modify the
independent external PBX.

## Repository changes

- Extended `allow-kamailio-signaling-required-traffic` with exactly one
  destination-agnostic UDP/5060 egress rule selected by the Kamailio signaling
  workload label.
- Preserved DNS, PostgreSQL, rtpengine, Asterisk, and FreeSWITCH egress rules.
- Preserved the V1-B `38.146.161.46/32` UDP/5060 ingress rule unchanged.
- Changed `uac.reg_contact_addr` from a `sip:`-prefixed value to the
  scheme-free Kamailio Service host and port required by Kamailio UAC.
- Updated the deployment configuration checksum from the rendered ConfigMap.
- Extended the existing Kamailio and security config mutation checks to reject
  unrelated destination-agnostic ports, provider IP blocks in egress, missing
  external SIP capability, and scheme-prefixed `reg_contact_addr` values.

The egress rule is a Kubernetes capability boundary, not provider-management
state. C7A/T6 still select the provider and Kamailio still executes the
registration.

## Verification

Repository checks passed:

- `scripts/kamailio-signaling/config-check-test`
- `make security-config-check`
- `make kamailio-signaling-config-check`
- `make k8s-config-check`
- `make repository-hygiene`
- `make secret-scan`
- `git diff --check`

The canonical `make security-apply` applied the NetworkPolicy and platform
configuration and rolled Kamailio successfully. The command later stopped at
the existing Gateway lifecycle step because `helm` is unavailable in the
environment; no alternate apply path was used.

Live Kamailio evidence:

- Kamailio Pod: `1/1 Running`.
- `kamailio-registration-observer`: ready replicas running.
- `kamailio-registration-control`: internal `ClusterIP`, TCP/8090 only.
- Rendered and live `reg_contact_addr` is
  `kamailio-sip-internal.utcp-platform.svc.cluster.local:5060`.
- The V1-B `kamailio-sip-external` NodePort object remains preserved but its
  host/server-load-balancer publication was not activated.

An unauthenticated UDP OPTIONS and REGISTER probe from the actual Kamailio Pod
to `38.146.161.46:5060` still timed out after the capability was applied. No
credential was read or used. Therefore anonymous external reachability remains
a live runtime gap despite the repository policy/configuration repair.

Existing synthetic provider proofs remained green:

- `make kamailio-signaling-external-trunk-runtime-proof`
- `make asterisk-external-trunk-runtime-proof`

The external PBX credential remains outside this evidence.
