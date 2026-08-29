# ADR-031: Native-k3s Provider-Facing SIP Signaling Authority

## Status

Accepted as the canonical authority for UTCP's provider-facing SIP signaling
identity on the current native-k3s environment. No runtime behavior, Kamailio
configuration, Kubernetes manifest, router configuration, or DNS record is
changed by this documentation packet.

It resolves the narrow audit verdict
`POLICY_OR_TOPOLOGY_AUTHORITY_UNRESOLVED` recorded against Gap B and makes the
next bounded implementation packet unambiguous.

This ADR decides exactly one question: **which layer owns the SIP address and
port that external providers use to reach UTCP, and how that identity is
represented, validated, and consumed.** Environment authority (native k3s /
`default` / `utcp-dev01`) is settled by the canonical environment cutover and
is restated here only where this decision depends on it.

## Context

Five consecutive real-provider V1-A calls reproduced the same defect. The
provider sends an in-dialog BYE carrying **no Route header**, targeting a
dialog remote target UTCP cannot serve. Kamailio receives the BYE, but
`route[WITHINDLG]`'s `loose_route()` returns false because no route set exists,
so the request falls through to `404 Not Found`; managed Asterisk never
receives the BYE and the dialog expires only by `dlg_ontimeout`.

The narrow signaling-authority audit established the mechanics exactly:

* **Empty provider route set by construction.** `record_route()` appears twice
  in the Kamailio configuration — the internal application relay path and the
  inbound external-trunk path. It is absent from
  `route[RUNTIME_EXTERNAL_TRUNK]`, the sole provider-egress path, which reaches
  `t_relay()` with only `dlg_manage()` and `t_on_failure()` before it. UTCP
  therefore never anchors itself into the provider's dialog route set. This is
  corroborated by preserved dialog records showing empty `caller.route_set` and
  `callee.route_set`.
* **Private runtime Contact.** Managed Asterisk's transport binds
  `0.0.0.0:5060` with no `external_signaling_address`, so its Contact is the
  pod IP (`sip:asterisk@10.42.0.<pod>:5060`). Kamailio does not modify it: no
  `fix_nated_contact`, `set_contact_alias`, `uac_replace_contact`, or topology
  hiding runs on the provider egress path.
* **Return path already sufficient.** Once a provider BYE arrives with a valid
  route set, `loose_route()` succeeds, the Request-URI remains the runtime
  Contact — which is directly routable inside the cluster — `MEDIA_DELETE`
  runs, and `t_relay()` delivers to the managed runtime. No database lookup or
  additional correlation is required.
* **Provider-visible identity unresolved.** The provider socket advertises
  `kamailio-sip-external.utcp-platform.svc.cluster.local:5060`, a
  cluster-internal name no external peer can resolve or route to. The
  registration Contact advertised to the provider is likewise cluster-internal.
  No configuration key, ADR, or data fixture anywhere in the repository owns a
  provider-reachable address for UTCP.
* **NodePort is not the answer by default.** `kamailio-sip-external` is a
  NodePort Service: `port 5060/UDP`, `nodePort 30560`, `targetPort
  sip-ext-udp`, `externalTrafficPolicy: Local`. That is a Kubernetes transport
  boundary, not a statement about what external peers dial.
* **Historical k3d edge cut off.** The canonical environment cutover stopped
  the historical k3d environment's ownership of TCP/80, TCP/443 and UDP/5060,
  and `scripts/native-k3s/lib` now enforces
  `ensure_no_historical_edge_collision`. k3d remains an optional non-canonical
  local integration environment.

The consequence is that adding `record_route()` alone would make the defect
worse, not better: `record_route()` derives its URI from the sending socket's
advertised identity, so it would insert an unroutable `.svc.cluster.local`
Record-Route into the provider's route set — converting a latent
misconfiguration into an active one. The missing element is an authority for
the provider-facing identity, which this ADR supplies.

## Decision

### 1. Authority map

```text
Provider remote endpoint authority
  Where UTCP sends traffic: the provider's address, port, registration target
  and realm.
  Owner: ExternalTrunk / TrunkEndpoint (unchanged).

UTCP external signaling identity authority
  What UTCP advertises to providers so they can reach it.
  Owner: native-k3s deployment configuration (this ADR).

Native Kubernetes transport authority
  Service type, port, targetPort, nodePort, externalTrafficPolicy.
  Owner: the Kubernetes manifests (unchanged).

Router / NAT / DNS authority
  Making the advertised identity actually reach the native edge.
  Owner: external network infrastructure, outside UTCP.

Runtime Contact authority
  The managed runtime's own signaling identity.
  Owner: the runtime adapter and its runtime (unchanged).
```

No layer may assert what another owns. In particular, provider endpoint data
and UTCP's own identity are opposite directions of the same trunk and must
never be conflated (§2, Rejected Alternatives).

### 2. The provider-facing signaling identity is deployment configuration

The provider-facing SIP address and port are **native-k3s deployment
configuration**, owned by the same deployment lifecycle that already owns the
image lock, generated credentials, and rendered server overlay. They are not a
database-managed product entity, and no application-domain schema change is
required.

This follows the repository's existing separation. `ExternalTrunk` and
`TrunkEndpoint` describe *remote provider connectivity*; `TelephonyAddress`
describes *telephony identity*; `RuntimeNode` describes *execution identity*.
UTCP's own public infrastructure identity is none of those, and silently
overloading one of them would be an authority inversion. It is a property of
*where this UTCP deployment sits on the network*, which is precisely what the
native deployment configuration already expresses.

### 3. Canonical address owner and representation

```text
UTCP_SERVER_PROVIDER_SIP_ADDRESS
```

* Owned by the native-k3s deployment configuration, resolved by
  `scripts/native-k3s/lib` alongside the existing `UTCP_SERVER_*` values, and
  stored in the deployment state directory with the image lock and generated
  credentials.
* **Host only.** An IPv4 literal, an IPv6 literal, or a DNS hostname. No
  scheme, no port, no URI wrapper. Kamailio accepts all three forms as a socket
  advertised address, so no additional representation is needed.
* The same address is never stored in a second format anywhere else.

### 4. Canonical port owner and representation

```text
UTCP_SERVER_PROVIDER_SIP_PORT
```

* Owned by the same deployment configuration, represented as a bare integer.
* This is the port **external SIP peers dial**. It is deliberately independent
  of `nodePort`, of the Service `port`, and of the Kamailio listener port.
* Defining it separately is what allows the deployment to be correct in the
  ordinary case where a router presents SIP on a well-known port and forwards
  to an arbitrary NodePort.

### 5. Relationship to the Kubernetes NodePort

```text
external provider
  → UTCP_SERVER_PROVIDER_SIP_ADDRESS : UTCP_SERVER_PROVIDER_SIP_PORT
  → external network / router / NAT authority
  → utcp-dev01 : <native edge port>
  → kamailio-sip-external Service (NodePort 30560/UDP)
  → Kamailio provider listener (udp:0.0.0.0:5060, socket name "provider")
```

`nodePort 30560` is an infrastructure transport boundary and **MUST NOT** be
inferred to be the provider-visible port. A deployment in which the provider
dials `<public>:5060` and a router DNATs to `utcp-dev01:30560` is
architecturally valid, as is one where the advertised port equals the NodePort.
This ADR asserts the model, not any particular site's NAT rule.

External peers are never required to know Kubernetes NodePort details.

### 6. Network and NAT ownership boundary

UTCP owns:

* the signaling identity it must advertise;
* validation that the required identity is configured;
* rendering that identity into the Kamailio provider socket.

External infrastructure owns:

* making the advertised address and port reach the native edge;
* NAT and DNAT rules, firewall policy, and public DNS.

UTCP **MUST NOT** discover, infer, or rewrite router configuration, and does
not manage those facilities.

### 7. Kamailio advertisement authority — the provider socket owns it

```text
SOCKET_OWNS_EXTERNAL_IDENTITY
```

The provider-facing Kamailio socket carries the full external identity:

```text
listen=udp:0.0.0.0:5060 advertise <ADDRESS>:<PORT> name "provider"
```

Ordinary `record_route()` on the provider egress path then derives the correct
provider-facing route representation with no route-specific override.

**Why the route-specific alternative is less appropriate for UTCP.** UTCP
bridges two distinct signaling networks on two distinct sockets — the internal
`runtime` socket (`udp:0.0.0.0:5062`) that faces managed runtimes, and the
external `provider` socket (`udp:0.0.0.0:5060`) that faces providers. Because
these are different sockets, Kamailio's `rr` module inserts a Record-Route for
each side (§9). A route-specific preset mechanism would have to construct both
headers by hand, reimplementing per-socket logic the socket abstraction already
performs correctly, and would risk corrupting the internal-facing header while
fixing the external one. Socket-level advertisement places each identity with
the socket that owns that network, which is exactly the abstraction Kamailio
provides and the separation UTCP already relies on.

### 8. Internal versus provider socket identity

The two sockets keep **independent** advertised identities:

```text
provider socket  → provider-reachable external identity   (this ADR)
runtime socket   → cluster-internal identity              (unchanged)
```

The internal `runtime` socket's advertised identity **MUST NOT** be replaced
with the external one. Managed runtimes reach Kamailio over cluster networking
and must continue to do so.

Managed runtimes **MUST NOT** depend on, or be configured with, the external
public SIP identity (§11).

### 9. Record-Route contract

The required semantic result on the provider-egress path:

```text
initial provider-bound dialog-forming INVITE
  → Kamailio anchors itself into the dialog
  → the provider retains a route set whose next provider-visible hop is the
    canonical provider-facing identity
  → a subsequent in-dialog request from the provider carries a Route header
    that returns the dialog to Kamailio
```

The governing invariant is deliberately expressed as a property, not a count:

```text
The provider receives a valid route set whose next provider-visible hop is
reachable and which deterministically returns the dialog through Kamailio.
```

### 10. Double Record-Route

`modparam("rr", "enable_full_lr", 1)` is configured; `enable_double_rr` is not
set, so the module default applies. Because UTCP relays between two different
sockets on two disconnected networks, double Record-Route is the **expected and
architecturally correct** behavior: the provider-facing header carries the
provider-reachable identity, and the internal-facing header carries the runtime
socket identity, so an in-dialog request returns through Kamailio and is then
correctly directed back toward the runtime network.

```text
An acceptance criterion requiring exactly one Record-Route is INVALID for this
topology and MUST NOT be written.
```

Acceptance is measured by the §9 invariant.

### 11. Runtime Contact contract

```text
Asterisk / runtime Contact   runtime-private identity (pod or runtime address)
provider route set            provider-reachable Kamailio identity
in-dialog request             Route header returns it to Kamailio
Request-URI                   remains the runtime Contact (the remote target)
Kamailio                      relays internally to the managed runtime
```

Under RFC 3261 loose routing the route set determines the next hop while the
remote target stays in the Request-URI. The private Contact is therefore not a
defect — it is exactly the address Kamailio needs to reach the runtime once the
request has returned to UTCP.

```text
NO Contact rewriting is required for Gap B.
```

`fix_nated_contact()`, `set_contact_alias()`, `uac_replace_contact()`, and an
Asterisk `external_signaling_address` are all **NOT REQUIRED** and MUST NOT be
added as defensive compatibility.

### 12. Runtime neutrality

Provider-facing dialog anchoring is owned by Kamailio and the SIP edge, never
by a specific execution runtime. A future FreeSWITCH-managed call uses the same
provider-facing signaling identity through the same Kamailio path. Runtime
Contact details may remain runtime-specific; the provider-facing contract does
not vary by runtime.

### 13. Fail-closed validation

Where native provider-facing dialog anchoring requires the external identity,
the canonical native configuration check **MUST** fail with an explicit reason
when it is absent — for example:

```text
provider-facing SIP signaling identity is not configured
```

The check **MUST NOT** silently substitute any of:

```text
.svc.cluster.local names
Pod IP
Node IP
NodePort
127.0.0.1
```

as provider-visible identity. No silent inference, and no partial
configuration: an address without a port, or a port without an address, is a
validation failure.

These values are **configuration, not activation**. Their presence configures
the canonical external SIP edge; it does not switch a feature on. No
`ENABLE_PUBLIC_SIP`, `USE_EXTERNAL_SIGNALING`, `ALLOW_PROVIDER_DIALOGS`, gate,
allowlist, or hidden activation flag is introduced — these are prohibited
operational friction under the repository's standing rules.

### 14. Public-address discovery is not canonical authority

Provider-facing signaling identity is deterministic deployment configuration.
Runtime correctness **MUST NOT** depend on `ifconfig.me`, `icanhazip`, STUN,
any external web API, UPnP, NAT-PMP, or automatic router inspection. Observed
or discovered public values may appear in diagnostics and evidence, but they
are never canonical authority.

### 15. k3d is not provider signaling authority

k3d / `utcp-local` is an optional non-canonical local integration environment.
It **MUST NOT** be provider signaling authority, and no compatibility logic,
fallback, or dual-canonical behavior may be introduced for it. Optional local
testing may use independent local values only when that environment is
explicitly selected; those values never become native V1 authority.

### 16. Historical reconciliation

The Stage-4 foundation legitimately recorded that it exposed no public
SIP/RTP; that statement describes the Stage-4 foundation and is preserved as
written. Later V1 external SIP work deliberately introduced the native SIP edge
capability. This ADR describes current V1 authority and requires no rewrite of
historical evidence.

## Consequences

### Bounded implementation seams

```text
native deployment configuration
  Resolve UTCP_SERVER_PROVIDER_SIP_ADDRESS and UTCP_SERVER_PROVIDER_SIP_PORT
  in scripts/native-k3s/lib alongside the existing UTCP_SERVER_* values,
  stored with the image lock and generated credentials.

native configuration validation
  scripts/native-k3s/config-check fails closed with an explicit reason when the
  identity is required and absent or partial, and rejects cluster-internal,
  Pod, Node, NodePort, and loopback substitutes.

Kamailio ConfigMap provider socket
  infrastructure/kubernetes/base/platform/kamailio-configmap.yaml — the
  provider socket advertises the canonical identity, rendered through the
  existing render_server() placeholder substitution precedent. The runtime
  socket identity is unchanged.

route[RUNTIME_EXTERNAL_TRUNK]
  Anchor the dialog before t_relay(), preserving the existing correlation-header
  gate, remove_hf() stripping, $ru/$du construction, uac_auth retry, and CSeq
  behavior.

rr module configuration and tests
  Record and assert the double-Record-Route expectation for the two-socket
  topology.

documentation and evidence
  Record the deployment configuration contract.
```

No application-domain schema change is required.

### Required regression coverage

```text
Provider-bound dialog-forming INVITE carries a Record-Route whose
provider-visible hop is the canonical configured identity — not a
.svc.cluster.local name, Pod IP, Node IP, or loopback.

The runtime socket's advertised identity is unchanged.

An in-dialog request bearing that Route header is accepted by loose_route(),
retains the runtime Contact as Request-URI, and is relayed to the managed
runtime.

No Contact rewriting is introduced on any path.

Configuration check fails closed, with an explicit reason, when the identity is
absent or partially configured.

Existing provider-corridor behavior is unchanged: correlation-header gate,
X-UTCP header stripping before relay, uac_auth retry, CSeq tracking,
CallerIdentity, media formats.

Double Record-Route is accepted; no test asserts exactly one Record-Route.
```

### Required live acceptance proof

One fresh canonical V1-A Call, answered, whose provider-originated in-dialog
BYE reaches managed Asterisk and terminalizes the CallLeg as `remote` /
`remote` per ADR-030 — the remote path currently recorded as
`NOT_REACHED_DUE_TO_GAP_B`. That proof additionally requires the deployment to
be configured with a genuinely reachable identity and the external network to
forward it; both are deployment inputs, not architectural questions.

## Rejected Alternatives

**ExternalTrunk or TrunkEndpoint owns UTCP's public address.** Rejected as an
authority inversion. Those entities describe the *remote peer* — where UTCP
sends traffic. `endpoint_uri`, `registration_target`, and the derived
`provider_host` are the provider's identity and MUST NOT be reused as UTCP's
own. The two are opposite directions of the same trunk, and conflating them
would make UTCP's infrastructure identity vary per trunk, which it does not.

**RuntimeNode owns UTCP's public address.** Rejected. RuntimeNode is execution
and runtime identity. The provider-facing edge is a property of the deployment,
not of any execution target, and multiple RuntimeNodes share one SIP edge.

**TelephonyAddress owns it.** Rejected. TelephonyAddress carries telephony
identity — caller and destination — not infrastructure addressing.

**NodePort automatically becomes the external SIP port.** Rejected. NodePort is
a Kubernetes transport boundary. Binding provider-visible identity to it would
force external peers to know cluster implementation details and would break the
ordinary case where a router presents a well-known SIP port and forwards to an
arbitrary NodePort.

**Asterisk `external_signaling_address` owns public identity.** Rejected. In a
Kamailio-anchored architecture the provider-facing proxy owns provider
topology. Making managed runtimes independently learn router or public-edge
configuration would spread external topology across every runtime, break
runtime neutrality, and contradict the private-Contact contract in §11, which
requires no public identity at the runtime at all.

**Automatic public-IP discovery.** Rejected. Runtime correctness must not
depend on external services, STUN, UPnP, or NAT-PMP. Discovery is
non-deterministic, may return a value that is not inbound-reachable, and would
make signaling identity vary with network conditions.

**Contact rewriting.** Rejected as unnecessary (§11). The private Contact is
exactly what Kamailio needs to reach the runtime once loose routing has
returned the request. Adding rewriting would be defensive compatibility with no
evidenced need.

**Dual k3d / native authority.** Rejected (§15). Two canonical edges on one
host is the coexistence hazard the environment cutover eliminated. No
compatibility path is reintroduced.

**Route-specific preset Record-Route instead of socket advertisement.**
Rejected (§7). It would reimplement per-socket logic Kamailio already performs
and risk corrupting the internal-facing Record-Route.

## Non-Goals

This ADR explicitly does not address:

* router, NAT, firewall, or DNAT configuration, or public DNS provisioning —
  these belong to external network infrastructure;
* the specific public address or port of any particular deployment, which is a
  deployment value supplied through the configuration contract defined here;
* **Gap A** — delayed-observation versus origination-timeout precedence;
* **Gap E** — SIP/Q.850 failure taxonomy and `failure_class`/`failure_code`
  population;
* **Gap F** — provider-wire trust-boundary proof;
* **K5** — distributed placement and host lifecycle;
* **RMA** — recording and media archive.

No authentication, CallerIdentity, CSeq tracking, media format, runtime
placement, or termination authority behavior is changed.
