<?php

namespace Tests\Feature\FreeSwitch;

use App\RuntimeAdapters\FreeSwitch\FreeSwitchCatalog;
use App\RuntimeAdapters\FreeSwitch\FreeSwitchEslException;
use App\RuntimeAdapters\FreeSwitch\FreeSwitchEslProtocol;
use App\RuntimeAdapters\FreeSwitch\SocketFreeSwitchEslTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SocketFreeSwitchEslTransportTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_event_stream_consumes_greeting_auth_and_subscription_ack_before_first_event(): void
    {
        $body = "Event-Name: CHANNEL_CREATE\r\nUnique-ID: event-1\r\n";
        $event = $this->frame('text/event-plain', $body);
        $subscription = 'event plain CHANNEL_CREATE CHANNEL_ANSWER CHANNEL_HOLD CHANNEL_UNHOLD CHANNEL_BRIDGE CHANNEL_UNBRIDGE CHANNEL_HANGUP_COMPLETE DTMF';

        $this->withServer(
            "Content-Type: auth/request\r\n\r\n",
            ["Content-Type: command/reply\r\nReply-Text: +OK accepted\r\n\r\n", "Content-Type: command/reply\r\nReply-Text: +OK event listener enabled plain\r\n\r\n"],
            ["auth secret\n\n", $subscription."\n\n"],
            $event,
            function (int $port) use ($subscription): void {
                $transport = $this->transport($port);
                $stream = $transport->openEventStream('tenant-1', 'node-1', $subscription);

                $this->assertSame(
                    "Content-Type: text/event-plain\r\nContent-Length: ".strlen("Event-Name: CHANNEL_CREATE\r\nUnique-ID: event-1\r\n")."\r\n\r\nEvent-Name: CHANNEL_CREATE\r\nUnique-ID: event-1\r\n",
                    FreeSwitchEslProtocol::readFrame($stream),
                );
                $transport->closeEventStream($stream);
            },
        );
    }

    public function test_open_event_stream_rejects_invalid_authentication_without_subscribing(): void
    {
        try {
            $this->withServer(
                "Content-Type: auth/request\r\n\r\n",
                ["Content-Type: command/reply\r\nReply-Text: -ERR invalid\r\n\r\n"],
                ["auth secret\n\n"],
                '',
                function (int $port): void {
                    $this->transport($port)->openEventStream('tenant-1', 'node-1', 'event plain CHANNEL_CREATE');
                },
            );
            $this->fail('Expected authentication failure.');
        } catch (FreeSwitchEslException $exception) {
            $this->assertSame('freeswitch_esl_authentication_failed', $exception->failureCode);
        }
    }

    public function test_open_event_stream_rejects_invalid_greeting_before_writing_auth(): void
    {
        try {
            $this->withServer(
                "Content-Type: command/reply\r\nReply-Text: +OK unexpected\r\n\r\n",
                [],
                [],
                '',
                function (int $port): void {
                    $this->transport($port)->openEventStream('tenant-1', 'node-1', 'event plain CHANNEL_CREATE');
                },
            );
            $this->fail('Expected invalid greeting failure.');
        } catch (FreeSwitchEslException $exception) {
            $this->assertSame('freeswitch_esl_authentication_greeting_invalid', $exception->failureCode);
        }
    }

    public function test_open_event_stream_distinguishes_subscription_failure_from_authentication_failure(): void
    {
        try {
            $this->withServer(
                "Content-Type: auth/request\r\n\r\n",
                ["Content-Type: command/reply\r\nReply-Text: +OK accepted\r\n\r\n", "Content-Type: command/reply\r\nReply-Text: -ERR subscription denied\r\n\r\n"],
                ["auth secret\n\n", "event plain CHANNEL_CREATE\n\n"],
                '',
                function (int $port): void {
                    $this->transport($port)->openEventStream('tenant-1', 'node-1', 'event plain CHANNEL_CREATE');
                },
            );
            $this->fail('Expected subscription failure.');
        } catch (FreeSwitchEslException $exception) {
            $this->assertSame('freeswitch_esl_subscription_failed', $exception->failureCode);
            $this->assertNotSame('freeswitch_esl_authentication_failed', $exception->failureCode);
        }
    }

    public function test_execute_normalizes_command_reply_text_and_preserves_command_rejection(): void
    {
        $this->withServer(
            "Content-Type: auth/request\r\n\r\n",
            ["Content-Type: command/reply\r\nReply-Text: +OK accepted\r\n\r\n", "Content-Type: command/reply\r\nReply-Text: +OK command accepted\r\n\r\n"],
            ["auth secret\n\n", "api status\n\n"],
            '',
            function (int $port): void {
                $this->assertSame(['response' => '+OK command accepted'], $this->transport($port)->execute('tenant-1', 'node-1', 'api', 'status'));
            },
        );

        $this->withServer(
            "Content-Type: auth/request\r\n\r\n",
            ["Content-Type: command/reply\r\nReply-Text: +OK accepted\r\n\r\n", "Content-Type: command/reply\r\nReply-Text: -ERR no such channel\r\n\r\n"],
            ["auth secret\n\n", "api uuid_kill missing\n\n"],
            '',
            function (int $port): void {
                $this->assertSame(['response' => '-ERR no such channel'], $this->transport($port)->execute('tenant-1', 'node-1', 'api', 'uuid_kill missing'));
            },
        );
    }

    public function test_execute_normalizes_api_response_body_without_reply_text(): void
    {
        $body = " +OK api response body \r\n";
        $this->withServer(
            "Content-Type: auth/request\r\n\r\n",
            ["Content-Type: command/reply\r\nReply-Text: +OK accepted\r\n\r\n", $this->frame('api/response', $body)],
            ["auth secret\n\n", "api status\n\n"],
            '',
            function (int $port): void {
                $this->assertSame(['response' => '+OK api response body'], $this->transport($port)->execute('tenant-1', 'node-1', 'api', 'status'));
            },
        );
    }

    public function test_protocol_helpers_use_real_header_wrapped_frames(): void
    {
        FreeSwitchEslProtocol::assertAuthRequestGreeting("Content-Type: auth/request\r\n\r\n");
        FreeSwitchEslProtocol::assertAuthenticated("Content-Type: command/reply\r\nReply-Text: +OK accepted\r\n\r\n");
        $this->assertSame('+OK accepted', FreeSwitchEslProtocol::responseTextFromFrame("Content-Type: command/reply\r\nReply-Text: +OK accepted\r\n\r\n"));
    }

    private function transport(int $port): SocketFreeSwitchEslTransport
    {
        $now = now();
        DB::table('runtime_node_credentials')->where('runtime_node_id', 'node-1')->delete();
        DB::table('runtime_node_endpoints')->where('runtime_node_id', 'node-1')->delete();
        DB::table('runtime_nodes')->where('id', 'node-1')->delete();
        DB::table('tenants')->where('id', 'tenant-1')->delete();
        DB::table('tenants')->insert([
            'id' => 'tenant-1',
            'slug' => 'transport-test',
            'display_name' => 'Transport test tenant',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('runtime_nodes')->insert([
            'id' => 'node-1',
            'tenant_id' => 'tenant-1',
            'name' => 'Transport test node',
            'slug' => 'transport-test-node',
            'runtime_family' => 'freeswitch',
            'adapter_key' => 'freeswitch-esl',
            'desired_state' => 'active',
            'observed_state' => 'ready',
            'configuration_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('runtime_node_endpoints')->insert([
            'id' => Str::uuid()->toString(),
            'runtime_node_id' => 'node-1',
            'purpose' => 'control',
            'transport' => 'tcp',
            'host' => '127.0.0.1',
            'port' => $port,
            'path' => null,
            'tls_mode' => 'disabled',
            'priority' => 100,
            'enabled' => true,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('runtime_node_credentials')->insert([
            'id' => Str::uuid()->toString(),
            'runtime_node_id' => 'node-1',
            'credential_type' => 'freeswitch-esl',
            'identifier' => 'transport-test',
            'encrypted_secret' => Crypt::encryptString('secret'),
            'secret_fingerprint' => hash('sha256', 'secret'),
            'version' => 1,
            'status' => 'active',
            'rotated_at' => $now,
            'expires_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new SocketFreeSwitchEslTransport(new FreeSwitchCatalog);
    }

    private function frame(string $contentType, string $body = ''): string
    {
        return 'Content-Type: '.$contentType."\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body;
    }

    private function withServer(string $greeting, array $responses, array $expectedRequests, string $tail, callable $operation): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the in-memory ESL socket test.');
        }

        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, $errstr);
        $address = stream_socket_get_name($server, false);
        $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
        $pid = pcntl_fork();
        $this->assertGreaterThanOrEqual(0, $pid);

        if ($pid === 0) {
            $connection = stream_socket_accept($server, 5);
            if (! is_resource($connection)) {
                exit(2);
            }
            stream_set_timeout($connection, 5);
            fwrite($connection, $greeting);
            foreach ($expectedRequests as $index => $expectedRequest) {
                $request = $this->readRequest($connection);
                if ($request !== $expectedRequest) {
                    exit(3);
                }
                fwrite($connection, $responses[$index]);
            }
            if ($tail !== '') {
                fwrite($connection, $tail);
            }
            fclose($connection);
            fclose($server);
            exit(0);
        }

        try {
            $operation($port);
        } finally {
            fclose($server);
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }

    /** @param resource $stream */
    private function readRequest($stream): string
    {
        $request = '';
        while (! str_ends_with($request, "\n\n")) {
            $chunk = fread($stream, 1);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $request .= $chunk;
        }

        return $request;
    }
}
