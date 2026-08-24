<?php

namespace App\RuntimeAdapters\FreeSwitch;

use App\ControlPlane\RuntimeOperations\FailureClass;
use RuntimeException;

final class FreeSwitchEslException extends RuntimeException
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
