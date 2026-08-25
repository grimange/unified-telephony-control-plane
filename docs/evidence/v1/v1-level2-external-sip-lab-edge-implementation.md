# V1 Level-2 External SIP Lab Edge Implementation

Status: repository implementation complete; live edge activation and
bidirectional external acceptance remain pending. V1 remains active.

## Scope and authority

This bounded packet adds the transport-only edge for the independent Asterisk
PBX at `38.146.161.46`. C7A owns `ExternalTrunk`, `TrunkEndpoint`, credential
references, `TelephonyAddress`, caller identity, and caller-identity policy.
C7B owns routes, route decisions, and destinations. T6 remains the derived
provider projection. Kamailio remains the SIP-aware signaling executor;
Kubernetes and k3d provide packet transport only.

The synthetic peer remains a deterministic regression fixture. It is not the
Level-2 acceptance peer. The real `38.146.161.46` PBX remains outside the UTCP
cluster and is the required interoperability target.

## Selected edge

The repository-native edge is one additional same-selector Kubernetes Service:

```text
kamailio-sip-internal  ClusterIP   UDP/5060   existing internal authority
kamailio-sip-external  NodePort    UDP/5060   nodePort 30560
```

k3d publishes exactly `0.0.0.0:5060:30560/udp` to the existing server load
balancer. No relay container, shell proxy, kubectl port-forward, duplicate
Kamailio deployment, Traefik UDP route, SIP policy, or alternate lifecycle was
introduced. Traefik remains HTTP/HTTPS/WSS transport only.

The external path is:

```text
38.146.161.46:5060/UDP
  -> routed network / upstream NAT if present
  -> UTCP host UDP/5060
  -> k3d server load balancer UDP/30560
  -> kamailio-sip-external
  -> Kamailio
```

The reverse path uses the same edge. The Service uses `externalTrafficPolicy:
Cluster`; actual source identity must be confirmed after the edge is activated.

## Security and policy

Kamailio ingress adds exactly `38.146.161.46/32` for UDP/5060 and retains the
existing Asterisk, FreeSWITCH, and Traefik corridors. No `0.0.0.0/0` policy,
TCP/5060, TLS/5061, hostNetwork, arbitrary hostPort, or unrelated NodePort was
added. Because the current cluster has not been rebuilt with the new k3d
publication, source-IP behavior has not yet been observed at Kamailio.

## Reachability classification

`BEHIND_UPSTREAM_NAT`.

Observed local address: `192.168.86.181`. Observed public egress address:
`158.62.75.77`. The host is not directly assigned the public address, so the
edge requires the upstream router to forward UDP/5060 to the local host after
the canonical k3d mapping is activated. No router or UPnP mutation was made.

## Credential handling

`.env-external-pbx-sip-credentials` is ignored by `.gitignore`, untracked, and
was corrected to mode `600`. Its values were not copied into manifests,
application code, evidence, or images. A local variable-name inspection was
mistakenly performed against this non-dotenv file and exposed a credential
value in tool output; the value was not committed or written to repository
files, but the dedicated lab credential must be rotated before live proof.
The normal C7A Admin API remains the only intended authority for creating the
canonical credential reference.

## Runtime status and proof gap

Static edge, rendered Kubernetes, Kamailio, k3d, credential-ignore, and T6
seam checks pass. The existing `utcp-local` cluster remains unchanged and its
server load balancer currently has no UDP/5060 publication. The repository
configuration requires a cluster recreation to add an immutable k3d port
mapping; the available recreate proof deletes and recreates the cluster and
does not establish preservation of PostgreSQL/Redis PVC contents. Therefore no
destructive rebuild was performed.

No real `UTCP -> 38.146.161.46` call or `PBX -> UTCP` call was attempted because
the canonical external edge is not live. No source IP observed by Kamailio,
canonical ExternalTrunk runtime call correlation, PBX AOR contact, or
bidirectional media evidence is claimed here.

After a safe canonical edge activation, the operator-side PBX contact will be:

```text
contact=sip:158.62.75.77:5060
transport=udp
```

That value is an observed public egress address, not yet a proven inbound
reachable address. If the router uses a different stable public address, the
actual mapped address must replace it. The PBX-side `outbound_auth` and
`identify` requirements remain to be determined from the canonical inbound
authentication contract after reachability exists; no unnecessary PBX change
is prescribed.

## Verification

Passed:

```text
make k3d-config-check
make v1-external-sip-edge-config-check
make kamailio-signaling-config-check
make asterisk-external-trunk-config-check
```

`scripts/k3d/verify` was run and correctly reported the current runtime gap:
`V1 external SIP UDP/5060 publication not found on k3d server load balancer`.
