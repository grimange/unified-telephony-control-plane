# T3-S2B Committed-Prover Media Reproof — Build Blocker

Date: 2026-08-02

Starting commit: `708f196` (`fix(t3): make committed media prover reproducible`)

Phase marker: `UTCP_PHASE=T1`

Verdict: `T3_S2B_COMMITTED_MEDIA_PROOF_INCOMPLETE`

## Summary

The committed prover image **cannot be built** from `708f196`. The Dockerfile's
own post-install verification step, `certutil -H`, always exits non-zero, so the
`RUN` layer fails and the build aborts. `libnss3-tools` installs correctly and
`certutil` is fully functional; only the verification command is wrong.

Because the image cannot be produced, neither Scenario A nor Scenario B was
executed. No proof namespace, Job, Secret, ConfigMap, or proof NetworkPolicy was
applied. No production resource was changed and no workload restarted.

Everything that could be verified without the image was verified, and all of it
passes: the six previously recorded prover defects (`A`–`F`) are correctly
addressed in the committed source, the proof overlay and TLS-trust wiring are
correct, the committed login selectors are validated against the live
application through an independent Playwright MCP natural login, and the new
`sip-dialog` unit tests pass.

## Repository Baseline

```text
HEAD             708f196 (branch main), working tree clean
UTCP_PHASE       T1
make t3-media-prover-config-check         pass
make t3-media-prover-config-check-test    pass
make media-config-check / -test           pass
make security-config-check / -test        pass
node tools/t3-media-prover/sip-dialog-test.mjs   pass
```

## Runtime Baseline

```text
kamailio      kamailio-56b99d4b57-kldt4       uid 41dfde71-…  restarts 4   Ready
rtpengine     rtpengine-74cd786966-8vhff      uid 0fbd6b20-…  restarts 2   Ready
              started 2026-08-01T22:17:53Z
              lastState terminated exitCode 139, reason Error,
              startedAt 22:08:50Z, finishedAt 22:17:40Z   (historical, PRODUCT_DEFECT-16)
asterisk      asterisk-ari-676b58b676-dzfm4   uid 6e3b5c64-…  restarts 0   Ready
              rtp show settings: Port start 10000, Port end 20000
              0 active channels, 0 active calls
secondary     asterisk-ari-b-8557bd4d76-rcjfn uid 8a904cdd-…  restarts 15  Ready
policy generations   allow-rtpengine-media 2, allow-asterisk-sip-from-kamailio 4
rtpengine sessions own/foreign       0 / 0
rtpengine ports_used / ports_free    0 / 200
database      tables 41, tenants 27, RuntimeNodes 110 (asterisk, simulator),
              pending outbox 0
redis         dbsize 41, keys sip/dialog/rtp/media 0/0/0/0
```

All required preconditions satisfied.

## Committed Image Build — FAILS

```text
make image-build-t3-media-prover
```

```text
#9 85.76 Selecting previously unselected package libnss3-tools.
#9 85.77 Preparing to unpack .../libnss3-tools_2%3a3.98-1ubuntu0.2_amd64.deb ...
#9 85.84 Setting up libnss3:amd64 (2:3.98-1ubuntu0.2) ...
#9 85.87 Setting up libnss3-tools (2:3.98-1ubuntu0.2) ...
#9 ERROR: process "/bin/sh -c apt-get update
     && apt-get install --no-install-recommends --yes libnss3-tools
     && rm -rf /var/lib/apt/lists/*
     && certutil -H >/dev/null 2>&1" did not complete successfully: exit code: 1
make: *** [Makefile:117: image-build-t3-media-prover] Error 1
```

The package installs. The failure is the verification command itself. Measured
directly in the pinned base image after installing `libnss3-tools`:

```text
certutil path         /usr/bin/certutil
certutil -H           exit=1        (prints usage; non-zero by design)
certutil -V           exit=255
certutil --version    exit=1
certutil -N -d sql:/tmp/nss --empty-password   exit=0   (functional)
```

`certutil` has no zero-exit help or version flag. `-H` is documented usage
output and returns `1`, so the committed `RUN` layer can never succeed.

Classification: `PROOF_HARNESS_DEFECT-G`. The smallest bounded correction is to
verify presence rather than help output, for example `command -v certutil` or a
throwaway `certutil -N -d sql:$(mktemp -d) --empty-password`, and to extend
`scripts/t3-media-prover/config-check` to assert the verification command is not
one that exits non-zero on success.

No image digest was produced. No alternate or diagnostic image was built, and no
scratchpad prover copy was created.

## Certutil and NSS Trust

Not verifiable inside a built image, because no image exists. Verified in the
pinned base image plus the committed layer contents:

```text
libnss3-tools installs cleanly            yes
certutil present and functional            yes (certutil -N exits 0)
Dockerfile USER 1000:1000                  present
Dockerfile ENV HOME=/home/ubuntu           present
```

Committed source verification (`tools/t3-media-prover/prover.mjs`):

```text
NSS database path   path.join(process.env.HOME || '/home/ubuntu', '.pki', 'nssdb')
--user-data-dir     absent
ignoreHTTPSErrors   absent
--ignore-certificate-errors  absent
max-bundle          absent
```

Rendered proof overlay:

```text
NODE_EXTRA_CA_CERTS  /etc/ssl/certs/utcp-local-ca.crt
volume local-ca      configMap t3-media-prover-local-ca, key ca.crt
                     -> subPath utcp-local-ca.crt, readOnly
scenario             configMapKeyRef t3-media-prover-scenario / scenario
credentials          secretRef (ephemeral, runner-created)
private key material none
```

The trust design is correct; only the build step blocks it.

## Playwright MCP Natural Login Proof

Independent evidence, run from the real login page with no injected cookies,
local storage, Redis session, or database session. Its session was **not**
passed to anything else.

```text
start                https://app.utcp.local.test/login   (Sign in - UTCP)
TLS                  https, no certificate error
input[type="email"]      1 present, visible, enabled
input[type="password"]   1 present, enabled
button[type="submit"]    1 present, enabled, text "Sign in"
input[name="email"]      0 present
submit                real form submission
after login          https://app.utcp.local.test/dashboard  (Dashboard - UTCP)
post-login selector  .app-shell, nav, [data-testid="app-shell"]  -> 2 matches
                     (.app-shell 1, nav 1, [data-testid] 0)
GET /api/v1/auth/session   HTTP 200, authenticated
user.status                active
password_change_required   false
memberships                1
active tenant              slug "local", "Local Tenant",
                           tenant_id 7be59d2a-…, membership + tenant status active
capabilities               [] (plain tenant-member; no admin capabilities)
catalog_version            present
logout                     HTTP 200
session after logout       HTTP 401
```

This directly validates the login changes in `708f196`: `input[name="email"]`
does **not** exist, so the previous name-first selector list was the source of
the hydration race, and the committed `input[type="email"]` /
`input[type="password"]` / `button[type="submit"]` selectors plus the
`.app-shell, nav, [data-testid="app-shell"]` post-login wait all resolve
correctly against the live application.

`.playwright-mcp/` was removed afterwards and is absent.

## Committed Prover Natural Login

Not executed — blocked by the image build.

## Scenario A and Scenario B

Neither scenario was selected, applied, or executed. No Job, Pod, Secret,
ConfigMap, proof NetworkPolicy, or proof namespace was created.

Running the committed runner would have been actively misleading: the local
registry still held a `utcp/t3-media-prover:0.1.0-k1-dev` tag built from the
**pre-`708f196`** source, so the Job would have silently executed the old prover
and produced a result that did not represent the committed implementation. That
tag and all node-cached copies were removed (see Cleanup).

## Committed Source Review Against Defects A–F

Verified by reading `708f196`; all six are correctly addressed in source, and
all remain unproven at runtime only because the image cannot be built.

| Defect | Committed treatment | Source state |
|---|---|---|
| `A` no `certutil` | `libnss3-tools` added to the Dockerfile | package install correct; **verification command broken — `PROOF_HARNESS_DEFECT-G`** |
| `B` `--user-data-dir` | argument removed from `chromium.launch()` | correct |
| `C` NSS path | database built at `$HOME/.pki/nssdb`, recreated per run | correct |
| `D` login race | auto-waiting `waitFor` on typed selectors plus `waitForLocatorReady`; `firstLocator`/`fillFirst`/`clickFirst` deleted | correct, and selectors independently validated above |
| `E` audio energy | read from `inbound-rtp.totalAudioEnergy`; `track` branch removed; new `audioEnergySource` field asserted in both `assertMediaCounters` and `assertStructuredResult` | correct |
| `F` in-dialog target | `createDialog()` stores `remoteTarget` from the 2xx `Contact`; ACK and BYE use it; `To` URI now distinct from the request URI; `waitForMessage` returns the matched message and advances the shared cursor | correct, covered by `sip-dialog-test.mjs` |

`tools/t3-media-prover/sip-dialog.mjs` is a pure module used only by
`sip-dialog-test.mjs`; `prover.mjs` does not import it (its browser-context copy
is inlined), so the Dockerfile's `COPY` set is correct.

## PRODUCT_DEFECT-16 Status

No scenario ran, so no new teardown evidence was produced.

```text
rtpengine uid          0fbd6b20-…   unchanged
restart count          2            unchanged across this run
container start        2026-08-01T22:17:53Z   unchanged
last termination       exitCode 139, finishedAt 2026-08-01T22:17:40Z (historical)
sessions / ports       0 / 0, ports_free 200
```

`PRODUCT_DEFECT-16` remains open and unconfirmed. This run contributes no
evidence for or against it, and no root cause is claimed.

## Provider-Neutrality Assessment

Unchanged and still enforced. `make t3-media-prover-config-check` passes, which
rejects ARI, AMI, Asterisk channel identifiers, `fs_cli`, FreeSWITCH ESL,
`hostNetwork`, `ignoreHTTPSErrors`, `--ignore-certificate-errors`, and
`max-bundle` in the prover source. The committed browser logic asserts only on
SIP dialog state, the response `Contact` remote target, the route set, ICE,
DTLS, RTP packets and bytes, `inbound-rtp.totalAudioEnergy`, BYE direction,
final SIP response, and cleanup state. No Asterisk CLI stimulus was issued in
this run.

```text
provider-neutral media contract:  previously proven against Asterisk reference runtime
runtime agnosticism:              not yet proven
```

## Containment Preservation

Rendered proof overlay contains exactly the committed resources and none of:

```text
NodePort  LoadBalancer  ExternalIP  HostPort  HostNetwork  hostPID  hostIPC
privileged  ipBlock  UDPRoute  hostPath  Deployment  Service
```

Ports are exactly DNS UDP/TCP `53`, application/WSS TCP `443`, and rtpengine
media UDP `40000-40099`. No direct prover-to-Asterisk media permission. Nothing
was applied, so live containment is trivially unchanged: no public media
exposure, no host route, no k3d media publication.

## External Media Edge Status

```text
contained in-cluster media core (committed prover):  not proven this run
external browser media reachability:                 not proven, unchanged, T3-S3
```

## State and Workload Preservation

Full-cluster Pod snapshot diff between baseline and final is **empty** — every
workload retained its UID and restart count and all are Ready.

```text
database public tables             41   ->  41
tenants                            27   ->  27
RuntimeNodes                       110  ->  110
RuntimeNode families               asterisk, simulator   unchanged
pending outbox                     0    ->  0
Redis sip/dialog/rtp/media         0/0/0/0  ->  0/0/0/0
rtpengine sessions own/foreign     0/0  ->  0/0
rtpengine ports_used / ports_free  0/200 -> 0/200
Asterisk active channels           0    ->  0
production NetworkPolicy generations   unchanged (2 and 4)
```

One proof member, membership, and telephony session were created through the
canonical API for the MCP login proof; tenant and RuntimeNode counts are
unchanged. Redis `db0` growth is ordinary session and cache activity.

## Findings

| Classification | Finding |
|---|---|
| **`PROOF_HARNESS_DEFECT-G`** | The committed prover image cannot be built. `tools/t3-media-prover/Dockerfile` verifies the `libnss3-tools` install with `certutil -H`, which exits `1` by design (usage output), failing the `RUN` layer. `libnss3-tools` installs correctly and `certutil -N` exits `0`, so only the verification command is wrong |
| `PASS` | Defects `B`, `C`, `D`, `E`, `F` are correctly addressed in the committed source and covered by `sip-dialog-test.mjs` and the extended `config-check` mutation tests |
| `PASS` | Independent Playwright MCP natural login succeeds from the real login page under normal TLS verification, with no injected browser, Redis, or database state; logout returns `200` and the session then returns `401` |
| `PASS` | The committed login selectors are validated live: `input[name="email"]` does not exist, while `input[type="email"]`, `input[type="password"]`, `button[type="submit"]`, and the `.app-shell, nav, [data-testid="app-shell"]` post-login wait all resolve |
| `PASS` | The rendered proof overlay contains only committed proof resources with exact ports, correct public-CA-only trust wiring, and no containment weakening |
| `PASS` | `node tools/t3-media-prover/sip-dialog-test.mjs` passes; all repository checks pass |
| `PASS` | No production workload restarted, no production resource changed, and all canonical state authority values are unchanged |
| `PRODUCT_DEFECT-16` | Open and unconfirmed. This run produced no new teardown evidence; rtpengine restart count, UID, and container start time are unchanged. No root cause claimed |
| `PROOF_POLICY_DEFECT` | None. No proof policy was applied, and the rendered proof policies are unchanged and correct |
| `PROOF_LIMITATION` | The committed readiness marker for Scenario B is `document.body.dataset.utcpMediaReady`, which is in-page only and emits no log line, so an external controller cannot observe it directly. Previous runs gated the stimulus on an equivalent externally observable condition (runtime-side reciprocal RTP plus a single active channel). Emitting a readiness line to stdout would make the committed contract directly observable |
| `EXPECTED_BEHAVIOR` | The pre-`708f196` prover image tag in the local registry was removed so a future run cannot silently execute stale prover code |

## Smallest Bounded Correction

One line in `tools/t3-media-prover/Dockerfile`: replace
`certutil -H >/dev/null 2>&1` with a presence check that exits zero on success,
for example `command -v certutil >/dev/null`. Optionally assert functionality
with `certutil -N -d sql:"$(mktemp -d)" --empty-password`. Add a
`scripts/t3-media-prover/config-check` guard plus mutation coverage rejecting a
verification command that exits non-zero on success, then re-run this proof.

## Cleanup

```text
proof Jobs / Pods / Secrets / ConfigMaps    none created
proof NetworkPolicies / namespace           none created
prover image tags                           none produced; the stale pre-708f196
                                            registry tag and all three node-cached
                                            copies removed
diagnostic images                           none built
.playwright-mcp/                            removed, absent
browser profiles / NSS databases / cookies  MCP state removed; no in-cluster Pod ran
structured-result scratch files             none produced
port-forwards                               none used
temporary Helm v4.0.3                       provisioned, verified, removed
credential or Authorization material        none remaining
```

Left in place, as required: the corrected production media NetworkPolicies
(generations `2` and `4`), the deployed Asterisk image carrying `rtp.conf`, and
`.runtime/tls/utcp-local-ca.crt` (public certificate only, no private key,
gitignored) which the committed runner requires.

Kamailio Ready, rtpengine Ready, Asterisk Ready, secondary runtime unchanged,
zero proof media sessions and allocations, zero active channels.

## Verification Performed

```text
git status / git log -20 / grep UTCP_PHASE versions.env
make t3-media-prover-config-check / -test        pass
make media-config-check / -test                  pass
make security-config-check / -test               pass
make repository-hygiene                          pass
make workflow-check                              pass
make secret-scan                                 pass
make k8s-config-check                            pass
make kamailio-signaling-config-check / -test     pass
make check                                       pass
make gateway-config-check                        pass (pinned Helm v4.0.3, removed)
node tools/t3-media-prover/sip-dialog-test.mjs   pass
git diff --check / git diff --cached --check     clean
make image-build-t3-media-prover                 FAIL (PROOF_HARNESS_DEFECT-G)
```

## Status

```text
PRODUCT_DEFECT-15                        = closed (unchanged)
committed prover defects B, C, D, E, F   = addressed in source, unproven at runtime
committed prover defect A                = superseded by PROOF_HARNESS_DEFECT-G
PROOF_HARNESS_DEFECT-G                   = open, blocks the whole proof
PRODUCT_DEFECT-16                        = open, unconfirmed, no new evidence
T3-S2B in-cluster WebRTC media proof     = INCOMPLETE
T3-S2C second-runtime parity             = Not Started
T3-S3 external media edge                = Not Started
T3-S2 overall                            = In Progress
T3                                       = In Progress
UTCP_PHASE=T1
```

```text
contained in-cluster media core:      not proven with the committed prover
external browser media reachability:  not proven
Asterisk:                             current reference runtime
runtime agnosticism:                  not yet proven
```

## Recommended Next Step

Bounded implementation of the one-line Dockerfile verification fix plus its
config-check guard, then re-run this proof unchanged.
