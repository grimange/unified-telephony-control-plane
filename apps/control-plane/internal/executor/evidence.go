package executor

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/hex"
	"time"

	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/infrastructure"
	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/kernel"
)

type SigningIdentity struct {
	Issuer, KeyID string
	PrivateKey    ed25519.PrivateKey
}

// Evidence signs fresh attestations about historical immutable durable facts.
// A challenge never authorizes a send. The canonical consumer independently
// validates issuer policy, current canonical identity and single challenge use.
func (j *Journal) Evidence(ctx context.Context, id string, c kernel.DispatchEvidenceChallenge, s SigningIdentity) (out infrastructure.SignedDispatchEvidence, err error) {
	err = j.withLock(ctx, func(es map[string]Entry, _ head) error {
		e, ok := es[id]
		if !ok {
			return ErrDenied
		}
		g := e.Grant
		if c.ID == "" || c.Nonce == "" || c.Audience == "" || c.DispatchID != g.DispatchID || c.OperationID != g.OperationID || c.IntentID != g.IntentID || c.Attempt != g.Attempt || c.Executor != g.Executor || c.TenantID != g.TenantID || c.RuntimeNodeID != g.RuntimeNodeID || c.ClusterID != g.ClusterID || c.Namespace != g.Namespace || c.Name != g.Name || c.IncarnationID != g.IncarnationID || c.ExpectedUID != g.ExpectedUID || c.Generation != g.Generation || c.Fingerprint != g.Fingerprint || c.Action != g.Action || c.EnvelopeHash != g.EnvelopeHash {
			return ErrDenied
		}
		now := time.Now().UTC()
		if c.IssuedAt.IsZero() || now.Before(c.IssuedAt) || !now.Before(c.ExpiresAt) || s.Issuer == "" || s.KeyID == "" {
			return ErrDenied
		}
		kind := ""
		submissions := 0
		switch e.State {
		case "closed":
			if g.Phase == "reserved" {
				kind = "pre_dispatch_rejected"
			} else {
				kind = "definitive_non_delivery"
			}
		case "api_rejected":
			if g.Phase != "consumed" || !definitive(e.APICode, e.APIReason) {
				return ErrDenied
			}
			kind = "api_rejected"
			submissions = 1
		default:
			return ErrUncertain
		}
		nonce := make([]byte, 16)
		if _, x := rand.Read(nonce); x != nil {
			return x
		}
		expiry := now.Add(20 * time.Second)
		if c.ExpiresAt.Before(expiry) {
			expiry = c.ExpiresAt
		}
		p := infrastructure.DispatchEvidencePayload{ContractVersion: infrastructure.DispatchEvidenceVersion, ReceiptID: hex.EncodeToString(nonce), Issuer: s.Issuer, KeyID: s.KeyID, ChallengeID: c.ID, Nonce: c.Nonce, Audience: c.Audience, Purpose: "dispatch_evidence", TenantID: g.TenantID, RuntimeNodeID: g.RuntimeNodeID, ClusterID: g.ClusterID, Namespace: g.Namespace, Name: g.Name, IncarnationID: g.IncarnationID, ExpectedUID: g.ExpectedUID, IntentID: g.IntentID, DispatchID: g.DispatchID, OperationID: g.OperationID, Attempt: g.Attempt, Executor: g.Executor, EnvelopeHash: g.EnvelopeHash, Generation: g.Generation, Fingerprint: g.Fingerprint, Action: g.Action, EvidenceType: kind, Outcome: "confirmed_not_applied", JournalID: j.id, Final: true, Complete: true, Submissions: submissions, APICode: e.APICode, APIReason: e.APIReason, StartedAt: now, FinishedAt: now, ExpiresAt: expiry}
		j.hook("before_signature")
		var x error
		out, x = infrastructure.SignDispatchEvidence(p, s.PrivateKey)
		if x != nil {
			return x
		}
		j.hook("after_signature")
		return nil
	})
	return
}
