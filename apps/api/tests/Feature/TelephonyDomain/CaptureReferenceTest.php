<?php

namespace Tests\Feature\TelephonyDomain;

use App\TelephonyDomain\CaptureReference;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CaptureReferenceTest extends TestCase
{
    public function test_valid_capture_references_round_trip_and_derive_from_recording_sessions(): void
    {
        $id = 'f378c9e4c806daedb175c5d9f6173f20';
        $reference = CaptureReference::parse('utcp:capture/'.$id);

        $this->assertSame('utcp:capture/'.$id, $reference->canonical());
        $this->assertSame('utcp-capture-'.$id, $reference->providerReference('asterisk'));
        $this->assertSame($reference->canonical(), CaptureReference::canonicalFromProviderReference($reference->providerReference('asterisk')));
        $this->assertSame('utcp:capture/'.$id, CaptureReference::forRecordingSession($id)->canonical());
        $this->assertNotSame('utcp:capture/'.md5($id), CaptureReference::forRecordingSession($id)->canonical());
    }

    public function test_malformed_references_are_rejected_and_unrelated_provider_values_do_not_resolve(): void
    {
        foreach (['', 'utcp:capture/', 'utcp:capture/ABCDEF0123456789ABCDEF0123456789', 'utcp:capture/0123456789abcdef0123456789abcde', 'utcp:capture/0123456789abcdef0123456789abcdef ', 'utcp:capture/../secret'] as $value) {
            try {
                CaptureReference::parse($value);
                self::fail('Expected invalid capture reference: '.$value);
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('invalid_capture_ref', $exception->getMessage());
            }
        }

        foreach (['', 'ABCDEF0123456789ABCDEF0123456789', 'not-a-recording-session-id'] as $id) {
            try {
                CaptureReference::forRecordingSession($id);
                self::fail('Expected invalid recording session identifier: '.$id);
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('invalid_capture_ref', $exception->getMessage());
            }
        }
    }

    public function test_unknown_provider_and_unrelated_provider_references_fail_closed(): void
    {
        $reference = CaptureReference::parse('utcp:capture/0123456789abcdef0123456789abcdef');

        $this->expectExceptionObject(new InvalidArgumentException('capture_ref_unresolved'));
        $reference->providerReference('unknown');
    }

    public function test_unrelated_provider_references_return_null(): void
    {
        foreach (['utcp-call-leg-0123456789abcdef0123456789abcdef', 'utcp-conf-abc', 'arbitrary', 'utcp-capture-not-a-digest'] as $value) {
            $this->assertNull(CaptureReference::canonicalFromProviderReference($value));
        }
    }
}
