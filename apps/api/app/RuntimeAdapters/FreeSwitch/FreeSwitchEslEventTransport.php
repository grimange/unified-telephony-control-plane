<?php

namespace App\RuntimeAdapters\FreeSwitch;

interface FreeSwitchEslEventTransport
{
    /** @return resource */
    public function openEventStream(string $tenantId, string $runtimeNodeId, string $subscription): mixed;

    /** @param resource $stream */
    public function closeEventStream($stream): void;
}
