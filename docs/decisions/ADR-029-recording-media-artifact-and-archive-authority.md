# ADR-029 — Recording, Media Artifact, and Archive Authority

## Status

Accepted as a planned UTCP core capability and R0-critical roadmap track. This
ADR defines a future boundary only; it does not claim Recording & Media Archive
implementation or live proof.

## Context

Recording and media-artifact lifecycle are reusable telephony mechanisms that
multiple application types can consume. They are not campaign, dialer, CRM,
contact-center, or legal/compliance workflow. The consuming application decides
why recording is requested; UTCP coordinates the reusable technical lifecycle.

RMA begins only after the established V1 Call/CallLeg corridor and K5E
distributed infrastructure proof. This sequencing avoids designing recording
around one host, one runtime, or one local filesystem before host visibility,
RuntimeNode-to-host correlation, placement and failure-domain awareness,
telephony-aware draining, maintenance coordination, and distributed
restoration are established.

## Decision

The authority boundaries are:

| Concern | Authority |
| --- | --- |
| Business reason, customer workflow, business consent meaning, jurisdiction/business interpretation, and application disposition | Consuming application / product |
| Authorized technical recording intent, tenant technical policy, recording lifecycle, Call/CallLeg/Conference correlation, capability selection, observations, artifact metadata, archive target desired state, credential references, archive transfer lifecycle, basic technical retention/deletion, authorization, and audit | UTCP and PostgreSQL for canonical durable metadata |
| Instantaneous media capture, runtime-specific recording commands, and media generation mechanics | Telephony/media execution: Asterisk, FreeSWITCH, rtpengine, or a future recording/media adapter |
| Durable recording bytes and bucket/container mechanics | Object storage |
| Workload placement and infrastructure resources | Kubernetes |

UTCP does not choose a single canonical capture engine in this ADR. Provider
behavior remains behind runtime-neutral adapter contracts.

## Conceptual entities and lifecycle separation

Future design may use these conceptual names: `RecordingPolicy`,
`RecordingSession`, `RecordingArtifact`, `MediaArchiveTarget`,
`MediaArchiveCredentialReference`, `ArchiveTransfer`, and
`RecordingObservation`. They are architectural concepts, not implemented
schema commitments.

`RecordingSession` is not `RecordingArtifact`. Artifact metadata is not media
bytes. Capture lifecycle is not archive-transfer lifecycle. A completed
recording must be able to have an archive transfer that is pending, retrying, or
failed; for example, `recording = completed` and `archive = retrying` is valid.

PostgreSQL owns canonical recording, artifact, and archive metadata. It should
not store large recording binaries as the normal architecture, and the UTCP
API/Laravel layer is not assumed to proxy the full byte stream as the canonical
archive path. The conceptual direction is:

```text
runtime/capture executor -> media artifact -> archive executor -> object storage
```

UTCP orchestrates and records lifecycle/evidence; it need not become the media
data plane.

## Archive target and storage direction

`MediaArchiveTarget` is the provider-neutral future archive configuration
authority. Conceptual configuration may include a name, driver/provider class,
endpoint, region, bucket/container, prefix, credential reference, encryption
policy, retention policy, desired state, and observed health. Exact database
fields are intentionally deferred and AWS-specific identifiers are not
canonical.

The preferred first adapter direction is S3-compatible object storage, with
MinIO as the deterministic local proof target. This can later cover AWS S3,
MinIO, Cloudflare R2, Wasabi, Backblaze B2, DigitalOcean Spaces, and other
compatible providers. Those providers are not claimed as tested or supported
today. Google Cloud Storage and Azure Blob remain possible future adapters.

BYO storage credentials are a first-class direction. Tenants/operators may
eventually configure a supported destination and credential reference through
the normal authenticated UTCP management authority. Credential values are
write-only/versioned secrets and must not appear in normal APIs, UI, logs,
audit, events, or evidence. Safe metadata may include configured state,
credential version, rotation time, and expiry. Tenant-managed targets must not
require environment-variable credentials or manual secret-file workflows.

The intended rotation direction is:

```text
new credential -> validate through canonical target lifecycle
-> new archive work uses new version
-> in-flight work completes/retries safely -> old version retires
```

No manual Pod restart, normal CLI activation, or environment feature gate is
part of the intended lifecycle. Exact rotation schema and implementation remain
future RMA work.

## Retention, consent, and permissions boundaries

UTCP may own basic technical retention such as indefinite retention or retain
until a configured date/period, with an auditable and authorized conceptual
direction such as `AVAILABLE -> RETENTION_EXPIRED -> DELETING -> DELETED`.
UTCP does not determine every legal retention requirement.

Applications and organizations own business consent determination, jurisdiction
and business policy, customer notice, agent notification, and campaign-specific
consent logic. UTCP may enforce an already-configured authorized technical
recording policy, but a Call does not by itself establish that recording is
legally permitted. Legal hold, e-discovery, litigation preservation, PCI/HIPAA
workflow, jurisdiction-specific automation, and regulatory exports remain
separate future domains.

Recording media is more sensitive than ordinary Call metadata. Future
capabilities should be independently authorized rather than inheriting
`telephony.calls.view` automatically. Illustrative planned capability families
include `recordings.view`, `recordings.play`, `recordings.download`,
`recordings.delete`, `recordings.manage`, `media.archive_targets.view`, and
`media.archive_targets.manage`; no permission entries are implemented by this
ADR.

## Planned RMA slices and proof

The planned slices are:

* RMA-A — Recording Authority and Lifecycle
* RMA-B — Runtime-Neutral Capture Contract
* RMA-C — Recording Artifact Authority
* RMA-D — Archive Target and Secret-Reference Authority
* RMA-E — S3-Compatible Archive Adapter and Deterministic MinIO Proof
* RMA-F — BYO Storage Credentials and Rotation
* RMA-G — Retention and Deletion Lifecycle
* RMA-H — Distributed Recording and Archive Natural Live Proof

RMA-H must prove distributed behavior without assuming one host or local
filesystem. None of these slices claims schemas, migrations, APIs, workers,
adapters, MinIO deployment, or runtime support today.

## Relationship to other architecture

ADR-024 remains authoritative for Kubernetes Nodes, scheduling, capacity facts,
and Pod placement, while UTCP owns telephony interpretation and RuntimeNode
lifecycle. RMA follows K5E so future recording/archive work can use those
established distributed facts; ADR-024 does not define RMA contracts.

ADR-026 remains the future Media Processing Plane for streaming DSP,
transcription, synthesis, and AI processing, and remains post-R0 rather than an
R0 gate. RMA owns recording artifact and archive lifecycle. A Recording
Artifact may later be consumed by transcription, offline enhancement,
analytics, or AI processing, but recording does not make those implementations
part of R0. ADR-026's streaming-first rule remains: a recording file is an
artifact, not the foundation of live media processing.

## Non-goals

This ADR does not implement production recording, playback, a presigned
download API, an object-storage product, AWS/GCS/Azure integration, MinIO
deployment, legal compliance automation, or any application/runtime/Kubernetes
resource.
