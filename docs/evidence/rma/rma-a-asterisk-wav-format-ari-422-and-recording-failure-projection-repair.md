# RMA-A Asterisk WAV Capability, ARI 422, and Failure Projection Repair

## Scope and established evidence

The prior natural-live acceptance captured a post-answer channel recording request using `format=wav` that returned HTTP 422. Asterisk had `autoload = no`; `format_wav.so` was present in the image but absent from the explicit module list. The adapter normalized the 422 as `conflict/ari_resource_conflict`, and the terminally failed start operation left its RecordingSession in `requested`.

## Bounded implementation

- Added `load = format_wav.so` to the repository-owned explicit Asterisk module configuration. `autoload = no`, existing module ordering, and the channel-recording primitive remain unchanged.
- Split ARI status handling so 409 remains `conflict/ari_resource_conflict`, while 422 is `unsupported_capability/ari_recording_format_unsupported`. JSON provider messages are retained when available.
- Ensured `CommandWorker` always resolves `RecordingSessionService` for completion/failure projection, including workers constructed without the optional dependency. Terminal operation failure therefore projects canonical RecordingSession failure metadata through the existing service.

## Validation

The Asterisk external-trunk configuration check and its mutation suite pass, including explicit WAV-load and conflicting `noload` cases. PHP syntax checks pass for changed PHP files and `git diff --check` passes. The repository image contains the Asterisk configuration, so a new immutable Asterisk image is required before runtime proof. PHP feature tests could not run in this checkout because `apps/api/vendor/bin/phpunit` is not present.

## Runtime/deployment status

No live Asterisk configuration was changed and no runtime deployment was performed in this implementation packet. The required next proof is canonical immutable image publication/deployment followed by a fresh bounded recording smoke confirming `wav` capability and the corrected 422 mapping.

## Preserved scope

RecordingSession eligibility/reconciliation, CallLeg lifecycle, NetworkPolicy, Echo fixture, bridge semantics, FreeSWITCH, storage, and unrelated configuration checks remain untouched.

Current-State-Impact: yes
Current-State Ledger Impact: UPDATED
Phase-Status Consistency Check: PASS
