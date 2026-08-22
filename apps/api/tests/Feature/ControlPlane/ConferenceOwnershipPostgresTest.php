<?php

namespace Tests\Feature\ControlPlane;

use App\Identity\IdentityIds;
use App\RuntimeAdapters\Asterisk\AsteriskAriClient;
use App\RuntimeAdapters\Asterisk\AsteriskAriEventNormalizer;
use App\RuntimeAdapters\Asterisk\AsteriskAriProfileService;
use App\RuntimeAdapters\Asterisk\AsteriskCatalog;
use App\RuntimeAdapters\Asterisk\AsteriskRuntimeAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ConferenceOwnershipPostgresTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgres_conference_channel_ownership_uses_conference_runtime_node_and_generic_calls_are_safe(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The conference ownership regression requires PostgreSQL.');
        }

        [$tenantId, $nodeA, $nodeB, $userId, $sessionId] = $this->context();
        $conferenceId = IdentityIds::new();
        $participantId = IdentityIds::new();
        $channelId = 'conf-channel-postgres';
        $now = now();

        DB::table('conferences')->insert([
            'id' => $conferenceId,
            'tenant_id' => $tenantId,
            'slug' => 'postgres-ownership',
            'display_name' => 'PostgreSQL ownership',
            'runtime_node_id' => $nodeA,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('conference_participants')->insert([
            'id' => $participantId,
            'tenant_id' => $tenantId,
            'conference_id' => $conferenceId,
            'telephony_session_id' => $sessionId,
            'user_id' => $userId,
            'runtime_channel_id' => $channelId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertTrue(DB::table('conference_participants')
            ->join('conferences', 'conferences.id', '=', 'conference_participants.conference_id')
            ->where('conference_participants.tenant_id', $tenantId)
            ->where('conferences.tenant_id', $tenantId)
            ->where('conferences.runtime_node_id', $nodeA)
            ->where('conference_participants.runtime_channel_id', $channelId)
            ->exists());
        $this->assertFalse(DB::table('conference_participants')
            ->join('conferences', 'conferences.id', '=', 'conference_participants.conference_id')
            ->where('conference_participants.tenant_id', $tenantId)
            ->where('conferences.tenant_id', $tenantId)
            ->where('conferences.runtime_node_id', $nodeB)
            ->where('conference_participants.runtime_channel_id', $channelId)
            ->exists());

        $client = new class(new AsteriskCatalog, app(AsteriskAriProfileService::class)) extends AsteriskAriClient
        {
            public function executeCallOperation(string $tenantId, string $runtimeNodeId, string $operationType, array $payload, array $legs): array
            {
                return ['provider_action' => $operationType, 'runtime_channel_id' => $legs[0]['runtime_channel_id'] ?? null];
            }
        };
        $adapter = new AsteriskRuntimeAdapter(new AsteriskCatalog, $client);

        $conferenceCallId = Str::uuid()->toString();
        $conferenceLegId = Str::uuid()->toString();
        DB::table('calls')->insert(['id' => $conferenceCallId, 'tenant_id' => $tenantId, 'direction' => 'outbound', 'observed_state' => 'answered', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('call_legs')->insert(['id' => $conferenceLegId, 'tenant_id' => $tenantId, 'call_id' => $conferenceCallId, 'runtime_node_id' => $nodeA, 'runtime_channel_id' => $channelId, 'direction' => 'outbound', 'role' => 'source', 'observed_state' => 'answered', 'created_at' => $now, 'updated_at' => $now]);
        $sameNode = $adapter->execute($this->callOperation($conferenceCallId, $conferenceLegId, $nodeA, $channelId));
        $this->assertSame('terminal_failure', $sameNode['status']);
        $this->assertSame('asterisk_conference_channel_not_generic', $sameNode['failure_code']);

        $wrongNodeCallId = Str::uuid()->toString();
        $wrongNodeLegId = Str::uuid()->toString();
        DB::table('calls')->insert(['id' => $wrongNodeCallId, 'tenant_id' => $tenantId, 'direction' => 'outbound', 'observed_state' => 'answered', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('call_legs')->insert(['id' => $wrongNodeLegId, 'tenant_id' => $tenantId, 'call_id' => $wrongNodeCallId, 'runtime_node_id' => $nodeB, 'runtime_channel_id' => $channelId, 'direction' => 'outbound', 'role' => 'source', 'observed_state' => 'answered', 'created_at' => $now, 'updated_at' => $now]);
        $wrongNode = $adapter->execute($this->callOperation($wrongNodeCallId, $wrongNodeLegId, $nodeB, $channelId));
        $this->assertSame('completed', $wrongNode['status']);

        $genericCallId = Str::uuid()->toString();
        $genericLegId = Str::uuid()->toString();
        DB::table('calls')->insert(['id' => $genericCallId, 'tenant_id' => $tenantId, 'direction' => 'outbound', 'observed_state' => 'answered', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('call_legs')->insert(['id' => $genericLegId, 'tenant_id' => $tenantId, 'call_id' => $genericCallId, 'runtime_node_id' => $nodeA, 'runtime_channel_id' => 'utcp-call-leg-'.$genericLegId, 'direction' => 'outbound', 'role' => 'source', 'observed_state' => 'answered', 'created_at' => $now, 'updated_at' => $now]);
        $generic = $adapter->execute($this->callOperation($genericCallId, $genericLegId, $nodeA, 'utcp-call-leg-'.$genericLegId));
        $this->assertSame('completed', $generic['status']);

        $receipt = (object) [
            'tenant_id' => $tenantId,
            'runtime_node_id' => $nodeA,
        ];
        $genericEvents = (new AsteriskAriEventNormalizer(
            new AsteriskCatalog,
            (new AsteriskCatalog)->eventType('stasis_start'),
        ))->normalize($receipt, [
            'channel_id' => 'utcp-call-leg-'.$genericLegId,
            'occurred_at' => now()->toISOString(),
        ]);
        $this->assertCount(1, $genericEvents);
        $this->assertSame('call.leg.offered', $genericEvents[0]['observation_type']);
        $this->assertSame($genericLegId, $genericEvents[0]['subject_id']);

        $conferenceEvents = (new AsteriskAriEventNormalizer(
            new AsteriskCatalog,
            (new AsteriskCatalog)->eventType('channel_destroyed'),
        ))->normalize($receipt, [
            'channel_id' => $channelId,
            'occurred_at' => now()->toISOString(),
        ]);
        $this->assertSame([], $conferenceEvents);
    }

    /** @return array{0:string,1:string,2:string,3:string,4:string} */
    private function context(): array
    {
        $tenantId = IdentityIds::new();
        $nodeA = IdentityIds::new();
        $nodeB = IdentityIds::new();
        $userId = IdentityIds::new();
        $sessionId = IdentityIds::new();
        $now = now();
        DB::table('tenants')->insert(['id' => $tenantId, 'slug' => 'postgres-ownership-'.substr($tenantId, 0, 8), 'display_name' => 'Postgres ownership', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('users')->insert(['id' => $userId, 'email' => 'postgres-ownership-'.substr($userId, 0, 8).'@utcp.local.test', 'normalized_email' => 'postgres-ownership-'.substr($userId, 0, 8).'@utcp.local.test', 'display_name' => 'Postgres ownership', 'password' => 'not-used', 'created_at' => $now, 'updated_at' => $now]);
        foreach ([[$nodeA, 'asterisk-a'], [$nodeB, 'asterisk-b']] as [$id, $slug]) {
            DB::table('runtime_nodes')->insert(['id' => $id, 'tenant_id' => $tenantId, 'name' => $slug, 'slug' => $slug.'-'.substr($id, 0, 8), 'runtime_family' => 'asterisk', 'adapter_key' => 'asterisk-ari', 'desired_state' => 'active', 'observed_state' => 'unobserved', 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('telephony_sessions')->insert(['id' => $sessionId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'status' => 'ended', 'issued_at' => $now, 'expires_at' => $now->addHour(), 'created_at' => $now, 'updated_at' => $now]);

        return [$tenantId, $nodeA, $nodeB, $userId, $sessionId];
    }

    /** @return array<string, mixed> */
    private function callOperation(string $callId, string $legId, string $nodeId, string $channelId): array
    {
        return ['operation_type' => 'call.leg.answer', 'aggregate_type' => 'call_leg', 'aggregate_id' => $legId, 'runtime_node_id' => $nodeId, 'payload' => ['call_id' => $callId, 'leg_id' => $legId, 'runtime_channel_id' => $channelId]];
    }
}
