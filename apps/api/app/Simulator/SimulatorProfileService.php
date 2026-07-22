<?php

namespace App\Simulator;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Messaging\EventEnvelope;
use App\ControlPlane\Messaging\OutboxRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\StableJson;
use App\RuntimeRegistry\AdapterConfiguration\AdapterConfigurationDescriptorCollection;
use App\RuntimeRegistry\AdapterConfiguration\AdapterConfigurationFieldDescriptor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SimulatorProfileService
{
    public const SCENARIO_KEY_MIN_LENGTH = 1;

    public const SCENARIO_VERSION = 1;

    public const SEED_MIN_LENGTH = 1;

    public const SEED_MAX_LENGTH = 120;

    public function __construct(
        private readonly SimulatorCatalog $catalog,
        private readonly AuditRepository $audit,
        private readonly OutboxRepository $outbox,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function show(string $tenantId, string $runtimeNodeId): array
    {
        $node = $this->simulatorNode($tenantId, $runtimeNodeId);
        $profile = DB::table('simulator_profiles')->where('runtime_node_id', $runtimeNodeId)->first();
        $state = DB::table('simulator_states')->where('runtime_node_id', $runtimeNodeId)->first();

        return [
            'runtime_node_id' => $runtimeNodeId,
            'adapter_key' => $node->adapter_key,
            'configured' => $profile !== null,
            'profile' => $profile === null ? null : [
                'scenario_key' => $profile->scenario_key,
                'scenario_version' => (int) $profile->scenario_version,
                'seed' => $profile->seed,
                'parameters' => json_decode((string) $profile->parameters, true, 512, JSON_THROW_ON_ERROR),
                'configuration_generation' => (int) $profile->configuration_generation,
                'updated_at' => $profile->updated_at,
            ],
            'state' => $state === null ? null : [
                'phase' => $state->current_phase,
                'logical_sequence' => (int) $state->logical_sequence,
                'attempt_count' => (int) $state->attempt_count,
                'applied_configuration_generation' => (int) $state->applied_configuration_generation,
                'active_connection_epoch' => $state->active_connection_epoch,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        $scenario = (string) ($input['scenario_key'] ?? '');
        $version = (int) ($input['scenario_version'] ?? self::SCENARIO_VERSION);
        $seed = (string) ($input['seed'] ?? 'local');
        if (
            strlen($seed) < self::SEED_MIN_LENGTH
            || strlen($seed) > self::SEED_MAX_LENGTH
            || ! preg_match('/^[A-Za-z0-9._:-]+$/', $seed)
        ) {
            throw new InvalidArgumentException('Invalid simulator seed.');
        }

        $parameters = is_array($input['parameters'] ?? null) ? $input['parameters'] : [];

        return [
            'scenario_key' => $scenario,
            'scenario_version' => $version,
            'seed' => $seed,
            'parameters' => $this->catalog->validateParameters($parameters),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'scenario_key' => null,
            'scenario_version' => self::SCENARIO_VERSION,
            'seed' => 'local',
            'parameters' => [],
        ];
    }

    public function descriptors(): AdapterConfigurationDescriptorCollection
    {
        $defaults = $this->defaults();
        $scenarioMaxLength = max(array_map('strlen', $this->catalog->scenarios()) ?: [self::SCENARIO_KEY_MIN_LENGTH]);

        return new AdapterConfigurationDescriptorCollection([
            AdapterConfigurationFieldDescriptor::text(
                'scenario_key',
                'Scenario key',
                'Deterministic simulator scenario key from the server simulator catalog.',
                true,
                $defaults['scenario_key'],
                10,
                ['min_length' => self::SCENARIO_KEY_MIN_LENGTH, 'max_length' => $scenarioMaxLength],
            ),
            AdapterConfigurationFieldDescriptor::integer(
                'scenario_version',
                'Scenario version',
                'Deterministic simulator scenario contract version.',
                true,
                $defaults['scenario_version'],
                20,
                ['min' => self::SCENARIO_VERSION, 'max' => self::SCENARIO_VERSION, 'step' => 1],
            ),
            AdapterConfigurationFieldDescriptor::text(
                'seed',
                'Seed',
                'Stable deterministic seed used by the simulator profile.',
                true,
                $defaults['seed'],
                30,
                ['min_length' => self::SEED_MIN_LENGTH, 'max_length' => self::SEED_MAX_LENGTH],
            ),
            AdapterConfigurationFieldDescriptor::json(
                'parameters',
                'Parameters',
                'Optional scalar simulator parameters keyed by the selected scenario.',
                true,
                $defaults['parameters'],
                40,
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function put(ExecutionContext $context, string $tenantId, string $runtimeNodeId, array $input): array
    {
        $validated = $this->validate($input);
        $scenario = $validated['scenario_key'];
        $version = $validated['scenario_version'];
        $seed = $validated['seed'];
        $parameters = $validated['parameters'];
        $this->catalog->assertScenario($scenario, $version);

        DB::transaction(function () use ($context, $tenantId, $runtimeNodeId, $scenario, $version, $seed, $parameters): void {
            $node = $this->simulatorNode($tenantId, $runtimeNodeId, true);
            $generation = ((int) $node->configuration_version) + 1;

            DB::table('runtime_nodes')->where('id', $runtimeNodeId)->update([
                'configuration_version' => $generation,
                'updated_by' => $context->actorId,
                'updated_at' => now(),
            ]);
            DB::table('simulator_profiles')->updateOrInsert(
                ['runtime_node_id' => $runtimeNodeId],
                [
                    'scenario_key' => $scenario,
                    'scenario_version' => $version,
                    'seed' => $seed,
                    'parameters' => StableJson::encode($parameters),
                    'configuration_generation' => $generation,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            DB::table('simulator_states')->updateOrInsert(
                ['runtime_node_id' => $runtimeNodeId],
                [
                    'scenario_key' => $scenario,
                    'scenario_version' => $version,
                    'seed' => $seed,
                    'logical_sequence' => 0,
                    'current_phase' => 'configured',
                    'attempt_count' => 0,
                    'active_connection_epoch' => null,
                    'applied_configuration_generation' => 0,
                    'next_event_sequence' => 1,
                    'state_payload' => StableJson::encode([]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            DB::table('runtime_reconciliation_states')->where('target_type', 'runtime_node')->where('target_id', $runtimeNodeId)->delete();

            $payload = [
                'adapter_key' => $this->catalog->adapterKey(),
                'scenario_key' => $scenario,
                'scenario_version' => $version,
                'configuration_generation' => $generation,
            ];
            $this->audit->append($context, 'runtime_node.simulator_configuration_changed', 'runtime_node', $runtimeNodeId, $payload);
            $this->outbox->append(EventEnvelope::forAggregate('runtime_node.simulator_configuration_changed', 1, 'runtime_node', $runtimeNodeId, $payload, $context));
        });

        return $this->show($tenantId, $runtimeNodeId);
    }

    private function simulatorNode(string $tenantId, string $runtimeNodeId, bool $lock = false): object
    {
        $query = DB::table('runtime_nodes')->where('id', $runtimeNodeId)->where('tenant_id', $tenantId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $node = $query->first();
        abort_unless($node !== null, 404, 'Runtime node not found.');
        if ($node->runtime_family !== $this->catalog->family() || $node->adapter_key !== $this->catalog->adapterKey()) {
            throw new InvalidArgumentException('Runtime node is not configured for the deterministic simulator.');
        }

        return $node;
    }
}
