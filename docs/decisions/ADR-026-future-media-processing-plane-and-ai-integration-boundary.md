# ADR-026 — Future Media Processing Plane and AI Integration Boundary

- Status: Accepted as a future architecture boundary; not implemented
- Date: 2026-08-24
- Phase: Future architecture compatibility; not a roadmap phase and not R0
- Supersedes: none
- Related: [ADR-020](ADR-020-t3-rtp-media-plane.md), [ADR-024](ADR-024-kubernetes-host-awareness-and-telephony-aware-infrastructure-operations.md)

## Context

UTCP owns provider-neutral telephony policy, desired state, orchestration,
reconciliation, and normalized runtime evidence. rtpengine is the media
transport and anchoring authority, while Asterisk and FreeSWITCH remain live
telephony execution authorities behind adapters. Future capabilities such as
noise suppression, transcription, speech synthesis, and interactive voice
agents need a stable place to attach without moving application workflow into a
PBX, model semantics into rtpengine, or vendor-specific identifiers into the
canonical Call and CallLeg contracts.

## Decision

Document a separate, future **Media Processing Plane**. It is an architectural
seam only; this ADR creates no service, schema, API, processor registry, or
runtime deployment.

```text
Application / consumer business intent
                |
                v
        UTCP Control Plane
        Call/CallLeg correlation, policy,
        capability selection, orchestration, audit
                |
                v
        Media Transport Plane: rtpengine
        RTP/SRTP transport, anchoring, fork, injection
                |
                v
        Future Media Processing Plane
        observer | inline transformer | interactive participant
```

The authority boundaries are:

- The consuming application owns why processing is used, conversation and
  business workflow, CRM meaning, campaign behavior, and business outcomes.
- UTCP may eventually own authorized media-processing intent and policy,
  capability-oriented selection, lifecycle orchestration, failure policy,
  correlation, audit, and normalized observations. None of these future
  concepts are current models or APIs.
- rtpengine owns RTP/SRTP transport, ICE/DTLS-SRTP mediation, WebRTC/RTP
  adaptation, anchoring, relay, media fork and injection, and any supported
  codec/transcoding or recording transport mechanics. rtpengine does not own
  tenant policy, AI semantics, model choice, transcript retention, or business
  workflow. Its NG protocol and commands remain below the UTCP boundary.
- A future Media Processor owns media transformation or interpretation, such
  as DSP, speech recognition, speech synthesis, or inference.
- Kubernetes owns processor workload placement, resources, restart, and
  scheduling. UTCP may interpret readiness and eligibility but does not replace
  the Kubernetes scheduler.

### Streaming-first seam

The future live integration abstraction is a media stream correlated to the
canonical UTCP Call, CallLeg, and participant identity. A WAV or other file is
an artifact or consumer for recording, post-processing, offline enhancement,
archival, or batch transcription; it is not the foundation of live processing.
Conceptually, one stream may be forked to recording, transcription, analysis,
denoising, synthesis, or another processor. No `MediaStream` model, table, or
API is introduced by this ADR.

### Processor classes

1. **Observer** receives a media copy without modifying the canonical call
   media path. Examples include live transcription, analytics, quality
   measurement, recording, and compliance analysis. Observer failure degrades
   or loses the processing result but must not interrupt or degrade RTP
   continuity.
2. **Inline Transformer** transforms media before it reaches its destination.
   Examples include noise suppression, voice enhancement, and automatic gain
   control. Each future attachment must declare an explicit failure policy,
   such as bypass, fail closed, or terminate enhancement. There is no silent,
   undocumented fallback.
3. **Interactive Participant** consumes media and generates media, as an AI
   voice assistant or other virtual participant would. It remains a media
   participant/consumer associated with the existing normalized Call, CallLeg,
   and participant concepts rather than creating an “AI call” lifecycle.

Applications will eventually request normalized outcomes or capabilities, not
engine names, model names, IP addresses, or implementation details. Names such
as `media.noise_suppression`, `speech.transcription`, or
`speech.realtime_agent` are illustrative future capability families only; they
are not current catalog entries or API commitments.

### Implementation and runtime independence

Processor implementation language is not part of a UTCP canonical contract.
Future implementations may use C/C++, Rust, Go, Python, a GPU-native runtime,
or an external service/API, selected later according to latency, cost,
hardware, library maturity, and operational complexity. No language, model,
ML runtime, speech vendor, or GPU technology is canonical.

Future processors must not be forced to masquerade as `RuntimeNode`s. Current
RuntimeNodes represent telephony execution runtimes such as Asterisk and
FreeSWITCH. A future conceptual execution-resource family may contain both
RuntimeNodes and Media Processors, but this ADR creates no superclass, schema,
table, or registry and does not change RuntimeNode.

### Runtime behavior, placement, and privacy

Real-time processing will eventually require explicit treatment of overload,
latency, frame loss, queue buildup, processor failure, GPU exhaustion, and
network interruption. Observer failure remains non-blocking to the call. Inline
failure is policy-driven and observable. Interactive failure is isolated to the
media participant where feasible rather than corrupting canonical Call
authority.

Future processor selection may consider readiness, capacity, processing and
queue latency, media health, site/location, and failure domain alongside the
call/media location. This remains compatible with K5's distributed
infrastructure direction without changing K5, adding GPU scheduling, or making
media processing an R0 requirement.

Processor attachment must eventually be tenant-authorized, session/call
scoped, identity-bound, auditable, network-restricted, and time-bounded where
appropriate. Applications must not supply arbitrary processor URLs. The future
direction is a canonical processor identity, an authorized capability, and a
controlled media-session attachment. Transcription processing is distinct from
transcript persistence and retention; processing audio does not authorize
indefinite storage.

The future AI-compatible stream may look like:

```text
media -> VAD -> STT -> AI/reasoning -> TTS -> generated media
```

This must not require a new Call model, AI-specific SIP model, direct
Asterisk/FreeSWITCH AI authority, or AI-specific core frontend branch. PBX
dialplans or provider plugins may remain technical leaves when justified, but
they do not become application-level AI orchestration authority.

### Evolution and non-goals

An illustrative maturity path is architecture guidance, not scheduling:

```text
architecture seam -> offline enhancement -> passive live fork
-> inline transformation -> interactive participant
-> placement/capacity optimization
```

This ADR explicitly does not implement media processing, AI, DSP,
transcription, speech synthesis, a new service, a MediaProcessor schema or
migration, an API, a processor registry or health worker, a media streaming
protocol, rtpengine integration code, Kubernetes resources, GPU deployment,
or a new namespace. It creates no formal roadmap phase and adds no R0 gate.

## Consequences

The boundary preserves rtpengine as transport authority and UTCP as the future
policy/orchestration authority while leaving processor languages, models,
protocols, placement mechanics, and deployment runtimes open for evidence-led
future work. Current Call, CallLeg, RuntimeNode, adapter, Kubernetes, and K5
contracts remain unchanged. Recording & Media Archive is a separate planned
R0-critical capability under [`ADR-029`](ADR-029-recording-media-artifact-and-archive-authority.md):
RMA owns recording artifact and archive lifecycle, while this ADR continues to
own future streaming DSP, transcription, synthesis, and AI processing. A
RecordingArtifact may later be consumed by those systems, but remains an
artifact and does not make media processing an R0 requirement. Any future
implementation must add its own concrete
contract, security policy, runtime evidence, and failure semantics before it is
treated as current capability.
