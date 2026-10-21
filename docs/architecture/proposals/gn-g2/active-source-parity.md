# Active provider projections: source parity and migration boundaries

Status: source-backed mapping for the isolated GN-G2 implementation. This is
neither an adopted architecture decision nor a production writer transfer.
The [v3 contract](contracts/v3/README.md) supplements v1/v2; it does not reinterpret
inert creation as activation. The implementation report records executed proof:
[active-runtime report](../UTCP_GN_G2_ACTIVE_RUNTIME_PROJECTION_PROFILES_AND_FIVE_CORRIDOR_PARITY.md).

## Sources and comparison method

The canonical PHP projection producers are
[ManagedAsteriskProvisioningOperationHandler](../../../../apps/api/app/RuntimeProvisioning/ManagedAsteriskProvisioningOperationHandler.php)
(`deployment`, line 112; `desiredDeployment`, line 126) and
[ManagedFreeSwitchProvisioningOperationHandler](../../../../apps/api/app/RuntimeProvisioning/ManagedFreeSwitchProvisioningOperationHandler.php)
(`desiredDeployment`, line 106; `deployment`, line 122). Provisioning and
convergence reuse those builders. The isolated Go counterpart is
[active/projection.go](../../../../components/kubernetes-effects/active/projection.go).

[ActiveProjectionParityTest.php](../../../../tests/gn-g2-active-projection/ActiveProjectionParityTest.php)
invokes the actual Laravel builders and independently calculates PHP
fingerprints. [active/parity_test.go](../../../../components/kubernetes-effects/active/parity_test.go)
decodes their output through Kubernetes types and compares the entire PodSpec,
provider labels, replica intent and strategy semantics. It separately checks
the deliberate physical-name/selector transformation. This is executable
projection parity; it does not execute either telephony process or install
canonical production lifecycle persistence.

Classification: **Equivalent** preserves the source's semantics;
**Transformed** is a deliberate infrastructure-contract change;
**Production integration** remains with the production authority or needs a
future integration assignment; **Unsupported** is rejected by this interface;
**Operator decision** requires explicit future authorization.

## Complete Deployment field inventory

| PHP field or behavior | Fixed Go representation | Classification and evidence |
| --- | --- | --- |
| `apps/v1`, `Deployment`, namespace `utcp-runtime` | Same kind; trusted cluster and namespace catalog restrict the request | Equivalent in parity fixtures; configured namespace trust remains an operator input |
| Provider/slug/hash Deployment name | Never-reused `utcp-<incarnation>` physical name | Transformed; v2 one-way UID binding prevents adoption of a replacement by name |
| `part-of=utcp`, component `asterisk-ari` / `freeswitch-esl`, instance name, RuntimeNode slug | Same provider/service correlation labels plus tenant, RuntimeNode ID, target and incarnation ownership | Equivalent provider correlation; transformed additional immutable identity |
| Selector on part-of/component/slug | Immutable incarnation selector preserved from inert creation | Transformed; the provider Service selector remains compatible with provider and slug labels |
| Pod labels including `utcp.io/network-role` | Same provider role and slug; additional incarnation identity | Equivalent provider/network role; transformed identity |
| `replicas: 1` | Explicit absolute replicas in the authorized intent, including source-equivalent `1` | Equivalent desired replica effect; activation requires bound state and current claim |
| No explicit pause or strategy | Active projection explicitly sets `paused: false`, RollingUpdate with 25% surge / 25% unavailable | Transformed explicit Kubernetes default semantics; rollout pause is not suspension |
| Digest-qualified configured image; pull policy `Always` | Same digest-qualified reference and `Always`; exact per-profile approved-image catalog required | Equivalent image requirement plus stricter trust boundary; no mutable-tag resolution or invented digest |
| One named provider container | Exactly `asterisk` or `freeswitch`; no sidecars or init containers | Equivalent; additional containers are unsupported |
| No command or arguments override | Fixed profile leaves image entrypoint unchanged | Equivalent; caller-supplied commands/arguments are unsupported |
| `envFrom.secretRef.name` | Approved namespace-scoped `secret_name` slot, preserving the PHP-produced reference | Equivalent reference; Secret material/publication remains production integration |
| Requests 50m CPU / 128Mi; limits 500m / 384Mi | Same quantities for both providers | Equivalent; full PodSpec comparison |
| Token automount false; termination grace 30 seconds | Same explicit values | Equivalent |
| Pod `runAsNonRoot`, RuntimeDefault seccomp | Same explicit values | Equivalent |
| Container privilege escalation false; drop ALL capabilities | Same explicit values | Equivalent; privileged or host access is unsupported |
| No explicit ServiceAccount, placement, affinities, tolerations, host namespaces, imagePullSecrets or lifecycle hooks | No such caller-configurable fields; Kubernetes defaults apply | Equivalent source absence; future requirements require reviewed versioned extension |
| Parent metadata/spec outside the controlled projection | Preserved by conditional parent patch | Transformed explicit field ownership; the complete Pod template, replicas, strategy and execution markers are controlled |
| No configuration digest annotations in builder | `configuration_id` / `configuration_sha256` become intent markers | Transformed correlation evidence; not proof of Secret or ConfigMap payload immutability |

The legacy Service and Secret names come from
[ManagedRuntimeResourceIdentity::names](../../../../apps/api/app/RuntimeProvisioning/ManagedRuntimeResourceIdentity.php)
(line 10). They must not be regenerated from the new incarnation Deployment
name. Existing Service selectors match provider/slug labels rather than the
new incarnation selector; their eventual ownership and lifecycle therefore
need explicit production coordination.

### Asterisk-specific fields

Source: `ManagedAsteriskProvisioningOperationHandler.php`, lines 114–117.

| Source behavior | Go profile `asterisk.managed.v1` | Classification |
| --- | --- | --- |
| Ports `ari:8088/TCP`, `sip:5060/UDP` | Identical named ports | Equivalent |
| `asterisk-local-config` ConfigMap volume; fixed `asterisk-local-sip-fixtures`, optional true | Same fixed optional ConfigMap; read-only mount `/opt/utcp-asterisk-local-config` | Equivalent; no arbitrary ConfigMap or volume input |
| Readiness exec `/usr/local/bin/utcp-asterisk-readiness`, period 15s, timeout 5s, failure 3 | Identical | Equivalent |
| Startup uses readiness command; initial delay 15s, failure 12 | Identical | Equivalent |
| Liveness exec `/usr/sbin/asterisk -C /tmp/utcp-asterisk/asterisk.conf -rx "core show uptime"`; initial 20s, period 20s, timeout 5s, failure 3 | Identical | Equivalent |
| No read-only-root setting or explicit numeric Pod UID/GID | Same absence; image/runtime supplies the user and writable filesystem needs | Equivalent; unrequested hardening would change the startup contract |

The ConfigMap's optionality is retained, not converted into evidence of complete
configuration. Its mutable contents can change independently of the projection
fingerprint. Immutable/versioned configuration publication is a future
production integration requirement; Go neither reads nor verifies payloads.

### FreeSWITCH-specific fields

Source: `ManagedFreeSwitchProvisioningOperationHandler.php`, lines 124–143.

| Source behavior | Go profile `freeswitch.managed.v1` | Classification |
| --- | --- | --- |
| Ports `sip:5060/UDP`, `esl:8021/TCP`, `rtp:21000/UDP` | Identical named ports | Equivalent; port declarations do not constrain the full RTP network range |
| Three emptyDirs: runtime, run and log | Same objects and mounts `/var/lib/freeswitch`, `/var/run/freeswitch`, `/var/log/freeswitch` | Equivalent, including object-valued emptyDirs |
| Pod runAsUser/runAsGroup/fsGroup 1000 | Same explicit values | Equivalent |
| Container readOnlyRootFilesystem true | Same explicit value | Equivalent |
| Startup/readiness/liveness exec `/usr/local/bin/utcp-freeswitch-healthcheck`; period 10s, timeout 5s | Same; startup failure 24, readiness/liveness failure 6 | Equivalent |

## Caller-to-effect graph and five-corridor parity

The PHP interface is
[KubernetesWorkloadClient](../../../../apps/api/app/Infrastructure/RuntimeFencing/KubernetesWorkloadClient.php).
Its current HTTP implementation uses merge patches, including `/scale`, and
ownership inspection before managed apply/delete. The Go action protocol
intentionally adds Kubernetes-enforced parent UID/resourceVersion predicates
and generation markers; reproducing the old unconditional transport would
weaken the new contract.

| Corridor and current callers | Current effect / authority | Isolated Go action sequence | Result, retry and proof boundary |
| --- | --- | --- | --- |
| **Provisioning:** Asterisk `execute`, line 79; FreeSWITCH `provisionOperation`, line 52 | `applyDeployment`; tenant/node/request/target checks, configured digest, credential and Service publication, registry activation remain PHP | Canonical prepare → v2 `create_suspended` → observe actual UID → canonical one-way bind → v3 `converge_projection` | Deliberately staged creation; creation alone cannot authorize activation. PHP builder PodSpec parity plus test-only lifecycle/claim tests. Service, Secret, endpoint, capability and activation persistence remain production integration |
| **Convergence:** `ManagedRuntimeWorkloadConvergenceOperationHandler::execute`, line 63 | Calls provider `desiredDeployment` then `applyDeployment`; canonical managed active/draining gate. Asterisk observed image persistence remains PHP | Allocate current intent/generation → current bound claim → v3 `converge_projection` | Same provider template, explicit CAS and whole-template ownership. Repeated current intent may be already satisfied; conflict requires observation/canonical reconciliation rather than blind RV refresh |
| **Fencing:** `KubernetesRuntimeFenceAdapter::fence`, line 48 | `scaleDeployment(..., 0)` with PostgreSQL/domain fencing provenance; completion also requires desired/status/available replicas zero and no owned Pods | Current claim → v1 `admit_generation` → v1 `set_replicas(0)`; observe exact UID's ReplicaSet/Pod chain separately | Equivalent infrastructure effect; successful patch is not completed fencing. Current production inspector and Pod selector are Asterisk-specific; FreeSWITCH effect tests are an extension, not existing domain parity |
| **Restoration:** `RuntimeNodeRestoreOperationHandler::scaleToTarget`, line 282 | `scaleDeployment` after disabled-state, source-fence, config-version, conference, readiness, listener-lease and epoch checks | Fresh current bound claim / approved profile → v3 projection when needed → generation admission and authorized absolute replicas | Test-only current-incarnation/claim/generation parity. Domain prerequisites remain Laravel. Production restoration uses the Asterisk inspector; FreeSWITCH domain support is not established |
| **Deprovisioning:** Asterisk `ManagedAsteriskDeprovisioningOperationHandler::execute`, line 92; FreeSWITCH `deprovisionOperation`, line 94 | `deleteDeployment`, plus owned Service/Secret cleanup; canonical retired managed-node gate | Canonical retiring barrier → v2 `retire_delete` with UID/RV preconditions → observe absence → canonical terminal retirement | Accepted deletion is not completed deletion. Finalizers, replacement UID and uncertain deletion remain explicit. Service/Secret cleanup and durable completion stay PHP |

Focused source references:
[convergence](../../../../apps/api/app/RuntimeProvisioning/ManagedRuntimeWorkloadConvergenceOperationHandler.php),
[fencing](../../../../apps/api/app/Infrastructure/RuntimeFencing/KubernetesRuntimeFenceAdapter.php),
[restoration](../../../../apps/api/app/Infrastructure/RuntimeFencing/RuntimeNodeRestoreOperationHandler.php),
[Asterisk deletion](../../../../apps/api/app/RuntimeProvisioning/ManagedAsteriskDeprovisioningOperationHandler.php),
[ownership inspector](../../../../apps/api/app/Infrastructure/RuntimeFencing/KubernetesRuntimeWorkloadInspector.php),
[HTTP workload client](../../../../apps/api/app/Infrastructure/RuntimeFencing/HttpKubernetesWorkloadClient.php).

`KubernetesRuntimeWorkloadInspector::isOwnedAsteriskDeployment` (line 10) and
`HttpKubernetesWorkloadClient::listOwnedPods` (line 81; component selector within
that method) establish the FreeSWITCH limitation directly. The test bridge must
not be described as having widened those production domain contracts.

## Canonical authority and failure mapping

The bridge uses the existing
[RuntimeOperationRepository](../../../../apps/api/app/ControlPlane/RuntimeOperations/RuntimeOperationRepository.php)
to create and lease actual Laravel test operations. The canonical incarnation
model and profile authorization remain test-only in
[ActiveAuthority.php](../../../../tests/gn-g2-active-projection/ActiveAuthority.php).
They are not PostgreSQL production lifecycle integration. Full-envelope claim
matching, lossless decimal generations and PHP/Go fingerprints establish the
wire boundary without giving Go tenant or durable operation authority.

All five corridor fixtures preserve normalized uncertainty receipts. The
test-only compatibility mapping is ownership conflict → `ownership_conflict`,
immutable identity mismatch → `target_mismatch`, forbidden →
`permission_denied`, generation/RV conflict → `fence_in_progress`, and unknown
outcome → `unavailable` **with the normalized `observe_first` receipt retained**.
Success/already-satisfied carry no PHP exception reason. These are compatibility
tests, not production handler conversion or proof that an unavailable action
was never applied. Failed JSON Patch tests may return 422 and remain conservative
`invalid_request`; the adapter does not parse human Status messages to invent a
retry guarantee.

## Activation and remaining migration requirements

Active execution permission requires current canonical bound identity, complete
approved profile/image/Secret slot, current full-envelope claim, globally current
generation/fingerprint, expected UID/RV and no retirement marker. The parent
patch changes the controlled template, rollout state, absolute replicas and
markers together. Zero replicas plus the inert creation template contains
creation; `paused: true` alone does not prevent existing ReplicaSets from scaling.
ReplicaSet/Pod observations establish effects separately from Deployment spec
acceptance and telephony readiness.

A request dispatched before claim expiry or retirement can still reach
Kubernetes. A newer Kubernetes barrier prevents an older prepared conditional
write afterward; an old request arriving before that barrier may apply
temporarily. PostgreSQL cannot retract the request. Terminal retirement and
fencing completion therefore require authoritative convergence and owned-Pod
drain evidence, not a wall-clock claim about request revocation.

Remaining production integration: durable canonical incarnation/generation and
cross-version authorization, authenticated production claim verifier, explicit
image/Secret/configuration catalogs and publication ownership, FreeSWITCH domain
fence/restore decision, all five writer corridors, old writer credential cutoff,
legacy in-flight recovery, external replica writers/HPA, conditional-compatible
rollback, preserved V1/K5 proof and separate static-check repair. ADR adoption
requires the operator. None of these are installed by this isolated assignment.

Current-State Ledger Impact: `NO_CHANGE_REQUIRED`. This supporting proposal does
not alter the canonical RMA-E next action or accepted V1/K5 classifications.
