<?php

namespace App\Identity\UserAccess;

use Carbon\CarbonImmutable;

final readonly class ResetUserPasswordResult
{
    public function __construct(
        public string $userId,
        public string $userDisplay,
        public string $temporaryPassword,
        public CarbonImmutable $expiresAt,
        public int $sessionVersion,
        public bool $sessionsRevoked,
    ) {}
}
