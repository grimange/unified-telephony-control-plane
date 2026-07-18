<?php

namespace App\Infrastructure\RuntimeFencing;

use RuntimeException;

final class KubernetesWorkloadClientException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $reason);
    }

    public static function unavailable(string $message = 'Kubernetes API is unavailable.'): self
    {
        return new self('unavailable_to_control', $message);
    }

    public static function forbidden(string $message = 'Kubernetes API denied the fencing request.'): self
    {
        return new self('permission_denied', $message);
    }

    public static function targetMismatch(string $message = 'Kubernetes workload target did not match the fencing request.'): self
    {
        return new self('target_mismatch', $message);
    }

    public static function conflict(string $message = 'Kubernetes workload fencing is still in progress.'): self
    {
        return new self('fence_in_progress', $message);
    }

    public static function failed(string $message = 'Kubernetes API returned malformed fencing evidence.'): self
    {
        return new self('failed', $message);
    }
}
