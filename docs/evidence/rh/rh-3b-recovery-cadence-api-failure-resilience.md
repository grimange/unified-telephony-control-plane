# RH-3B — Recovery cadence and API-failure resilience

Status: IMPLEMENTED / TESTED. Browser proof is intentionally pending RH-3C and RH-3D.

RH-3B is confined to browser/API recovery orchestration. The recovery coordinator now uses the deterministic ladder `[1000, 2000, 3000, 5000, 8000, 10000]` milliseconds, capped at 10000 milliseconds, with a fixed ±20% jitter ratio. Recovery API requests use a bounded 10000 millisecond timeout and abort cleanup without changing the behavior of unrelated API callers.

Network, timeout/abort, HTTP 5xx, and HTTP 429 outcomes retry through the ladder. HTTP 401 preserves the existing authentication flow; HTTP 403 stops in Needs attention; HTTP 404 stops in Ready; HTTP 409 performs canonical bootstrap rediscovery rather than blindly repeating a mutation. Offline state suspends recovery requests, admission, and INVITEs. Online events are coalesced through a 1000 millisecond debounce and the existing recovery single-flight.

An established SIP dialog is preserved during API outage, while a missing dialog cannot be replaced until canonical bootstrap authority is available. RH-2 attempt fencing, canonical Connected gating, explicit Leave cancellation, RH-1's 120-second grace, and the no-Leave-on-unmount rule remain unchanged. No backend, schema, telephony, Reverb, RH-3C, or RH-3D changes were introduced.

Focused frontend/API tests and the full web test, lint, typecheck, and build checks pass. Repository-wide checks are recorded in the implementation report; browser/live proof was not performed.
