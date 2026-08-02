# T3-S2C FreeSWITCH Parity Overlay Composition Correction

Starting commit: `b03b377` (`docs(t3): record freeswitch parity overlay blocker`).

## PRODUCT_DEFECT-21

The parity overlay directly included `../../base/platform` and
`../../base/runtime`. That bypassed the canonical `overlays/local` image
transformations and ConfigMap/Secret generators. Its render therefore omitted
ten canonical local resources and reverted platform images to unresolved base
names such as `utcp-api`, `utcp-kamailio`, `utcp-rtpengine`, and
`utcp-asterisk-ari`.

The overlay now inherits `../local` and adds the FreeSWITCH component and its
bounded selection patches. The composition is now:

```text
base platform/runtime -> overlays/local -> overlays/local-freeswitch delta
```

No local image mapping, generator, or unrelated runtime/platform definition is
copied into the parity overlay.

## Rendered contract

The canonical local render contains 43 resources. The parity render contains
those same 43 resources plus only:

* `Deployment/utcp-runtime/freeswitch`;
* `Service/utcp-runtime/freeswitch-sip`;
* `Secret/utcp-runtime/utcp-local-freeswitch-esl-credentials`.

The only changed canonical resources are `Deployment/utcp-runtime/asterisk-ari`
(`spec.replicas: 1 -> 0`) and `Service/utcp-runtime/application-runtime-sip`
(the selector narrows to the selected FreeSWITCH workload). The default local
overlay continues selecting the Asterisk runtime.

The committed render validator compares resources by API version, kind,
namespace, and name. It rejects removed canonical resources, unexpected added
resources, any unlisted field drift, unresolved bare `utcp-*` images, missing
generated references, generator output drift, dual runtime selection, public
FreeSWITCH Service exposure, and provider-specific Kamailio relay changes.
It compares complete rendered container and init-container image fields, not
only Kustomize `images` blocks.

The validator also preserves the canonical generated ConfigMaps and Secrets
without printing secret values. FreeSWITCH adds only its local ESL credential
Secret. The existing selected-runtime Service remains ClusterIP-only and the
generic Kamailio relay continues to target `application-runtime-sip`.

## Coverage and proof boundary

`make freeswitch-overlay-check` performs the offline local/parity render,
image-resolution, generated-resource reference, runtime-selection, default
Asterisk, and bounded-delta checks. `scripts/freeswitch/overlay-check-test`
covers direct base re-inclusion, removed inheritance, image reversion, missing
generators and references, unrelated workload drift, selection/fallback drift,
FreeSWITCH resource removal, public SIP exposure, and generic relay drift.

The full repository checks and the existing FreeSWITCH startup smoke test are
required; no Kubernetes resource was applied and no Scenario A or Scenario B
was run. A non-applying `kubectl diff` remains the final repository/runtime
comparison before the focused live parity proof.

Status:

```text
PRODUCT_DEFECT-17 = closed
PRODUCT_DEFECT-18 = closed
PRODUCT_DEFECT-19 = closed
PRODUCT_DEFECT-20 = closed
PRODUCT_DEFECT-21 = corrected

FreeSWITCH parity overlay = safe and ready for live application
T3-S2C = ready for focused live parity proof
T3-S2 overall = In Progress
T3-S3 = Not Started
T3 = In Progress
UTCP_PHASE=T1
```
