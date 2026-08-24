<?php

namespace App\TelephonyDomain;

use InvalidArgumentException;

/** Canonical C6 media identity parser and provider-boundary resolver. */
final readonly class MediaReference
{
    private const PREFIX = 'utcp:media/';

    private const FREESWITCH_SOUND_ROOT = '/usr/share/freeswitch/sounds/';

    private function __construct(private string $identifier) {}

    public static function parse(string $reference): self
    {
        if (! str_starts_with($reference, self::PREFIX)) {
            throw new InvalidArgumentException('invalid_media_ref');
        }
        $identifier = substr($reference, strlen(self::PREFIX));
        if ($identifier === '' || trim($identifier) !== $identifier || str_contains($identifier, '..') || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $identifier)) {
            throw new InvalidArgumentException('invalid_media_ref');
        }

        return new self($identifier);
    }

    public function canonical(): string
    {
        return self::PREFIX.$this->identifier;
    }

    public function providerReference(string $provider): string
    {
        return match ($provider) {
            'asterisk' => 'sound:'.$this->identifier,
            'freeswitch' => self::FREESWITCH_SOUND_ROOT.$this->identifier.'.wav',
            default => throw new InvalidArgumentException('media_ref_unresolved'),
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
        if (preg_match('/^sound:(?<identifier>[A-Za-z0-9][A-Za-z0-9._-]*)$/', $reference, $matches)) {
            return self::parse(self::PREFIX.$matches['identifier'])->canonical();
        }
        if (str_starts_with($reference, self::FREESWITCH_SOUND_ROOT) && str_ends_with($reference, '.wav')) {
            $identifier = substr($reference, strlen(self::FREESWITCH_SOUND_ROOT), -4);
            if ($identifier !== '' && ! str_contains($identifier, '/') && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $identifier)) {
                return self::parse(self::PREFIX.$identifier)->canonical();
            }
        }

        return null;
    }
}
