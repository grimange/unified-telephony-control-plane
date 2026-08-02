# T3-S2B Committed Media Prover Correction

Date: 2026-08-02

Starting commit: `b9f240b` (`docs(t3): record committed prover build blocker`)

Phase marker: `UTCP_PHASE=T1`

## Status

`PRODUCT_DEFECT-15 = closed`.

`PROOF_HARNESS_DEFECT-G = corrected`: the committed image build is proven.
The six committed prover defects A-F remain corrected in the repository.
T3-S2B is ready for final committed-prover Scenario A and Scenario B reproof.
T3 remains In Progress. T3-S2C and T3-S3 remain Not Started.

## Corrections

- In the pinned NSS utility, `certutil -H` is the usage/options-listing
  command; the observed exit status is `1` even though it prints usage. It is
  therefore not a successful Docker build assertion. `certutil -N` is the
  functional database-initialization command.
- The pinned Playwright image installs `libnss3-tools` during image build,
  verifies `certutil` through `command -v`, and functionally initializes a
  temporary SQLite NSS database with `certutil -N -d sql:... --empty-password`,
  asserting `cert9.db` and `key4.db` before deleting the temporary directory.
- The prover initializes only `$HOME/.pki/nssdb` as UID/GID `1000`, imports
  only the public local CA, and removes the NSS database during cleanup.
- Chromium uses a normal ephemeral context without `--user-data-dir` or any
  TLS verification bypass.
- Natural login auto-waits for the actual email, password, and submit controls,
  then waits for authenticated navigation and the application shell.
- Audio proof reads `inbound-rtp.totalAudioEnergy`, requires positive and
  increasing energy, and records `audioEnergySource` as
  `inbound-rtp.totalAudioEnergy` alongside packet, byte, jitter, and loss data.
- The successful INVITE response `Contact` is stored as the dialog remote
  target. ACK uses that target with the original INVITE CSeq; BYE uses it with
  the next local CSeq. Record-Route, Call-ID, tags, and Contact failure cases
  are protected by deterministic SIP-dialog unit tests.
- Scenario B emits exactly one non-sensitive
  `UTCP_T3_MEDIA_PROVER_READY_FOR_RUNTIME_HANGUP` stdout marker only after
  login, dialog, ICE/DTLS, RTP, inbound audio-energy, and active-dialog
  readiness. Scenario A cannot emit it.

## Coverage

Static and mutation checks cover executable and functional NSS build checks,
temporary SQL database cleanup, certificate and launch security, natural-login
hydration, inbound audio assertions, SIP remote-target and route-set behavior,
CSeq handling, scenario reachability, Job collection, cleanup, readiness
ordering and sensitivity, and media containment. Focused Node tests cover
response Contact parsing, ACK/BYE target selection, route-set order, tags,
Call-ID, CSeq, and missing or malformed Contact handling.

`make image-build-t3-media-prover` completed successfully after the correction.
The built local image `utcp-t3-media-prover:dev` produced image ID and digest
`sha256:52986c602d69cf5b20d45b9cc2abeea376596c893af51807bd5a502ce5061f69`.
No Kubernetes resources were applied and neither committed scenario was run.

## PRODUCT_DEFECT-16

`PRODUCT_DEFECT-16` remains open. It was observed twice during abandoned
half-open sessions, was not reproduced across four clean diagnostic cycles,
and has no established root cause. The next live proof must record rtpengine
Pod UID and restart count around each clean committed scenario. No workaround
or restart behavior was added here.
