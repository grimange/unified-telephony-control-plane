# Asterisk ARI Adapter Runbook

T0 proves Asterisk ARI transport and runtime observation only. It does not provision ConfBridge conferences, channels, SIP endpoints, RTP, media, trunks, or browser calling.

## Authority

- C2 owns the `RuntimeNode`, endpoints, encrypted write-only ARI credential metadata, declared capabilities, and desired lifecycle state.
- C2 owns the per-node `asterisk_ari_profiles` adapter configuration record. Environment values provide bounded creation defaults only; configured nodes are not driven by permanent global ARI environment authority.
- `AsteriskAriClient` owns ARI HTTP and WebSocket transport inside the adapter boundary.
- `asterisk-ari-events` dynamically discovers eligible active `asterisk-ari` RuntimeNodes and owns one node-scoped listener lease at a time.
- C3 owns runtime operations, leases, fencing, connection epochs, raw receipts, normalized observations, projection checkpoints, and reconciliation.
- PostgreSQL is the durable authority. Redis remains transient queue/coordination infrastructure only.
- Normal management authority is the web-admin UI calling canonical authenticated RuntimeNode APIs. Proof scripts are automated tests against those same APIs, not a second management surface.

## Local Runtime Fixture

The local Kubernetes fixture deploys an internal Asterisk ARI service in `utcp-runtime`. It exposes only the internal ARI HTTP/WebSocket port through ClusterIP. It has no public Service, Gateway route, NodePort, LoadBalancer, host port, SIP listener, RTP port, PJSIP endpoint, ConfBridge execution, host network, host PID, hostPath, Docker socket, or Kubernetes API token.

Local ARI credentials are generated into an ignored repository runtime file by the Kubernetes lifecycle and are written into C2 through the authenticated API proof. They must not appear in endpoint URLs, logs, audit records, outbox events, receipts, status output, or evidence.

## Verification

```sh
make asterisk-ari-config-check
make asterisk-ari-test
make k8s-image-build
make k8s-image-push
make k8s-apply
make asterisk-ari-api-proof
make asterisk-ari-runtime-proof
make asterisk-ari-status
```

`asterisk-ari-api-proof` uses the normal C1 session and CSRF lifecycle to create or reuse `local-asterisk-ari`, configure endpoints, write the ARI credential through the write-only C2 API, configure the per-node ARI profile through the canonical adapter-configuration route, declare only `runtime.observation` and `event.stream`, and activate the node.

`asterisk-ari-runtime-proof` must observe deployed workers performing inspection, WebSocket connection, raw receipt ingestion, normalization, projection, and readiness convergence. It must not replace the normal lifecycle with manual runtime command execution.

## RuntimeNode Management

The RuntimeNode detail page is the PBX-oriented management surface for Asterisk ARI nodes. It renders backend catalog metadata, persisted node capabilities, safe credential metadata, per-node adapter configuration, desired-versus-observed evidence, and scoped audit history. It does not reveal credentials, duplicate endpoints inside the ARI profile, expose raw ARI event payloads, or provide manual listener/reconcile/retry/mark-ready controls.

The adapter-configuration API validates bounded timeout and reconnect settings, stores them in PostgreSQL, audits successful changes, advances the node configuration generation, and wakes automatic processing. A missing profile is incomplete configuration and prevents listener eligibility until the explicit profile exists.

## Exclusions

T0 deliberately excludes ConfBridge, C5 conference operation support on Asterisk, channel origination, channel control, dialplan execution, SIP registration, PJSIP configuration, Kamailio, RTP/media, rtpengine, browser WebRTC, trunks, PSTN, and FreeSWITCH ESL. Those remain later roadmap phases.
