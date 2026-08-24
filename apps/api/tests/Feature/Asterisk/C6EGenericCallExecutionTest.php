<?php

namespace Tests\Feature\Asterisk;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\RuntimeAdapters\Asterisk\AsteriskAriClient;
use App\RuntimeAdapters\Asterisk\AsteriskAriEventNormalizer;
use App\RuntimeAdapters\Asterisk\AsteriskAriProfileService;
use App\RuntimeAdapters\Asterisk\AsteriskCatalog;
use App\RuntimeAdapters\Asterisk\AsteriskRuntimeAdapter;
use App\RuntimeEngine\Events\RuntimeEventReceiptRepository;
use App\RuntimeEngine\Projection\ProjectionService;
use App\TelephonyDomain\CallOperationCatalog;
use App\TelephonyDomain\RuntimeChannelIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class C6EGenericCallExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_generic_inbound_proof_fixture_enters_generic_stasis_without_conference_or_t3_routing(): void
    {
        $dialplan = file_get_contents(base_path('../../infrastructure/kubernetes/components/asterisk-sip-fixtures/extensions.local.conf'));

        $this->assertIsString($dialplan);
        $this->assertStringContainsString('exten => c6-generic-proof,1,NoOp(UTCP C6 generic inbound proof fixture)', $dialplan);
        $this->assertStringContainsString('Stasis(utcp-t0-observation,c6-generic-proof)', $dialplan);
        $this->assertStringNotContainsString('c6-generic-proof,1,NoOp(UTCP local T3-S2A media fixture)', $dialplan);
        $this->assertStringContainsString('exten => 9900,1,NoOp(UTCP local T3-S2A media fixture)', $dialplan);
        $this->assertStringContainsString('exten => _[c]o[n]f-.,1', file_get_contents(base_path('../../infrastructure/docker/asterisk/config/extensions.conf')));
        $this->assertStringNotContainsString('conference', strtolower($dialplan));
    }

    public function test_catalog_operations_execute_through_asterisk_runtime_adapter_without_mutating_call_state(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $callId = Str::uuid()->toString();
        $legId = Str::uuid()->toString();
        $now = now();
        DB::table('calls')->insert(['id' => $callId, 'tenant_id' => $tenantId, 'direction' => 'outbound', 'observed_state' => 'answered', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('call_legs')->insert(['id' => $legId, 'tenant_id' => $tenantId, 'call_id' => $callId, 'runtime_node_id' => $nodeId, 'runtime_channel_id' => 'ari-channel-1', 'direction' => 'outbound', 'role' => 'source', 'observed_state' => 'answered', 'created_at' => $now, 'updated_at' => $now]);

        $catalog = new AsteriskCatalog;
        $client = new class($catalog, app(AsteriskAriProfileService::class)) extends AsteriskAriClient
        {
            public array $operations = [];

            public function executeCallOperation(string $tenantId, string $runtimeNodeId, string $operationType, array $payload, array $legs): array
            {
                $this->operations[] = compact('tenantId', 'runtimeNodeId', 'operationType', 'payload', 'legs');

                return ['provider_action' => 'channels.answer', 'runtime_channel_id' => $legs[0]['runtime_channel_id']];
            }
        };
        $adapter = new AsteriskRuntimeAdapter($catalog, $client);

        $result = $adapter->execute([
            'operation_type' => 'call.leg.answer',
            'aggregate_type' => 'call_leg',
            'aggregate_id' => $legId,
            'runtime_node_id' => $nodeId,
            'payload' => ['call_id' => $callId, 'leg_id' => $legId, 'runtime_channel_id' => 'ari-channel-1'],
        ]);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('ari-channel-1', $client->operations[0]['legs'][0]['runtime_channel_id']);
        $this->assertSame('answered', DB::table('call_legs')->where('id', $legId)->value('observed_state'));
        $this->assertSame(19, count(CallOperationCatalog::all()));
    }

    public function test_stale_runtime_channel_is_rejected_before_ari_execution(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $callId = Str::uuid()->toString();
        $legId = Str::uuid()->toString();
        $now = now();
        DB::table('calls')->insert(['id' => $callId, 'tenant_id' => $tenantId, 'direction' => 'outbound', 'observed_state' => 'ringing', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('call_legs')->insert(['id' => $legId, 'tenant_id' => $tenantId, 'call_id' => $callId, 'runtime_node_id' => $nodeId, 'runtime_channel_id' => 'current-channel', 'direction' => 'outbound', 'role' => 'source', 'observed_state' => 'ringing', 'created_at' => $now, 'updated_at' => $now]);
        $catalog = new AsteriskCatalog;
        $client = new class($catalog, app(AsteriskAriProfileService::class)) extends AsteriskAriClient
        {
            public int $calls = 0;

            public function executeCallOperation(string $tenantId, string $runtimeNodeId, string $operationType, array $payload, array $legs): array
            {
                $this->calls++;

                return [];
            }
        };

        $result = (new AsteriskRuntimeAdapter($catalog, $client))->execute([
            'operation_type' => 'call.leg.hangup',
            'aggregate_type' => 'call_leg',
            'aggregate_id' => $legId,
            'runtime_node_id' => $nodeId,
            'payload' => ['call_id' => $callId, 'leg_id' => $legId, 'runtime_channel_id' => 'old-channel'],
        ]);

        $this->assertSame('terminal_failure', $result['status']);
        $this->assertSame(FailureClass::Conflict->value, $result['failure_class']);
        $this->assertSame(0, $client->calls);
    }

    public function test_asterisk_stasis_start_uses_c6_observation_ingress_for_inbound_adoption(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $receipts = app(RuntimeEventReceiptRepository::class);
        $epoch = $receipts->openEpoch($tenantId, $nodeId, 'asterisk-ari', 'c6e-epoch');
        $accepted = $receipts->ingest($tenantId, $nodeId, 'asterisk-ari', $epoch, 'ari:stasis-start-1', 'asterisk.ari.channel.stasis_start', 1, []);
        $receipt = DB::table('runtime_event_receipts')->where('id', $accepted['id'])->first();
        $normalizer = new AsteriskAriEventNormalizer(new AsteriskCatalog, (new AsteriskCatalog)->eventType('stasis_start'));
        app(ProjectionService::class)->apply($receipt, $normalizer->normalize($receipt, [
            'runtime_node_id' => $nodeId,
            'configuration_generation' => 1,
            'channel_id' => 'inbound-ari-channel',
            'remote_identity' => '+15550101',
            'occurred_at' => now()->toISOString(),
        ]));

        $leg = DB::table('call_legs')->where('runtime_node_id', $nodeId)->where('runtime_channel_id', 'inbound-ari-channel')->first();
        $this->assertNotNull($leg);
        $this->assertSame('inbound', DB::table('calls')->where('id', $leg->call_id)->value('direction'));
        $this->assertSame('offered', $leg->observed_state);
        $this->assertDatabaseHas('runtime_observations', ['observation_type' => 'call.leg.offered']);
    }

    public function test_asterisk_originate_channel_is_deterministically_correlated_to_existing_outbound_leg(): void
    {
        [$tenantId, $nodeId] = $this->runtimeNode();
        $callId = Str::uuid()->toString();
        $legId = Str::uuid()->toString();
        $now = now();
        DB::table('calls')->insert(['id' => $callId, 'tenant_id' => $tenantId, 'direction' => 'outbound', 'observed_state' => 'requested', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('call_legs')->insert(['id' => $legId, 'tenant_id' => $tenantId, 'call_id' => $callId, 'runtime_node_id' => $nodeId, 'runtime_channel_id' => null, 'direction' => 'outbound', 'role' => 'source', 'observed_state' => 'requested', 'created_at' => $now, 'updated_at' => $now]);

        $channelId = RuntimeChannelIdentity::forCallLeg($legId);
        $this->assertSame($legId, RuntimeChannelIdentity::callLegId($channelId));
        $receipts = app(RuntimeEventReceiptRepository::class);
        $epoch = $receipts->openEpoch($tenantId, $nodeId, 'asterisk-ari', 'c6e-correlation-epoch');
        $accepted = $receipts->ingest($tenantId, $nodeId, 'asterisk-ari', $epoch, 'ari:stasis-start-correlation', 'asterisk.ari.channel.stasis_start', 1, []);
        $receipt = DB::table('runtime_event_receipts')->where('id', $accepted['id'])->first();
        $normalizer = new AsteriskAriEventNormalizer(new AsteriskCatalog, (new AsteriskCatalog)->eventType('stasis_start'));
        $normalized = $normalizer->normalize($receipt, [
            'runtime_node_id' => $nodeId,
            'configuration_generation' => 1,
            'channel_id' => $channelId,
            'remote_identity' => '+15550102',
            'occurred_at' => now()->toISOString(),
        ]);
        $this->assertSame($legId, $normalized[0]['subject_id']);
        app(ProjectionService::class)->apply($receipt, $normalized);

        $this->assertSame(1, DB::table('call_legs')->where('tenant_id', $tenantId)->count());
        $this->assertSame(1, DB::table('calls')->where('tenant_id', $tenantId)->count());
        $this->assertSame($channelId, DB::table('call_legs')->where('id', $legId)->value('runtime_channel_id'));
        $this->assertSame('outbound', DB::table('calls')->where('id', $callId)->value('direction'));
    }

    /** @return array{0:string,1:string} */
    private function runtimeNode(): array
    {
        $tenantId = Str::uuid()->toString();
        $nodeId = Str::uuid()->toString();
        DB::table('tenants')->insert(['id' => $tenantId, 'slug' => 'c6e-'.substr($tenantId, 0, 8), 'display_name' => 'C6E tenant', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('runtime_nodes')->insert(['id' => $nodeId, 'tenant_id' => $tenantId, 'name' => 'C6E Asterisk', 'slug' => 'c6e-'.substr($nodeId, 0, 8), 'runtime_family' => 'asterisk', 'adapter_key' => 'asterisk-ari', 'desired_state' => 'active', 'observed_state' => 'ready', 'configuration_version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$tenantId, $nodeId];
    }
}
