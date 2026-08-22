# C6A — Canonical Call Model and Operation Authority

## Status

C6A_CANONICAL_CALL_MODEL_AND_OPERATION_AUTHORITY_IMPLEMENTED_AND_TESTED

Implemented as a bounded repository slice on 2026-08-16. No adapter execution,
observation ingress, API/UI, conference cutover, or C7 resource was added.

## Implemented

- Added exactly calls and call_legs; no call_operations, call_observations,
  timeline, participant, or bridge table.
- Added nullable opaque C7 seams on calls without implementing routing.
- Added provider-neutral direction, state, and leg-role vocabulary plus explicit
  transition and terminal-metadata validation in CallDomainService.
- Reused runtime_operations for an outbound call intent and declared the
  complete normalized C6 operation target/capability catalog.
- Added C6 capability keys to the existing runtime registry catalog.
- Added audit and transactional outbox records through existing repositories.
- Added the PostgreSQL partial unique runtime-channel fence and deterministic
  SQLite/feature coverage for null channels, duplicate channels, and
  cross-tenant rejection.

## Preserved boundaries

The existing conference participant authority, RH-3 recovery corridor,
runtime_observations, TelephonySession authorization boundary, and all runtime
adapters are unchanged. Inbound runtime adoption and observation-driven
mutation remain C6C; handler execution and simulator behavior remain C6B.

## Verification

Focused test: cd apps/api && php artisan test tests/Feature/TelephonyDomain/CallDomainServiceTest.php

Result: 6 tests passed after correcting one assertion expectation. Repository
wide checks are reported in the completion report.
