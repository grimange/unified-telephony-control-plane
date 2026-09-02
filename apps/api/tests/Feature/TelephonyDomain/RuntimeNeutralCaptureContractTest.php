<?php

namespace Tests\Feature\TelephonyDomain;

use App\TelephonyDomain\CaptureReference;
use PHPUnit\Framework\TestCase;

final class RuntimeNeutralCaptureContractTest extends TestCase
{
    public function test_capture_operations_use_only_neutral_identity_fields(): void
    {
        $captureRef = CaptureReference::forRecordingSession('session-1')->canonical();
        $start = ['call_id' => 'call-1', 'leg_id' => 'leg-1', 'recording_session_id' => 'session-1', 'capture_ref' => $captureRef];
        $stop = ['call_id' => 'call-1', 'leg_id' => 'leg-1', 'recording_session_id' => 'session-1', 'capture_ref' => $captureRef];

        $this->assertSame($start['capture_ref'], $stop['capture_ref']);
        $this->assertSame(['call_id', 'leg_id', 'recording_session_id', 'capture_ref'], array_keys($start));
        $this->assertSame($captureRef, CaptureReference::parse($start['capture_ref'])->canonical());
        $this->assertSame([], array_intersect(array_keys($start), ['format', 'wav', 'ifExists', 'recording_name', 'storage_path', 'ari_route', 'uuid_record']));
    }

    public function test_neutral_success_result_carries_only_canonical_runtime_capture_reference(): void
    {
        $captureRef = CaptureReference::forRecordingSession('session-1')->canonical();
        $result = ['status' => 'completed', 'provider_action' => 'channels.record', 'runtime_channel_id' => 'runtime-channel-1', 'runtime_capture_reference' => $captureRef];

        $this->assertSame($captureRef, $result['runtime_capture_reference']);
        $this->assertArrayNotHasKey('artifact_path', $result);
        $this->assertArrayNotHasKey('storage_uri', $result);
        $this->assertArrayNotHasKey('file_size', $result);
    }
}
