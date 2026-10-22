// Package app composes canonical services with the isolated internal transport.
// It opens no listener and never invokes Kubernetes or synthetic effects itself.
package app

import (
	"context"
	"errors"

	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/infrastructure"
	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/kernel"
)

type Dispatch struct{ Kernel *kernel.Service }

func (d Dispatch) Dispatch(ctx context.Context, peer string, q infrastructure.DispatchRequest) (infrastructure.DispatchResponse, error) {
	if d.Kernel == nil || peer == "" {
		return infrastructure.DispatchResponse{}, kernel.ErrDenied
	}
	var r kernel.Reservation
	var err error
	switch q.Operation {
	case "reserve":
		r, err = d.Kernel.Reserve(ctx, kernel.ReserveRequest{Envelope: q.Envelope, Executor: peer, Capability: q.Capability, Recovery: q.Recovery, BlockedIDs: q.BlockedIDs})
	case "consume":
		r, err = d.Kernel.Consume(ctx, kernel.ConsumeRequest{ID: q.ReservationID, Executor: peer, Capability: q.Capability, Envelope: q.Envelope})
	case "status":
		r, err = d.Kernel.Status(ctx, q.ReservationID, peer)
	case "result":
		// An executor assertion can only add uncertainty. A confirmed effect must
		// already have been established by the independently authenticated receipt path.
		if q.Result == nil {
			return infrastructure.DispatchResponse{}, kernel.ErrDenied
		}
		if q.Result.Outcome == "unknown" {
			err = d.Kernel.MarkUnknown(ctx, q.ReservationID, peer)
		} else {
			err = errors.New("authenticated observation receipt required")
		}
		if err == nil {
			r, err = d.Kernel.Status(ctx, q.ReservationID, peer)
		}
	default:
		err = kernel.ErrDenied
	}
	if err != nil {
		return infrastructure.DispatchResponse{}, err
	}
	retry := "none"
	if r.State == "dispatching" || r.State == "outcome_unknown" {
		retry = "observe_first"
	}
	return infrastructure.DispatchResponse{ContractVersion: infrastructure.DispatchVersion, ReservationID: r.ID, State: r.State, Granted: r.Granted, Retry: retry}, nil
}
