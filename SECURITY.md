# Security Policy

## Scope

This repository currently contains governance, architecture, repository hygiene material, a minimal Laravel API, a minimal Vue administration shell, Docker image build definitions, and a local Docker Compose platform with PostgreSQL and Redis. It does not yet contain Kubernetes manifests, Traefik deployment, telephony runtime configuration, simulator behavior, authentication, tenancy, authorization, or production deployment automation.

## Reporting

Open a private security advisory or contact the repository maintainer through the hosting platform for suspected vulnerabilities. Do not disclose exploitable details publicly before maintainers have had time to assess them.

## Secrets

Do not commit:

- `.env` files
- private keys
- API tokens
- SIP credentials
- database passwords
- cloud credentials
- private hostnames
- customer data
- real telephone identities

Examples and templates must use placeholder values only.

## Security Boundaries

- PostgreSQL is the canonical authority for business records.
- Redis must not become canonical business storage.
- WebSockets must not carry authoritative state transitions.
- Traefik must not become the primary SIP or RTP ingress in this roadmap.
- Kubernetes must not own telephony business policy.
- Runtime-specific behavior must remain behind adapter contracts.

Security-sensitive changes must document the authority boundary they affect and the verification performed.
