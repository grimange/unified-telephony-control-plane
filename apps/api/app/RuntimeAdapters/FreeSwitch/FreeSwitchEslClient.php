<?php

namespace App\RuntimeAdapters\FreeSwitch;

use App\ControlPlane\RuntimeOperations\FailureClass;
use App\TelephonyDomain\MediaReference;
use App\TelephonyDomain\RuntimeChannelIdentity;
use InvalidArgumentException;

final class FreeSwitchEslClient
{
    public function __construct(private readonly FreeSwitchEslTransport $transport) {}

    /** @param list<array{id:string,call_id:string,runtime_channel_id:string}> $legs */
    public function executeCallOperation(string $tenantId, string $runtimeNodeId, string $operationType, array $payload, array $legs): array
    {
        try {
            $channels = array_values(array_filter(array_map(static fn (array $leg): string => $leg['runtime_channel_id'], $legs)));
            $channel = $channels[0] ?? null;
            if ($operationType === 'call.leg.originate') {
                $legId = (string) ($payload['leg_id'] ?? ($legs[0]['id'] ?? ''));
                if ($legId === '') {
                    throw new FreeSwitchEslException(FailureClass::InvalidRequest, 'freeswitch_call_leg_missing', 'A normalized originate operation requires a CallLeg target.');
                }
                $runtimeId = RuntimeChannelIdentity::forCallLeg($legId);
                $destination = $this->token((string) ($payload['destination_ref'] ?? ''), 'destination_ref');

                return $this->send($tenantId, $runtimeNodeId, 'bgapi', 'originate {origination_uuid='.$runtimeId.'}'.$destination.' &park', 'freeswitch.originate', $runtimeId);
            }
            if ($channel === null) {
                throw new FreeSwitchEslException(FailureClass::Conflict, 'freeswitch_call_channel_unbound', 'The normalized CallLeg has no current FreeSWITCH channel.');
            }
            if ($operationType === 'call.hangup') {
                foreach ($channels as $id) {
                    $this->send($tenantId, $runtimeNodeId, 'api', 'uuid_kill '.$id, 'freeswitch.uuid_kill', $id);
                }

                return ['status' => 'completed', 'provider_action' => 'freeswitch.uuid_kill', 'runtime_channel_ids' => $channels];
            }
            if ($operationType === 'call.legs.unbridge') {
                $this->send($tenantId, $runtimeNodeId, 'api', 'uuid_setvar '.$channels[0].' park_after_bridge true', 'freeswitch.uuid_setvar', $channels[0]);
                $this->send($tenantId, $runtimeNodeId, 'api', 'uuid_setvar '.$channels[1].' park_after_bridge true', 'freeswitch.uuid_setvar', $channels[1]);

                return $this->send($tenantId, $runtimeNodeId, 'api', 'uuid_park '.$channel, 'freeswitch.uuid_park', $channel);
            }
            [$command, $action] = match ($operationType) {
                'call.leg.cancel_origination', 'call.leg.hangup' => ['uuid_kill '.$channel, 'freeswitch.uuid_kill'],
                'call.leg.answer' => ['uuid_answer '.$channel, 'freeswitch.uuid_answer'],
                'call.leg.hold' => ['uuid_hold '.$channel, 'freeswitch.uuid_hold'],
                'call.leg.resume' => ['uuid_hold off '.$channel, 'freeswitch.uuid_hold'],
                'call.legs.bridge' => [$this->relationshipCommand('uuid_bridge', $channels), 'freeswitch.uuid_bridge'],
                'call.leg.blind_transfer' => ['uuid_transfer '.$channel.' '.$this->token((string) ($payload['destination_ref'] ?? ''), 'destination_ref'), 'freeswitch.uuid_transfer'],
                'call.leg.redirect' => ['uuid_deflect '.$channel.' '.$this->token((string) ($payload['destination_ref'] ?? ''), 'destination_ref'), 'freeswitch.uuid_deflect'],
                'call.leg.mute' => ['uuid_audio '.$channel.' start read mute 0', 'freeswitch.uuid_audio'],
                'call.leg.unmute' => ['uuid_audio '.$channel.' stop', 'freeswitch.uuid_audio'],
                'call.leg.send_dtmf' => ['uuid_send_dtmf '.$channel.' '.$this->token((string) ($payload['digit'] ?? ''), 'digit'), 'freeswitch.uuid_send_dtmf'],
                'call.leg.play_media' => ['uuid_broadcast '.$channel.' '.$this->mediaReference((string) ($payload['media_ref'] ?? '')).' aleg', 'freeswitch.uuid_broadcast'],
                'call.leg.stop_media' => ['uuid_break '.$channel, 'freeswitch.uuid_break'],
                default => throw new FreeSwitchEslException(FailureClass::UnsupportedCapability, 'freeswitch_call_operation_unsupported', 'FreeSWITCH T4A does not support this operation.'),
            };

            return $this->send($tenantId, $runtimeNodeId, 'api', $command, $action, $channel);
        } catch (FreeSwitchEslException $exception) {
            return ['status' => $exception->retryable ? 'retry_scheduled' : 'terminal_failure', 'failure_class' => $exception->failureClass->value, 'failure_code' => $exception->failureCode, 'failure_message' => $exception->getMessage()];
        }
    }

    /** @param list<string> $channels */
    private function relationshipCommand(string $verb, array $channels): string
    {
        if (count($channels) !== 2) {
            throw new FreeSwitchEslException(FailureClass::InvalidRequest, 'freeswitch_relationship_requires_two_channels', 'The normalized relationship requires two current channels.');
        }

        return $verb.' '.$channels[0].' '.$channels[1];
    }

    private function token(string $value, string $name): string
    {
        if ($value === '' || preg_match('/[\s\r\n]/', $value)) {
            throw new FreeSwitchEslException(FailureClass::InvalidRequest, 'freeswitch_'.$name.'_invalid', 'FreeSWITCH '.$name.' is invalid.');
        }

        return $value;
    }

    private function mediaReference(string $reference): string
    {
        try {
            return MediaReference::parse($reference)->providerReference('freeswitch');
        } catch (InvalidArgumentException $exception) {
            $code = $exception->getMessage() === 'media_ref_unresolved' ? 'media_ref_unresolved' : 'invalid_media_ref';
            throw new FreeSwitchEslException(FailureClass::InvalidRequest, $code, $exception->getMessage());
        }
    }

    private function send(string $tenantId, string $nodeId, string $mode, string $command, string $action, string $channel): array
    {
        $result = $this->transport->execute($tenantId, $nodeId, $mode, $command);
        $response = trim((string) ($result['response'] ?? ''));
        if (! str_starts_with($response, '+OK')) {
            throw new FreeSwitchEslException(FailureClass::Conflict, 'freeswitch_esl_command_failed', 'FreeSWITCH rejected the command: '.substr($response, 0, 240));
        }

        return ['status' => 'completed', 'provider_action' => $action, 'runtime_channel_id' => $channel, 'esl_mode' => $mode, 'esl_command' => $command];
    }
}
