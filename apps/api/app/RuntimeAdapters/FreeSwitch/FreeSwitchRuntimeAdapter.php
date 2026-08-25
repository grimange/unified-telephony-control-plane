<?php

namespace App\RuntimeAdapters\FreeSwitch;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\RuntimeEngine\Commands\RuntimeAdapter;
use App\TelephonyDomain\CallOperationCatalog;
use Illuminate\Support\Facades\DB;

final class FreeSwitchRuntimeAdapter implements RuntimeAdapter
{
    public function __construct(private readonly FreeSwitchCatalog $catalog, private readonly FreeSwitchEslClient $client) {}

    public function adapterKey(): string
    {
        return $this->catalog->adapterKey();
    }

    public function execute(array $operation): array
    {
        $type = (string) ($operation['operation_type'] ?? '');
        $node = DB::table('runtime_nodes')->where('id', $operation['runtime_node_id'] ?? null)->first();
        if ($node === null || $node->adapter_key !== $this->adapterKey()) {
            return $this->failure(FailureClass::InvalidRequest, 'freeswitch_node_mismatch', 'Runtime node is not configured for FreeSWITCH ESL.');
        }
        if (! array_key_exists($type, CallOperationCatalog::all())) {
            return $this->failure(FailureClass::UnsupportedCapability, 'freeswitch_operation_unsupported', 'FreeSWITCH ESL does not support this operation.');
        }
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $tenant = (string) $node->tenant_id;
        $legs = [];
        $target = (string) ($operation['aggregate_type'] ?? '');
        if ($target === 'call_leg') {
            $leg = DB::table('call_legs')->where('tenant_id', $tenant)->where('id', (string) ($operation['aggregate_id'] ?? ''))->first();
            if ($leg === null || (string) $leg->runtime_node_id !== (string) $node->id) {
                return $this->failure(FailureClass::Conflict, 'freeswitch_call_leg_target_stale', 'CallLeg is not owned by the selected FreeSWITCH node.');
            }
            if ($type !== 'call.leg.originate' && (string) ($leg->runtime_channel_id ?? '') === '') {
                return $this->failure(FailureClass::Conflict, 'freeswitch_call_channel_unbound', 'CallLeg has no current FreeSWITCH channel.');
            }
            $legs[] = ['id' => (string) $leg->id, 'call_id' => (string) $leg->call_id, 'runtime_channel_id' => (string) ($leg->runtime_channel_id ?? '')];

            if ($type === 'call.leg.stop_media' && ! is_string($payload['media_ref'] ?? null)) {
                $previous = DB::table('runtime_operations')
                    ->where('tenant_id', $tenant)
                    ->where('aggregate_type', 'call_leg')
                    ->where('aggregate_id', (string) $leg->id)
                    ->where('operation_type', 'call.leg.play_media')
                    ->where('status', 'succeeded')
                    ->orderByDesc('created_at')
                    ->first();
                $previousPayload = $previous === null ? [] : json_decode((string) $previous->payload, true);
                if (! is_array($previousPayload) || ! is_string($previousPayload['media_ref'] ?? null)) {
                    return $this->failure(FailureClass::Conflict, 'freeswitch_active_media_missing', 'No canonical active media reference is available for FreeSWITCH stop.');
                }
                $payload['media_ref'] = $previousPayload['media_ref'];
            }
        } elseif ($target === 'call') {
            $call = DB::table('calls')->where('tenant_id', $tenant)->where('id', (string) ($operation['aggregate_id'] ?? ''))->first();
            if ($call === null) {
                return $this->failure(FailureClass::InvalidRequest, 'freeswitch_call_target_not_found', 'Call target was not found.');
            }
            $legs = DB::table('call_legs')->where('tenant_id', $tenant)->where('call_id', $call->id)->where('runtime_node_id', $node->id)->whereNotNull('runtime_channel_id')->get()->map(fn (object $leg): array => ['id' => (string) $leg->id, 'call_id' => (string) $leg->call_id, 'runtime_channel_id' => (string) $leg->runtime_channel_id])->all();
        } elseif ($target === 'relationship') {
            foreach (($payload['leg_ids'] ?? []) as $id) {
                $leg = DB::table('call_legs')->where('tenant_id', $tenant)->where('id', (string) $id)->first();
                if ($leg === null || (string) $leg->runtime_node_id !== (string) $node->id || ! $leg->runtime_channel_id) {
                    return $this->failure(FailureClass::Conflict, 'freeswitch_relationship_target_stale', 'Relationship legs are not current FreeSWITCH targets.');
                }
                $legs[] = ['id' => (string) $leg->id, 'call_id' => (string) $leg->call_id, 'runtime_channel_id' => (string) $leg->runtime_channel_id];
            }
        }
        $result = $this->client->executeCallOperation($tenant, (string) $node->id, $type, $payload, $legs);
        if (($result['status'] ?? 'completed') !== 'completed') {
            return $result;
        }

        return ['status' => 'completed', 'event_type' => 'runtime_operation.freeswitch_call_executed', 'event_payload' => array_merge(['adapter_key' => $this->adapterKey(), 'operation_type' => $type], $result)];
    }

    private function failure(FailureClass $class, string $code, string $message): array
    {
        return ['status' => 'terminal_failure', 'failure_class' => $class->value, 'failure_code' => $code, 'failure_message' => $message];
    }
}
