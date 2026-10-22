# Kaneo migration preview — dry run only

Snapshot: `5e028b9ab3d96f3430bf715f89d66ab19a758761` plus per-file hashes. Existing matches: 0; no remote writes. Every row requires later authorization. Initial candidates are a proposed review subset, not approved creates. Historical summaries retain source completion in text; `done` is not assigned by this audit.

| Key | Title | Column | Parent | Initial subset | Source records |
| --- | --- | --- | --- | --- | --- |
| utcp:a0 | Minimal outbound, inbound and IVR-style reference consumers | to-do | None | False | A0 |
| utcp:checker | Repair runtime-engine checker / accepted K5 API-client label mismatch | to-do | None | True | CHECKER |
| utcp:go-cp0 | Architecture review and formal Go-native ADR adoption | to-do | utcp:go-native | True | GO-CP0 |
| utcp:go-cp1 | Isolated canonical kernel and infrastructure authority stores | in-review | utcp:go-native | True | GO-CP1 |
| utcp:go-cp1-review | Independent review of canonical kernel E1–E4 evidence | to-do | utcp:go-cp1 | True | GO-CP1-REVIEW |
| utcp:go-cp2 | Identity, HTTP and realtime compatibility | to-do | utcp:go-native | True | GO-CP2 |
| utcp:go-cp2-e1 | Isolated identity and tenancy compatibility | to-do | utcp:go-cp2 | True | GO-CP2-E1 |
| utcp:go-cp3 | Registry and infrastructure integration | to-do | utcp:go-native | False | GO-CP3 |
| utcp:go-cp4 | Telephony and signaling migration | to-do | utcp:go-native | False | GO-CP4 |
| utcp:go-cp5 | Recording and remaining domain/API parity | to-do | utcp:go-native | False | GO-CP5 |
| utcp:go-cp6 | Controlled authority handover and Laravel retirement | to-do | utcp:go-native | False | GO-CP6 |
| utcp:go-native | Go-native control-plane modernization | to-do | None | True | GO-NATIVE |
| utcp:history-gn | GN-G0–G2 isolated foundation and proof summary | in-review | None | False | GN-G0, GN-G1, GN-G2-EFFECTS, GN-G2-REAL-API, GN-G2-LIFECYCLE, GN-G2-ACTIVE, GN-G2-CANONICAL |
| utcp:history-go-cp1 | GO-CP1 E1–E4 developer verification summary | in-review | utcp:go-cp1 | True | GO-CP1-E1, GO-CP1-E2, GO-CP1-E3, GO-CP1-E4 |
| utcp:history-k5 | K5A–E completed infrastructure summary | to-do | utcp:k5 | False | K5A, K5B, K5C, K5D, K5E |
| utcp:history-platform | Completed platform, telephony and UI foundation summary | to-do | None | False | F0, F1, F2, F3, F4, K0, K1, K2, K3, K4, C0, C1, C2, C3, C4, C5, T0, T1, T2, T3, T4, T5, T6, V0, RT-1, RNM, RNP, C6, C7A, C7B, UI-A, UI-B, UI-C, UI-D, UI-E, RT-1A |
| utcp:history-rma-ad | RMA-A–D completed recording authority summary | to-do | utcp:rma | False | RMA-A, RMA-B, RMA-C, RMA-D |
| utcp:history-v1 | V1 closure summary with six closed gaps | to-do | None | False | V1, V1-GAP-A, V1-GAP-B, V1-GAP-C, V1-GAP-D, V1-GAP-E, V1-GAP-F |
| utcp:k5 | Distributed telephony infrastructure, placement and host lifecycle | to-do | None | False | K5 |
| utcp:k5f | Guided existing-cluster host enrollment | to-do | utcp:k5 | False | K5F |
| utcp:preservation | Review and preserve untracked modernization artifacts in source control | to-do | None | True | PRESERVATION |
| utcp:r0 | Finite core release acceptance boundary | to-do | None | False | R0 |
| utcp:rma | Recording & Media Archive | to-do | None | True | RMA |
| utcp:rma-e | S3-Compatible Archive Adapter and Deterministic MinIO Proof | to-do | utcp:rma | True | RMA-E |
| utcp:roadmap-residue | Reconcile stale descriptive RMA/K5 roadmap summaries | to-do | None | True | ROADMAP-RESIDUE |
| utcp:vue-scanner | Repair precise Vue credential-form scanner false positive | to-do | None | True | VUE-SCANNER |

Exact dispositions, hashes, source paths and dependency types are in [JSON](migration-preview.json) and [record inventory](inventory-records.json). Duplicate record IDs and migration keys: zero. Grouping many historical IDs under one summary is intentional, not an identity collision.

## Disposition totals

{"M1": 10, "M2": 10, "M3": 63, "M4": 32, "M5": 33, "M6": 3}

There are 151 distinct records, 26 proposed remote units (13 suggested initial units), six aggregated historical summaries and zero existing matches. 68 records have no remote create; they remain Git-only or excluded. The JSON carries complete status dimensions and source hashes. `utcp:go-cp1` is proposed `in-review`; imported source completion remains distinct from operator closure.
