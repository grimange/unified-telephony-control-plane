<?php

namespace Tests\Feature\RuntimeRegistry;

use App\ControlPlane\RuntimeOperations\RuntimeOperationRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\Identity\IdentityIds;
use App\Infrastructure\RuntimeFencing\RuntimeNodeWorkloadIdentityResolver;
use App\Models\User;
use App\RuntimeEngine\EngineIds;
use App\RuntimeEngine\Sources\EventSourceRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class RuntimeRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_manages_runtime_node_without_revealing_credentials(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin();
        $secret = 'c2-proof-secret-'.bin2hex(random_bytes(6));

        $created = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $this->nodePayload(), ['Idempotency-Key' => 'runtime-node-create-key'])
            ->assertCreated()
            ->assertJsonPath('runtime_node.desired_state', 'draft')
            ->assertJsonPath('runtime_node.observed_state', 'unobserved')
            ->json('runtime_node');

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $this->nodePayload(), ['Idempotency-Key' => 'runtime-node-create-key'])
            ->assertCreated()
            ->assertJsonPath('runtime_node.id', $created['id']);

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$created['id']}/endpoints", [
                'purpose' => 'control',
                'transport' => 'https',
                'host' => 'asterisk-control.local.test',
                'port' => 8089,
                'path' => '/ari',
                'tls_mode' => 'verify',
            ])
            ->assertCreated();

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$created['id']}/endpoints", [
                'purpose' => 'events',
                'transport' => 'wss',
                'host' => 'asterisk-events.local.test',
                'port' => 8089,
                'path' => '/ari/events',
                'tls_mode' => 'verify',
            ])
            ->assertCreated();

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->putJson("/api/v1/admin/runtime-nodes/{$created['id']}/capabilities", [
                'capabilities' => ['event.stream', 'runtime.observation'],
            ])
            ->assertOk()
            ->assertJsonPath('runtime_node.capabilities.0', 'event.stream')
            ->assertJsonPath('runtime_node.capabilities.1', 'runtime.observation');

        $credential = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$created['id']}/credentials", [
                'credential_type' => 'control-api',
                'identifier' => 'proof-user',
                'secret' => $secret,
            ], ['Idempotency-Key' => 'runtime-credential-create-key'])
            ->assertCreated()
            ->assertJsonMissing(['secret' => $secret])
            ->assertJsonMissing(['encrypted_secret' => true])
            ->json('credential');

        $row = DB::table('runtime_node_credentials')->where('id', $credential['id'])->first();
        $this->assertNotNull($row);
        $this->assertNotSame($secret, $row->encrypted_secret);
        $this->assertSame($secret, Crypt::decryptString($row->encrypted_secret));

        $rotated = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$created['id']}/credentials/{$credential['id']}/rotate", [
                'credential_type' => 'control-api',
                'identifier' => 'proof-user',
                'secret' => $secret.'-rotated',
            ], ['Idempotency-Key' => 'runtime-credential-rotate-key'])
            ->assertOk()
            ->assertJsonPath('credential.version', 2)
            ->json('credential');

        $this->assertDatabaseHas('runtime_node_credentials', ['id' => $credential['id'], 'status' => 'retired']);
        $this->assertDatabaseHas('runtime_node_credentials', ['id' => $rotated['id'], 'status' => 'active']);

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$created['id']}/desired-state", ['desired_state' => 'active'])
            ->assertOk()
            ->assertJsonPath('runtime_node.desired_state', 'active')
            ->assertJsonPath('runtime_node.observed_state', 'unobserved');

        $serialized = json_encode([
            DB::table('control_plane_audit_records')->get()->all(),
            DB::table('control_plane_outbox_messages')->get()->all(),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($secret, $serialized);
        $this->assertStringNotContainsString($secret.'-rotated', $serialized);
    }

    public function test_runtime_node_capacity_weight_zero_is_accepted_as_unlimited_slots(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin();
        $payload = $this->nodePayload('unlimited-capacity');
        $payload['capacity_weight'] = 0;

        $node = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $payload)
            ->assertCreated()
            ->assertJsonPath('runtime_node.placement.capacity_weight', 0)
            ->json('runtime_node');

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->patchJson("/api/v1/admin/runtime-nodes/{$node['id']}", ['capacity_weight' => 0])
            ->assertOk()
            ->assertJsonPath('runtime_node.placement.capacity_weight', 0);
    }

    public function test_tenant_member_cross_tenant_access_and_invalid_inputs_fail_closed(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin('admin-two@utcp.local.test');
        [$otherAdmin, $otherTenantId] = $this->createTenantAdmin('other-admin@utcp.local.test', 'other');
        $member = $this->createUser('member@utcp.local.test');
        $this->attachTenantRole($member->id, $tenantId, 'tenant-member');

        $node = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $this->nodePayload())
            ->assertCreated()
            ->json('runtime_node');

        $this->actingAs($member)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $this->nodePayload('member-denied'))
            ->assertForbidden();

        $this->actingAs($otherAdmin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $otherTenantId])
            ->getJson("/api/v1/admin/runtime-nodes/{$node['id']}")
            ->assertNotFound();

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'draining'])
            ->assertUnprocessable();

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/endpoints", [
                'purpose' => 'sip',
                'transport' => 'udp',
                'host' => 'bad.local.test',
                'port' => 5060,
            ])
            ->assertUnprocessable();

        DB::table('tenants')->where('id', $tenantId)->update(['status' => 'suspended']);
        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-nodes')
            ->assertStatus(409);
    }

    public function test_idempotency_conflict_and_route_inventory_exclude_live_runtime_authority(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin();

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $this->nodePayload('first'), ['Idempotency-Key' => 'runtime-node-conflict-key'])
            ->assertCreated();

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $this->nodePayload('second'), ['Idempotency-Key' => 'runtime-node-conflict-key'])
            ->assertConflict();

        $routes = implode("\n", collect(app('router')->getRoutes())->map(fn ($route) => $route->uri())->all());
        $this->assertStringContainsString('api/v1/admin/runtime-nodes', $routes);
        $this->assertStringNotContainsString('api/v1/admin/ari', $routes);
        $this->assertStringNotContainsString('api/v1/admin/esl', $routes);
        $this->assertStringNotContainsString('test-connection', $routes);
        $this->assertStringNotContainsString('reconcile', $routes);
    }

    public function test_runtime_node_create_accepts_structured_kubernetes_workload_label_and_resolver_round_trips(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin();
        $payload = $this->nodePayload('structured-create');
        $payload['labels'] = [
            'purpose' => 't5-runtime',
            'kubernetes_workload' => [
                'namespace' => 'utcp-runtime',
                'deployment' => 'asterisk-ari-b',
            ],
        ];

        $node = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $payload)
            ->assertCreated()
            ->assertJsonPath('runtime_node.placement.labels.purpose', 't5-runtime')
            ->assertJsonPath('runtime_node.placement.labels.kubernetes_workload.namespace', 'utcp-runtime')
            ->assertJsonPath('runtime_node.placement.labels.kubernetes_workload.deployment', 'asterisk-ari-b')
            ->json('runtime_node');

        $persisted = json_decode((string) DB::table('runtime_nodes')->where('id', $node['id'])->value('labels'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('t5-runtime', $persisted['purpose']);
        $this->assertSame(['namespace' => 'utcp-runtime', 'deployment' => 'asterisk-ari-b'], $persisted['kubernetes_workload']);

        $identity = app(RuntimeNodeWorkloadIdentityResolver::class)->resolve(DB::table('runtime_nodes')->where('id', $node['id'])->first());
        $this->assertSame('utcp-runtime', $identity->namespace);
        $this->assertSame('asterisk-ari-b', $identity->deployment);
    }

    public function test_fenced_disabled_node_active_request_schedules_restore_operation_and_keeps_node_disabled(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin();
        $member = $this->createUser('restore-member@utcp.local.test');
        $this->attachTenantRole($member->id, $tenantId, 'tenant-member');
        $payload = $this->nodePayload('restore-node');
        $payload['labels'] = [
            'purpose' => 'restore-proof',
            'kubernetes_workload' => [
                'namespace' => 'utcp-runtime',
                'deployment' => 'asterisk-ari-restore-node',
            ],
        ];
        $node = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $payload)
            ->assertCreated()
            ->json('runtime_node');
        DB::table('runtime_nodes')->where('id', $node['id'])->update([
            'desired_state' => 'disabled',
            'configuration_version' => 2,
            'updated_at' => now(),
        ]);
        $sourceFenceId = $this->sourceFenceOperation($tenantId, $node['id'], 'asterisk-ari-restore-node', 31, 1);

        $this->actingAs($member)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'active'])
            ->assertForbidden();

        $response = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", [
                'desired_state' => 'active',
                'reason' => 'return to placement pool',
            ])
            ->assertOk()
            ->assertJsonPath('runtime_node.desired_state', 'disabled')
            ->assertJsonPath('runtime_operation.operation_type', 'runtime.node.restore')
            ->json();

        $operationId = $response['runtime_operation']['id'];
        $this->assertSame('disabled', DB::table('runtime_nodes')->where('id', $node['id'])->value('desired_state'));
        $this->assertSame(1, DB::table('runtime_operations')->where('operation_type', 'runtime.node.restore')->where('runtime_node_id', $node['id'])->count());
        $operation = DB::table('runtime_operations')->where('id', $operationId)->first();
        $this->assertSame('runtime_node', $operation->aggregate_type);
        $this->assertSame($node['id'], $operation->aggregate_id);
        $this->assertSame(8, (int) $operation->max_attempts);
        $operationPayload = json_decode((string) $operation->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($sourceFenceId, $operationPayload['source_fence_operation_id']);
        $this->assertSame(31, $operationPayload['source_fence_generation']);
        $this->assertSame('utcp-runtime', $operationPayload['workload_namespace']);
        $this->assertSame('asterisk-ari-restore-node', $operationPayload['deployment']);
        $this->assertSame(1, $operationPayload['target_replicas']);
        $this->assertSame(2, $operationPayload['expected_runtime_node_configuration_version']);
        $this->assertSame('return to placement pool', $operationPayload['reason']);

        $repeat = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'active'])
            ->assertOk()
            ->json('runtime_operation');
        $this->assertSame($operationId, $repeat['id']);
        $this->assertSame(1, DB::table('runtime_operations')->where('operation_type', 'runtime.node.restore')->where('runtime_node_id', $node['id'])->count());
    }

    public function test_terminal_restore_predecessor_allows_authorized_successor_request(): void
    {
        [$admin, $tenantId, $node, $sourceFenceId, $terminalId] = $this->restorableNodeWithRestoreOperation('restore-terminal-successor');
        DB::table('runtime_operations')->where('id', $terminalId)->update([
            'status' => 'terminal_failed',
            'last_failure_class' => 'runtime_unavailable',
            'last_failure_code' => 'runtime_restore_listener_lease_missing',
            'completed_at' => now()->subSecond(),
            'updated_at' => now()->subSecond(),
        ]);

        $successor = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'active'])
            ->assertOk()
            ->assertJsonPath('runtime_node.desired_state', 'disabled')
            ->json('runtime_operation');

        $this->assertNotSame($terminalId, $successor['id']);
        $this->assertSame('terminal_failed', DB::table('runtime_operations')->where('id', $terminalId)->value('status'));
        $payload = $this->operationPayload($successor['id']);
        $this->assertSame($terminalId, $payload['supersedes_restore_operation_id']);
        $this->assertSame(2, $payload['restore_attempt_generation']);
        $this->assertSame($sourceFenceId, $payload['source_fence_operation_id']);
        $this->assertSame(8, (int) DB::table('runtime_operations')->where('id', $successor['id'])->value('max_attempts'));
        $this->assertSame(2, DB::table('runtime_operations')->where('operation_type', 'runtime.node.restore')->where('runtime_node_id', $node['id'])->count());

        $repeat = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'active'])
            ->assertOk()
            ->json('runtime_operation');
        $this->assertSame($successor['id'], $repeat['id']);
        $this->assertSame(2, DB::table('runtime_operations')->where('operation_type', 'runtime.node.restore')->where('runtime_node_id', $node['id'])->count());
    }

    public function test_terminal_successor_can_be_superseded_by_later_authorized_request(): void
    {
        [$admin, $tenantId, $node, , $firstId] = $this->restorableNodeWithRestoreOperation('restore-terminal-sequence');
        DB::table('runtime_operations')->where('id', $firstId)->update([
            'status' => 'terminal_failed',
            'last_failure_class' => 'runtime_unavailable',
            'last_failure_code' => 'runtime_restore_pods_not_ready',
            'completed_at' => now()->subSeconds(3),
            'updated_at' => now()->subSeconds(3),
        ]);
        $secondId = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'active'])
            ->assertOk()
            ->json('runtime_operation.id');
        DB::table('runtime_operations')->where('id', $secondId)->update([
            'status' => 'terminal_failed',
            'last_failure_class' => 'runtime_unavailable',
            'last_failure_code' => 'runtime_restore_listener_lease_missing',
            'completed_at' => now()->subSecond(),
            'updated_at' => now()->subSecond(),
        ]);

        $thirdId = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'active'])
            ->assertOk()
            ->json('runtime_operation.id');

        $this->assertNotSame($firstId, $secondId);
        $this->assertNotSame($secondId, $thirdId);
        $this->assertSame($secondId, $this->operationPayload($thirdId)['supersedes_restore_operation_id']);
        $this->assertSame(3, $this->operationPayload($thirdId)['restore_attempt_generation']);
        $this->assertSame(3, DB::table('runtime_operations')->where('operation_type', 'runtime.node.restore')->where('runtime_node_id', $node['id'])->count());
    }

    public function test_successful_restore_predecessor_is_returned_without_successor(): void
    {
        [$admin, $tenantId, $node, , $operationId] = $this->restorableNodeWithRestoreOperation('restore-successful-predecessor');
        DB::table('runtime_operations')->where('id', $operationId)->update([
            'status' => 'succeeded',
            'completed_at' => now()->subSecond(),
            'updated_at' => now()->subSecond(),
        ]);

        $response = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'active'])
            ->assertOk()
            ->json('runtime_operation');

        $this->assertSame($operationId, $response['id']);
        $this->assertSame(1, DB::table('runtime_operations')->where('operation_type', 'runtime.node.restore')->where('runtime_node_id', $node['id'])->count());
    }

    public function test_cancelled_and_expired_restore_predecessors_allow_successor_when_authority_remains_valid(): void
    {
        foreach (['cancelled', 'expired'] as $status) {
            [$admin, $tenantId, $node, , $predecessorId] = $this->restorableNodeWithRestoreOperation('restore-'.$status.'-successor');
            DB::table('runtime_operations')->where('id', $predecessorId)->update([
                'status' => $status,
                'cancelled_at' => $status === 'cancelled' ? now()->subSecond() : null,
                'completed_at' => $status === 'expired' ? now()->subSecond() : null,
                'updated_at' => now()->subSecond(),
            ]);

            $successor = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
                ->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'active'])
                ->assertOk()
                ->json('runtime_operation.id');

            $this->assertNotSame($predecessorId, $successor);
            $this->assertSame($predecessorId, $this->operationPayload($successor)['supersedes_restore_operation_id']);
        }
    }

    public function test_stale_configuration_does_not_reuse_terminal_restore_authority(): void
    {
        [$admin, $tenantId, $node, , $terminalId] = $this->restorableNodeWithRestoreOperation('restore-stale-config-successor');
        DB::table('runtime_operations')->where('id', $terminalId)->update([
            'status' => 'terminal_failed',
            'last_failure_class' => 'runtime_unavailable',
            'last_failure_code' => 'runtime_restore_listener_lease_missing',
            'completed_at' => now()->subSecond(),
            'updated_at' => now()->subSecond(),
        ]);
        DB::table('runtime_nodes')->where('id', $node['id'])->update([
            'configuration_version' => 3,
            'updated_at' => now(),
        ]);

        $successor = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'active'])
            ->assertOk()
            ->json('runtime_operation.id');

        $this->assertNotSame($terminalId, $successor);
        $this->assertArrayNotHasKey('supersedes_restore_operation_id', array_filter($this->operationPayload($successor), static fn ($value): bool => $value !== null));
        $this->assertSame(3, $this->operationPayload($successor)['expected_runtime_node_configuration_version']);
    }

    public function test_runtime_node_update_adds_structured_workload_label_and_preserves_flat_labels_by_replacement_contract(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin();
        $node = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $this->nodePayload('structured-update'))
            ->assertCreated()
            ->assertJsonPath('runtime_node.placement.labels.purpose', 'proof')
            ->json('runtime_node');

        $updated = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->patchJson("/api/v1/admin/runtime-nodes/{$node['id']}", [
                'labels' => [
                    'purpose' => 'proof',
                    'kubernetes_workload' => [
                        'namespace' => 'utcp-runtime',
                        'deployment' => 'asterisk-ari',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('runtime_node.placement.labels.purpose', 'proof')
            ->assertJsonPath('runtime_node.placement.labels.kubernetes_workload.namespace', 'utcp-runtime')
            ->assertJsonPath('runtime_node.placement.labels.kubernetes_workload.deployment', 'asterisk-ari')
            ->json('runtime_node');

        $this->assertSame(['namespace' => 'utcp-runtime', 'deployment' => 'asterisk-ari'], $updated['placement']['labels']['kubernetes_workload']);
        $identity = app(RuntimeNodeWorkloadIdentityResolver::class)->resolve(DB::table('runtime_nodes')->where('id', $node['id'])->first());
        $this->assertSame('utcp-runtime', $identity->namespace);
        $this->assertSame('asterisk-ari', $identity->deployment);
    }

    public function test_runtime_node_labels_reject_malformed_workload_and_arbitrary_nested_values(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin();
        $cases = [
            'missing-namespace' => [
                'kubernetes_workload' => ['deployment' => 'asterisk-ari-b'],
            ],
            'missing-deployment' => [
                'kubernetes_workload' => ['namespace' => 'utcp-runtime'],
            ],
            'wrong-namespace' => [
                'kubernetes_workload' => ['namespace' => 'default', 'deployment' => 'asterisk-ari-b'],
            ],
            'invalid-deployment' => [
                'kubernetes_workload' => ['namespace' => 'utcp-runtime', 'deployment' => 'Asterisk_Ari_B'],
            ],
            'extra-workload-key' => [
                'kubernetes_workload' => ['namespace' => 'utcp-runtime', 'deployment' => 'asterisk-ari-b', 'pod' => 'asterisk-ari-b-123'],
            ],
            'string-workload' => [
                'kubernetes_workload' => 'asterisk-ari-b',
            ],
            'arbitrary-nested-label' => [
                'other_label' => ['value' => 'nested'],
            ],
            'non-string-label' => [
                'priority' => 1,
            ],
        ];

        foreach ($cases as $slug => $labels) {
            $payload = $this->nodePayload('reject-'.$slug);
            $payload['labels'] = $labels;
            $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
                ->postJson('/api/v1/admin/runtime-nodes', $payload)
                ->assertUnprocessable()
                ->assertJsonStructure(['message']);
        }
    }

    public function test_flat_only_runtime_node_labels_remain_valid_on_create_and_update(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin();
        $payload = $this->nodePayload('flat-only');
        $payload['labels'] = ['purpose' => 't0-proof', 'environment' => 'local'];

        $node = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $payload)
            ->assertCreated()
            ->assertJsonPath('runtime_node.placement.labels.purpose', 't0-proof')
            ->assertJsonPath('runtime_node.placement.labels.environment', 'local')
            ->json('runtime_node');

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->patchJson("/api/v1/admin/runtime-nodes/{$node['id']}", [
                'labels' => ['purpose' => 't0-proof', 'environment' => 'local', 'owner' => 'utcp'],
            ])
            ->assertOk()
            ->assertJsonPath('runtime_node.placement.labels.purpose', 't0-proof')
            ->assertJsonPath('runtime_node.placement.labels.environment', 'local')
            ->assertJsonPath('runtime_node.placement.labels.owner', 'utcp');

        $persisted = json_decode((string) DB::table('runtime_nodes')->where('id', $node['id'])->value('labels'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['purpose' => 't0-proof', 'environment' => 'local', 'owner' => 'utcp'], $persisted);
    }

    public function test_node_a_and_node_b_registration_definitions_are_accepted_by_runtime_node_create_schema(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin();

        foreach (['local-asterisk-ari-a', 'local-asterisk-ari-b'] as $slug) {
            $definition = $this->registrationDefinition($slug);
            $node = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
                ->postJson($definition['runtime_node']['path'], $definition['runtime_node']['body'])
                ->assertCreated()
                ->assertJsonPath('runtime_node.slug', $slug)
                ->assertJsonPath('runtime_node.placement.labels.kubernetes_workload.namespace', 'utcp-runtime')
                ->assertJsonPath('runtime_node.placement.labels.kubernetes_workload.deployment', $slug === 'local-asterisk-ari-a' ? 'asterisk-ari-a' : 'asterisk-ari-b')
                ->json('runtime_node');

            $identity = app(RuntimeNodeWorkloadIdentityResolver::class)->resolve(DB::table('runtime_nodes')->where('id', $node['id'])->first());
            $this->assertSame('utcp-runtime', $identity->namespace);
            $this->assertSame($definition['runtime_node']['body']['labels']['kubernetes_workload']['deployment'], $identity->deployment);
        }
    }

    public function test_runtime_management_catalog_is_backend_authority_for_families_adapters_and_capabilities(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin();

        $catalog = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson('/api/v1/admin/runtime-node-catalog')
            ->assertOk()
            ->assertJsonPath('catalog.runtime_families.simulator.adapters.0', 'simulator-deterministic')
            ->assertJsonPath('catalog.adapter_keys.asterisk-ari.adapter_configuration_available', true)
            ->json('catalog');

        $this->assertContains('event.stream', $catalog['adapter_keys']['asterisk-ari']['supported_capabilities']);
        $this->assertContains('runtime.observation', $catalog['adapter_keys']['asterisk-ari']['supported_capabilities']);
        $this->assertNotContains('conference.execution', $catalog['adapter_keys']['asterisk-ari']['supported_capabilities']);
        $this->assertArrayNotHasKey('implementation_class', $catalog['adapter_keys']['asterisk-ari']);
    }

    public function test_asterisk_capabilities_reject_unsupported_values_and_preserve_unchanged_actual_values(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin();
        $node = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $this->nodePayload('asterisk-capability-proof'))
            ->assertCreated()
            ->json('runtime_node');

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->putJson("/api/v1/admin/runtime-nodes/{$node['id']}/capabilities", [
                'capabilities' => ['event.stream', 'runtime.observation'],
            ])
            ->assertOk();

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->putJson("/api/v1/admin/runtime-nodes/{$node['id']}/capabilities", [
                'capabilities' => ['event.stream', 'runtime.observation'],
            ])
            ->assertOk()
            ->assertJsonPath('runtime_node.capabilities.0', 'event.stream')
            ->assertJsonPath('runtime_node.capabilities.1', 'runtime.observation');

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->putJson("/api/v1/admin/runtime-nodes/{$node['id']}/capabilities", [
                'capabilities' => ['event.stream', 'runtime.observation', 'conference.execution'],
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('runtime_node_capabilities', [
            'runtime_node_id' => $node['id'],
            'capability_key' => 'conference.execution',
        ]);
    }

    public function test_asterisk_adapter_configuration_is_per_node_scoped_and_wakes_processing(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin();
        [$otherAdmin, $otherTenantId] = $this->createTenantAdmin('other-config-admin@utcp.local.test', 'other-config');
        $node = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $this->nodePayload('asterisk-config-proof'))
            ->assertCreated()
            ->json('runtime_node');

        DB::table('runtime_reconciliation_states')->insert([
            'id' => EngineIds::new(),
            'tenant_id' => $tenantId,
            'target_type' => 'runtime_node',
            'target_id' => $node['id'],
            'desired_generation' => 1,
            'observed_generation' => null,
            'status' => 'blocked',
            'next_check_at' => now()->addHour(),
            'blocked_reason' => 'old_configuration',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $source = (new EventSourceRepository)->ensureRuntimeNodeSource($tenantId, $node['id']);
        DB::table('runtime_listener_leases')->insert([
            'id' => EngineIds::new(),
            'event_source_id' => $source->id,
            'tenant_id' => $tenantId,
            'runtime_node_id' => $node['id'],
            'listener_kind' => 'asterisk-ari-events',
            'status' => 'claimed',
            'owner' => 'listener-private',
            'fencing_token' => EngineIds::token(),
            'claimed_at' => now(),
            'heartbeat_at' => now(),
            'lease_expires_at' => now()->addMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson("/api/v1/admin/runtime-nodes/{$node['id']}/adapter-configuration")
            ->assertOk()
            ->assertJsonPath('adapter_configuration.configured', false);

        $updated = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->putJson("/api/v1/admin/runtime-nodes/{$node['id']}/adapter-configuration", [
                'application_name' => 'utcp_t0',
                'connect_timeout_ms' => 1500,
                'request_timeout_ms' => 7000,
                'websocket_handshake_timeout_ms' => 8000,
                'heartbeat_interval_ms' => 15000,
                'reconnect_min_delay_ms' => 500,
                'reconnect_max_delay_ms' => 10000,
            ])
            ->assertOk()
            ->assertJsonPath('adapter_configuration.configured', true)
            ->assertJsonPath('adapter_configuration.profile.application_name', 'utcp_t0')
            ->json('adapter_configuration');

        $this->assertSame(2, (int) $updated['profile']['configuration_version']);
        $this->assertDatabaseHas('asterisk_ari_profiles', ['runtime_node_id' => $node['id'], 'application_name' => 'utcp_t0']);
        $this->assertDatabaseMissing('runtime_reconciliation_states', ['target_id' => $node['id']]);
        $this->assertDatabaseHas('runtime_listener_leases', ['runtime_node_id' => $node['id'], 'status' => 'released']);
        $this->assertDatabaseHas('control_plane_audit_records', ['subject_id' => $node['id'], 'action' => 'runtime_node.asterisk_ari_configuration_changed']);
        $this->assertDatabaseHas('control_plane_outbox_messages', ['aggregate_id' => $node['id'], 'event_type' => 'runtime_node.asterisk_ari_configuration_changed']);

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->putJson("/api/v1/admin/runtime-nodes/{$node['id']}/adapter-configuration", [
                'application_name' => 'bad value',
                'connect_timeout_ms' => 1,
            ])
            ->assertUnprocessable();

        $this->actingAs($otherAdmin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $otherTenantId])
            ->getJson("/api/v1/admin/runtime-nodes/{$node['id']}/adapter-configuration")
            ->assertNotFound();
    }

    public function test_runtime_evidence_history_and_credential_retirement_are_scoped_and_sanitized(): void
    {
        [$admin, $tenantId] = $this->createTenantAdmin();
        [$otherAdmin, $otherTenantId] = $this->createTenantAdmin('other-evidence-admin@utcp.local.test', 'other-evidence');
        $node = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $this->nodePayload('evidence-proof'))
            ->assertCreated()
            ->json('runtime_node');
        $epochId = EngineIds::new();
        $eventId = EngineIds::new();
        $operationId = EngineIds::new();
        $oldCredential = $this->createCredentialRow($node['id'], 'control-api', 'old-secret');
        $newCredential = $this->createCredentialRow($node['id'], 'control-api', 'new-secret', 2);

        DB::table('runtime_event_connection_epochs')->insert([
            'id' => $epochId,
            'tenant_id' => $tenantId,
            'runtime_node_id' => $node['id'],
            'adapter_key' => 'asterisk-ari',
            'status' => 'closed',
            'owner' => 'epoch-private',
            'fencing_token' => EngineIds::token(),
            'opened_at' => now()->subMinutes(5),
            'closed_at' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('runtime_event_receipts')->insert([
            'id' => $eventId,
            'tenant_id' => $tenantId,
            'runtime_node_id' => $node['id'],
            'adapter_key' => 'asterisk-ari',
            'connection_epoch_id' => $epochId,
            'external_event_key' => 'event-1',
            'event_type' => 'asterisk.authentication_failed',
            'event_version' => 1,
            'payload_hash' => hash('sha256', 'payload'),
            'sanitized_payload' => json_encode(['status' => 'safe'], JSON_THROW_ON_ERROR),
            'occurred_at' => now()->subMinute(),
            'received_at' => now()->subMinute(),
            'status' => 'processed',
            'available_at' => now()->subMinute(),
            'failure_class' => 'authentication_failed',
            'failure_code' => 'bad password / private host',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('runtime_operations')->insert([
            'id' => $operationId,
            'tenant_id' => $tenantId,
            'operation_type' => 'runtime.node.inspect',
            'aggregate_type' => 'runtime_node',
            'aggregate_id' => $node['id'],
            'runtime_node_id' => $node['id'],
            'payload_version' => 1,
            'payload' => json_encode(['safe' => true], JSON_THROW_ON_ERROR),
            'status' => 'terminal_failed',
            'correlation_id' => EngineIds::new(),
            'request_id' => EngineIds::new(),
            'available_at' => now()->subMinute(),
            'last_failure_class' => 'runtime_unavailable',
            'last_failure_code' => 'private error with host',
            'updated_at' => now(),
            'created_at' => now(),
        ]);
        DB::table('runtime_reconciliation_states')->insert([
            'id' => EngineIds::new(),
            'tenant_id' => $tenantId,
            'target_type' => 'runtime_node',
            'target_id' => $node['id'],
            'desired_generation' => 1,
            'status' => 'blocked',
            'next_check_at' => now()->addMinute(),
            'last_checked_at' => now(),
            'last_operation_id' => $operationId,
            'blocked_reason' => 'host password stack',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $evidence = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson("/api/v1/admin/runtime-nodes/{$node['id']}/runtime-evidence")
            ->assertOk()
            ->json('runtime_evidence');
        $this->assertSame('closed', $evidence['connection']['state']);
        $this->assertArrayNotHasKey('owner', $evidence['listener']);
        $this->assertArrayNotHasKey('fencing_token', $evidence['connection']);
        $this->assertStringNotContainsString(' ', $evidence['reconciliation']['sanitized_failure_code']);

        DB::table('control_plane_audit_records')->insert([
            'id' => EngineIds::new(),
            'tenant_id' => $tenantId,
            'actor_type' => 'user',
            'actor_id' => $admin->id,
            'action' => 'runtime_node.credential_retired',
            'subject_type' => 'runtime_node',
            'subject_id' => $node['id'],
            'request_id' => EngineIds::new(),
            'correlation_id' => EngineIds::new(),
            'metadata' => json_encode(['data' => ['secret' => 'should-not-appear', 'change' => 'credential retired']], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
        $history = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->getJson("/api/v1/admin/runtime-nodes/{$node['id']}/history?limit=5")
            ->assertOk()
            ->assertJsonPath('pagination.limit', 5)
            ->json();
        $this->assertTrue(in_array('runtime_node.credential_retired', array_column($history['history'], 'action'), true));
        $this->assertStringNotContainsString('should-not-appear', json_encode($history, JSON_THROW_ON_ERROR));

        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/credentials/{$oldCredential}/retire")
            ->assertOk();
        $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/credentials/{$newCredential}/retire")
            ->assertUnprocessable();

        $this->actingAs($otherAdmin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $otherTenantId])
            ->getJson("/api/v1/admin/runtime-nodes/{$node['id']}/runtime-evidence")
            ->assertNotFound();
        $this->actingAs($otherAdmin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $otherTenantId])
            ->getJson("/api/v1/admin/runtime-nodes/{$node['id']}/history")
            ->assertNotFound();
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createTenantAdmin(string $email = 'admin@utcp.local.test', string $slug = 'local'): array
    {
        $user = $this->createUser($email);
        $tenantId = IdentityIds::new();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'slug' => $slug,
            'display_name' => ucfirst($slug).' Tenant',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('platform_role_assignments')->insert([
            'id' => IdentityIds::new(),
            'user_id' => $user->id,
            'role_key' => 'platform-admin',
            'assigned_by_user_id' => null,
            'created_at' => now(),
        ]);
        $this->attachTenantRole($user->id, $tenantId, 'tenant-admin');

        return [$user, $tenantId];
    }

    private function attachTenantRole(string $userId, string $tenantId, string $roleKey): void
    {
        $membershipId = IdentityIds::new();
        DB::table('tenant_memberships')->insert([
            'id' => $membershipId,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_role_assignments')->insert([
            'id' => IdentityIds::new(),
            'membership_id' => $membershipId,
            'role_key' => $roleKey,
            'assigned_by_user_id' => null,
            'created_at' => now(),
        ]);
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'id' => IdentityIds::new(),
            'email' => $email,
            'normalized_email' => $email,
            'display_name' => 'Runtime Registry User',
            'password' => Hash::make('correct-password-123'),
            'status' => 'active',
            'password_change_required' => false,
            'session_version' => 1,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function nodePayload(string $slug = 'proof-runtime'): array
    {
        return [
            'name' => 'Proof Runtime',
            'slug' => $slug,
            'runtime_family' => 'asterisk',
            'adapter_key' => 'asterisk-ari',
            'placement_region' => 'local',
            'placement_zone' => 'dev',
            'placement_priority' => 100,
            'capacity_weight' => 10,
            'labels' => ['purpose' => 'proof'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationDefinition(string $slug): array
    {
        $path = dirname(base_path(), 2)."/infrastructure/runtime-nodes/asterisk-ari/{$slug}.registration.json";

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function createCredentialRow(string $runtimeNodeId, string $type, string $secret, int $version = 1): string
    {
        $id = IdentityIds::new();
        DB::table('runtime_node_credentials')->insert([
            'id' => $id,
            'runtime_node_id' => $runtimeNodeId,
            'credential_type' => $type,
            'identifier' => 'proof-user',
            'encrypted_secret' => Crypt::encryptString($secret),
            'secret_fingerprint' => hash('sha256', $runtimeNodeId.':'.$secret),
            'status' => 'active',
            'version' => $version,
            'rotated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @return array{0: User, 1: string, 2: array<string, mixed>, 3: string, 4: string}
     */
    private function restorableNodeWithRestoreOperation(string $slug): array
    {
        [$admin, $tenantId] = $this->createTenantAdmin('admin-'.$slug.'@utcp.local.test', 'tenant-'.$slug);
        $payload = $this->nodePayload($slug);
        $payload['labels'] = [
            'purpose' => 'restore-proof',
            'kubernetes_workload' => [
                'namespace' => 'utcp-runtime',
                'deployment' => 'asterisk-ari-'.$slug,
            ],
        ];
        $node = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson('/api/v1/admin/runtime-nodes', $payload)
            ->assertCreated()
            ->json('runtime_node');
        DB::table('runtime_nodes')->where('id', $node['id'])->update([
            'desired_state' => 'disabled',
            'configuration_version' => 2,
            'updated_at' => now(),
        ]);
        $sourceFenceId = $this->sourceFenceOperation($tenantId, $node['id'], 'asterisk-ari-'.$slug, 41, 1);
        $operationId = $this->actingAs($admin)->withSession(['user_session_version' => 1, 'active_tenant_id' => $tenantId])
            ->postJson("/api/v1/admin/runtime-nodes/{$node['id']}/desired-state", ['desired_state' => 'active'])
            ->assertOk()
            ->json('runtime_operation.id');

        return [$admin, $tenantId, $node, $sourceFenceId, $operationId];
    }

    /**
     * @return array<string, mixed>
     */
    private function operationPayload(string $operationId): array
    {
        $payload = DB::table('runtime_operations')->where('id', $operationId)->value('payload');
        $this->assertIsString($payload);
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function sourceFenceOperation(string $tenantId, string $nodeId, string $deployment, int $generation, int $preScaleReplicas): string
    {
        $operationId = app(RuntimeOperationRepository::class)->create(
            'runtime.node.runtime.fence',
            'conference',
            IdentityIds::new(),
            [
                'conference_id' => IdentityIds::new(),
                'former_runtime_binding_id' => IdentityIds::new(),
                'former_runtime_node_id' => $nodeId,
                'runtime_node_id' => $nodeId,
                'configuration_generation' => $generation,
                'runtime_fence_provenance' => [
                    'scale_to_zero_requested' => [
                        'by_operation' => true,
                        'operation_id' => 'pending-source-fence',
                        'namespace' => 'utcp-runtime',
                        'deployment' => $deployment,
                        'pre_scale_replicas' => $preScaleReplicas,
                        'attempt_count' => 1,
                        'requested_at' => now()->subMinutes(2)->toJSON(),
                    ],
                    'runtime_node_disabled' => [
                        'by_operation' => true,
                        'operation_id' => 'pending-source-fence',
                        'runtime_node_id' => $nodeId,
                        'disabled_at' => now()->subMinute()->toJSON(),
                    ],
                ],
            ],
            ExecutionContext::system(tenantId: $tenantId, reason: 'source fence for restore request test'),
            runtimeNodeId: $nodeId,
        );
        $payload = json_decode((string) DB::table('runtime_operations')->where('id', $operationId)->value('payload'), true, 512, JSON_THROW_ON_ERROR);
        $payload['runtime_fence_provenance']['scale_to_zero_requested']['operation_id'] = $operationId;
        $payload['runtime_fence_provenance']['runtime_node_disabled']['operation_id'] = $operationId;
        DB::table('runtime_operations')->where('id', $operationId)->update([
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'succeeded',
            'completed_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        return $operationId;
    }
}
