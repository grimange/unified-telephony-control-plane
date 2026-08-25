<?php

namespace App\TelephonyDomain;

use InvalidArgumentException;

final class TelephonyAddressNormalizer
{
    /**
     * @return array{type: string, normalized_value: string}
     */
    public function normalize(string $type, string $value): array
    {
        $type = strtolower(trim($type));
        $value = trim($value);

        return match ($type) {
            'e164' => ['type' => $type, 'normalized_value' => $this->e164($value)],
            'sip_uri' => ['type' => $type, 'normalized_value' => $this->sipUri($value)],
            default => throw new InvalidArgumentException('Unsupported telephony address type.'),
        };
    }

    private function e164(string $value): string
    {
        $normalized = preg_replace('/[\s().-]+/', '', $value) ?? $value;
        if (! preg_match('/^\+[1-9][0-9]{7,14}$/', $normalized)) {
            throw new InvalidArgumentException('Telephony address must be a valid E.164 number.');
        }

        return $normalized;
    }

    private function sipUri(string $value): string
    {
        if (! preg_match('/^(sips?):([^@\s]+)@([A-Za-z0-9.-]+)(?::([0-9]{1,5}))?$/', $value, $matches)) {
            throw new InvalidArgumentException('Telephony address must be a valid SIP URI.');
        }
        $port = $matches[4] ?? '';
        if ($port !== '' && ((int) $port < 1 || (int) $port > 65535)) {
            throw new InvalidArgumentException('SIP URI port is invalid.');
        }

        return strtolower($matches[1]).':'.$matches[2].'@'.strtolower($matches[3]).($port === '' ? '' : ':'.$port);
    }
}
