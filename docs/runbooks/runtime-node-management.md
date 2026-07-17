# RuntimeNode Management Runbook

RuntimeNode is the canonical management authority for PBX-facing runtime nodes. UI copy may use PBX-oriented labels, but the domain model, API authority, PostgreSQL records, audit history, reconciliation, and adapter registry remain RuntimeNode-based.

## Normal Management Authority

```text
web-admin UI -> canonical RuntimeNode APIs -> PostgreSQL and registered adapter services
```

The frontend loads runtime families, adapter keys, capability metadata, endpoint requirements, credential requirements, and adapter-configuration availability from the authenticated backend catalog. It must initialize capability checkboxes from the node's persisted capabilities and must not apply frontend defaults when editing an unchanged node.

Proof scripts remain automated tests:

```text
proof scripts -> same canonical RuntimeNode APIs
```

They are not a second management authority and must not add separate CLI mutation surfaces for normal lifecycle actions.

## Asterisk ARI Nodes

For `adapter_key=asterisk-ari`, the T0 capability set is limited to:

```text
event.stream
runtime.observation
```

Conference execution, conference lifecycle, conference participation, channel control, registration observation, and recording are not available for T0 Asterisk RuntimeNodes. The backend validates compatibility even if an old or malicious client submits unsupported capabilities.

ARI adapter configuration is stored in `asterisk_ari_profiles`, one profile per RuntimeNode. The profile contains application name, HTTP/WebSocket timeout settings, heartbeat interval, and reconnect delay bounds. It does not contain credentials, endpoint URLs, executable class names, scripts, or environment selectors.

Environment values may provide bounded creation defaults only. Once a node is configured, Asterisk runtime components consume the RuntimeNode profile from PostgreSQL. A missing profile is incomplete configuration and prevents automatic listener eligibility until the profile exists.

## Credential Lifecycle

Runtime credentials are write-only. API and UI responses expose only safe metadata such as credential type, label, fingerprint, timestamps, and active/retired state. Plaintext, ciphertext, authorization headers, endpoint credentials, and reveal controls are not exposed.

Credential retirement uses the existing authenticated RuntimeNode credential API. The UI requires a normal destructive-action confirmation and refreshes safe metadata after a successful retirement. The backend prevents retiring the last active credential of a type unless a future lifecycle rule explicitly permits it.

## Runtime Evidence

The runtime-evidence panel is read-only and shows desired-versus-observed state from existing PostgreSQL authorities:

- desired and observed lifecycle state
- desired and observed configuration generation
- latest observation time
- listener lease freshness without owner identity or fencing token
- connection state and safe timing metadata without epoch IDs
- reconciliation state and sanitized failure fields
- inspection success and failure timestamps

The panel does not expose raw event payloads, operation payloads, stack traces, pod names, credential material, endpoint URLs, usernames, tenant-wide aggregate data, or manual runtime controls.

## Audit History

The history panel reads append-only RuntimeNode lifecycle audit records through a scoped, paginated API. It displays timestamp, action, safe actor description, and sanitized summary only. It does not expose raw JSON request bodies by default and does not provide deletion or mutation controls.

## Verification

Use automated tests and repository checks for implementation proof:

```sh
make runtime-registry-config-check
make runtime-registry-test
make runtime-engine-config-check
make runtime-engine-test
make asterisk-ari-config-check
make asterisk-ari-test
make web-test
make web-lint
make web-typecheck
```

Natural browser acceptance is intentionally pending for a separate Playwright MCP task. Do not claim T0 complete from this runbook or from static checks alone.
