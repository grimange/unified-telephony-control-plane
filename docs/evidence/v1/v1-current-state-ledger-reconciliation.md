# V1 Current-State Ledger Reconciliation and Mandatory Gate

Current-State-Impact: yes

Date: 2026-08-29

## Scope

This bounded governance packet reconciles the sole current-state ledger,
`docs/roadmap/phase-status.md`, with committed V1 implementation and evidence
through Gap B topology-coherent provider dialog anchoring. It does not deploy
native k3s or claim the pending controlled provider live proof.

## Reconciled state

Native k3s (`default`, `utcp-dev01`, `192.168.254.124`) remains the canonical
V1 authority and `utcp-local` is secondary/non-canonical. Gap C and Gap D are
closed. Gap B has topology-coherent repository implementation proof passed,
with controlled registration/NAT live proof pending. ADR-031 implementation is
complete, while stable-public-edge acceptance remains deferred by environment,
not abandoned. Gaps A and E remain open; Gap F remains a proof gap only where
the current evidence supports that classification. K5 and RMA sequencing is
unchanged.

## Mandatory reconciliation contract

Root and documentation instructions now require status-impacting bounded work
to reconcile `phase-status.md` in the same packet. New or modified evidence
documents must declare exactly one `Current-State-Impact: yes` or `no` marker;
`yes` requires the ledger in the same change set, while `no` does not.

The deterministic `scripts/repository/phase-status-consistency-check` resolves
an explicit `STATUS_LEDGER_BASE_SHA` range when supplied, combines it with the
working-tree/index/untracked delta, and uses the latest commit as the clean
local fallback. It validates only change-set metadata and does not interpret
natural-language status claims. The existing repository hygiene Make target and
GitHub Actions workflow enforce it with read-only contents permission.

## Verification boundary

Focused mutation tests cover status-impacting evidence with and without a
ledger change, non-impacting evidence, missing and invalid markers, ordinary
source-only changes, ledger-only reconciliation, and untouched historical
evidence. The pending next action remains one controlled registration/NAT live
proof on the exact committed native-k3s state.
