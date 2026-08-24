<?php

namespace App\RuntimeAdapters\FreeSwitch;

use App\ControlPlane\RuntimeOperations\FailureClass;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class SocketFreeSwitchEslTransport implements FreeSwitchEslEventTransport, FreeSwitchEslTransport
{
    public function __construct(private readonly FreeSwitchCatalog $catalog) {}

    public function execute(string $tenantId, string $runtimeNodeId, string $mode, string $command): array
    {
        if (! in_array($mode, ['api', 'bgapi'], true)) {
            throw new FreeSwitchEslException(FailureClass::InvalidRequest, 'freeswitch_esl_mode_invalid', 'ESL mode is invalid.');
        }
        $endpoint = DB::table('runtime_node_endpoints')->where('runtime_node_id', $runtimeNodeId)->where('purpose', 'control')->where('enabled', true)->whereIn('transport', ['tcp', 'tls'])->orderBy('priority')->first();
        $credential = DB::table('runtime_node_credentials')->where('runtime_node_id', $runtimeNodeId)->where('credential_type', $this->catalog->credentialType())->where('status', 'active')->orderByDesc('version')->first();
        if ($endpoint === null || $credential === null) {
            throw new FreeSwitchEslException(FailureClass::RuntimeUnavailable, 'freeswitch_esl_endpoint_unconfigured', 'FreeSWITCH ESL control endpoint or credential is unavailable.', true);
        }
        $scheme = $endpoint->transport === 'tls' ? 'tls' : 'tcp';
        $stream = @stream_socket_client($scheme.'://'.$endpoint->host.':'.((int) $endpoint->port), $errno, $errstr, 5);
        if (! is_resource($stream)) {
            throw new FreeSwitchEslException(FailureClass::RuntimeUnavailable, 'freeswitch_esl_transport_unavailable', 'FreeSWITCH ESL connection failed.', true);
        }
        try {
            stream_set_timeout($stream, 5);
            $this->authenticate($stream, Crypt::decryptString((string) $credential->encrypted_secret));
            fwrite($stream, $mode.' '.$command."\n\n");

            return ['response' => FreeSwitchEslProtocol::responseTextFromFrame(FreeSwitchEslProtocol::readFrame($stream))];
        } finally {
            fclose($stream);
        }
    }

    public function openEventStream(string $tenantId, string $runtimeNodeId, string $subscription): mixed
    {
        $endpoint = $this->endpoint($runtimeNodeId);
        $credential = $this->credential($runtimeNodeId);
        if ($endpoint === null || $credential === null) {
            throw new FreeSwitchEslException(FailureClass::RuntimeUnavailable, 'freeswitch_esl_endpoint_unconfigured', 'FreeSWITCH ESL event endpoint or credential is unavailable.', true);
        }

        $scheme = $endpoint->transport === 'tls' ? 'tls' : 'tcp';
        $stream = @stream_socket_client($scheme.'://'.$endpoint->host.':'.((int) $endpoint->port), $errno, $errstr, 5);
        if (! is_resource($stream)) {
            throw new FreeSwitchEslException(FailureClass::RuntimeUnavailable, 'freeswitch_esl_transport_unavailable', 'FreeSWITCH ESL event connection failed.', true);
        }

        try {
            stream_set_timeout($stream, 5);
            $this->authenticate($stream, Crypt::decryptString((string) $credential->encrypted_secret));
            fwrite($stream, $subscription."\n\n");
            $subscriptionResponse = FreeSwitchEslProtocol::responseTextFromFrame(FreeSwitchEslProtocol::readFrame($stream));
            if (! str_starts_with($subscriptionResponse, '+OK')) {
                throw new FreeSwitchEslException(FailureClass::Conflict, 'freeswitch_esl_subscription_failed', 'FreeSWITCH ESL event subscription failed.');
            }

            return $stream;
        } catch (\Throwable $exception) {
            fclose($stream);
            throw $exception;
        }
    }

    public function closeEventStream($stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    private function endpoint(string $runtimeNodeId): ?object
    {
        return DB::table('runtime_node_endpoints')->where('runtime_node_id', $runtimeNodeId)->where('purpose', 'control')->where('enabled', true)->whereIn('transport', ['tcp', 'tls'])->orderBy('priority')->first();
    }

    private function credential(string $runtimeNodeId): ?object
    {
        return DB::table('runtime_node_credentials')->where('runtime_node_id', $runtimeNodeId)->where('credential_type', $this->catalog->credentialType())->where('status', 'active')->orderByDesc('version')->first();
    }

    /** @param resource $stream */
    private function authenticate($stream, string $password): void
    {
        FreeSwitchEslProtocol::assertAuthRequestGreeting(FreeSwitchEslProtocol::readFrame($stream));
        fwrite($stream, 'auth '.$password."\n\n");
        FreeSwitchEslProtocol::assertAuthenticated(FreeSwitchEslProtocol::readFrame($stream));
    }
}
