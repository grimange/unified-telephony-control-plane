<?php

namespace App\TelephonyDomain;

use InvalidArgumentException;

/** Canonical runtime-neutral recording capture identity. */
final readonly class CaptureReference
{
    private const PREFIX = 'utcp:capture/';

    private function __construct(private string $identifier) {}

    public static function parse(string $reference): self
    {
        if (! str_starts_with($reference, self::PREFIX)) {
            throw new InvalidArgumentException('invalid_capture_ref');
        }
        $identifier = substr($reference, strlen(self::PREFIX));
        if (! preg_match('/^[a-f0-9]{32}$/', $identifier)) {
            throw new InvalidArgumentException('invalid_capture_ref');
        }

        return new self($identifier);
    }

    public static function forRecordingSession(string $id): self
    {
        return self::parse(self::PREFIX.$id);
    }

    public function canonical(): string
    {
        return self::PREFIX.$this->identifier;
    }

    public function providerReference(string $provider): string
    {
        return match ($provider) {
            'asterisk' => 'utcp-capture-'.$this->identifier,
            default => throw new InvalidArgumentException('capture_ref_unresolved'),
        };
    }

    public static function canonicalFromProviderReference(?string $reference): ?string
    {
        if ($reference === null || trim($reference) === '') {
            return null;
        }
        $reference = trim($reference);
        if (str_starts_with($reference, self::PREFIX)) {
            return self::parse($reference)->canonical();
        }
        if (preg_match('/^utcp-capture-(?<identifier>[a-f0-9]{32})$/', $reference, $matches)) {
            return self::parse(self::PREFIX.$matches['identifier'])->canonical();
        }

        return null;
    }
}
