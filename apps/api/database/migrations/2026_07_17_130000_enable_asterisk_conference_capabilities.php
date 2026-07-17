<?php

use App\Identity\IdentityIds;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();
        $runtimeNodeIds = DB::table('runtime_nodes')
            ->where('adapter_key', 'asterisk-ari')
            ->pluck('id');

        foreach ($runtimeNodeIds as $runtimeNodeId) {
            foreach (['conference.lifecycle', 'conference.participation'] as $capability) {
                $exists = DB::table('runtime_node_capabilities')
                    ->where('runtime_node_id', $runtimeNodeId)
                    ->where('capability_key', $capability)
                    ->exists();
                if (! $exists) {
                    DB::table('runtime_node_capabilities')->insert([
                        'id' => IdentityIds::new(),
                        'runtime_node_id' => $runtimeNodeId,
                        'capability_key' => $capability,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $runtimeNodeIds = DB::table('runtime_nodes')
            ->where('adapter_key', 'asterisk-ari')
            ->pluck('id');

        DB::table('runtime_node_capabilities')
            ->whereIn('runtime_node_id', $runtimeNodeIds)
            ->whereIn('capability_key', ['conference.lifecycle', 'conference.participation'])
            ->delete();
    }
};
