# GO-CP1 E3 — source-backed recovery decision matrix

Parent: [E3 implementation report](../UTCP_GO_NATIVE_CANONICAL_KERNEL_E3_TRUSTED_RECOVERY_EVIDENCE.md).
This matrix describes the isolated Go canonical kernel. Synthetic signed evidence
is not a live Kubernetes observation or proof of executor-journal implementation.

## Evidence and transition rules

| Condition | Trusted evidence and correlation | Safe canonical interpretation | Future delivery / historical uncertainty | Regression family |
| --- | --- | --- | --- | --- |
| Work rejected before dispatch | New signed dispatch evidence, exact reserved dispatch/operation/attempt/executor/request digest; finalized journal, zero submissions | Cancel that reservation; record intent `confirmed_not_applied` and immutable evidence | No external submission under that finalized right. This is not permission to replay a consumed attempt | E3 definitive evidence |
| Consumed right conclusively never submitted | New signed `definitive_non_delivery`, finalized executor journal, zero submissions and no future/in-flight submission possible | Exact dispatch and intent `confirmed_not_applied` | Requires trustworthy irreversible journal finalization; cancellation, timeout or process exit alone are insufficient | E3 definitive evidence / no duplicate grant |
| One exact request receives terminal API rejection | New signed `api_rejected`, one submission, finalized journal, authenticated target API Status Failure and supported structured code/reason | Exact dispatch and intent `confirmed_not_applied` | No automatic mutation retries or other submissions may exist. An existing similarly named resource is not adopted | E3 API rejection categories |
| Mutation dispatched; response lost | Existing dispatch `outcome_unknown`; no definitive negative proof | Retain uncertainty and require trusted observation | Request may still arrive or may already have applied | Existing unknown outcome and E3 recovery |
| GET shows unchanged resource | Existing challenged observation of current fields | No historical nonapplication conclusion | Previously dispatched work may arrive later; prior transient effects may be invisible | E3 ambiguous evidence rejection |
| GET currently reports no resource after uncertain create | Complete exact absence observation still cannot prove no delayed POST | Keep uncertain create and terminal physical-identity history; cleanup-only late binding | No object exists on which a generation barrier can fence a delayed create | E3 absent-create protection |
| Exact newer conditional barrier observed | Existing signed `effect` receipt for exact historical recovery dispatch, UID/incarnation, generation and fingerprint | Confirm recovery dispatch; mark its recorded predecessor dispatches `contained` | Future older conditional writes are fenced by the observed barrier. Older intent outcomes remain unknown; earlier application is not disproven | E3 lost-barrier observation and progress |
| Recovery barrier response lost; marker not established | No matching exact authenticated effect evidence | Retain unresolved recovery slot | No duplicate consumed grant or recovery-of-recovery chain. Stronger evidence/quiescence may be necessary | E3 bounded recovery |
| DELETE acknowledged | Transport/API acceptance only | Request may be accepted or pending; no terminal retirement | Finalizers or an uncertain request can remain | Existing receipt and lifecycle regressions |
| Exact resource absence after authorized retirement | Existing complete, fresh, challenged `absence` receipt under exact retirement/dispatch trust contract | Terminal retirement when canonical and unresolved-predecessor checks also pass | Historical intent/UID identity remains retained. Failed GET/LIST or omission is not absence | Existing retirement receipts and E3 identity regressions |
| Recovery dispatch receives terminal negative evidence | New exact signed finalized journal evidence under current canonical authority | Resolve only that failed recovery; retain older ordinary uncertainty | Frees the one unresolved recovery slot. A new canonical operation/generation must independently qualify; no automatic barrier loop | E3 definitive recovery rejection |
| Lease expires after consumption | Database clock and operation record | No new grant; historical delivery remains unresolved | Lease expiry cannot retract the external request | E3 current-authority / existing claims |
| Receipt refers to superseded generation, changed attempt or retired target | Current canonical PostgreSQL state | Reject new dispatch-evidence acceptance; no authority resurrected | Existing exact accepted-byte retry may return status only; historical observation of a recovery barrier has its narrower existing exception | E3 canonical-change and replay regressions |

## Source ownership

- [dispatch.go](../../../../apps/control-plane/internal/kernel/dispatch.go):
  `Reserve`, `Consume`, `MarkUnknown`; immutable consumed-attempt identity and
  one unresolved recovery slot. Recovery rejects an uncertain create and an
  already unresolved recovery predecessor.
- [receipts.go](../../../../apps/control-plane/internal/kernel/receipts.go):
  `Challenge`, `AcceptReceipt`, `recoveryEvidence`; existing V1 observation
  purposes remain `bind`, `effect`, `absence`. Exact recovery observation may be
  accepted after supersession without granting dispatch or lifecycle authority.
- [kernel dispatch evidence](../../../../apps/control-plane/internal/kernel/dispatch_evidence.go):
  challenge creation, current-authority checks and transactional nonapplication
  acceptance. Lease expiry does not itself invalidate a fresh historical negative
  attestation because accepting it grants no execution authority.
- [signature verification](../../../../apps/control-plane/internal/infrastructure/dispatch_evidence.go):
  separate `utcp.dispatch-evidence.v1`, strict JSON, trusted executor SAN/key,
  exact challenge/envelope identity, bounded freshness and evidence categories.

## Effect-specific consequences

| Effect | What definitive rejection can establish | What it cannot establish |
| --- | --- | --- |
| `admit_generation` | This exact finalized single dispatch did not install its barrier | Whether another request installed equivalent markers or an older request applied earlier |
| `set_replicas` | This exact dispatch did not perform its conditional replica/marker mutation | That current replica equality is historical proof, or that an external replica writer is excluded |
| `create_suspended` | Zero-submission finalized proof or terminal rejection can resolve this exact creation dispatch | GET absence cannot clear delayed creation. `AlreadyExists` does not bind/adopt the conflicting object |
| `converge_projection` (V2 inert / V3 provider) | This exact conditional projection request was not applied | Runtime readiness or telephony execution; mutable configuration contents are not proven by a reference hash |
| `retire_delete` | This exact DELETE was not applied | Physical absence or completed retirement, even when rejection is `NotFound`; use the separate absence receipt contract |

## Trust and liveness boundary

The configured executor's signed journal attestation is the source of terminal
non-delivery knowledge. Signatures establish attribution and integrity, not the
truth of an unimplemented external journal. E3 tests synthetic issuer behavior;
they do not contact Kubernetes. A future executor must durably enforce one wire
submission, finalized non-delivery and authenticated API-response correlation
before it may issue these evidence classes.

An observed recovery barrier releases its own uncertainty slot through the
existing receipt path while preserving contained predecessors. A fresh current
canonical operation can then pass normal claim/domain checks. Definitive negative
evidence can likewise release a failed recovery slot without resolving its older
ordinary predecessor; a newly authorized canonical generation may request the
single recovery slot. The bound is concurrent unresolved recovery authority, not
a lifetime number of separately authorized recovery operations. If that barrier
cannot be established, automatic progress remains blocked; credentials/in-flight
quiescence or a stronger acknowledged execution protocol may be required. E3
provides no automatic compensation, PHP fallback or operator bypass.
