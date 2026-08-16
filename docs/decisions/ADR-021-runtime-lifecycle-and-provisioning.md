# ADR-021: Runtime Lifecycle Management and Managed Runtime Provisioning

## Status

Accepted as product direction; provisioning is deferred.

## Decision

`RuntimeNode` remains the single canonical management object for both externally
managed runtimes and runtimes eventually provisioned by UTCP. Managed
provisioning will create or bind a `RuntimeNode`; it will not introduce a
separate managed-runtime registry or vendor-specific node authority. Both
onboarding paths converge on the same lifecycle engine after binding.

Runtime lifecycle completion is tracked separately as **RNM — Runtime Node
Management Completion**. Managed provisioning is tracked separately as **RNP —
Managed Runtime Provisioning**, and RNM precedes RNP in implementation priority.

The intended normal-operator path is Admin UI → deploy a supported Asterisk or
FreeSWITCH runtime → UTCP provisioner → deployment target → runtime
infrastructure → `RuntimeNode` → the common lifecycle. Normal operators should
not need Dockerfile authoring, `kubectl`, Helm, Kustomize, raw Kubernetes YAML,
or direct database changes. Connecting an already deployed runtime remains a
supported advanced, migration, compatibility, and externally-managed path.

Provisioning and lifecycle management remain separate internal
responsibilities. Future deployment targets must remain deployment-neutral and
may represent Kubernetes, VMs, physical hosts, or cloud targets without leaking
Kubernetes implementation details into the core runtime model.

## Consequences

- PostgreSQL `RuntimeNode` desired state remains the lifecycle authority.
- Existing-runtime adoption remains useful without becoming the primary normal
  user experience.
- RNM can complete lifecycle semantics without prematurely implementing a PBX
  provisioner.
- No second runtime registry, managed-node type, or provider-node authority is
  permitted.
