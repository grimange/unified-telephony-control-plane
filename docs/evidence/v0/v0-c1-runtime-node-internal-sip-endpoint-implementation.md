# V0-C1 — RuntimeNode Internal SIP Endpoint and Managed Asterisk Provisioning

## Status

**Complete in the repository; browser/live proof not performed.**

This bounded implementation follows the completed V0-C conference SIP routing
architecture contract. It does not cut over conference admission, change
Kamailio routing, alter the 9900 T3 fixture, or implement RT-1/Reverb.

## Implemented contract

The canonical RuntimeNode endpoint catalog now supports:

```text
purpose=sip
transport=udp
```

The existing RuntimeRegistryService endpoint upsert path remains the authority.
Managed Asterisk provisioning adds the endpoint automatically after generating
the existing managed resources:

```text
purpose: sip
transport: udp
host: <managed-service>.utcp-runtime.svc.cluster.local
port: 5060
enabled: true
```

The database catalog constraints are updated for both fresh installations and
existing installations through a forward migration. No endpoint model or new
table was introduced.

## Managed Kubernetes resources

The generated, ownership-labelled Asterisk Deployment now declares the existing
ARI TCP/8088 container port and an internal SIP UDP/5060 container port named
`sip`. The same owned ClusterIP Service preserves ARI/control TCP/8088 and adds
UDP/5060 targeting the named `sip` port. No host port, NodePort, LoadBalancer,
Gateway, or public SIP exposure was added.

Retries continue to use the existing deterministic resource and endpoint
upserts. A retry converges on one Deployment, one Service, and one canonical
SIP endpoint; it does not create a second endpoint authority. Existing
control/events/health endpoint registrations remain present and unchanged.

## Network boundary

The Kamailio signaling policy no longer pins SIP ingress to the historical
`utcp.dev/runtime-node: local-asterisk-ari` fixture label. It selects the
managed Asterisk labels already emitted by RNP:

```text
app.kubernetes.io/component: asterisk-ari
utcp.io/network-role: asterisk-ari
```

Only the Kamailio signaling workload is allowed to reach managed Asterisk on
UDP/5060. Existing media/DNS policy semantics remain separate. Browser and
external network paths do not gain direct RuntimeNode SIP access.

## Authority and boundaries preserved

`telephony-infrastructure-worker` remains the sole Kubernetes writer and the
existing owned Service remains the deprovision authority. RuntimeNode readiness
is unchanged; no SIP-readiness state machine was introduced. The catalog is
provider-neutral, but only managed Asterisk provisioning is implemented here;
FreeSWITCH and external-runtime SIP workflows remain future work.

The 9900 fixture remains T3 connectivity/media proof only. Canonical conference
admission still uses its current path until V0-C2 through V0-C5 are delivered.
No `runtime_channel_id`, conference route view, Kamailio `sqlops`, or conference
route was added.

## Verification boundary

Repository-focused tests cover catalog values, generated Deployment and Service
ports, complete endpoint preservation, managed SIP endpoint registration, and
idempotent provisioning. Repository policy/configuration checks cover the
managed Asterisk selectors and Kamailio-only UDP/5060 ingress. No deployment or
browser proof is claimed for V0-C1.

## V0-C status

```text
V0-C architecture contract COMPLETE
V0-C1 RuntimeNode internal SIP endpoint COMPLETE
V0-C2 admission destination PENDING
V0-C3 Kamailio routing PENDING
V0-C4 inbound participant cutover PENDING
V0-C5 T3/reference separation verification PENDING
V0-C6 natural browser proof PENDING
V0 overall IN PROGRESS
RT-1 PLANNED / NOT IMPLEMENTED
```
