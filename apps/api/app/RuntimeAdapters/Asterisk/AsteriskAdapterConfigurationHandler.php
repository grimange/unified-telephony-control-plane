<?php

namespace App\RuntimeAdapters\Asterisk;

use App\ControlPlane\Shared\ExecutionContext;
use App\RuntimeRegistry\AdapterConfiguration\AdapterConfigurationHandler;

final class AsteriskAdapterConfigurationHandler implements AdapterConfigurationHandler
{
    public function __construct(
        private readonly AsteriskCatalog $catalog,
        private readonly AsteriskAriProfileService $profiles,
    ) {}

    public function adapterKey(): string
    {
        return $this->catalog->adapterKey();
    }

    public function supports(object $runtimeNode): bool
    {
        return $runtimeNode->adapter_key === $this->adapterKey();
    }

    public function read(object $runtimeNode, ExecutionContext $context): array
    {
        return $this->profiles->show((string) $runtimeNode->tenant_id, (string) $runtimeNode->id);
    }

    public function validate(object $runtimeNode, array $payload, ExecutionContext $context): array
    {
        return $this->profiles->validate($payload);
    }

    public function update(object $runtimeNode, array $payload, ExecutionContext $context): array
    {
        return $this->profiles->put($context, (string) $runtimeNode->tenant_id, (string) $runtimeNode->id, $this->validate($runtimeNode, $payload, $context));
    }
}
