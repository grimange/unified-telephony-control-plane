# T3-S2B Committed Media Prover Correction

Date: 2026-08-02

Starting commit: `d4218d5` (`docs(t3): record reciprocal media corridor proof`)

Phase marker: `UTCP_PHASE=T1`

## Status

`PRODUCT_DEFECT-15 = closed`.

Committed prover defects A-F are corrected in the repository. T3-S2B is ready
for final committed-prover Scenario A and Scenario B reproof. T3 remains
In Progress. T3-S2C and T3-S3 remain Not Started.

## Corrections

- The pinned Playwright image installs `libnss3-tools` during image build and
  performs a non-mutating `certutil -H` presence check.
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

## Coverage

Static and mutation checks cover NSS tooling and path, certificate and launch
security, natural-login hydration, inbound audio assertions, SIP remote-target
and route-set behavior, CSeq handling, scenario reachability, Job collection,
cleanup, and media containment. Focused Node tests cover response Contact
parsing, ACK/BYE target selection, route-set order, tags, Call-ID, CSeq, and
missing or malformed Contact handling.

No Kubernetes resources were applied and neither committed scenario was run.

## PRODUCT_DEFECT-16

`PRODUCT_DEFECT-16` remains open. It was observed twice during abandoned
half-open sessions, was not reproduced across four clean diagnostic cycles,
and has no established root cause. The next live proof must record rtpengine
Pod UID and restart count around each clean committed scenario. No workaround
or restart behavior was added here.
