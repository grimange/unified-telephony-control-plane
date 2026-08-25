<?php

namespace App\TelephonyDomain;

use InvalidArgumentException;

/** Provider-neutral reference to a canonical or existing opaque UTCP destination. */
final readonly class DestinationRef
{
    private function __construct(private string $type, private string $value) {}

    public static function from(mixed $input): self
    {
        if (is_string($input)) {
            if (str_starts_with($input, 'opaque:')) {
                return self::opaque(substr($input, 7));
            }
            if (str_starts_with($input, 'telephony_address:')) {
                return self::telephonyAddress(substr($input, 18));
            }
        }

        if (is_array($input) && ($input['type'] ?? null) === 'telephony_address') {
            return self::telephonyAddress((string) ($input['id'] ?? ''));
        }
        if (is_array($input) && ($input['type'] ?? null) === 'opaque') {
            return self::opaque((string) ($input['value'] ?? ''));
        }

        throw new InvalidArgumentException('invalid_destination_ref');
    }

    public static function telephonyAddress(string $id): self
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
            throw new InvalidArgumentException('invalid_destination_ref');
        }

        return new self('telephony_address', strtolower($id));
    }

    public static function opaque(string $value): self
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,158}$/', $value) || preg_match('/asterisk|freeswitch|kamailio|sofia|pjsip|rtpengine/i', $value)) {
            throw new InvalidArgumentException('invalid_destination_ref');
        }

        return new self('opaque', $value);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function canonical(): string
    {
        return $this->type === 'telephony_address' ? 'telephony_address:'.$this->value : 'opaque:'.$this->value;
    }

    public function toArray(): array
    {
        return ['type' => $this->type, 'value' => $this->value, 'reference' => $this->canonical()];
    }
}
