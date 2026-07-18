<?php

namespace App\Infrastructure\RuntimeFencing;

use RuntimeException;

final class RuntimeNodeWorkloadIdentityException extends RuntimeException
{
    public static function targetMismatch(string $message = 'RuntimeNode workload identity is invalid.'): self
    {
        return new self($message);
    }
}
