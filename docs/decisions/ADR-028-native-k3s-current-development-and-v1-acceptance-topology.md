# ADR-028: Native k3s Current Development and V1 Acceptance Topology

## Status

Accepted as the current development and V1 acceptance topology. This ADR
realigns current repository authority; it does not mutate Kubernetes, change
host resolution, or claim that external SIP acceptance has passed.

## Context

Historical K0-K4 phases were developed and proven with the k3d `utcp-local`
topology. Stage 4 subsequently established native k3s on `utcp-dev01` and
proved the application, Gateway API, Traefik, persistence, runtime, and
browser path there. Current hostnames resolve to `192.168.254.124`.

Stale current-state guidance later caused V1 work to regress to the historical
k3d topology. The two topologies cannot both be treated as canonical on this
host: native k3s owns the standard HTTP/HTTPS edge, and loopback publication by
a secondary k3d environment does not by itself isolate traffic from native
ServiceLB, hostPort, or kube-proxy behavior.

## Decision

Native k3s on `utcp-dev01` is the current active-phase development and
acceptance topology. It is canonical for current V1 preparation, V1 natural
external SIP acceptance, active-roadmap runtime proof, and future K5
native/distributed infrastructure work unless a later accepted ADR changes the
topology authority.

Only one topology may be designated canonical for a given active proof task.
Historical evidence does not select a current deployment target.

### Current canonical identity

```text
environment: native-k3s
cluster/node: utcp-dev01
edge address: 192.168.254.124
application endpoint: https://app.utcp.local.test
hostnames: utcp.local.test, app.utcp.local.test, sip.utcp.local.test, events.utcp.local.test
host resolution: 192.168.254.124
```

### Standard edge ownership

The current native edge owns:

```text
192.168.254.124:80
192.168.254.124:443
```

`app.utcp.local.test` is the current application hostname authority. The
historical k3d values `127.0.0.1:80` and `127.0.0.1:443` are not the current V1
application authority.

### k3d role and coexistence

k3d / `utcp-local` remains supported secondary development and regression
infrastructure: it is useful for historical K0-K4 work, optional disposable
local testing, and hermetic Kubernetes/static/runtime regression when a task
explicitly selects it. It is not deprecated or unsupported, and it must not
automatically become canonical because a historical runbook mentions it.

On one physical host, a secondary k3d environment must not be assumed safely
isolated merely because it binds loopback. Do not run competing canonical
edges on ports 80/443, and do not use `curl --resolve` alone as proof of
cluster identity. Environment-specific proof must identify the canonical
topology explicitly.

### Future evolution

K5 grows from native Kubernetes toward multi-host, failure-domain, and
cloud/hybrid operation. This ADR does not make k3d the production or
distributed authority. A future ADR may establish a different topology.
