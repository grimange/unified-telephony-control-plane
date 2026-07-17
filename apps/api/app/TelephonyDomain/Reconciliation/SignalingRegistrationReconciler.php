<?php

namespace App\TelephonyDomain\Reconciliation;

use App\RuntimeEngine\Reconciliation\Reconciler;
use App\RuntimeEngine\Reconciliation\ReconciliationResult;
use Illuminate\Support\Facades\DB;

final class SignalingRegistrationReconciler implements Reconciler
{
    public function targetType(): string
    {
        return 'signaling_registration';
    }

    public function evaluate(object $claim): ReconciliationResult
    {
        $row = DB::table('signaling_registration_observations')->where('id', $claim->target_id)->first();
        if ($row === null) {
            return ReconciliationResult::blocked('signaling_registration_missing');
        }

        $session = DB::table('telephony_sessions')->where('id', $row->telephony_session_id)->first();
        $hasActiveCredential = DB::table('telephony_signaling_credentials')
            ->where('telephony_session_id', $row->telephony_session_id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->exists();

        if ($session !== null && $session->status === 'active' && $session->expires_at > now() && $hasActiveCredential) {
            if ($row->desired_state !== 'eligible') {
                return ReconciliationResult::waiting('desired_state_update_pending', 30);
            }

            return match ((string) $row->observed_state) {
                'registered' => ReconciliationResult::converged(30),
                'pending_removal' => ReconciliationResult::waiting('registration_pending_replacement', 30),
                default => ReconciliationResult::waiting('client_register_pending', 30),
            };
        }

        if ($row->desired_state !== 'removed') {
            return ReconciliationResult::waiting('desired_removal_pending', 30);
        }

        return match ((string) $row->observed_state) {
            'unregistered', 'expired' => ReconciliationResult::converged(60),
            'registered', 'pending_removal' => ReconciliationResult::waiting('bounded_contact_expiry_pending', 30),
            default => ReconciliationResult::waiting('registration_removal_unobserved', 30),
        };
    }
}
