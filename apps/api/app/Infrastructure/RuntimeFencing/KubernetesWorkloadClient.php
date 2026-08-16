<?php

namespace App\Infrastructure\RuntimeFencing;

interface KubernetesWorkloadClient
{
    /**
     * Return the sanitized metadata for one managed-boundary resource, or null when absent.
     *
     * @return array<string, mixed>|null
     */
    public function inspectResource(string $kind, string $name): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function getDeployment(string $namespace, string $name): ?array;

    public function scaleDeployment(string $namespace, string $name, int $replicas): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function listOwnedPods(string $namespace, RuntimeNodeWorkloadIdentity $identity): array;

    /**
     * @param  array<string, mixed>  $desired
     * @return array<string, mixed>
     */
    public function applySecret(array $desired, string $runtimeNodeSlug): array;

    /**
     * @param  array<string, mixed>  $desired
     * @return array<string, mixed>
     */
    public function applyDeployment(array $desired, string $runtimeNodeSlug): array;

    /**
     * @param  array<string, mixed>  $desired
     * @return array<string, mixed>
     */
    public function applyService(array $desired, string $runtimeNodeSlug): array;

    public function deleteSecret(string $name, string $runtimeNodeSlug): bool;

    public function deleteDeployment(string $name, string $runtimeNodeSlug): bool;

    public function deleteService(string $name, string $runtimeNodeSlug): bool;
}
