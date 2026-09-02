<?php

namespace Tests\Feature\TelephonyDomain;

use App\ControlPlane\Shared\RecordingArtifactId;
use App\ControlPlane\Shared\RecordingSessionId;
use App\Identity\IdentityIds;
use App\TelephonyDomain\CaptureReference;
use App\TelephonyDomain\RecordingArtifactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

final class RecordingArtifactTest extends TestCase
{
    use RefreshDatabase;

    public function test_artifact_identity_is_independent_and_validated(): void
    {
        $first = RecordingArtifactId::new()->value();
        $second = RecordingArtifactId::new()->value();

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $first);
        $this->assertNotSame($first, $second);
        $this->assertSame($first, RecordingArtifactId::fromString($first)->value());

        $this->expectException(InvalidArgumentException::class);
        RecordingArtifactId::fromString('not-an-artifact-id');
    }

    public function test_observations_create_and_finalize_one_artifact_idempotently(): void
    {
        [$tenant, $node, $session] = $this->fixture();
        $capture = CaptureReference::forRecordingSession($session)->canonical();
        $service = app(RecordingArtifactService::class);

        $service->applyCaptureObservation($tenant, $capture, 'started', $node, null, null, '2026-09-02T12:00:00Z');
        $service->applyCaptureObservation($tenant, $capture, 'started', $node, null, null, '2026-09-02T12:01:00Z');
        $pending = DB::table('recording_artifacts')->where('recording_session_id', $session)->first();
        $this->assertNotNull($pending);
        $this->assertSame('pending', $pending->state);
        $this->assertSame('2026-09-02T12:00:00Z', $pending->observed_started_at);

        $service->applyCaptureObservation($tenant, $capture, 'finished', $node, 'WAV', 3000, '2026-09-02T12:02:00Z');
        $service->applyCaptureObservation($tenant, $capture, 'finished', $node, 'WAV', 3000, '2026-09-02T12:03:00Z');
        $artifact = DB::table('recording_artifacts')->where('recording_session_id', $session)->first();
        $this->assertSame('available', $artifact->state);
        $this->assertSame('wav', $artifact->media_format);
        $this->assertSame(3000, (int) $artifact->duration_ms);
        $this->assertSame(1, DB::table('recording_artifacts')->where('recording_session_id', $session)->count());
        $this->assertSame(1, DB::table('control_plane_outbox_messages')->where('event_type', 'recording_artifact.available')->count());
        $this->assertSame(1, DB::table('control_plane_audit_records')->where('action', 'recording_artifact.available')->count());
    }

    public function test_finished_before_started_is_order_independent_and_available_does_not_regress(): void
    {
        [$tenant, $node, $session] = $this->fixture();
        $service = app(RecordingArtifactService::class);
        $capture = CaptureReference::forRecordingSession($session)->canonical();

        $service->applyCaptureObservation($tenant, $capture, 'finished', $node, 'wav', 0, null);
        $this->assertSame('available', DB::table('recording_artifacts')->where('recording_session_id', $session)->value('state'));
        $service->applyCaptureObservation($tenant, $capture, 'started', $node, null, null, null);

        $this->assertSame('available', DB::table('recording_artifacts')->where('recording_session_id', $session)->value('state'));
        $this->assertSame(1, DB::table('control_plane_outbox_messages')->where('event_type', 'recording_artifact.available')->count());
    }

    public function test_exact_session_correlation_is_tenant_scoped_and_unknown_capture_creates_nothing(): void
    {
        [$tenant, $node, $sessionA] = $this->fixture();
        [, , $sessionB] = $this->fixture('second');
        $service = app(RecordingArtifactService::class);

        $service->applyCaptureObservation($tenant, CaptureReference::forRecordingSession($sessionA)->canonical(), 'started', $node, null, null, null);
        $this->assertDatabaseHas('recording_artifacts', ['recording_session_id' => $sessionA]);
        $this->assertDatabaseMissing('recording_artifacts', ['recording_session_id' => $sessionB]);

        $service->applyCaptureObservation($tenant, 'utcp:capture/'.str_repeat('a', 32), 'started', $node, null, null, null);
        $this->assertSame(1, DB::table('recording_artifacts')->count());
        $this->assertSame('requested', DB::table('recording_sessions')->where('id', $sessionA)->value('observed_state'));
    }

    public function test_invalid_capture_and_invalid_state_do_not_create_artifacts(): void
    {
        [$tenant, $node, $session] = $this->fixture();
        $service = app(RecordingArtifactService::class);

        try {
            $service->applyCaptureObservation($tenant, 'invalid', 'started', $node, null, null, null);
            $this->fail('malformed capture references must be rejected');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('invalid_capture_ref', $exception->getMessage());
        }
        try {
            $service->applyCaptureObservation($tenant, CaptureReference::forRecordingSession($session)->canonical(), 'available', $node, 'wav', null, null);
            $this->fail('unsupported artifact states must be rejected');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('invalid recording artifact observation state', $exception->getMessage());
        }
        $this->assertDatabaseCount('recording_artifacts', 0);
    }

    public function test_artifact_schema_has_required_cardinality_and_metadata_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('recording_artifacts', [
            'id', 'tenant_id', 'recording_session_id', 'call_id', 'call_leg_id', 'runtime_node_id',
            'capture_ref', 'state', 'media_format', 'duration_ms', 'observed_started_at', 'available_at',
            'created_at', 'updated_at',
        ]));
        $this->assertFalse(Schema::hasColumn('call_legs', 'recording_ref'));
        $indexes = Schema::getIndexes('recording_artifacts');
        $this->assertContains('recording_artifacts_session_unique', array_column($indexes, 'name'));
        $this->assertContains('recording_artifacts_tenant_state_idx', array_column($indexes, 'name'));
        $this->assertContains('recording_artifacts_tenant_call_idx', array_column($indexes, 'name'));
        $this->assertContains('recording_artifacts_tenant_leg_idx', array_column($indexes, 'name'));
    }

    /** @return array{0:string,1:string,2:string} */
    private function fixture(string $suffix = 'one'): array
    {
        $tenant = IdentityIds::new();
        $node = IdentityIds::new();
        $call = IdentityIds::new();
        $leg = IdentityIds::new();
        $session = RecordingSessionId::new()->value();
        $now = now();
        DB::table('tenants')->insert(['id' => $tenant, 'slug' => 'artifact-'.$suffix.'-'.substr($tenant, 0, 8), 'display_name' => 'Artifact '.$suffix, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('runtime_nodes')->insert(['id' => $node, 'tenant_id' => $tenant, 'name' => 'Artifact node', 'slug' => 'artifact-node-'.$suffix.'-'.substr($node, 0, 8), 'runtime_family' => 'simulator', 'adapter_key' => 'simulator-deterministic', 'desired_state' => 'active', 'observed_state' => 'ready', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('calls')->insert(['id' => $call, 'tenant_id' => $tenant, 'direction' => 'outbound', 'observed_state' => 'answered', 'runtime_node_id' => $node, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('call_legs')->insert(['id' => $leg, 'tenant_id' => $tenant, 'call_id' => $call, 'runtime_node_id' => $node, 'direction' => 'outbound', 'role' => 'source', 'observed_state' => 'answered', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('recording_sessions')->insert(['id' => $session, 'tenant_id' => $tenant, 'call_id' => $call, 'call_leg_id' => $leg, 'desired_state' => 'recording', 'observed_state' => 'requested', 'requested_at' => $now, 'created_at' => $now, 'updated_at' => $now]);

        return [$tenant, $node, $session];
    }
}
