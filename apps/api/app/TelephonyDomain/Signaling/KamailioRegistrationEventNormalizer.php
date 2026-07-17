<?php

namespace App\TelephonyDomain\Signaling;

use App\RuntimeEngine\Events\EventNormalizer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class KamailioRegistrationEventNormalizer implements EventNormalizer
{
    public function __construct(private readonly string $eventType) {}

    public function adapterKey(): string
    {
        return KamailioRegistrationObserver::ADAPTER_KEY;
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    public function eventVersion(): int
    {
        return 1;
    }

    public function normalize(object $receipt, array $payload): array
    {
        $identity = is_string($payload['signaling_identity'] ?? null) ? (string) $payload['signaling_identity'] : '';
        if ($identity === '') {
            throw new InvalidArgumentException('signaling identity is required');
        }

        $registration = DB::table('signaling_registration_observations')
            ->where('signaling_identity', $identity)
            ->orderByDesc('updated_at')
            ->first();

        if ($registration === null) {
            $credential = DB::table('telephony_signaling_credentials')
                ->where('username', $identity)
                ->orderByDesc('issued_at')
                ->first();
            if ($credential === null) {
                throw new InvalidArgumentException('signaling identity is not mapped to a TelephonySession');
            }
            $registration = (object) [
                'tenant_id' => $credential->tenant_id,
                'telephony_session_id' => $credential->telephony_session_id,
                'desired_state' => 'eligible',
            ];
        }

        $state = match ($this->eventType) {
            'kamailio.registration.accepted',
            'kamailio.registration.refreshed',
            'kamailio.registration.replaced' => ((string) ($registration->desired_state ?? '') === 'removed' ? 'pending_removal' : 'registered'),
            'kamailio.registration.removed' => 'unregistered',
            'kamailio.registration.expired' => 'expired',
            default => throw new InvalidArgumentException('unsupported kamailio registration event'),
        };

        return [[
            'tenant_id' => (string) $registration->tenant_id,
            'observation_type' => 'signaling.registration.observed',
            'observation_version' => 1,
            'subject_type' => 'signaling_registration',
            'subject_id' => (string) $registration->telephony_session_id,
            'observed_state' => $state,
            'observed_at' => is_string($payload['occurred_at'] ?? null) ? $payload['occurred_at'] : now(),
            'payload' => [
                'adapter_key' => $this->adapterKey(),
                'event_type' => $this->eventType,
                'signaling_identity' => $identity,
                'observed_expires_at' => is_string($payload['observed_expires_at'] ?? null) ? $payload['observed_expires_at'] : null,
                'ruid' => is_string($payload['ruid'] ?? null) ? $payload['ruid'] : null,
                'contact_fingerprint' => is_string($payload['contact_fingerprint'] ?? null) ? $payload['contact_fingerprint'] : null,
            ],
        ]];
    }
}
