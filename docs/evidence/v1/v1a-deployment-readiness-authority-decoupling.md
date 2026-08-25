# V1-A Deployment-Readiness Authority Decoupling

This bounded correction separates generic application deployment readiness from
strict full desired-topology acceptance. It does not implement registration
behavior, activate V1-B, modify the external PBX, or use external SIP
credentials.

## Authority split

`scripts/k3d/runtime-ready` is the deployment-readiness authority. It verifies
that the canonical `utcp-local` cluster is present and running, the repository
Kubernetes context and namespace are correct, and the expected Kubernetes nodes
are Ready. It rejects absent and stopped clusters. It does not inspect optional
or future immutable host publications.

`scripts/k3d/verify` remains the strict full desired-topology verifier. Its
V1-B assertion remains unchanged: the running server load balancer must expose
`0.0.0.0:5060 -> 30560/udp`. The current pre-V1-B cluster therefore continues
to fail `make k3d-verify` for that exact reason.

Normal Kubernetes, gateway, security, and observability deployment callers use
`runtime-ready`. A newly absent cluster is created from the declared cluster
configuration and then receives strict verification; an existing running or
stopped cluster is reused or started and receives runtime-readiness verification
without recreation.

## Verification

Focused repository checks passed:

- `make k3d-lifecycle-test`
- `scripts/k3d/runtime-ready`
- `bash -n` on changed shell scripts
- `git diff --check`
- API feature suite, including the two-registration endpoint isolation case

The canonical `make k8s-apply` passed the corrected cluster precondition and
proceeded through image publication, data/runtime application, and migration
creation. It then exposed a separate existing V1-A deployment defect: migration
`2026_08_25_090000_add_external_trunk_registration_authority` failed because
PostgreSQL does not contain the role
`utcp_kamailio_registration_reader` required by its grant. That registration
database defect is outside this lifecycle-authority packet and was not changed.

Consequently the live registration tables and
`kamailio-registration-control` Service were not established by this attempted
deployment. No REGISTER was sent, no external PBX or credential file was used,
and V1-B was not activated.

The existing PostgreSQL and Redis PVC identities remained bound:

- `postgres-data-postgres-0` -> `pvc-856a1a2f-1e92-4cd9-8251-94555d593409`
- `redis-data-redis-0` -> `pvc-1b8f2fc3-1d08-46b5-b99d-5a21e2cf0ba1`

The phase remains `V1_REMAINS_ACTIVE`. The next bounded implementation must
repair the missing registration database-role provisioning or otherwise make
the migration's established least-privilege role contract deployable, followed
by the separate live external REGISTER proof.
