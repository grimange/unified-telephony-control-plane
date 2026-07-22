# UI-D3 — Migration Broadcast Driver Fix

Verdict: `UI_D_MIGRATION_BROADCAST_DRIVER_FIX_COMPLETE`

Starting commit: `355944f` (`docs(ui): record blocked runtime node realtime proof`).
Phase marker: `UTCP_PHASE=T1` unchanged.

This is a repository-only Kubernetes configuration correction. The UI-D2 natural browser proof was not
performed.

## Failed Deployment Symptom

The UI-D2 proof attempt against `79a8be6` stopped during `make k8s-apply` before platform workloads rolled
out. The deployment created the `utcp-migrate` Job, then `php artisan migrate --force` booted Laravel with:

```text
BROADCAST_CONNECTION=reverb
```

The migration Job did not receive Reverb application credentials, so broadcaster construction failed with the
Pusher application key absent:

```text
Failed to create broadcaster for connection "reverb" ...
Pusher\Pusher::__construct(): Argument #1 ($auth_key) must be of type string, null given
```

## Root Cause

UI-D1 correctly made the API, worker, outbox dispatcher, and Reverb runtime use Reverb. It also changed the
local migration overlay to Reverb even though the migration Job:

- runs only the `migrate` entrypoint role;
- executes only `php artisan migrate --force`;
- does not publish RuntimeNode events;
- does not run the queue worker;
- does not run the outbox dispatcher.

That made an otherwise non-publishing workload depend on credentials it should not hold.

## Correction

`infrastructure/kubernetes/overlays/local/migration/application-config.properties` now restores the
credential-free Laravel log broadcaster for the migration workload:

```text
BROADCAST_CONNECTION=log
```

The migration overlay no longer carries Reverb server, host, port, scheme, scaling, or origin properties.
The migration Job still imports only:

```text
utcp-application-config
utcp-local-data-credentials
utcp-local-kamailio-db-credentials
```

It does not reference `utcp-local-reverb-credentials`, and it receives no `REVERB_APP_ID`, `REVERB_APP_KEY`,
or `REVERB_APP_SECRET`.

## Platform Publisher Preservation

The publishing workloads remain unchanged:

```text
api
worker
control-plane-outbox-dispatcher
```

Their rendered platform overlay still uses:

```text
BROADCAST_CONNECTION=reverb
REVERB_HOST=reverb.utcp-platform.svc.cluster.local
REVERB_PORT=8080
```

and each still references `utcp-local-reverb-credentials`. The Reverb Deployment also still references the
same credentials.

## Port and Routing Preservation

Reverb remains internally exposed only as a ClusterIP service on port `8080`. Public browser WSS authority
remains `app.utcp.local.test` on standard HTTPS/TLS port `443` through the existing gateway `/app/` proxy.
No NodePort, second hostname, or second external port was added.

## Regression Coverage

`apps/api/tests/Feature/Platform/ReverbRealtimeInfrastructureTest.php` now renders the local migration and
platform overlays and proves:

- migration uses `BROADCAST_CONNECTION=log`;
- migration does not reference the Reverb Secret;
- migration does not receive Reverb application credentials;
- migration still receives database and migration configuration;
- API, worker, and outbox dispatcher retain Reverb broadcasting and credentials;
- Reverb retains credentials and ClusterIP service port `8080`;
- `BROADCAST_CONNECTION=reverb` with a truly absent Reverb key is an invalid boot configuration.

`scripts/kubernetes/config-check` now renders the migration overlay directly and rejects a migration workload
that uses Reverb or receives Reverb application credentials.

## Remaining Live Proof

The only remaining proof gap is to re-run the existing UI-D2 natural Playwright MCP live proof from the
corrected commit.
