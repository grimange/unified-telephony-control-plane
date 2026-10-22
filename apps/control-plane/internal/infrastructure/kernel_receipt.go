package infrastructure

import (
	"context"
	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/kernel"
	"time"
)

// ReceiptVerifier is composition-owned: callers cannot choose trust policy per
// receipt. PeerSAN must come from authenticated transport, never request JSON.
type ReceiptVerifier struct {
	Policy  TrustPolicy
	PeerSAN string
	Now     func() time.Time
}

func (v ReceiptVerifier) Verify(ctx context.Context, raw []byte, c kernel.ReceiptChallenge) (kernel.ReceiptEvidence, error) {
	if err := ctx.Err(); err != nil {
		return kernel.ReceiptEvidence{}, err
	}
	r, err := ParseReceipt(raw)
	if err != nil {
		return kernel.ReceiptEvidence{}, err
	}
	now := time.Now()
	if v.Now != nil {
		now = v.Now()
	}
	x, err := VerifyReceipt(r, Challenge{ID: c.ID, Nonce: c.Nonce, Audience: c.Audience, Purpose: c.Purpose, IntentID: c.IntentID, DispatchID: c.DispatchID, EnvelopeHash: c.EnvelopeHash, TenantID: c.TenantID, RuntimeNodeID: c.RuntimeNodeID, ClusterID: c.ClusterID, Namespace: c.Namespace, Name: c.Name, IncarnationID: c.IncarnationID, ExpectedUID: c.ExpectedUID, Generation: c.Generation, Fingerprint: c.Fingerprint, EffectDigest: c.EffectDigest, ExpiresAt: c.ExpiresAt, IssuedAt: c.IssuedAt, Action: c.Action, ProjectionDigest: c.ProjectionDigest}, v.Policy, v.PeerSAN, now)
	if err != nil {
		return kernel.ReceiptEvidence{}, err
	}
	p := x.Payload
	return kernel.ReceiptEvidence{ReceiptID: p.ReceiptID, Issuer: p.Issuer, ChallengeID: p.ChallengeID, TenantID: p.TenantID, RuntimeNodeID: p.RuntimeNodeID, IncarnationID: p.IncarnationID, ClusterID: p.ClusterID, Namespace: p.Namespace, Name: p.Name, ExpectedUID: p.ExpectedUID, ObservedUID: p.ObservedUID, ResourceVersion: p.ResourceVersion, Purpose: p.Purpose, IntentID: p.IntentID, DispatchID: p.DispatchID, EnvelopeHash: p.EnvelopeHash, Generation: p.Generation, Fingerprint: p.Fingerprint, EffectDigest: p.EffectDigest, Outcome: p.Outcome, Replicas: p.Replicas, ObservedTenantID: p.ObservedTenantID, ObservedRuntimeNodeID: p.ObservedRuntimeNodeID, ObservedIncarnationID: p.ObservedIncarnationID, AdmittedGeneration: p.AdmittedGeneration, AdmittedFingerprint: p.AdmittedFingerprint, AppliedGeneration: p.AppliedGeneration, AppliedFingerprint: p.AppliedFingerprint, ObservedProjectionDigest: p.ObservedProjectionDigest, Complete: p.Complete, StartedAt: p.StartedAt, FinishedAt: p.FinishedAt, ExpiresAt: p.ExpiresAt, SafeEnvelope: x.SafeEnvelope}, nil
}
