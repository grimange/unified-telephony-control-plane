# T4 media reference and FreeSWITCH playback implementation

Date: 2026-08-23

## Status

- T4A: IMPLEMENTED / TESTED
- T4B: IMPLEMENTED / TESTED
- T4C1: IMPLEMENTED / TESTED / LIVE PROVEN / FROZEN
- T4C2: IMPLEMENTED / TESTED / LIVE PROVEN / FROZEN
- T4 overall: IN PROGRESS
- `media.playback`: IMPLEMENTED / TESTED; live proof pending
- `recording`: remaining parity work
- `call.transfer`: deferred to C8 for attended-transfer and handoff semantics

T4D is not a repository phase.

## Repository contract

UTCP owns the generic opaque `utcp:media/<identifier>` syntax, canonical media
identity, playback control, and the provider-adapter resolution boundary. The
caller/application owns the logical identifier and the application/operator owns
the media content. UTCP does not own a tenant media catalog, upload flow, storage
service, synchronization product, or media administration surface.

Provider adapters resolve only syntactically valid canonical references at the
runtime boundary. Asterisk maps `utcp:media/<id>` to its sanctioned `sound:<id>`
namespace. FreeSWITCH maps it to the managed sound root
`/usr/share/freeswitch/sounds/<id>.wav`. Provider-local values are converted back
to the generic canonical reference for observations; they never become canonical
media identity. No API-container filesystem preflight is used for arbitrary
operator-provisioned media. `media_ref_unresolved` is reserved for a genuine
adapter-boundary transformation failure, while malformed canonical syntax uses
the canonical invalid-reference validation path.

The repository validation asset is `utcp:media/reference-tone`, projected into
the managed Asterisk and FreeSWITCH sound namespaces. It is validation-only and
is not an identifier allowlist. The playback implementation loads `mod_sndfile`,
subscribes to `PLAYBACK_START`/`PLAYBACK_STOP`, preserves
`call.leg.media_started`/`call.leg.media_stopped`, and activates only FreeSWITCH
`media.playback` in the existing capability catalog. Recording, transfer,
event-stream, runtime-observation, and conference capabilities remain dark.

The Asterisk config check now points at the canonical checked-in SIP fixture
component rather than the stale nonexistent local overlay path.

No live Kubernetes, browser, or provider proof was performed for this record.
