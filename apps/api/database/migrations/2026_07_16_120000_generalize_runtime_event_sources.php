<?php

use App\RuntimeEngine\EngineIds;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_sources', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->string('source_kind', 80);
            $table->string('source_key', 200);
            $table->uuid('runtime_node_id')->nullable();
            $table->timestampsTz();

            $table->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->cascadeOnDelete();
            $table->unique(['source_kind', 'source_key'], 'event_sources_kind_key_unique');
            $table->index(['runtime_node_id'], 'event_sources_runtime_node_idx');
        });

        $this->relaxRuntimeMetadataNullability();

        Schema::table('runtime_listener_leases', function (Blueprint $table): void {
            $table->char('event_source_id', 32)->nullable()->after('id');
            $table->foreign('event_source_id')->references('id')->on('event_sources')->cascadeOnDelete();
            $table->unique(['event_source_id', 'listener_kind'], 'runtime_listener_source_kind_unique');
        });

        Schema::table('runtime_event_connection_epochs', function (Blueprint $table): void {
            $table->char('event_source_id', 32)->nullable()->after('id');
            $table->foreign('event_source_id')->references('id')->on('event_sources')->cascadeOnDelete();
            $table->index(['event_source_id', 'status'], 'runtime_event_epochs_source_status_idx');
        });

        Schema::table('runtime_event_receipts', function (Blueprint $table): void {
            $table->char('event_source_id', 32)->nullable()->after('id');
            $table->foreign('event_source_id')->references('id')->on('event_sources')->cascadeOnDelete();
            $table->unique(['event_source_id', 'connection_epoch_id', 'external_event_key'], 'runtime_event_receipts_source_dedupe_unique');
        });

        Schema::table('runtime_projection_checkpoints', function (Blueprint $table): void {
            $table->char('event_source_id', 32)->nullable()->after('id');
            $table->foreign('event_source_id')->references('id')->on('event_sources')->cascadeOnDelete();
            $table->unique(['projector', 'partition_key', 'event_source_id'], 'runtime_projection_checkpoint_source_unique');
        });

        $this->backfillRuntimeNodeSources();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE event_sources ADD CONSTRAINT event_sources_kind_check CHECK (source_kind IN ('runtime-node', 'kamailio-registration'))");
            DB::statement("ALTER TABLE event_sources ADD CONSTRAINT event_sources_runtime_node_shape_check CHECK ((source_kind = 'runtime-node' AND runtime_node_id IS NOT NULL) OR (source_kind <> 'runtime-node' AND runtime_node_id IS NULL))");
        }
    }

    public function down(): void
    {
        Schema::table('runtime_projection_checkpoints', function (Blueprint $table): void {
            $table->dropUnique('runtime_projection_checkpoint_source_unique');
            $table->dropForeign(['event_source_id']);
            $table->dropColumn('event_source_id');
        });

        Schema::table('runtime_event_receipts', function (Blueprint $table): void {
            $table->dropUnique('runtime_event_receipts_source_dedupe_unique');
            $table->dropForeign(['event_source_id']);
            $table->dropColumn('event_source_id');
        });

        Schema::table('runtime_event_connection_epochs', function (Blueprint $table): void {
            $table->dropIndex('runtime_event_epochs_source_status_idx');
            $table->dropForeign(['event_source_id']);
            $table->dropColumn('event_source_id');
        });

        Schema::table('runtime_listener_leases', function (Blueprint $table): void {
            $table->dropUnique('runtime_listener_source_kind_unique');
            $table->dropForeign(['event_source_id']);
            $table->dropColumn('event_source_id');
        });

        Schema::dropIfExists('event_sources');
    }

    private function relaxRuntimeMetadataNullability(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'runtime_listener_leases',
            'runtime_event_connection_epochs',
            'runtime_event_receipts',
        ] as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN tenant_id DROP NOT NULL");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN runtime_node_id DROP NOT NULL");
        }

        DB::statement('ALTER TABLE runtime_projection_checkpoints ALTER COLUMN runtime_node_id DROP NOT NULL');
    }

    private function backfillRuntimeNodeSources(): void
    {
        $now = now();
        foreach (DB::table('runtime_nodes')->select('id')->orderBy('id')->cursor() as $node) {
            $source = DB::table('event_sources')
                ->where('source_kind', 'runtime-node')
                ->where('source_key', $node->id)
                ->first();

            if ($source === null) {
                $sourceId = EngineIds::new();
                DB::table('event_sources')->insert([
                    'id' => $sourceId,
                    'source_kind' => 'runtime-node',
                    'source_key' => $node->id,
                    'runtime_node_id' => $node->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $sourceId = $source->id;
            }

            DB::table('runtime_listener_leases')
                ->where('runtime_node_id', $node->id)
                ->whereNull('event_source_id')
                ->update(['event_source_id' => $sourceId, 'updated_at' => $now]);

            DB::table('runtime_event_connection_epochs')
                ->where('runtime_node_id', $node->id)
                ->whereNull('event_source_id')
                ->update(['event_source_id' => $sourceId, 'updated_at' => $now]);

            DB::table('runtime_event_receipts')
                ->where('runtime_node_id', $node->id)
                ->whereNull('event_source_id')
                ->update(['event_source_id' => $sourceId, 'updated_at' => $now]);

            DB::table('runtime_projection_checkpoints')
                ->where('runtime_node_id', $node->id)
                ->whereNull('event_source_id')
                ->update(['event_source_id' => $sourceId, 'updated_at' => $now]);
        }
    }
};
