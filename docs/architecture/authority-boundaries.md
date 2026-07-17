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

C2 implements `RuntimeNode` as the sole canonical registry entity. It does not create `ProviderNode`, `TelephonyServer`, `PBXServer`, `AsteriskServer`, or `FreeSwitchServer` authorities. Runtime family and adapter key are configuration metadata only. Backend catalog authority, derived from the runtime registry configuration, is the only management catalog for runtime families, adapter keys, adapter-supported capabilities, endpoint requirements, credential requirements, and adapter-configuration availability; the frontend renders that catalog and must not carry a second checked-in capability or adapter catalog. Desired state is administrator intent; observed state remains `unobserved` or `unknown` until C3 introduces observation, projection, and reconciliation. C2 stores encrypted write-only credentials but never exposes plaintext or ciphertext through API responses, audit metadata, outbox payloads, logs, or status commands.

C3 implements only the runtime-neutral processing engine. It changes observed state through projection authority, dispatches outbox rows automatically, stores raw runtime-event receipts, advances projection checkpoints atomically with projections, and schedules reconciliation through PostgreSQL-backed leases and fencing. C3 event-source identity is canonical and may be backed by a `RuntimeNode` or by shared platform infrastructure without a RuntimeNode. The same event-source row owns leases, fencing, source epochs, receipts, and checkpoints for both shapes. Shared platform infrastructure, including the future Kamailio registration observer, must not create fake RuntimeNodes or parallel platform-specific lease, epoch, receipt, or checkpoint tables. C3 does not add a production simulator, no-op adapter, ARI or ESL client, Asterisk or FreeSWITCH event listener, public command execution route, manual projection route, manual reconciliation route, or manual mark-ready authority.

C4 implements the first leaf `RuntimeAdapter`: `simulator-deterministic`, selected only through the C2 authenticated runtime-registry API (`runtime_family: simulator`, `adapter_key: simulator-deterministic`). It owns its own scenario configuration (`simulator_profiles`, `simulator_states`) and scheduled-event queue (`simulator_scheduled_events`) in PostgreSQL, and integrates with C3 only through the same `RuntimeAdapter`, raw-event-receipt, observation, checkpoint, and reconciliation contracts every future real adapter must use. C4 does not add telephony sessions, conferences, SIP registration, or any live Asterisk, FreeSWITCH, Kamailio, or rtpengine adapter; the generic C3 engine must not, and does not, contain any reference to the simulator adapter class or to a simulator scenario key.

C5 owns telephony sessions, conferences, conference participants, runtime bindings, desired state, lifecycle timestamps, admission reason, and termination reason in PostgreSQL. A C5 `TelephonySession` is an authenticated user's tenant-scoped control-plane telephony authorization session only; it is not SIP registration, media connectivity, a call, browser microphone access, or a runtime channel. C5 runtime work is expressed as runtime-neutral operations (`conference.ensure`, `conference.close`, `conference.participant.ensure`, `conference.participant.remove`) and observed only through C3 receipts, normalizers, projectors, checkpoints, and reconcilers. C5 must not add SIP credentials, SIP registration, WebRTC/media, ARI, ESL, Asterisk, FreeSWITCH, Kamailio, rtpengine, a public join route, or a manual runtime/projection/reconciliation control path.

T0 adds Asterisk ARI only as a runtime adapter and event-listener boundary beneath C3. C2 remains authoritative for the Asterisk RuntimeNode, endpoints, encrypted write-only ARI credential metadata, declared capabilities, desired state, and per-node ARI profile. `AsteriskAriClient` may decrypt credentials only inside the adapter boundary for immediate Basic Authorization use, and it must consume the explicit RuntimeNode profile instead of a permanent global environment fallback. `asterisk-ari-events` owns no business state; it claims PostgreSQL listener leases, opens C3 connection epochs, and writes bounded raw receipts. RuntimeNode observed state changes only through C3 normalized observations and projection. Adapter-configuration changes are performed through the generic authenticated RuntimeNode API, dispatched through registered handlers rather than controller-level adapter branches, audited through the append-only audit authority, and allowed to wake automatic reconciliation/listener processing. T0 must not add ConfBridge execution, C5 conference operation support, channel control, SIP/PJSIP, RTP/media, trunks, PSTN, browser WebRTC, public ARI routes, manual reconnect/readiness authority, a runtime allowlist, an environment feature gate, or a second management CLI.

T1 starts the signaling-registration corridor with `TelephonySession` as the PostgreSQL authority for eligibility and `telephony_signaling_credentials` as the only persisted SIP credential authority. One active TelephonySession may issue one active short-lived SIP credential with a stable session-scoped username in the canonical `sip.utcp.local.test` realm. UTCP stores HA1 as credential-equivalent secret material and returns the generated plaintext SIP secret only in the issuance response. The web password is never reused for SIP. `signaling_registration_observations` records desired registration state and observer-owned projected state; controllers and credential services may seed desired state but must not claim live Contact state. Kamailio is the sole registrar for actual REGISTER authentication, current Contact binding, replacement, explicit deregistration, and runtime expiration. The T1-B foundation deploys and live-proves that registrar corridor through trusted WSS and native usrloc. The T1-C repository implementation connects Kamailio `usrloc` snapshots to the existing C3 lease, fencing, source-epoch, receipt, checkpoint, normalizer, projection, and reconciliation authority with sanitized internal receipts. T1-D places safe signaling metadata and one-time credential issuance inside the canonical User & Access Management detail surface under the user's active TelephonySession. It must not create permanent SIP accounts, user-to-provider-node bindings, PBX assignments, credential recovery controls, manual Contact controls, manual observer controls, manual projection controls, or manual reconciliation controls. Raw Contact values, SIP messages, Authorization headers, HA1, and plaintext SIP secrets must not appear in public registration metadata. Natural browser acceptance, disposable Compose compatibility, and full T1 acceptance remain pending. `UTCP_PHASE` remains `T0` until those remaining T1 authorities are proven.

Runtime evidence and history views are read-only projections over existing PostgreSQL authorities. They must sanitize raw runtime payloads, endpoint URLs, credentials, stack traces, lease-owner identity, fencing tokens, epoch IDs, pod names, and unrelated tenant records.

## CLI Boundary

Repository commands may help bootstrap, diagnose, recover, migrate, or verify the system. The C1 local identity bootstrap command is exceptional first-administrator initialization only. Routine users, tenants, memberships, password resets, and role assignments belong to the web/API authority and must not move into general-purpose CLI management.

`utcp:user-access:reset-password` is a bounded C1 recovery exception for an existing user. The command resolves exactly one user through the application service, generates a temporary password internally, stores only the hash and bounded temporary-password lifecycle fields in PostgreSQL, revokes sessions through the existing session-version authority, rotates remember-token access, appends sanitized audit records, and requires normal login plus forced password change before ordinary application access resumes. It must not create users, activate accounts, grant roles, change memberships, reveal existing credentials, add an HTTP reset endpoint for AI-coder use, or become normal identity management authority.

RuntimeNode proof scripts remain automated verification clients of the same canonical RuntimeNode APIs. They are not an alternate management authority and must not grow independent mutation commands for adapter configuration, credential retirement, runtime evidence, or audit history.
