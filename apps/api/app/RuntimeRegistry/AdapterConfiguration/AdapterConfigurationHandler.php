<?php

namespace App\RuntimeRegistry\AdapterConfiguration;

use App\ControlPlane\Shared\ExecutionContext;

interface AdapterConfigurationHandler
{
    public function adapterKey(): string;

    public function supports(object $runtimeNode): bool;

    public function configurationDescriptors(): AdapterConfigurationDescriptorCollection;

    /**
     * @return array<string, mixed>
     */
    public function read(object $runtimeNode, ExecutionContext $context): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validate(object $runtimeNode, array $payload, ExecutionContext $context): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(object $runtimeNode, array $payload, ExecutionContext $context): array;
}
