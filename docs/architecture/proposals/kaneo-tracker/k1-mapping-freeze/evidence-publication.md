# Evidence publication prerequisite

The packet deliberately separates local exact byte identity from remote publication. A HEAD-matching tracked file can have a structurally correct pinned GitHub blob URL; K1 has not verified remote access or that this local commit is published. Working-tree-only sources, including the modified ledger and untracked policy/reports, receive no fabricated immutable URL.

Before K2, a separately authorized publication packet must preserve the source bytes and provide an immutable index mapping every path/SHA-256 below to a remotely accessible revision/object. Verify access and exact bytes. The K1 packet itself and its mapping/digests/checklist must also become durably accessible. No commit/push is authorized by K1 or implied by importing the preservation task. Publishing evidence is a real prerequisite; a newly created preservation task cannot satisfy it retroactively.

| Source path | Exact SHA-256 | Availability in this checkout |
| --- | --- | --- |
| `apps/web/src/views/ExternalTrunkDetailView.vue` | `ba62b668057c94614542ab93c121328a76a3238e79e39ba99d79c69c6c6d9f62` | HEAD_BYTES |
| `docs/architecture/proposals/UTCP_GO_NATIVE_CANONICAL_KERNEL_E1.md` | `f10d2f6061dfc4e59da13d56ef2cf9640f6fed8760d753868ae4e122e9c895ad` | WORKTREE_ONLY_REQUIRES_PUBLICATION |
| `docs/architecture/proposals/UTCP_GO_NATIVE_CANONICAL_KERNEL_E2_ASTERISK_DOMAIN_POLICY_PARITY.md` | `b5dba11c57e70d0ea114f3f20c3508ca0e52c7263456cbfe443935e54bccc780` | WORKTREE_ONLY_REQUIRES_PUBLICATION |
| `docs/architecture/proposals/UTCP_GO_NATIVE_CANONICAL_KERNEL_E3_TRUSTED_RECOVERY_EVIDENCE.md` | `2d829eeaf2fc971a1bd115f1eeee195727931c9612d9eed09e6b0ddeecfcd023` | WORKTREE_ONLY_REQUIRES_PUBLICATION |
| `docs/architecture/proposals/UTCP_GO_NATIVE_CANONICAL_KERNEL_E4_DURABLE_EXECUTOR_JOURNAL.md` | `039e26ca7926c6ca0ce493a5a837bb05dc9860811661f788f8323c6946f9f891` | WORKTREE_ONLY_REQUIRES_PUBLICATION |
| `docs/architecture/proposals/go-cp1-e4/next-assignment.md` | `ebf1cb09419b24c6e70f32da5ca1e1187209322893c68023c811149962fdd9d7` | WORKTREE_ONLY_REQUIRES_PUBLICATION |
| `docs/architecture/proposals/go-native-control-plane-adr-proposed.md` | `b0a5639cc67674c6d43b966eac9ebe49e1bf26de3b1850c47a0c1872297f8663` | WORKTREE_ONLY_REQUIRES_PUBLICATION |
| `docs/architecture/proposals/go-native-control-plane/migration-roadmap.md` | `2eed81183259b9f3001dfff10b1c75cd9878e00dbc3b3ba555477e45ef742546` | WORKTREE_ONLY_REQUIRES_PUBLICATION |
| `docs/governance/KANEO_CANONICAL_TRACKER_POLICY.md` | `a23f794fd1f8b826b34c93d698c2505f14c85062a02f506756c42b0cb349feef` | WORKTREE_ONLY_REQUIRES_PUBLICATION |
| `docs/roadmap/implementation-roadmap.md` | `0b78cea559481aa835da131aacbdb628ada8bd360a296032860c28188ddb082a` | HEAD_BYTES |
| `docs/roadmap/phase-status.md` | `9894b3284c484b91b53ca71e8e15920e7c6e47756de5349082ca24339c8de8d2` | WORKTREE_ONLY_REQUIRES_PUBLICATION |
| `infrastructure/kubernetes/base/platform/api-deployment.yaml` | `c8bbddccbaea2ac25c5f18cc6d35ca3c84807fe310735deaca0ce155a9d6a300` | HEAD_BYTES |
| `infrastructure/kubernetes/base/platform/scheduler-deployment.yaml` | `25441abb2f8eb2aef5275c8f6db487676970da2aa03506c339d234d8832e491c` | HEAD_BYTES |
| `infrastructure/kubernetes/base/platform/telephony-reconciler-deployment.yaml` | `8b210b4d14ba7e5039f779b8666d01d42f4ab4c5a6ac2c3566b8e10ea7ef70f3` | HEAD_BYTES |
| `scripts/ci/secret-scan` | `70ff179bfcdf0a71b3c845419010fc8a5c462a710fb4b44b20194aeb12531e8b` | HEAD_BYTES |
| `scripts/runtime-engine/config-check` | `84aba8dcccfb32255fca8f3930aafac477a574c39d5a7d8526afa951335a878e` | HEAD_BYTES |

The operator may approve these exact descriptions using path/hash locators, provided the verified publication index is an accepted external review artifact bound in the K2 journal. That preserves approved task bytes. If clickable new URLs must appear in Kaneo task descriptions, first prepare and review a new frozen payload/digest after publication; K2 must not interpolate unapproved URL changes. Permission/trigger approvals and the MIGRATING activation entry are separate control records, not retrospective edits to source evidence.
