# User Access Recovery Runbook

## Purpose

`utcp:user-access:reset-password` is a bounded C1 break-glass recovery command for an existing UTCP user.

Use it for:

- operator break-glass recovery
- local browser acceptance preparation
- AI-coder natural-login preparation
- existing-user password recovery

It resolves one existing user, generates a temporary password inside the application, replaces the password hash, records a bounded expiration, revokes existing authenticated sessions, rotates remember-token access, requires password change after the next successful login, and appends sanitized audit records.

## Not Its Purpose

Do not use this command for:

- normal user creation
- normal password management
- role management
- tenant management
- permission management
- account activation
- authentication bypass

The command must not accept a plaintext password argument, reveal an existing password, mutate roles or memberships, unsuspend accounts, create tenants, create users, or bypass the normal login and password-change flow.

## Kubernetes Usage

The Make wrapper executes the command in the canonical Kubernetes API Pod without changing the global Kubernetes context:

```sh
make user-access-reset-password \
  USER='operator@example.com' \
  REASON='Natural browser acceptance' \
  EXPIRES_IN=30 \
  SHOW_PASSWORD=1
```

`SHOW_PASSWORD=1` prints the generated temporary password once. Without it, the reset is issued, existing sessions are revoked, and the output confirms that the password was not displayed.

The wrapper uses the repository kubeconfig and `k3d-utcp-local` context, selects a running API Pod, and runs the canonical Artisan command. It does not start Compose, create users, modify APNTalk, or print Pod environment.

## Direct Kubernetes Exec

Use this form when a Make wrapper is not appropriate:

```sh
KUBECONFIG=.runtime/kubeconfig/utcp-local.yaml \
kubectl --context k3d-utcp-local -n utcp-platform exec deploy/api -- \
  php artisan utcp:user-access:reset-password \
    --user='operator@example.com' \
    --reason='Natural browser acceptance' \
    --expires-in=30 \
    --show-password
```

## Direct Artisan Usage

For isolated development and test databases:

```sh
php artisan utcp:user-access:reset-password \
  --user='operator@example.com' \
  --reason='Natural browser acceptance' \
  --expires-in=30 \
  --show-password
```

Options:

- `--user` is required and must be an exact existing email address or user UUID.
- `--reason` is required, sanitized, bounded, and recorded in audit metadata.
- `--expires-in` is optional minutes, defaults to `30`, and must remain bounded.
- `--show-password` is the only way to print the generated password.

The command does not accept `--password`, `--temporary-password`, or any equivalent plaintext password input.

## Natural Browser Workflow

```text
issue temporary password
→ open fresh browser
→ normal login
→ forced password change
→ normal application access
→ logout
```

Until password change succeeds, the authenticated user is restricted to the normal identity/session endpoint, password-change path, CSRF path, and logout. RuntimeNode administration, tenant administration, normal application pages, and privileged APIs remain unavailable.

## Expiration

The temporary credential expires at the stored `temporary_password_expires_at` timestamp. An expired temporary password fails through the normal safe login error path and does not restore the prior password. A new explicit reset is required.

## Audit and Evidence

The reset-issued audit event records safe metadata such as the target user ID, reason, issue time, expiry time, session-revocation status, actor type, and command name.

Audit records and terminal evidence must not contain:

- plaintext temporary passwords
- password hashes
- remember tokens
- session identifiers
- tenant memberships
- role assignments
- full user records

Terminal output containing the temporary password should not be copied into evidence, committed files, issue comments, or chat transcripts.
