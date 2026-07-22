# UI-C8 Runtime Adapter Configuration Descriptor Contract

Date: 2026-07-22

Starting commit: `6838da1`

## Result

UI-C8 adds the missing canonical server contract for RuntimeNode adapter configuration descriptors. The Runtime Registry management catalog now publishes adapter-owned field metadata so a later UI-C task can replace the remaining handwritten Runtime Nodes form branch without moving adapter authority into the frontend.

UI-C remains `In Progress`. This task does not remove the current `asterisk-ari` frontend branch; the next bounded frontend task consumes these descriptors and removes that branch.

## Previous Missing Authority

The Runtime Registry catalog exposed adapter identity, availability, endpoint requirements, and capability metadata, but it did not describe adapter-configuration fields. The frontend therefore still carried the bounded Asterisk-specific form authority in `apps/web/src/views/RuntimeNodesView.vue`.

## Canonical Descriptor Owner

Descriptor ownership is now co-located with adapter configuration authority:

- `AdapterConfigurationHandler::configurationDescriptors()` publishes descriptors through the existing adapter-configuration dispatch seam.
- `AdapterConfigurationRegistry::descriptorsForAdapter()` resolves descriptors by registered adapter key.
- `RuntimeRegistryCatalog` serializes descriptors generically for adapters marked `adapter_configuration_available`.
- Asterisk descriptor values come from `AsteriskAriProfileService`, the existing authority for Asterisk ARI defaults and validation.
- Simulator descriptor values come from `SimulatorProfileService`, the existing authority for simulator profile defaults and validation.

Generic Runtime Registry code does not branch on adapter keys for descriptor publication.

## Descriptor Schema

Each field descriptor publishes:

- `key`
- `label`
- `help`
- `input_type`
- `required`
- `read_only`
- `write_only`
- `default`
- `order`
- `validation`, only when applicable

Supported initial field types are:

- `text`
- `integer`
- `json`

`json` is included because `simulator-deterministic` is already configuration-capable and its current canonical `parameters` field is an array payload. No password, secret, select, or boolean descriptor type is introduced in this slice because no current server adapter configuration contract requires one.

Validation hints are UI metadata only. Backend request validation remains authoritative.

## Asterisk Descriptor Matrix

The Asterisk ARI adapter publishes exactly these seven configuration fields:

| Key | Type | Required | Default | Order | Validation hints |
| --- | --- | --- | --- | --- | --- |
| `application_name` | `text` | yes | `utcp-t0-observation` | 10 | `min_length=3`, `max_length=80` |
| `connect_timeout_ms` | `integer` | yes | `2000` | 20 | `min=250`, `max=30000`, `step=1` |
| `request_timeout_ms` | `integer` | yes | `4000` | 30 | `min=250`, `max=60000`, `step=1` |
| `websocket_handshake_timeout_ms` | `integer` | yes | `4000` | 40 | `min=250`, `max=60000`, `step=1` |
| `heartbeat_interval_ms` | `integer` | yes | `15000` | 50 | `min=1000`, `max=120000`, `step=1` |
| `reconnect_min_delay_ms` | `integer` | yes | `1000` | 60 | `min=100`, `max=120000`, `step=1` |
| `reconnect_max_delay_ms` | `integer` | yes | `30000` | 70 | `min=100`, `max=300000`, `step=1` |

The defaults and numeric limits are defined once in `AsteriskAriProfileService` and reused by both backend validation/default processing and descriptor publication.

## Other Configurable Adapters

`simulator-deterministic` is currently marked `adapter_configuration_available=true`, so it now publishes descriptors for its existing profile contract:

| Key | Type | Required | Default | Order |
| --- | --- | --- | --- | --- |
| `scenario_key` | `text` | yes | `null` | 10 |
| `scenario_version` | `integer` | yes | `1` | 20 |
| `seed` | `text` | yes | `local` | 30 |
| `parameters` | `json` | yes | `[]` | 40 |

`freeswitch-esl` remains `adapter_configuration_available=false` and publishes no writable descriptor collection.

## Descriptor Integrity

The descriptor value objects fail deterministically for malformed committed descriptors, including:

- Empty keys or labels.
- Unsupported input types.
- Duplicate field keys.
- Duplicate order values.
- Non-positive order values.
- Simultaneous read-only and write-only flags.
- Write-only fields that publish defaults.
- Invalid numeric or text ranges.
- Validation hints incompatible with the declared input type.

The Runtime Registry catalog also fails if an adapter is marked configuration-available but the registered handler returns no descriptor fields.

## Catalog Response

The management catalog now adds adapter configuration metadata under each configurable adapter:

```json
{
  "adapter_configuration_available": true,
  "adapter_configuration": {
    "fields": [
      {
        "key": "application_name",
        "label": "ARI application name",
        "help": "Stasis application name subscribed by the Asterisk ARI listener.",
        "input_type": "text",
        "required": true,
        "read_only": false,
        "write_only": false,
        "default": "utcp-t0-observation",
        "order": 10,
        "validation": {
          "min_length": 3,
          "max_length": 80
        }
      }
    ]
  }
}
```

Existing catalog properties remain intact. Descriptor fields describe adapter contracts, not a specific RuntimeNode's current configuration.

## Secret Boundary

No credentials, node-specific configuration values, one-time secrets, or runtime-node readbacks are included in catalog descriptors. Write-only descriptors cannot publish defaults.

Frontend API types were extended so the catalog response can represent descriptors, but no frontend descriptor catalog or renderer was added.

## Existing Endpoint Preservation

Existing RuntimeNode adapter-configuration read and write endpoints remain the canonical state path for current node configuration. Focused regression tests cover sanitized readback, accepted payloads, defaults, invalid-value rejection, version updates, audit events, and absence of credentials in responses.

## Tests

Focused tests were added for:

- Runtime Registry catalog descriptor publication.
- Asterisk descriptor keys, order, labels, types, required/read-only/write-only flags, defaults, and validation hints.
- Configurable-adapter availability invariants.
- Descriptor validation failures.
- Descriptor/backend validation consistency.
- Existing Asterisk and simulator configuration endpoint behavior.
- Frontend TypeScript catalog contract parsing without cutting over the Runtime Nodes renderer.

## Remaining UI-C Work

- Consume canonical descriptors in the Runtime Nodes frontend.
- Replace and remove the handwritten `asterisk-ari` form branch.
- Complete remaining shared management-action adoption.
- Run the later natural browser proof for catalog-rendered RuntimeNode forms and action workflows.
