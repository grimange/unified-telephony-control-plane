# T3-S2C FreeSWITCH Runtime Startup Correction

Starting commit: `fe1746e` (`docs(t3): record freeswitch parity runtime blocker`).

This bounded repository correction closes the four startup defects that blocked
the committed FreeSWITCH parity runtime:

* `PRODUCT_DEFECT-17`: the entrypoint treated `-c` as if it accepted a file.
  It now uses argument-less `-c`, with `-conf /etc/freeswitch`,
  `-log /var/log/freeswitch`, `-db /var/lib/freeswitch/db`, and
  `-run /var/run/freeswitch`, all under one `exec` process.
* `PRODUCT_DEFECT-18`: the invented XML root was replaced by the normal
  `<document type="freeswitch/xml">` document with configuration, dialplan,
  chatplan, directory, and languages sections.
* `PRODUCT_DEFECT-19`: pod-level UID/GID/fsGroup `1000` and three explicit
  writable `emptyDir` mounts cover the log, run, and database paths. The
  entrypoint checks writability before starting FreeSWITCH.
* `PRODUCT_DEFECT-20`: TCP probes were replaced by one loopback-only
  `fs_cli` health executable that checks core status and the
  `utcp-internal` Sofia profile in `RUNNING` state.

The configuration tree contains only the required console, commands, XML
dialplan, dialplan applications, Sofia, and local Event Socket modules. Sofia
binds UDP 5060 with PCMU and the `utcp` context. The `9900` fixture answers and
echoes media. Core RTP is explicitly configured as UDP `21000-21099`, which
does not overlap Asterisk `10000-20000` or rtpengine `40000-40099`.

Event Socket listens on `127.0.0.1:8021` only and receives its password through
the existing local overlay Secret convention. No Service, NetworkPolicy, host
port, or public surface exposes it. The generic Kamailio and rtpengine routes,
runtime selection authority, Asterisk adapter, and production policies are
unchanged.

## Image-level startup proof

`DOCKER_CONFIG=/tmp/utcp-docker-config make freeswitch-startup-smoke-test`
rebuilt the pinned image and ran the exact committed entrypoint as UID/GID
`1000:1000` with read-only root storage and temporary writable mounts. The
smoke test passed:

* required modules loaded;
* `utcp-internal` reported `RUNNING` on UDP 5060;
* the explicit `21000-21099` RTP configuration and `9900` dialplan were
  present in the running image;
* all three runtime directories were writable;
* the executable healthcheck succeeded;
* SIGTERM produced bounded clean termination.

The final local image build produced config digest
`sha256:f2af31899e99a9bf8e8647041c794f70e25659ae8d3457f52b78b377d1200ff5`.
The image was not pushed or deployed. Buildkit's local manifest-list digest is
not used as evidence because it changes with each local attestation export.

Static and mutation checks cover the official command flags, XML root and
sections, required modules, Sofia and dialplan contracts, explicit RTP range,
UID/GID and writable mounts, loopback Event Socket, executable probes, public
surface containment, and preservation of Asterisk fallback prohibition. No
Kubernetes resource was applied and Scenario A/B were not run.

`PRODUCT_DEFECT-16` remains historical, open, and unconfirmed in this
repository-only correction. T3-S2C is ready for focused live parity proof;
T3-S2 overall and T3 remain In Progress, T3-S3 is Not Started, and
`UTCP_PHASE=T1` is unchanged.
