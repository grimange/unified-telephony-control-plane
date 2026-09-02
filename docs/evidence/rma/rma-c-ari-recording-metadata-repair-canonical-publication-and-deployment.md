# RMA-C ARI Recording Metadata Repair — Canonical Publication and Deployment

Current-State-Impact: yes

## Verdict

`RMA_C_ARI_OBJECT_SANITIZER_RECORDING_METADATA_REPAIR_CANONICALLY_PUBLISHED_AND_DEPLOYED`

Date: 2026-09-02

## Scope

The RMA-C natural-live blocker was bounded to `AsteriskAriClient::sanitizeAriObject()` stripping the provider's `format` and optional integer `duration` fields before the existing listener and normalizer could consume them. The repaired source preserves `format` as a bounded string and `duration` only as an integer. No artifact lifecycle, schema, observation, capture, or provider execution behavior changed in this deployment packet.

## Publication

- Application source: `dc6ae91b9edadada9f0c321489d8b81c75f5b90f`
- Publication workflow: `Native k3s Images`, run `33599966526`, successful
- Image-lock artifact: `native-k3s-image-lock-dc6ae91b9edadada9f0c321489d8b81c75f5b90f`, artifact ID `9834952009`
- API digest: `sha256:3729d93f448a87084d9057504a25040c19cd7e31cf0c13b5eaaf52fe3a98a538`
- Asterisk digest: `sha256:cf9d0303513756c7e878175e54ae9262506eab2a0f9f80b5987968952b8530ac`

The commit-scoped image lock was promoted through the canonical `server-image-sync` target and deployed through the native-k3s `server-apply`, `server-status`, and `server-proof` targets. The actual Ready API and all relevant API-derived event-processing workloads run the published API digest. The current managed Asterisk workload runs the published Asterisk digest.

## Runtime and schema preservation

Native k3s rollout converged. RuntimeNode `102d58ba-93ec-4601-a2a3-81f95801440f` is active and ready, with configuration and execution image current and the `recording` capability present. The existing RMA-C migration remains applied; `recording_artifacts` exists with its approved constraints and indexes, and `call_legs.recording_ref` remains absent. FreeSWITCH remains without the `recording` capability. No RMA-D/E archive or storage authority was added.

## Remaining proof

The corrected revision is deployed, but the focused natural-live RMA-C reproof remains pending. That next proof must establish that preserved ARI metadata reaches `media_format`, allowing the existing pending artifact to finalize exactly once to available. RMA-C is not marked complete.
