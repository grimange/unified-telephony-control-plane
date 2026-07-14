# Local Trusted TLS Runbook

## Scope

Local Gateway TLS uses the operator-prepared certificate and key under ignored runtime storage:

```text
.runtime/tls/utcp-local.crt
.runtime/tls/utcp-local.key
```

The repository does not install a CA, generate a replacement CA, mutate `/etc/hosts`, or commit certificate/private-key material.

## Apply

Use:

```sh
make gateway-tls-apply
```

The target:

1. Locates the prepared certificate and key.
2. Validates parsing, expiry, key match, CA=false, server-auth EKU when present, file permissions, required SANs, and Git tracking absence.
3. Applies `traefik-system/utcp-local-gateway-tls`.
4. Waits for Traefik and Gateway readiness.
5. Compares the served certificate fingerprint with the prepared certificate.
6. Runs normal Gateway proof.

`make gateway-tls` delegates to the same apply path.

## Required Current SANs

```text
app.utcp.local.test
utcp.local.test
```

Future SANs may include:

```text
sip.utcp.local.test
events.utcp.local.test
```

Do not activate `sip.utcp.local.test` or `events.utcp.local.test` until their implementation phases.

## Runtime Authority Cutoff

The old repository-generated self-signed Gateway leaf path is retired for local runtime use. Local scripts do not silently fall back to generated self-signed material.

CI may create isolated CI-only certificate material under CI-owned temporary storage. That behavior is separate from the operator-prepared local certificate.

## Browser Proof

Use repository Playwright in a fresh Chromium context without `ignoreHTTPSErrors: true`. If Chromium uses a separate trust store and does not trust the host-installed development CA, record that limitation instead of bypassing certificate errors.

## Troubleshooting

- Missing certificate or key: obtain the operator-prepared leaf certificate and key before applying.
- SAN mismatch: renew the prepared leaf certificate; do not generate a fallback certificate silently.
- Fingerprint mismatch after apply: wait for Traefik reload, then roll Traefik only when evidence shows the Secret did not reload.
- Port `443` conflict: stop before mutation and report the listener. UTCP scripts must not stop APNTalk or another cluster automatically.
