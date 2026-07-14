# Authority Boundaries

UTCP must not silently transfer authority from one component to another.

| Concern | Authority |
| --- | --- |
| Business policy, tenant policy, desired state, reconciliation decisions, audit history | UTCP |
| Users, tenants, memberships, account status, membership status, tenant status, built-in roles, capabilities, and role assignments | PostgreSQL through UTCP identity services |
| Runtime nodes, endpoint configuration, encrypted credential metadata, declared runtime capabilities, placement metadata, desired lifecycle state, and registry audit history | PostgreSQL through UTCP runtime registry services |
| Runtime operations, outbox dispatch state, raw runtime-event receipts, observations, projection checkpoints, reconciliation state, leases, and fencing | PostgreSQL through UTCP runtime engine services |
| Browser session authentication | Laravel session authentication; Redis may store transient session state only |
| Canonical persisted business records | PostgreSQL |
| Queues, locks, caching, transient projections | Redis |
| Real-time UI updates | Reverb/application WebSockets as notifications only |
| HTTP, HTTPS, and WSS ingress | Traefik |
| Live SIP signaling execution | Kamailio |
| RTP/SRTP media relay | rtpengine |
| Telephony application and call execution | Asterisk or FreeSWITCH |
| Workload placement and container orchestration | Kubernetes |
| Kubernetes namespace admission and pod-to-pod traffic boundaries | Kubernetes Pod Security Admission and NetworkPolicy |
| Local metrics, logs, dashboards, and alert delivery | Prometheus, Loki, Grafana, Alertmanager, and Alloy |
| Runtime-operation lifecycle, leases, fencing, outbox, inbox, idempotency, and audit records | UTCP application kernel backed by PostgreSQL |

## Required Separations

- PostgreSQL is canonical for business records. Redis must not be treated as the source of truth.
- PostgreSQL is canonical for identity, tenancy, membership, role, capability, and authorization-relevant audit records. Redis sessions and rate-limit keys are transient and must not become identity authority.
- PostgreSQL is canonical for runtime-node registry records. Redis, Kubernetes Pod discovery, and runtime health endpoints must not create, mutate, or delete runtime-node registry authority.
- The frontend renders server-provided identity, tenant, and capability state. It must not compute or grant authorization from client-provided roles, capability arrays, tenant IDs, route visibility, browser storage, or headers.
- Platform authority and tenant membership are distinct. Platform administrators are not modeled as members of a fabricated system tenant.
- WebSocket and Reverb messages notify clients. They must not be the only record of a business transition.
- Kubernetes placement and readiness signals must not encode tenant or telephony business policy.
- Kubernetes NetworkPolicies protect current namespace and service boundaries. They must not pre-authorize future telephony, media, or runtime paths before those workloads exist.
- Observability records runtime signals. Metrics, logs, dashboards, and alerts do not become canonical business state or reconciliation authority.
- Runtime-operation ownership, idempotency, inbox deduplication, outbox persistence, and audit records are PostgreSQL-backed application-kernel authority. Redis may deliver queue work later, but Redis must not decide operation ownership or become the canonical operation ledger.
- Runtime-event receipts, normalized observations, projection checkpoints, observed-state updates, and reconciliation state are PostgreSQL-backed runtime-engine authority. Redis queue delivery can wake workers, but Redis must not become event, projection, or reconciliation authority.
- Traefik exposes HTTP, HTTPS, and WSS traffic. It is not the primary SIP or RTP authority in this roadmap.
- Kamailio executes live SIP routing. UTCP owns desired gateway and routing policy.
- rtpengine relays media. UTCP owns desired media-resource registration and health policy.
- Asterisk and FreeSWITCH execute calls behind adapter contracts. Vendor-specific concepts should be normalized or exposed as explicit optional capabilities.

Application services must use normalized runtime contracts such as `TelephonyRuntimeAdapter`, `ConferenceRuntimeAdapter`, `RegistrationObservation`, and `ConferenceMembershipObservation`. Controllers and workflows must not branch directly on `asterisk` or `freeswitch`; runtime-specific commands stay inside adapters.

C0 does not implement those adapters. It only provides the runtime-neutral kernel that later registry, command/event, simulator, and telephony phases must use.

C1 does not implement telephony sessions, SIP credentials, registration, conferences, runtime nodes, provider nodes, or runtime adapters. C1 identity and authorization must remain reusable by future Asterisk, FreeSWITCH, simulator, Kamailio, and rtpengine phases without encoding vendor-specific identity assumptions.

C2 implements `RuntimeNode` as the sole canonical registry entity. It does not create `ProviderNode`, `TelephonyServer`, `PBXServer`, `AsteriskServer`, or `FreeSwitchServer` authorities. Runtime family and adapter key are configuration metadata only. Desired state is administrator intent; observed state remains `unobserved` or `unknown` until C3 introduces observation, projection, and reconciliation. C2 stores encrypted write-only credentials but never exposes plaintext or ciphertext through API responses, audit metadata, outbox payloads, logs, or status commands.

C3 implements only the runtime-neutral processing engine. It changes observed state through projection authority, dispatches outbox rows automatically, stores raw runtime-event receipts, advances projection checkpoints atomically with projections, and schedules reconciliation through PostgreSQL-backed leases and fencing. C3 does not add a production simulator, no-op adapter, ARI or ESL client, Asterisk or FreeSWITCH event listener, public command execution route, manual projection route, manual reconciliation route, or manual mark-ready authority.

## CLI Boundary

Repository commands may help bootstrap, diagnose, recover, migrate, or verify the system. The C1 local identity bootstrap command is exceptional first-administrator initialization only. Routine users, tenants, memberships, password resets, and role assignments belong to the web/API authority and must not move into general-purpose CLI management.
