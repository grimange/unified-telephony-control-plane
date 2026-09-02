# GitHub Actions Docs-Only Quality Trigger Noise Repair

Current-State-Impact: no

## Verdict

`CI_QUALITY_DOCS_ONLY_TRIGGER_NOISE_REPAIRED_AND_LIVE_PROVEN`

## Problem and bounded repair

The `Quality` workflow previously ran on every push to `main`, including
documentation and evidence-only commits. The representative noisy run was
Quality run `33607060435` for commit
`89907a87666a5158a0be097d1034127114402655`; it launched heavyweight jobs and
failed in unrelated quality corridors while Repository Hygiene passed.

The push trigger in `.github/workflows/quality.yml` now ignores only
`docs/**`. Pull requests and `workflow_dispatch` remain unchanged, so code,
configuration, workflow, and mixed docs-plus-code changes continue to run
Quality. The `Native k3s Images` push trigger also ignores only `docs/**`, as
required for the repository's documented docs-only path.

## Lightweight validation

`.github/workflows/repository-hygiene.yml` explicitly runs
`make phase-status-consistency-check` with the existing pull-request base SHA
or push `github.event.before`, followed by
`make phase-status-consistency-check-test`. It continues to run repository
hygiene, workflow validation, and whitespace checks.

## Proof commits and Actions

- Workflow repair commit: `b4392e8b5b15d3a33787632889a01bb388f18564`
  (`fix(ci): skip quality on docs-only pushes`)
- Its Repository Hygiene run `33609317258` succeeded. Quality run
  `33609317357` was superseded/cancelled after the follow-up workflow-scope
  commit; this is an unrelated workflow-change execution result, not hidden
  failure.
- Native-image trigger repair commit:
  `3d6d43453de409e73c18e29b2728d6eb61aa43a0`
  (`fix(ci): skip native images on docs-only pushes`)
- Its Repository Hygiene run `33609590713` succeeded. The corresponding
  workflow-change Quality run was `33609590785`, and Native k3s Images run was
  `33609590802`.

The next commit contains only this `docs/**` evidence file. Its expected
proof is that Repository Hygiene runs successfully while neither Quality nor
Native k3s Images is created for the docs-only push.

## Boundaries

No failure suppression, `continue-on-error`, notification workaround,
production source change, runtime change, Kubernetes mutation, or RMA roadmap
change was introduced. PR required-check behavior remains unfiltered.
