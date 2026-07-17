<?php

namespace App\TelephonyDomain\Signaling;

use Illuminate\Support\Carbon;

final class KamailioRegistrationSnapshotDiffer
{
    /**
     * @param  array<string, array<string, string>>  $previous
     * @param  array<string, array<string, string>>  $current
     * @return list<array<string, mixed>>
     */
    public function diff(array $previous, array $current, Carbon $collectedAt): array
    {
        $events = [];

        foreach ($current as $identity => $row) {
            $prior = $previous[$identity] ?? null;
            if ($prior === null) {
                $events[] = $this->event('kamailio.registration.accepted', $identity, $row, $collectedAt);

                continue;
            }

            if (($prior['ruid'] ?? '') !== ($row['ruid'] ?? '')) {
                $events[] = $this->event('kamailio.registration.replaced', $identity, $row, $collectedAt, $prior);

                continue;
            }

            if (strtotime((string) ($row['last_modified_at'] ?? '')) > strtotime((string) ($prior['last_modified_at'] ?? ''))) {
                $events[] = $this->event('kamailio.registration.refreshed', $identity, $row, $collectedAt, $prior);
            }
        }

        foreach ($previous as $identity => $row) {
            if (array_key_exists($identity, $current)) {
                continue;
            }

            $expiresAt = Carbon::parse((string) $row['expires_at']);
            $events[] = $this->event(
                $expiresAt->greaterThan($collectedAt) ? 'kamailio.registration.removed' : 'kamailio.registration.expired',
                $identity,
                $row,
                $collectedAt,
            );
        }

        return $events;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string>|null  $previous
     * @return array<string, mixed>
     */
    private function event(string $type, string $identity, array $row, Carbon $collectedAt, ?array $previous = null): array
    {
        $payload = [
            'event_type' => $type,
            'signaling_identity' => $identity,
            'ruid' => (string) ($row['ruid'] ?? ''),
            'observed_expires_at' => (string) ($row['expires_at'] ?? ''),
            'last_modified_at' => (string) ($row['last_modified_at'] ?? ''),
            'contact_fingerprint' => (string) ($row['contact_fingerprint'] ?? ''),
            'occurred_at' => $collectedAt->toISOString(),
        ];

        if ($previous !== null) {
            $payload['previous_ruid'] = (string) ($previous['ruid'] ?? '');
            $payload['previous_observed_expires_at'] = (string) ($previous['expires_at'] ?? '');
            $payload['previous_contact_fingerprint'] = (string) ($previous['contact_fingerprint'] ?? '');
        }

        return $payload;
    }
}
