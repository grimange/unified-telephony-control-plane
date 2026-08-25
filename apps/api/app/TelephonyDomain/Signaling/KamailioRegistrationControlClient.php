<?php

namespace App\TelephonyDomain\Signaling;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/** Internal-only provider execution seam; never an Admin API. */
final class KamailioRegistrationControlClient
{
    /** @return list<array<string,mixed>> */
    public function snapshot(string $tenantId): array
    {
        $endpoint = trim((string) config('telephony.kamailio_registration_control_url', ''));
        if ($endpoint === '') {
            return [];
        }
        $result = $this->call($endpoint, 'uac.reg_dump', []);
        return is_array($result) ? array_values(array_filter($result, 'is_array')) : [];
    }

    public function reconcile(string $tenantId): void
    {
        $endpoint = trim((string) config('telephony.kamailio_registration_control_url', ''));
        if ($endpoint === '') {
            return;
        }
        $before = $this->snapshot($tenantId);
        $current = DB::table('kamailio_external_trunk_registration_view')
            ->where('tenant_id', $tenantId)
            ->pluck('l_uuid')
            ->map(fn ($uuid): string => (string) $uuid)
            ->all();
        foreach (array_diff(array_map(fn (array $row): string => (string) ($row['l_uuid'] ?? ''), $before), $current) as $uuid) {
            if ($uuid !== '') {
                $this->call($endpoint, 'uac.reg_unregister', [$uuid]);
                $this->call($endpoint, 'uac.reg_remove', [$uuid]);
            }
        }
        foreach ($current as $uuid) {
            $this->call($endpoint, 'uac.reg_refresh', [(string) $uuid]);
        }
    }

    /** @return array<string,mixed>|list<array<string,mixed>> */
    private function call(string $endpoint, string $method, array $params): array
    {
        $response = Http::timeout(5)->post($endpoint, ['jsonrpc' => '2.0', 'id' => $method, 'method' => $method, 'params' => $params]);
        if (! $response->successful()) {
            Log::warning('kamailio registration control request failed', ['method' => $method, 'result' => 'provider_unavailable']);
            throw new \RuntimeException('Kamailio registration control request failed.');
        }
        $result = $response->json('result');
        return is_array($result) ? $result : [];
    }
}
