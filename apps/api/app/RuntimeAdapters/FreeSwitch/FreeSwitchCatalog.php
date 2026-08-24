<?php

namespace App\RuntimeAdapters\FreeSwitch;

final class FreeSwitchCatalog
{
    public function runtimeFamily(): string
    {
        return (string) config('freeswitch_esl.runtime_family', 'freeswitch');
    }

    public function adapterKey(): string
    {
        return (string) config('freeswitch_esl.adapter_key', 'freeswitch-esl');
    }

    public function credentialType(): string
    {
        return (string) config('freeswitch_esl.credential_type', 'freeswitch-esl');
    }
}
