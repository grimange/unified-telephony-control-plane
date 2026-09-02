# RMA-C Recording Artifact Authority Canonical Publication and Deployment

Current-State-Impact: yes

## Verdict

`RMA_C_RECORDING_ARTIFACT_AUTHORITY_CANONICALLY_PUBLISHED_AND_DEPLOYED`

## Source and publication

The synchronized `main` revision was `d863b13e8865f14cd5c304e10414e97de26e17b4`.
The `Native k3s Images` workflow run `33596230864` completed successfully for
that source revision and uploaded
`native-k3s-image-lock-d863b13e8865f14cd5c304e10414e97de26e17b4` (artifact
`9833661045`). The immutable image lock was promoted by
`GH_REPO=grimange/unified-telephony-control-plane make server-image-sync`.

Published immutable images were:

| Image | Digest |
| --- | --- |
| API | `ghcr.io/grimange/utcp-api@sha256:53e370c90f9682075f33231b2562edd0a1bda949c56961e90b03efd753a9f990` |
| Asterisk | `ghcr.io/grimange/utcp-asterisk@sha256:5a7ea5819e84f82de9be1c7e2c223b87813ae6a53ecee33e5b0f80aab2972a1d` |
| Web | `ghcr.io/grimange/utcp-web@sha256:9dabbec4a55d3127db30769a461b64ff067030be4e7f0c262152d961f4239e36` |
| Gateway | `ghcr.io/grimange/utcp-gateway@sha256:7669589bb2cc3249c7867fd2ac3462896c07666797d148c7dc9ac3328a253cca` |
| FreeSWITCH | `ghcr.io/grimange/utcp-freeswitch@sha256:5d31c87b44b2a74a32d2bbd1b1c871566ad100e1830415b3ff3eb06d99e1dc44` |
| RTPengine | `ghcr.io/grimange/utcp-rtpengine@sha256:3dbfbaaf1482ddb628d8792d638365f1aa72a8959b4752d20c0724af06e2f0f4` |

## Native-k3s deployment

`make server-apply`, `make server-status`, and `make server-proof` passed on
context `default`, with `utcp-dev01` and `utcp-dev02` Ready. The canonical
`utcp-migrate` Job completed successfully using the API image and applied
`2026_09_02_120000_create_recording_artifacts_table` as migration batch 11.

The running API Pod was `api-677fdf99b-ddlsq` on `utcp-dev01`, Ready with zero
restarts, and its actual image ID exactly matched the published API digest.
The managed Asterisk Pod was `asterisk-ari-86d475d979-v8gmp` on `utcp-dev02`,
Ready with zero restarts, and its actual image ID exactly matched the published
Asterisk digest.

## PostgreSQL schema proof

Read-only PostgreSQL inspection after the canonical migration confirmed the
`recording_artifacts` columns and required non-null fields. The table has the
tenant/state, tenant/call, and tenant/call-leg indexes; restrict-on-delete
foreign keys; the `recording_session_id` unique constraint; and the PostgreSQL
state and available-metadata checks. The `call_legs.recording_ref` column is
absent (`0` matching information-schema rows). The migration source retains
the deterministic pre-drop non-null guard, and the migration completed without
raising it.

## Deployed RMA-C presence and runtime baseline

Read-only inspection inside the running API image confirmed
`RecordingArtifactId`, `RecordingArtifactService`, artifact observation
projection, Asterisk format/duration normalization, and nested
`RecordingSessionResource` artifact projection. The deployed
`freeswitch-esl` capability list excludes `recording`; the `recording`
capability remains present on the managed Asterisk RuntimeNode.

RuntimeNode `102d58ba-93ec-4601-a2a3-81f95801440f` remained `active` / `ready`,
with configuration version `33` equal to observed configuration version `33`.
Its desired execution image was the published Asterisk immutable reference and
its observed execution image was the matching digest-only identity. Its
capabilities include `recording`.

## Validation and proof boundary

The authoritative API suite passed with `701 passed, 9 skipped, 5572
assertions`. `make phase-status-consistency-check`,
`make phase-status-consistency-check-test`, `make repository-hygiene`,
`git diff --check`, `make server-config-check`, `make server-image-preflight`,
`make server-apply`, `make server-status`, and `make server-proof` passed.

This record proves canonical publication, image-lock promotion, native-k3s
deployment, migration/schema authority, and runtime baseline preservation. It
does not perform the natural-live artifact transaction. The remaining proof is
`RMA_C_RECORDING_ARTIFACT_AUTHORITY_FOCUSED_DEPLOYED_NATURAL_LIVE_ACCEPTANCE`.
RMA-D and RMA-E remain untouched.
