# Evidence preservation and publication preflight

No staging, commit, push or remote publication is authorized or performed.
[Publication inventory](publication-inventory.json) covers all 166 pending entry
paths: four modified tracked documents and 162 untracked files. Every row records
SHA-256, Git status, evidence class, preservation action, publication requirement,
proposed path/content locator, publication status and secret/privacy screening.
The [16 task references](task-reference-publication.json) additionally cover clean
HEAD sources. All local hashes match; no remote source access is claimed.

The proposal is two separately reviewable preservation commits: 17 prerequisite
canonical-foundation paths, then 149 GO-CP1/source/evidence, proposed architecture,
readiness, governance and K1 paths. Exact paths/hashes and ordering are in
[preservation plan](preservation-plan.json); the three candidate path-list files
contain the full set and each batch. These are plans, not approved staging lists.
No unexplained unrelated pending file remains: the previously ambiguous canonical
proof script is part of the foundation chain. GN ownership remains distinct from
Go backend work; publication does not authorize execution or accept either phase.

Dependency evidence: tests/go-cp1/verify mounts tests/gn-g2-canonical and invokes
its PostgreSQL proof; E1/E2 oracle imports canonical foundation classes. Publishing
only the Go files would omit proof prerequisites. Preserve those prior files as a
separate reviewed commit rather than silently claiming a self-contained Go tree.
GO-CP1 E1–E4 reports are developer evidence, not independent/operator acceptance.

All 166 pending files have zero matches against repository secret-scanner patterns.
This is static screening, not a claim of exhaustive privacy review. Before any
approved staging, inspect exact bytes and intended publication audience; documents
can contain already documented development topology. No raw test dumps, journals,
credentials, private keys or temporary binaries are included in the candidate set.
Ignored .runtime, .env/credentials, dependency/cache and local tool directories
remain excluded; no forced add or publication of ignored contents is proposed.

K2 additions need their own exact reviewed boundary in [changed files](changed-files.json),
not implicit inclusion in either existing-source batch. Commit authorization and
remote publication authorization are separate. Branch/destination are unresolved
approval fields. A local commit alone does not satisfy remote access.

A future publisher must record approved commit/object IDs, read remote immutable
objects and compare their bytes against these hashes. The existing HEAD blob URLs
are structural references whose remote availability is unverified. Worktree-only
sources have no invented GitHub URL. Operator may explicitly accept frozen path/hash
locators and their access limitation under the current K2 direction; otherwise
publication/access is required before import. Adding new URLs inside descriptions
requires another reviewed freeze/digest. A future MIGRATING decision adds a separately
authorized policy control record: preserve the pre-transition evidence and journal
the reviewed post-transition hash without silently rehashing task-source claims.
