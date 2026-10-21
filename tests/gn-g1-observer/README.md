# GN-G1 PHP observation bridge and controlled parity proof

This directory is test-only. It is not autoloaded, registered, or routed by the
Laravel application. No production file changes are required. The Go observer
reads the same upstream fixtures and exports schema-checked normalized snapshots
to a disposable output directory; this suite consumes those actual outputs.

Run the observer verification/export first, then:

```bash
UTCP_GN_G1_OUTPUT=/tmp/utcp-gn-g1-output tests/gn-g1-observer/verify-php
```

The runner uses the existing PHP image by immutable image ID, `--network none`,
read-only source mounts, and an in-memory SQLite database. Go snapshot output
is mounted read-only; only the sanitized disposable `php-measurements.json`
file is mounted writable. The runner creates that host-owned file with a mode
that allows the existing image user to write it and rejects a symlink target. Synthetic token/CA marker files
exist only in the test container for Laravel `Http::fake`; they never connect to
Kubernetes. No live credentials, database, Redis, or Kubernetes are used.

## Implemented proof

`SnapshotInfrastructureClient.php` implements the existing read-only PHP
`KubernetesInfrastructureClient` interface. It validates the envelope, rejects
incomplete and stale facts, and translates normalized fields to the shapes
consumed by the existing Host visibility and placement services. Full JSON Schema
validation is performed by the Go exporter before consumption; the deliberately
invalid-version fixture tests rejection after that boundary. It is a test bridge,
not a new production authorization or persistence implementation.

`ObservationParityTest.php` compares existing PHP HTTP-client/service results
with actual Go output for healthy, absent topology, multiple Nodes/Pods, absent
Pod, terminal Pod, identity mismatch, absent Node, empty, and multi-page cases.
It tests the complete normative error taxonomy from read-only `errors.json`,
unknown-code fail-closed behavior, partial/API-unavailable, invalid-version,
and stale rejection; reusing a
snapshot cannot refresh its collection timestamp. Existing PHP routes enforce
tenant scope and read-only verbs. A spoofed tenant label does not authorize a
foreign canonical RuntimeNode. Existing PHP K5C refresh alone persists the facts;
the bridge/Go result cannot alter RuntimeNode desired state.

The complete existing Host visibility and manifest suites also run. A dedicated
regression demonstrates that the current PHP HTTP client ignores continuation
metadata while the Go collector completes the same three-page fixture.

## Intentional differences and contract limitation

The v1 contract removes arbitrary Node labels. Parity explicitly removes only
those unapproved labels from the raw PHP comparison; all retained public facts
and placement/correlation outcomes are compared. Current Host placement consumes
`app.kubernetes.io/part-of` and `app.kubernetes.io/instance`; topology persistence
consumes region, zone, and hostname. Those inputs survive the allowlist.

Current PHP placement includes `Succeeded` Pods as infrastructure facts and does
not infer telephony termination. The bridge preserves that behavior. v1 cannot
represent `metadata.deletionTimestamp`: the schema is closed and has no deletion
or terminating field. The terminal fixture includes a deletion timestamp in the
upstream object to demonstrate that it is intentionally absent from v1 output.
A separate negotiated version is required to carry Running-but-deleting state;
GN-G1 does not silently expand the normative contract.

Direct controller metadata remains unverified ownership evidence. The existing
PHP observation correlation checks namespace/instance; it does not validate the
complete Deployment/ReplicaSet/Pod UID chain. Mutation/lifecycle proof must not
reuse this observation correlation as authorization.

## Measurement method

`performance-1`, `performance-10`, and `performance-100` use three Kubernetes
Nodes and respectively 1, 10, and 100 managed Pods. Each Go page contains at most
five items. The actual current `HttpKubernetesInfrastructureClient` and
`K5CPlacementObservationService::refresh` run against complete unpaginated HTTP
fixtures, producing `2 * RuntimeNodes` list HTTP calls. The shared Go snapshot
then runs through the same PHP refresh/correlation/persistence path with no new
Kubernetes HTTP requests.

The output `php-measurements.json` records wall/process-CPU time, memory delta,
process peak memory and observed counts. Go acquisition has separately measured
cost and pages: `1 + ceil(RuntimeNodes / 5)`. The hypothetical repeated paginated
count is explicitly labeled; it is not a measurement of the current unpaginated
PHP client. PHP timing includes existing SQL correlation and persistence; it does
not include Go acquisition, real TLS/network latency or a real PostgreSQL server.
All resource figures are single-process developer simulations, not cluster
throughput or production sizing claims. Request sharing comes from one caller-
owned bounded snapshot; Go has no tenant policy, durable cache, or autonomous
observation authority.

An initial two-item performance page setting required 52 requests for the
100-Pod fixture. It exhausted the five-second acquisition budget after 32 Pod
pages with the configured client-go QPS=5/burst=10. That failure was retained as
a measured limit, not hidden by increasing the production deadline or rate.
The controlled successful measurements use five-item pages (2/3/21 total list
requests). Production's normative 500-item pages would require two requests for
these small fixtures; no production throughput is inferred from either setting.
