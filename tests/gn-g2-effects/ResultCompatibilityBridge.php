<?php

declare(strict_types=1);

namespace Utcp\GnG2Proof;

use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClientException;

/** Compatibility projection only; never discards durable uncertainty semantics. */
final class ResultCompatibilityBridge
{
    public static function project(array $result): array
    {
        $exception = match ($result['outcome'] ?? null) {
            'success', 'already_satisfied' => null,
            'invalid_request' => KubernetesWorkloadClientException::invalidRequest(),
            'unauthorized', 'forbidden' => KubernetesWorkloadClientException::forbidden(),
            'target_not_found', 'identity_mismatch' => KubernetesWorkloadClientException::targetMismatch(),
            'ownership_conflict' => KubernetesWorkloadClientException::ownershipConflict(),
            'resource_version_conflict', 'stale_generation', 'drift' => KubernetesWorkloadClientException::conflict(),
            'timeout', 'cancelled', 'transport_unavailable', 'retryable_failure', 'outcome_unknown', 'busy' => KubernetesWorkloadClientException::unavailable(),
            default => KubernetesWorkloadClientException::failed(),
        };
        return ['php_exception_reason' => $exception?->reason, 'normalized' => $result];
    }
}
