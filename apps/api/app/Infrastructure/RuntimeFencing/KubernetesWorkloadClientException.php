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
}
