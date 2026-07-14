# Repository Hygiene Runbook

Use this runbook for Phase F0 repository checks.

## Commands

```sh
git status --short
make help
make doctor
bash -n scripts/doctor scripts/check-repository-hygiene
make repository-hygiene
```

## Expected Results

- `make help` lists available repository commands.
- `make doctor` reports installed and missing tools without installing anything.
- Shell syntax validation exits successfully.
- Repository hygiene confirms required docs and ADRs exist.
- Tracked filenames do not include obvious `.env`, private-key, or secret-key material.

## Failure Handling

If `make doctor` reports later-phase tools missing, do not install them as part of F0. They are informational until the phase that requires them.

If repository hygiene reports a likely secret filename, remove the secret material from the index and replace it with a sanitized example only when that phase requires an example.
