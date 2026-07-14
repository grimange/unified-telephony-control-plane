# C3 Evidence: Runtime Engine

Date: 2026-07-14

## Trusted Local TLS Certificate

Prepared files inspected:

```text
.runtime/tls/utcp-local.crt
.runtime/tls/utcp-local.key
```

Validation observed:

- certificate parses successfully;
- private key parses successfully;
- certificate public key matches the private key;
- certificate is not expired;
- certificate is not a CA certificate;
- server-auth extended key usage is present;
- certificate is issued by the local mkcert development CA and is not self-issued;
- key file mode is `0600`;
- no `.runtime/tls` file is tracked by Git;
- old local self-signed Gateway fallback artifacts were removed from `.runtime/tls`.

Prepared certificate SHA-256 fingerprint:

```text
EB:E5:3F:41:C4:C0:8C:4B:9B:47:25:B3:E1:B9:F6:00:CA:F0:EF:1B:62:4B:29:60:3A:3B:D2:38:7C:40:56:73
```

SANs observed:

```text
utcp.local.test
app.utcp.local.test
sip.utcp.local.test
events.utcp.local.test
```

Live Kubernetes Secret apply and served-certificate proof were not observed in this run because `utcp-local` was stopped and `apntalk-local` was running with `127.0.0.1:443` published. UTCP did not stop, start, delete, or mutate APNTalk.

## Runtime Engine Proof

Commands observed passing:

```sh
make runtime-engine-config-check
make runtime-engine-test
make runtime-engine-proof
```

Focused test result:

```text
6 tests, 43 assertions
```

The disposable PostgreSQL proof built the API test image, created a clean proof database, ran the runtime-engine feature suite against PostgreSQL, and cleaned the proof database.

## Proved Behavior

- committed outbox message claim, dispatch, lease takeover, duplicate delivery safety, and stale-fencing rejection;
- rolled-back outbox message absence;
- command worker success, retry, terminal failure, unsupported handler, unsupported adapter, and PostgreSQL reload behavior;
- raw event receipt duplicate recognition and conflicting duplicate rejection;
- event normalizer claim, projection update, checkpoint update, unsupported event handling, and stale-fencing rejection;
- observed-state projection and stale-observation derivation;
- reconciliation target leasing, lease takeover, blocked state, and idempotent one-operation creation per desired generation.

## Runtime Boundaries

The config check inspected for:

- no public ARI or ESL controller;
- no ARI/ESL worker process;
- no production simulator or no-op adapter;
- no live runtime client;
- no manual reconciliation or projection route;
- no local self-signed TLS fallback;
- no runtime endpoint egress;
- no high-cardinality metric labels in C3 configuration.

## Unobserved Runtime Proof

The following phase-level proof remains unobserved in this run:

- applying the prepared certificate to the live Gateway Secret;
- served certificate fingerprint comparison on `127.0.0.1:443`;
- trusted repository Chromium browser proof without HTTPS-error bypass;
- Kubernetes rollout proof for the C3 process-role Deployments;
- K3/K4 live regression proof after C3 rollout;
- Prometheus target and alert proof for live C3 roles.

These gaps keep C3 incomplete for this run.
