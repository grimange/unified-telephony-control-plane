# T1 Evidence: Kamailio SIP-over-WSS Signaling — Final Closure (T1-G)

Date: 2026-07-16

## Current Verdict

`PHASE_T1_COMPLETE`

## Corridor Inventory

T1 was implemented across six functional corridors (informal naming retained only as a cross-reference; see `docs/roadmap/implementation-roadmap.md` for the canonical phase-identifier reconciliation):

- **T1-A** — generic C3 event-source authority shared by RuntimeNode-backed listeners and shared-platform observers (one lease/fencing/source-epoch/receipt/checkpoint model). Implemented; covered by `RuntimeEngineTest::test_event_source_identity_supports_runtime_node_and_platform_sources` and `test_platform_source_lease_epoch_receipt_checkpoint_and_fencing`.
- **T1-B** — pinned Kamailio registrar, trusted `sip.utcp.local.test` WSS route, three least-privilege PostgreSQL roles (`utcp_kamailio_auth_reader`, `utcp_kamailio_usrloc_writer`, `utcp_kamailio_observer_reader`), native `usrloc` persistence. Live-proven in both Kubernetes and disposable Compose (see below).
- **T1-C** — fenced `kamailio-registration-observer` process, sanitized `usrloc` snapshot diffing (Contact fingerprinted by SHA-256 at snapshot time, raw Contact never persisted), C3 registration receipts/normalizer/projection, automatic reconciliation. Implemented; covered by `KamailioRegistrationObserverTest` (5 focused tests). Live rollout, projection, and **fenced Pod takeover** proven during this closure corridor (see below) — this was the one T1-C proof gap the repository's own runbook had flagged as outstanding.
- **T1-D** — canonical User & Access Management UI: user list/detail, tenant memberships/roles/capabilities, active `TelephonySession` visibility and termination, nested signaling panel (safe metadata + one-time credential issuance), no permanent SIP account, no provider-node binding, no manual runtime controls. Live-proven via natural browser proof (see below).
- **T1-E** — natural browser acceptance. Not previously attempted anywhere in the repository (no Playwright test or committed browser evidence existed for T1 before this closure task). Proven in this corridor: real login, forced temporary-password change, tenant selection, user list/detail, signaling panel rendering, logout — twice, once per tenant, with no injected cookies/sessions.
- **T1-F** — disposable Kamailio Compose compatibility proof (`make compose-proof`). Live-run to completion in this corridor with verified cleanup.

## T1-G.2 — Two-Tenant Non-Platform Isolation Proof

Two ordinary tenant-member accounts (no platform role, no tenant-admin role) were created through the canonical authenticated admin API — `POST /api/v1/admin/users` (temporary password returned once, matching the existing one-time-secret pattern) and `POST /api/v1/admin/memberships` (`role_key: tenant-member`) — in two pre-existing distinct tenants the bootstrap administrator already held `tenant-admin` membership in (`Local Tenant` and `Proof Tenant 1784195144`). No direct SQL was used for account, membership, session, credential, or Contact creation anywhere in this closure task.

Natural Playwright browser proof, both directions:

- Member A logged in through the real login page, completed the forced temporary-password change, selected `Local Tenant`; the active-tenant dropdown offered only `Local Tenant`. The scoped Users list (121 members) did not include Member B. Direct navigation to Member B's user-detail URL returned `User not found.` (HTTP 404 on `/api/v1/admin/users/{id}`, confirmed via console message), with no sensitive fields rendered. Logged out naturally.
- Member B logged in through the real login page, completed the forced temporary-password change, selected `Proof Tenant 1784195144`; the active-tenant dropdown offered only that tenant. The scoped Users list (2 members: Member B and the platform admin) did not include Member A. Direct navigation to Member A's user-detail URL returned `User not found.` (HTTP 404), with no sensitive fields rendered. Logged out naturally.

Existing focused backend coverage (`TelephonyDomainTest::test_signaling_credential_access_is_tenant_scoped_and_session_end_revokes_registration_desire`, `test_session_expiry_removes_participation_and_cross_tenant_access_fails`) already asserts the equivalent cross-tenant boundary at the API layer; the natural browser proof did not expose any gap those tests miss, so no new regression test was added.

## T1-C Fenced Observer Takeover — Closed During This Corridor

The repository's own runbook (`docs/runbooks/kamailio-sip-over-wss-registration.md`, "Pending Runtime Work") listed "observer Pod takeover" as an outstanding live-proof item. Closed live against `utcp-local`:

1. Identified the lease-holding replica (`kamailio-registration-observer-...-5vzg8`, `status=polled`, `checkpoint_advanced=1`) versus the standby (`...-b55jl`, `status=lease_unavailable`).
2. Deleted the lease-holding Pod.
3. The Deployment recreated a replacement Pod (`...-lckqc`), which claimed the lease and began polling (`status=polled`, `checkpoint_advanced=1`) while the original standby remained standby.
4. `kamailio-signaling-status` before and after showed `canonical_observer_source_count=1`, `observer_lease_count=1`, `active_source_epoch_count=1` throughout, and `snapshot_lag_seconds` stayed low (~2.8s) — single fenced ownership was maintained with no lost polling progress.

## Kubernetes Phase-Wide Proof

All required commands passed against `utcp-local` (`.runtime/kubeconfig/utcp-local.yaml`, context `k3d-utcp-local`): `local-status`, `identity-*`, `runtime-registry-*`, `runtime-engine-*`, `telephony-domain-*`, `asterisk-ari-*`, `kamailio-signaling-*`, `k8s-config-check`/`k8s-status`/`k8s-proof`, `gateway-proof`, `security-proof`, `observability-proof`, `local-proof`. `kamailio-signaling-runtime-proof` live-proved REGISTER, refresh (same `ruid`, advancing `last_modified`), replace (single Contact), deregister, wrong-password rejection (401, no Contact), SHA-256-algorithm rejection (401, no Contact), Kamailio Pod restart with persisted-Contact recovery, session-end auth-view revocation, post-end refresh rejection (401), and bounded Contact expiration (observed within 115s) — all with `no_asterisk_sip_scope=true`. T0 Asterisk ARI regression (`asterisk-ari-runtime-proof`) passed with the listener holding its claimed lease/epoch and zero recent authentication failures, confirming T1 did not regress T0.

## Disposable Compose Phase-Wide Proof

`make compose-config` and `make compose-proof` both passed. The disposable project (`utcp-compose-proof-1861467`) started all 14 signaling-profile services, ran the full credential/REGISTER/refresh/replace/deregister/wrong-password/session-end/bounded-expiry/observer/projection/reconciliation corridor (`registration_projection_aggregates={"unregistered":1,"expired":1}`, `reconciliation_aggregates={"converged":1,"waiting":1}`, `canonical_kamailio_source_count=1`), and reported `no_asterisk_sip_scope=true`/`no_rtp_media_scope=true`. Cleanup was verified independently after the run: no `utcp-compose-proof-*` containers, networks, or volumes remained, `.runtime/compose/` was empty, and APNTalk's own Compose project (`apntalk-postgres`, `apntalk-redis`) and the `utcp-local` Kubernetes cluster were both unaffected throughout.

## Security Acceptance

- Three Kamailio PostgreSQL roles have disjoint grants (verified directly in `2026_07_16_160000_create_kamailio_registrar_foundation.php`): auth-reader → `SELECT` on the sanitized auth view + `version`; usrloc-writer → `SELECT/INSERT/UPDATE/DELETE` on `location` + sequence + `SELECT` on `version`; observer-reader → `SELECT` on `location` + `version` only.
- No application code path writes to `location`; the two `DB::table('location')` references found (`MetricsController`, `KamailioRegistrationObserver`) are both read-only.
- HA1 is computed only at credential-issuance time for storage; it is never serialized into an HTTP response, log, or audit record. Public registration metadata never includes HA1, raw Contact, `ruid`, or SIP messages.
- `make secret-scan` passed repository-wide.
- No `RuntimeNode`, adapter key, or runtime family exists for Kamailio anywhere in the registry code — Kamailio remains shared platform infrastructure, not a managed telephony execution provider.
- No public SIP UDP/TCP port, NodePort, LoadBalancer, or host networking exists on the Kamailio Deployment/Service (only ClusterIP port 8080/WS internally).
- No manual observer/projection/reconciliation/Contact control exists in the web UI (`App.test.ts` asserts "Run observer" does not render; grep for force-reconcile/manual-register/retry-now vocabulary is empty).

## Observability Acceptance

`kamailio_registration_*` metrics (`snapshot_polls_total`, `snapshot_poll_failures_total`, `observer_claims_total`, `observer_active`, `observer_lag_seconds`, `receipts_total`, `projection_backlog`, `contacts`) use only a `result`/event-type label — no user, session, or identity label. Alert rules (`UTCPKamailioRegistrationObserverUnavailable`, `...MissingLease`, `...CheckpointStale`, `...RepeatedPollFailure`, `...ProjectionBacklog`) all pair a zero/negative observer signal with a positive precondition; no alert fires on a bare zero-Contact count. `make observability-proof` passed (metrics, log ingestion, Grafana provisioning, synthetic alert delivery).

## Full Repository Regression

`make help`, `make doctor`, `make repository-hygiene`, `make workflow-check`, `make secret-scan`, `make test` (80 backend + 16 frontend tests passed, 2 pre-existing environment-conditional skips unrelated to T1), `make check` (hygiene, Pint, ESLint, vue-tsc), `make build`, `make container-check` (API/web image smoke + inspect) all passed. `git diff --check` reported no whitespace errors. All shell scripts pass `bash -n`; all PHP files pass `php -l`. `docs/roadmap/implementation-roadmap.md` was fully synchronized against the initial and application implementation plans (see that document's own reconciliation sections).

## Documentation Corrections Made During Closure

- `README.md`'s stale "Current Status" opening paragraph (previously claiming Phase C4 as the current summary while later paragraphs correctly described C4/C5/T0/T1 progress) was corrected to state the F0-T0 completed foundation and T1-in-progress status accurately.
- Added `docs/decisions/ADR-019-kamailio-signaling-registration-authority.md`, the first ADR specifically covering the T1 corridor (none existed previously).
- Updated the phase-marker guard scripts (`scripts/check-repository-hygiene`, `scripts/local/config-check`, `scripts/telephony-domain/config-check`, `scripts/asterisk-ari/config-check`, `scripts/kamailio-signaling/config-check`) to accept/require `UTCP_PHASE=T1` instead of hard-gating on `T0`.
- Historical evidence documents that correctly recorded an earlier point-in-time phase marker (e.g. `docs/evidence/runtime-node-management-authority.md`, `docs/evidence/local-runtime-authority-cutoff.md`) were left untouched to preserve historical accuracy, per repository convention.

## Boundary Notes

T1 does not implement Asterisk SIP signaling, ConfBridge, channel control, RTP/media, external trunks, or conference execution — those remain T2 (Asterisk conference execution), T3 (rtpengine browser media), and the extended-scope C6/C7/T6/V1 phases. No permanent user-to-provider-node binding, no fake Kamailio `RuntimeNode`, no Redis registration authority, and no manual registrar/observer/projection/reconciliation/Contact control exist anywhere in the corridor.
