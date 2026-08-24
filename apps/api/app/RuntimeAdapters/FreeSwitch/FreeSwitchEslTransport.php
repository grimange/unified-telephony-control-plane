<?php

namespace App\RuntimeAdapters\FreeSwitch;

interface FreeSwitchEslTransport
{
    /** @return array{response:string} */
    public function execute(string $tenantId, string $runtimeNodeId, string $mode, string $command): array;
}
