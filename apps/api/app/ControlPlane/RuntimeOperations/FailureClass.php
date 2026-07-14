<?php

namespace App\ControlPlane\RuntimeOperations;

enum FailureClass: string
{
    case TransientTransport = 'transient_transport';
    case RuntimeUnavailable = 'runtime_unavailable';
    case Timeout = 'timeout';
    case Conflict = 'conflict';
    case InvalidRequest = 'invalid_request';
    case UnsupportedCapability = 'unsupported_capability';
    case AuthenticationFailed = 'authentication_failed';
    case AuthorizationFailed = 'authorization_failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case InternalError = 'internal_error';

    public function retryable(): bool
    {
        return in_array($this, [
            self::TransientTransport,
            self::RuntimeUnavailable,
            self::Timeout,
            self::InternalError,
        ], true);
    }

    public function retryDelaySeconds(int $attempt): int
    {
        if (! $this->retryable()) {
            return 0;
        }

        return min(300, max(5, $attempt * 15));
    }
}
