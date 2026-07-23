<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->assertRuntimeOperationUuidCompatibility();

        DB::statement('ALTER TABLE runtime_operations ALTER COLUMN tenant_id TYPE uuid USING tenant_id::uuid');
        DB::statement('ALTER TABLE runtime_operations ALTER COLUMN runtime_node_id TYPE uuid USING runtime_node_id::uuid');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE runtime_operations ALTER COLUMN runtime_node_id TYPE varchar(255) USING runtime_node_id::text');
        DB::statement('ALTER TABLE runtime_operations ALTER COLUMN tenant_id TYPE varchar(255) USING tenant_id::text');
    }

    private function assertRuntimeOperationUuidCompatibility(): void
    {
        $uuidPattern = '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$';

        $malformedTenants = (int) DB::scalar(
            'SELECT COUNT(*) FROM runtime_operations WHERE tenant_id IS NOT NULL AND tenant_id !~ ?',
            [$uuidPattern],
        );
        if ($malformedTenants > 0) {
            throw new RuntimeException("runtime_operations.tenant_id contains {$malformedTenants} non-UUID value(s)");
        }

        $malformedRuntimeNodes = (int) DB::scalar(
            'SELECT COUNT(*) FROM runtime_operations WHERE runtime_node_id IS NOT NULL AND runtime_node_id !~ ?',
            [$uuidPattern],
        );
        if ($malformedRuntimeNodes > 0) {
            throw new RuntimeException("runtime_operations.runtime_node_id contains {$malformedRuntimeNodes} non-UUID value(s)");
        }

        $missingTenants = (int) DB::scalar(
            <<<'SQL'
            SELECT COUNT(*)
            FROM runtime_operations
            WHERE tenant_id IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1
                  FROM tenants
                  WHERE tenants.id = runtime_operations.tenant_id::uuid
              )
            SQL,
        );
        if ($missingTenants > 0) {
            throw new RuntimeException("runtime_operations.tenant_id contains {$missingTenants} unresolved tenant reference(s)");
        }

        $missingRuntimeNodes = (int) DB::scalar(
            <<<'SQL'
            SELECT COUNT(*)
            FROM runtime_operations
            WHERE runtime_node_id IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1
                  FROM runtime_nodes
                  WHERE runtime_nodes.id = runtime_operations.runtime_node_id::uuid
                    AND runtime_nodes.tenant_id = runtime_operations.tenant_id::uuid
              )
            SQL,
        );
        if ($missingRuntimeNodes > 0) {
            throw new RuntimeException("runtime_operations.runtime_node_id contains {$missingRuntimeNodes} unresolved RuntimeNode reference(s)");
        }
    }
};
