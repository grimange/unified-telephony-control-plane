# RMA-A Asterisk WAV Capability, ARI 422, and Failure Projection Repair

## Scope and established evidence

The prior natural-live acceptance captured a post-answer channel recording request using `format=wav` that returned HTTP 422. Asterisk had `autoload = no`; `format_wav.so` was present in the image but absent from the explicit module list. The adapter normalized the 422 as `conflict/ari_resource_conflict`, and the terminally failed start operation left its RecordingSession in `requested`.

## Bounded implementation

- Added `load = format_wav.so` to the repository-owned explicit Asterisk module configuration. `autoload = no`, existing module ordering, and the channel-recording primitive remain unchanged.
- Split ARI status handling so 409 remains `conflict/ari_resource_conflict`, while 422 is `unsupported_capability/ari_recording_format_unsupported`. JSON provider messages are retained when available.
- Ensured `CommandWorker` always resolves `RecordingSessionService` for completion/failure projection, including workers constructed without the optional dependency. Terminal operation failure therefore projects canonical RecordingSession failure metadata through the existing service.

## Validation

The Asterisk external-trunk configuration check and its mutation suite pass, including explicit WAV-load and conflicting `noload` cases. PHP syntax checks pass for changed PHP files and `git diff --check` passes. The repository image contains the Asterisk configuration, so a new immutable Asterisk image is required before runtime proof. PHP feature tests could not run in this checkout because `apps/api/vendor/bin/phpunit` is not present.

## Publication and native-k3s deployment

The exact source commit `0d3a6d538712608985679d2b458d1c39bafd7472` was published by
Native k3s Images workflow run `33496076760` (success; job `99818474003`). The
artifact `native-k3s-image-lock-0d3a6d538712608985679d2b458d1c39bafd7472` was
promoted through the repository image-lock workflow. The deployed immutable
references are:

- API: `ghcr.io/grimange/utcp-api@sha256:8436c9d497d8707bcf09d0c2b581e70fd52477992b462ffc8140fb82dcd440ea`
- Asterisk: `ghcr.io/grimange/utcp-asterisk@sha256:a6dbe7b4d6c3afb2860f66e43fba119481009b9876ec0cf149343ea6a414972e`

`make server-image-preflight` passed and `make server-apply` converged the
native-k3s API, worker, event, migration, and Asterisk workloads. The Asterisk
runtime Pod is `asterisk-ari-64778bbbf9-2nmnc` on `utcp-dev02` and the migration
completed successfully. The deployed API source contains both
`ari_recording_format_unsupported` and the lazy `RecordingSessionService`
resolver used by the worker projection path.

## Effective runtime capability proof

Using the deployed Asterisk configuration (`/tmp/utcp-asterisk/asterisk.conf`),
the supported runtime inspection reported:

```
Module format_wav.so ... Status Running
1 modules loaded
slin16 wav16 wav16
slin wav wav
6 file formats registered.
```

This proves the repository configuration survived immutable deployment and that
the runtime recognizes `wav`. No live database, Redis, ARI, or provider state
was mutated for this proof.

The complete fresh post-repair recording smoke (new Call, pre-answer intent,
automatic start, provider recording, and stop convergence) was not repeated in
this implementation packet; it remains the next Terra natural-live acceptance.

## Preserved scope

RecordingSession eligibility/reconciliation, CallLeg lifecycle, NetworkPolicy, Echo fixture, bridge semantics, FreeSWITCH, storage, and unrelated configuration checks remain untouched.

Current-State-Impact: yes
Current-State Ledger Impact: UPDATED
Phase-Status Consistency Check: PASS
