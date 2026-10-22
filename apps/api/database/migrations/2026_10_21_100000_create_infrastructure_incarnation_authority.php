<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infrastructure_incarnations', function (Blueprint $t): void {
            $t->string('id', 32)->primary();
            $t->uuid('tenant_id');
            $t->uuid('runtime_node_id');
            $t->string('cluster_id', 128);
            $t->string('namespace', 63);
            $t->string('physical_name', 63);
            $t->string('runtime_node_slug', 63);
            $t->string('target_id', 128);
            $t->string('kubernetes_uid', 128)->nullable();
            $t->string('state', 16)->default('preparing');
            $t->bigInteger('generation')->default(1);
            $t->string('profile', 40);
            $t->json('configuration');
            $t->string('prepare_key', 128);
            $t->timestampsTz();
            $t->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $t->foreign('runtime_node_id')->references('id')->on('runtime_nodes')->restrictOnDelete();
            $t->unique(['cluster_id', 'namespace', 'physical_name'], 'infra_physical_identity_unique');
            $t->unique(['tenant_id', 'runtime_node_id', 'prepare_key'], 'infra_preparation_unique');
            $t->index(['runtime_node_id', 'state']);
        });
        Schema::create('infrastructure_intents', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('incarnation_id', 32);
            // Match the existing kernel's character operation identity; tenant/node IDs are native UUIDs.
            $t->char('operation_id', 32);
            $t->bigInteger('generation');
            $t->string('action', 40);
            $t->string('fingerprint', 64);
            // Closed allowlisted infrastructure envelope; no credentials or lease token.
            $t->json('envelope');
            $t->string('outcome', 32)->default('not_dispatched');
            $t->boolean('superseded')->default(false);
            $t->unsignedInteger('dispatch_attempt')->nullable();
            $t->timestampsTz();
            $t->foreign('incarnation_id')->references('id')->on('infrastructure_incarnations')->restrictOnDelete();
            $t->foreign('operation_id')->references('id')->on('runtime_operations')->restrictOnDelete();
            $t->unique(['incarnation_id', 'generation', 'action'], 'infra_generation_action_unique');
            $t->unique(['operation_id', 'action'], 'infra_operation_action_unique');
            $t->index(['outcome', 'superseded']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE infrastructure_incarnations ADD CONSTRAINT infra_lifecycle_check CHECK (state IN ('preparing','bound','retiring','retired') AND generation > 0 AND physical_name = 'utcp-' || id AND (state <> 'bound' OR kubernetes_uid IS NOT NULL))");
            DB::statement("ALTER TABLE infrastructure_intents ADD CONSTRAINT infra_outcome_check CHECK (generation > 0 AND outcome IN ('not_dispatched','dispatch_rejected','dispatch_attempted','outcome_unknown','confirmed_applied','confirmed_not_applied','requires_observation','terminally_resolved'))");
            DB::statement("CREATE UNIQUE INDEX infra_one_current_incarnation ON infrastructure_incarnations (runtime_node_id) WHERE state <> 'retired'");
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION utcp_guard_infrastructure_incarnation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'infrastructure identity history is retained'; END IF;
 IF TG_OP = 'INSERT' THEN
  IF NEW.state <> 'preparing' OR NEW.generation <> 1 OR NEW.kubernetes_uid IS NOT NULL
  OR NOT EXISTS (SELECT 1 FROM runtime_nodes WHERE id = NEW.runtime_node_id AND tenant_id = NEW.tenant_id)
  THEN RAISE EXCEPTION 'infrastructure initial canonical identity violation'; END IF;
  RETURN NEW;
 END IF;
 IF ROW(NEW.id,NEW.tenant_id,NEW.runtime_node_id,NEW.cluster_id,NEW.namespace,NEW.physical_name,NEW.runtime_node_slug,NEW.target_id,NEW.profile,NEW.prepare_key,NEW.configuration::text)
 IS DISTINCT FROM ROW(OLD.id,OLD.tenant_id,OLD.runtime_node_id,OLD.cluster_id,OLD.namespace,OLD.physical_name,OLD.runtime_node_slug,OLD.target_id,OLD.profile,OLD.prepare_key,OLD.configuration::text)
 OR (OLD.kubernetes_uid IS NOT NULL AND NEW.kubernetes_uid IS DISTINCT FROM OLD.kubernetes_uid)
 OR NEW.generation < OLD.generation
 OR (NEW.state <> OLD.state AND NOT ((OLD.state = 'preparing' AND NEW.state IN ('bound','retiring')) OR (OLD.state = 'bound' AND NEW.state = 'retiring') OR (OLD.state = 'retiring' AND NEW.state = 'retired')))
 OR (OLD.state = 'retired' AND NEW IS DISTINCT FROM OLD)
 THEN RAISE EXCEPTION 'infrastructure immutable identity or lifecycle violation'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER infrastructure_incarnation_guard BEFORE INSERT OR UPDATE OR DELETE ON infrastructure_incarnations FOR EACH ROW EXECUTE FUNCTION utcp_guard_infrastructure_incarnation();
CREATE OR REPLACE FUNCTION utcp_guard_infrastructure_intent() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'infrastructure intent history is retained'; END IF;
 IF ROW(NEW.id,NEW.incarnation_id,NEW.operation_id,NEW.generation,NEW.action,NEW.fingerprint,NEW.envelope::text)
 IS DISTINCT FROM ROW(OLD.id,OLD.incarnation_id,OLD.operation_id,OLD.generation,OLD.action,OLD.fingerprint,OLD.envelope::text)
 OR (OLD.superseded AND NOT NEW.superseded)
 THEN RAISE EXCEPTION 'infrastructure intent is immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER infrastructure_intent_guard BEFORE UPDATE OR DELETE ON infrastructure_intents FOR EACH ROW EXECUTE FUNCTION utcp_guard_infrastructure_intent();
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('infrastructure_intents');
        Schema::dropIfExists('infrastructure_incarnations');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS utcp_guard_infrastructure_intent()');
            DB::statement('DROP FUNCTION IF EXISTS utcp_guard_infrastructure_incarnation()');
        }
    }
};
