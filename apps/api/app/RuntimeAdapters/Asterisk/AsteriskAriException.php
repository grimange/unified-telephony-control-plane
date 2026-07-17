<?php

namespace App\RuntimeAdapters\Asterisk;

use App\ControlPlane\RuntimeOperations\FailureClass;
use RuntimeException;

final class AsteriskAriException extends RuntimeException
{
    public function __construct(
        public readonly FailureClass $failureClass,
        public readonly string $failureCode,
        string $message,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
