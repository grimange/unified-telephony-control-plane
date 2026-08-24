<?php

namespace Tests\Unit\TelephonyDomain;

use App\TelephonyDomain\MediaReference;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MediaReferenceTest extends TestCase
{
    public function test_generic_safe_identifiers_are_canonical_and_resolve_at_provider_boundaries(): void
    {
        foreach (['reference-tone', 'welcome', 'customer-hold', 'foo-bar_01', 'c6b-test'] as $identifier) {
            $reference = MediaReference::parse('utcp:media/'.$identifier);

            $this->assertSame('utcp:media/'.$identifier, $reference->canonical());
            $this->assertSame('sound:'.$identifier, $reference->providerReference('asterisk'));
            $this->assertSame('/usr/share/freeswitch/sounds/'.$identifier.'.wav', $reference->providerReference('freeswitch'));
        }
    }

    public function test_malformed_references_are_invalid_syntax_not_unresolved_runtime_assets(): void
    {
        foreach (['', 'sound:reference-tone', '/tmp/reference-tone.wav', 'utcp:media/../secret', 'utcp:media/foo/bar', 'utcp:media/$bad'] as $value) {
            try {
                MediaReference::parse($value);
                self::fail('Expected invalid media reference: '.$value);
            } catch (InvalidArgumentException $exception) {
                self::assertSame('invalid_media_ref', $exception->getMessage());
            }
        }
    }

    public function test_provider_references_round_trip_to_generic_canonical_identity(): void
    {
        $this->assertSame('utcp:media/reference-tone', MediaReference::canonicalFromProviderReference('sound:reference-tone'));
        $this->assertSame('utcp:media/welcome', MediaReference::canonicalFromProviderReference('sound:welcome'));
        $this->assertSame('utcp:media/foo-bar_01', MediaReference::canonicalFromProviderReference('sound:foo-bar_01'));
        $this->assertSame('utcp:media/reference-tone', MediaReference::canonicalFromProviderReference('/usr/share/freeswitch/sounds/reference-tone.wav'));
        $this->assertSame('utcp:media/welcome', MediaReference::canonicalFromProviderReference('/usr/share/freeswitch/sounds/welcome.wav'));
        $this->assertSame('utcp:media/foo-bar_01', MediaReference::canonicalFromProviderReference('/usr/share/freeswitch/sounds/foo-bar_01.wav'));
    }

    public function test_provider_paths_outside_sanctioned_namespaces_do_not_become_canonical(): void
    {
        foreach (['sound:/tmp/welcome', 'sound:../welcome', '/tmp/welcome.wav', '/usr/share/freeswitch/other/welcome.wav', '/usr/share/freeswitch/sounds/welcome.mp3'] as $value) {
            $this->assertNull(MediaReference::canonicalFromProviderReference($value));
        }
    }
}
