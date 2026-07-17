<?php

namespace App\RuntimeAdapters\Asterisk;

final class AsteriskCatalog
{
    public function runtimeFamily(): string
    {
        return (string) config('asterisk_ari.runtime_family', 'asterisk');
    }

    public function adapterKey(): string
    {
        return (string) config('asterisk_ari.adapter_key', 'asterisk-ari');
    }

    public function listenerKind(): string
    {
        return (string) config('asterisk_ari.listener_kind', 'asterisk-ari-events');
    }

    public function credentialType(): string
    {
        return (string) config('asterisk_ari.credential_type', 'ari-basic');
    }

    public function eventType(string $key): string
    {
        $value = config('asterisk_ari.event_types.'.$key);
        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException('Unknown Asterisk ARI event type.');
        }

        return $value;
    }
}
