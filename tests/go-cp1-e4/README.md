# GO-CP1 E4 executor journal developer verification

Run from the repository root:

```bash
tests/go-cp1-e4/verify
```

The pinned wrapper checks the isolated executor, actual subprocess SIGKILL/restart,
Docker restart/replacement with the same bind-mounted journal, missing/corrupt
storage denial and the complete E3 → E2 → E1 regression chain. E3
provisions disposable PostgreSQL and runs E4's mTLS executor-producer integration.
No Kubernetes credential, cluster, telephony service or deployed database is used.
The full E4 wrapper selects a bounded 900-second Go test budget; inherited
verifiers retain their 240-second default unless explicitly overridden. This
changes test scheduling only, not runtime or authorization timeouts. E4 also
selects `GOFLAGS=-p=1` to serialize package scheduling under shared durable-I/O
load; independent processes and PostgreSQL transactions inside each race test
remain concurrent.

For iteration only:

```bash
tests/go-cp1-e4/verify --focused
```

Focused mode still uses real disposable PostgreSQL through E3, but skips its
inherited E2/E1/offline infrastructure chain and broader repository checks. It
cannot substitute for the full acceptance command.

`fixtures/` contains two positive shape fixtures and ten negative shape fixtures
for `apps/control-plane/contracts/executor-grant-v1.schema.json`. The positive
signatures are placeholders: schema validity is not cryptographic authorization.
Go tests independently create fresh Ed25519 keys and signed grants, verify mTLS
identity, and reject malformed/altered/revoked or mismatched envelopes.

Output is private `/tmp` material. Storage cleanup and each nested PostgreSQL
verifier emit sanitized cleanup records. Do not commit private keys, credentials,
raw logs, journal files, test probes or oracle payloads. Docker restart/replacement
proves closed-state reconstruction from retained mounted files only; it does not
establish Pod-rescheduling or permanent-storage-loss durability.

Design, exact results, limitations and commands:

- [E4 report](../../docs/architecture/proposals/UTCP_GO_NATIVE_CANONICAL_KERNEL_E4_DURABLE_EXECUTOR_JOURNAL.md)
- [Journal state machine](../../docs/architecture/proposals/go-cp1-e4/executor-state-machine.md)
- [Verification and troubleshooting](../../docs/architecture/proposals/go-cp1-e4/verification.md)
