<?php

use App\ControlPlane\Shared\StableJson;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('external_trunk_projection_artifacts')
            ->where('provider', 'kamailio')
            ->orWhere('provider', 'asterisk')
            ->orderBy('id')
            ->get()
            ->each(function (object $row): void {
                $artifact = json_decode((string) $row->artifact, true, 512, JSON_THROW_ON_ERROR);
                $routes = $artifact['routes'] ?? [];
                $changed = ($artifact['schema'] ?? null) !== 'utcp.t6.projection.v2';

                if (is_array($routes)) {
                    foreach ($routes as &$route) {
                        if (! is_array($route)) {
                            continue;
                        }
                        if (! array_key_exists('destination_user', $route)) {
                            $route['destination_user'] = $this->destinationUser((string) ($route['address'] ?? ''));
                            $changed = true;
                        }
                    }
                    unset($route);
                    $artifact['routes'] = $routes;
                }

                if (! $changed) {
                    return;
                }

                $artifact['schema'] = 'utcp.t6.projection.v2';
                $encoded = StableJson::encode($artifact);
                DB::table('external_trunk_projection_artifacts')->where('id', $row->id)->update([
                    'artifact' => $encoded,
                    'artifact_hash' => hash('sha256', $encoded),
                    'projected_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        // Historical artifact contents are not downgraded.
    }

    private function destinationUser(string $normalizedAddress): string
    {
        if (preg_match('/^sips?:([^@]+)@/i', $normalizedAddress, $matches) === 1) {
            return $matches[1];
        }

        return $normalizedAddress;
    }
};
