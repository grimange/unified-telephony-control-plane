<?php

namespace App\Identity\UserAccess;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Shared\CorrelationId;
use App\ControlPlane\Shared\ExecutionContext;
use App\ControlPlane\Shared\RequestId;
use App\Identity\IdentityContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ResetUserPasswordService
{
    public const DEFAULT_EXPIRATION_MINUTES = 30;

    public const MIN_EXPIRATION_MINUTES = 5;

    public const MAX_EXPIRATION_MINUTES = 1440;

    public function __construct(private readonly AuditRepository $audit) {}

    public function resetFromRequest(Request $request, string $userId, ?string $reason = null, int $expiresInMinutes = self::DEFAULT_EXPIRATION_MINUTES): ResetUserPasswordResult
    {
        return $this->resetById($userId, IdentityContext::fromRequest($request), $reason ?? 'platform user password reset', $expiresInMinutes, 'web-admin');
    }

    public function resetFromConsole(string $selector, string $reason, int $expiresInMinutes): ResetUserPasswordResult
    {
        $selector = trim($selector);
        if ($selector === '') {
            throw new InvalidArgumentException('User selector is required.');
        }

        return DB::transaction(function () use ($selector, $reason, $expiresInMinutes): ResetUserPasswordResult {
            $userId = $this->resolveUserId($selector);

            return $this->resetLockedUser(
                $userId,
                new ExecutionContext(
                    requestId: RequestId::new(),
                    correlationId: CorrelationId::new(),
                    causationId: null,
                    actorType: 'console_operator',
                    actorId: null,
                    tenantId: null,
                    reason: $this->sanitizeReason($reason),
                    origin: 'artisan',
                    occurredAt: CarbonImmutable::now(),
                ),
                $reason,
                $expiresInMinutes,
                'utcp:user-access:reset-password',
            );
        });
    }

    public function resetById(string $userId, ExecutionContext $context, string $reason, int $expiresInMinutes, string $commandName): ResetUserPasswordResult
    {
        return DB::transaction(fn (): ResetUserPasswordResult => $this->resetLockedUser($userId, $context, $reason, $expiresInMinutes, $commandName));
    }

    private function resetLockedUser(string $userId, ExecutionContext $context, string $reason, int $expiresInMinutes, string $commandName): ResetUserPasswordResult
    {
        $reason = $this->sanitizeReason($reason);
        $this->assertExpirationBounds($expiresInMinutes);

        $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
        if (! is_object($user)) {
            throw new RuntimeException('No existing user matched the selector.');
        }

        $temporaryPassword = $this->temporaryPassword();
        $now = CarbonImmutable::now();
        $expiresAt = $now->addMinutes($expiresInMinutes);
        $nextSessionVersion = ((int) $user->session_version) + 1;

        DB::table('users')->where('id', $userId)->update([
            'password' => Hash::make($temporaryPassword),
            'password_change_required' => true,
            'temporary_password_issued_at' => $now,
            'temporary_password_expires_at' => $expiresAt,
            'remember_token' => Str::random(60),
            'session_version' => $nextSessionVersion,
            'updated_at' => $now,
        ]);

        $this->audit->append($context, 'identity.user_password_reset_issued', 'user', $userId, [
            'target_user_id' => $userId,
            'reason' => $reason,
            'issued_at' => $now->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'sessions_revoked' => true,
            'actor_type' => $context->actorType,
            'command_name' => $commandName,
        ], $reason);

        return new ResetUserPasswordResult(
            userId: $userId,
            userDisplay: (string) $user->email,
            temporaryPassword: $temporaryPassword,
            expiresAt: $expiresAt,
            sessionVersion: $nextSessionVersion,
            sessionsRevoked: true,
        );
    }

    private function resolveUserId(string $selector): string
    {
        $matches = [];

        if (filter_var($selector, FILTER_VALIDATE_EMAIL) !== false) {
            $email = Str::lower($selector);
            $matches = DB::table('users')->where('normalized_email', $email)->pluck('id')->all();
        } elseif (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $selector) === 1) {
            $matches = DB::table('users')->where('id', $selector)->pluck('id')->all();
        } else {
            throw new InvalidArgumentException('User selector must be an exact email address or user UUID.');
        }

        if (count($matches) !== 1) {
            throw new RuntimeException(count($matches) === 0 ? 'No existing user matched the selector.' : 'User selector matched more than one user.');
        }

        return (string) $matches[0];
    }

    private function sanitizeReason(string $reason): string
    {
        $reason = trim(preg_replace('/\s+/', ' ', $reason) ?? '');
        if ($reason === '') {
            throw new InvalidArgumentException('Reason is required.');
        }
        if (mb_strlen($reason) > 240) {
            throw new InvalidArgumentException('Reason must be 240 characters or fewer.');
        }

        return $reason;
    }

    private function assertExpirationBounds(int $expiresInMinutes): void
    {
        if ($expiresInMinutes < self::MIN_EXPIRATION_MINUTES || $expiresInMinutes > self::MAX_EXPIRATION_MINUTES) {
            throw new InvalidArgumentException('Expiration must be between 5 and 1440 minutes.');
        }
    }

    private function temporaryPassword(): string
    {
        return 'utcp-'.bin2hex(random_bytes(18));
    }
}
