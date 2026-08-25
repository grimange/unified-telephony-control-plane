# V1 Managed Asterisk Required-Module Load Contract Defect

## Status

`V1_ASTERISK_REQUIRED_MODULES_LOAD_CONFIGURATION_DEFECT_IDENTIFIED`

Both module binaries are present in the managed image. The repository-owned
module-loader configuration uses `autoload = no` with an explicit allowlist that
omits both modules the repository's own outbound dialplan requires.

Evidence only. No code changed, no image rebuilt, no module loaded, no Pod
mutated or restarted, no `modules.conf` edited.

## Live Asterisk module state

RuntimeNode `rnp6-readiness-reproof-20260809`
(`c7e6f4ba-b925-462f-aff4-71c9fa9a4157`), Pod
`asterisk-rnp6-readiness-reproof-20260809-e2fb39c7-6b677b8fpc7d7`,
image `utcp/asterisk-ari@sha256:d503406106199c8f...`, started 2026-08-25T13:23:40Z.

```text
module show like app_dial                -> 0 modules loaded
module show like res_pjsip_header_funcs  -> 0 modules loaded
core show application Dial               -> "Your application(s) is (are) not registered"
core show function PJSIP_HEADER          -> "No function by that name registered."
```

Classification for both: **not loaded** (never attempted), therefore **not
registered**.

## Module filesystem state

Active module directory from the running configuration
(`/tmp/utcp-asterisk/asterisk.conf`): `astmoddir => /usr/lib/asterisk/modules`.

```text
/usr/lib/asterisk/modules                       326 modules present
/usr/lib/asterisk/modules/app_dial.so           PRESENT  85808 bytes
/usr/lib/asterisk/modules/res_pjsip_header_funcs.so  PRESENT  44832 bytes
```

This is **Case B**: binaries present, not loaded. Not an image/build omission.

## modules.conf authority

Repository source: `infrastructure/docker/asterisk/config/modules.conf`
(identical to the live effective `/tmp/utcp-asterisk/modules.conf`).

```text
[modules]
autoload = no
load = res_http_websocket.so
... 35 explicit load directives (ARI, Stasis, PJSIP core, bridge, codec,
    format_pcm, pbx_config, chan_pjsip, app_stasis, app_originate, app_echo) ...
```

```text
autoload                      no
app_dial occurrences          0
res_pjsip_header_funcs occ.   0
noload / require / preload    none present
```

Neither module is excluded by a `noload` directive. Both are excluded **by
omission** from the explicit allowlist that `autoload = no` makes authoritative.

## Startup load evidence

```text
loader.c: load_modules: 51 modules will be loaded.
Asterisk Ready.
module show -> 51 modules loaded        (326 available on disk)
```

Log grep for `app_dial`, `res_pjsip_header_funcs`, `undefined symbol`,
`declined`, `failed to load`, `cannot load`, `noload`: **no matches**. Asterisk
never attempted to load either module, so this is not a dependency or
startup-failure defect.

## The contract mismatch, inside one image

`infrastructure/docker/asterisk/config/extensions.conf` (the repository-owned
outbound execution contract) requires both primitives:

```text
 9: same => n,Set(PJSIP_HEADER(add,X-UTCP-Call-Leg-ID)=${UTCP_CALL_LEG_ID})
10: same => n,Set(PJSIP_HEADER(add,X-UTCP-Route-Decision-ID)=${UTCP_ROUTE_DECISION_ID})
11: same => n,Set(PJSIP_HEADER(add,X-UTCP-Trunk-Endpoint-ID)=${UTCP_TRUNK_ENDPOINT_ID})
12: same => n,Set(PJSIP_HEADER(add,X-UTCP-Caller-Identity-ID)=${UTCP_CALLER_IDENTITY_ID})
13: same => n,Dial(PJSIP/${EXTEN}@kamailio-edge,30)
```

`extensions.conf` was updated for the outbound contract (mtime 2026-08-25 20:19)
while `modules.conf` was not (mtime 2026-07-29 09:39). The dialplan gained two
new module dependencies that were never added to the allowlist.

Live consequence on the natural call:

```text
ERROR   pbx_functions.c: ast_func_write: Function PJSIP_HEADER not registered   (x4)
WARNING pbx.c: pbx_extension_helper: No application 'Dial' for extension (utcp-outbound, 97001, 6)
WARNING pbx.c: pbx_extension_helper: No application 'Dial' for extension (utcp-outbound, h, 6)
```

The channel entered `utcp-outbound` correctly and reached priority 6, confirming
the execution-contract freshness repair is genuinely closed. Kamailio received
nothing (`method=INVITE` log lines: 0).

## K1 Asterisk comparison

Pod `asterisk-ari-66b484bc85-scpsw`, image
`utcp/asterisk-ari@sha256:48caf914dfe69e719d...` (a different, newer digest):

```text
core show application Dial       -> not registered
core show function PJSIP_HEADER  -> not registered
app_dial.so on disk              -> PRESENT
module show                      -> 51 modules loaded
```

Both digests behave identically, so the defect is image-lineage-wide and driven
by the shared `modules.conf`, not by managed-RuntimeNode-specific configuration.

## Runtime readiness gap

Yes — a runtime reports `observed_state=ready` with `cv == ocv` while `Dial` and
`PJSIP_HEADER` are unavailable.

The seam is `infrastructure/docker/asterisk/readiness`, wired as both
`startupProbe` and `readinessProbe` (`/usr/local/bin/utcp-asterisk-readiness`) by
`ManagedAsteriskProvisioningOperationHandler`. It asserts:

```text
asterisk binary / config readable, modules.conf readable
core waitfullybooted
ARI HTTP 401
every module listed as `load =` in modules.conf is Running
Local channel type present
pjsip transport transport-udp-internal on 0.0.0.0:5060
```

The module check is **self-referential**: it validates the allowlist against
itself, so a module omitted from the allowlist is invisible to it. Nothing
asserts the applications and functions the repository-owned dialplan actually
calls, so readiness passes and the RuntimeNode converges.

No repository check anywhere asserts `Dial` or `PJSIP_HEADER` registration:
`grep -rn "PJSIP_HEADER|app_dial|core show application|core show function"` over
`scripts/` and `apps/api/tests` returns only
`scripts/asterisk-conference/config-check:38`, which merely requires that the
readiness script queries `module show like`.

## Registration stability

```text
observation        registered
observed_health    ready
last_success_at    2026-08-25 13:41:11+00
routingEligibility outbound {"eligible":true,"code":"external_trunk_eligible"}
```

Not interacted with.
