# V1-A Registration Reader Role Provisioning Repair

Date: 2026-08-25

Status: implemented and live deployment verified; V1 remains active.

## Scope and authority

The V1-A deployment reached the registration-authority migration through the
canonical Kubernetes lifecycle, but the existing database did not contain the
PostgreSQL role required by its provider-consumption grant. This packet repairs
that provisioning path only. C7A remains the canonical ExternalTrunk,
TrunkEndpoint, and credential-reference authority; C7B remains route authority;
T6 remains the derived projection layer; Kamailio remains the REGISTER executor.

The PostgreSQL role is only a least-privilege reader for Kamailio's sanitized
registration representation and its Kamailio `version` metadata.

## Canonical provisioning

PostgreSQL roles are provisioned by the existing Laravel migration authority,
consistent with the Kamailio foundation migration. The new
`2026_08_25_085000_provision_kamailio_registration_reader` migration creates or
converges `utcp_kamailio_registration_reader` before the V1-A schema/grant
migration. It is idempotent and obtains its password from the existing
deployment secret/environment path; no password is stored in migration source,
manifests, evidence, or logs. Its rollback is intentionally non-destructive,
matching the existing Kamailio role lifecycle.

The V1-A migration grants SELECT on
`kamailio_external_trunk_registration_view`. The follow-up
`2026_08_25_091000_grant_kamailio_registration_version_reader` grants SELECT on
the shared `version` table. The
`2026_08_25_092000_register_kamailio_registration_view_version` migration makes
the existing Kamailio metadata column wide enough for the provider table name
and records provider schema version 5. The later repair migration keeps this
metadata safe for existing databases whose foundation schema predates V1-A;
fresh installs use the widened foundation definition as well.

## Security evidence

The live role is LOGIN but is not superuser, CREATEDB, CREATEROLE, or a
replication role. Live `information_schema` grants contain only:

* SELECT on `kamailio_external_trunk_registration_view`;
* SELECT on `version`.

The role can query the provider representation. A live UPDATE attempt is
denied. No INSERT, DELETE, CREATE, DROP, TRUNCATE, ownership, or elevated
cluster privilege is granted.

The provider representation contains `auth_ha1`, credential reference/version,
and registration identity fields, but no plaintext SIP password. This task did
not read or use `.env-external-pbx-sip-credentials`.

## Live deployment and runtime

The canonical command `make k8s-apply` completed successfully after the repair.
The applied database records contain the role-provisioning migration, the
V1-A registration-authority migration, the version-reader grant migration, and
the provider-version repair migration.

Live schema evidence contains the four registration intent columns on
`trunk_endpoints`, `kamailio_external_trunk_registration_view`, and
`external_trunk_registration_observations`. The Kamailio deployment is healthy
with `uac.so`, `jsonrpcs.so`, the sanitized uac_reg datasource, and the
repository-local `reg_contact_addr`. The internal
`kamailio-registration-control` Service is ClusterIP-only on TCP/8090 with a
live endpoint; it has no NodePort, LoadBalancer, Gateway, Traefik, or serverlb
publication.

The live observation surface is present and has not been fabricated as
registered. No real external REGISTER was sent in this packet.

## Bounded adjacent corrections

Canonical rollout exposed two existing V1-A runtime/manifest mismatches that
were corrected within this deployment path: the Kubernetes port name was
shortened to the valid `reg-control` while retaining Service identity and
TCP/8090, and Kamailio's required `reg_contact_addr` was added. The existing
config check was also narrowed to distinguish the registration contact URI from
the Asterisk relay destination. No V1-B host publication was activated.

## Preservation and proof boundary

PostgreSQL and Redis remained healthy, and the canonical PVC identities were
preserved:

* PostgreSQL: `postgres-data-postgres-0` -> `pvc-856a1a2f-1e92-4cd9-8251-94555d593409`;
* Redis: `redis-data-redis-0` -> `pvc-1b8f2fc3-1d08-46b5-b99d-5a21e2cf0ba1`.

The synthetic Kamailio and Asterisk external-trunk runtime proofs remain green,
and the two-registration isolation test keeps provider/observation identity
keyed by distinct `trunk_endpoint_id` values.

`make k3d-verify` remains an expected strict-topology divergence because V1-B's
server-load-balancer UDP/5060 publication is intentionally inactive. A broad
`make k8s-proof` run reached healthy workload rollout but stopped on unrelated
historical proof workloads in `utcp-runtime`; this does not invalidate the
successful canonical deployment or the focused V1-A/T6 claims.

Remaining V1 proof: real external REGISTER challenge/authentication/refresh and
external PBX interoperability, followed by the independent call-control proof
required by the current V1 acceptance boundary.
