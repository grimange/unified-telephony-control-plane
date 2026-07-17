<?php

namespace App\TelephonyDomain\Signaling;

use App\ControlPlane\Audit\AuditRepository;
use App\ControlPlane\Shared\ExecutionContext;
use App\RuntimeEngine\Reconciliation\ReconciliationRepository;
use App\TelephonyDomain\TelephonyDomainIds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SignalingCredentialService
{
    private const ALGORITHM = 'MD5';

    private const SECRET_BYTES = 24;

    public function __construct(
        private readonly AuditRepository $audit,
        private readonly ReconciliationRepository $reconciliation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function issueOwn(string $tenantId, string $userId, string $telephonySessionId, ExecutionContext $context): array
    {
        return $this->issue($tenantId, $userId, $telephonySessionId, $context, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function issueForUser(string $tenantId, string $userId, string $telephonySessionId, ExecutionContext $context): array
    {
        return $this->issue($tenantId, $userId, $telephonySessionId, $context, false);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function metadata(string $tenantId, string $userId, string $telephonySessionId, bool $ownOnly = true): ?array
    {
        $session = DB::table('telephony_sessions')
            ->where('tenant_id', $tenantId)
            ->where('id', $telephonySessionId)
            ->first();
        if ($session === null || $session->user_id !== $userId) {
            return null;
        }

        $credential = DB::table('telephony_signaling_credentials')
            ->where('tenant_id', $tenantId)
            ->where('telephony_session_id', $telephonySessionId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('issued_at')
            ->first();
        $registration = DB::table('signaling_registration_observations')
            ->where('tenant_id', $tenantId)
            ->where('telephony_session_id', $telephonySessionId)
            ->first();

        return [
            'signaling_identity' => $this->usernameForSession($telephonySessionId),
            'credential' => $credential === null ? null : $this->serializeCredential($credential),
            'registration' => $registration === null ? [
                'desired_state' => $session->status === 'active' ? 'eligible' : 'removed',
                'observed_state' => 'unknown',
                'observed_at' => null,
                'observed_expires_at' => null,
                'last_event_type' => null,
                'failure_class' => null,
                'pending_removal' => false,
                'reconciliation_status' => null,
            ] : $this->serializeRegistration($registration),
        ];
    }

    public function revokeForSession(string $tenantId, string $telephonySessionId, string $reason, ?ExecutionContext $context = null): void
    {
        $now = now();
        DB::table('telephony_signaling_credentials')
            ->where('tenant_id', $tenantId)
            ->where('telephony_session_id', $telephonySessionId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $now,
                'updated_at' => $now,
            ]);

        $this->upsertDesiredRegistration($tenantId, $telephonySessionId, 'removed', $now);

        if ($context !== null) {
            $this->audit->append($context, 'telephony.signaling_credential.revoked', 'telephony_session', $telephonySessionId, [
                'tenant_id' => $tenantId,
                'telephony_session_id' => $telephonySessionId,
                'reason' => $reason,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(string $tenantId, string $userId, string $telephonySessionId, ExecutionContext $context, bool $ownOnly): array
    {
        return DB::transaction(function () use ($tenantId, $userId, $telephonySessionId, $context): array {
            $session = DB::table('telephony_sessions')
                ->where('tenant_id', $tenantId)
                ->where('id', $telephonySessionId)
                ->lockForUpdate()
                ->first();
            if ($session === null || $session->user_id !== $userId) {
                throw new HttpException(404, 'Telephony session not found.');
            }
            $now = now();
            $sessionExpiresAt = Carbon::parse($session->expires_at);
            if ($session->status !== 'active' || $sessionExpiresAt->lte($now)) {
                throw new HttpException(422, 'Active telephony session is required.');
            }

            DB::table('telephony_signaling_credentials')
                ->where('tenant_id', $tenantId)
                ->where('telephony_session_id', $telephonySessionId)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => $now,
                    'updated_at' => $now,
                ]);

            $username = $this->usernameForSession($telephonySessionId);
            $realm = (string) config('telephony_signaling.realm', 'sip.utcp.local.test');
            $secret = $this->generateSecret();
            $expiresAt = $now->copy()->addSeconds(min(
                (int) config('telephony_signaling.credential_lifetime_seconds', 120),
                max(1, $now->diffInSeconds($sessionExpiresAt, false)),
            ));
            $id = TelephonyDomainIds::new();

            DB::table('telephony_signaling_credentials')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'telephony_session_id' => $telephonySessionId,
                'username' => $username,
                'realm' => $realm,
                'algorithm' => self::ALGORITHM,
                'ha1' => md5($username.':'.$realm.':'.$secret),
                'issued_at' => $now,
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->upsertDesiredRegistration($tenantId, $telephonySessionId, 'eligible', $now);

            $this->audit->append($context, 'telephony.signaling_credential.issued', 'telephony_session', $telephonySessionId, [
                'tenant_id' => $tenantId,
                'telephony_session_id' => $telephonySessionId,
                'username' => $username,
                'realm' => $realm,
                'algorithm' => self::ALGORITHM,
                'issued_at' => $now->toISOString(),
                'expires_at' => $expiresAt->toISOString(),
                'previous_credential_revoked' => true,
            ]);

            return [
                'credential' => [
                    'username' => $username,
                    'realm' => $realm,
                    'algorithm' => self::ALGORITHM,
                    'sip_secret' => $secret,
                    'wss_uri' => (string) config('telephony_signaling.wss_uri', 'wss://sip.utcp.local.test/ws'),
                    'issued_at' => (string) $now,
                    'expires_at' => (string) $expiresAt,
                ],
            ];
        });
    }

    private function usernameForSession(string $telephonySessionId): string
    {
        return 'ts-'.Str::lower(str_replace('-', '', $telephonySessionId));
    }

    private function generateSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::SECRET_BYTES)), '+/', '-_'), '=');
    }

    private function upsertDesiredRegistration(string $tenantId, string $telephonySessionId, string $desiredState, Carbon $now): void
    {
        $existing = DB::table('signaling_registration_observations')
            ->where('tenant_id', $tenantId)
            ->where('telephony_session_id', $telephonySessionId)
            ->lockForUpdate()
            ->first();

        $values = [
            'signaling_identity' => $this->usernameForSession($telephonySessionId),
            'desired_state' => $desiredState,
            'updated_at' => $now,
        ];

        if ($existing === null) {
            $id = TelephonyDomainIds::new();
            DB::table('signaling_registration_observations')->insert(array_merge($values, [
                'id' => $id,
                'tenant_id' => $tenantId,
                'telephony_session_id' => $telephonySessionId,
                'desired_generation' => 1,
                'observed_state' => 'unknown',
                'created_at' => $now,
            ]));
            $this->reconciliation->ensureTarget($tenantId, 'signaling_registration', $id, 1);

            return;
        }

        $generation = ((int) ($existing->desired_generation ?? 1)) + (((string) $existing->desired_state === $desiredState) ? 0 : 1);
        DB::table('signaling_registration_observations')->where('id', $existing->id)->update(array_merge($values, [
            'desired_generation' => $generation,
        ]));
        $this->reconciliation->ensureTarget($tenantId, 'signaling_registration', (string) $existing->id, $generation);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCredential(object $row): array
    {
        return [
            'username' => $row->username,
            'realm' => $row->realm,
            'algorithm' => $row->algorithm,
            'issued_at' => $row->issued_at,
            'expires_at' => $row->expires_at,
            'revoked_at' => $row->revoked_at,
            'wss_uri' => (string) config('telephony_signaling.wss_uri', 'wss://sip.utcp.local.test/ws'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRegistration(object $row): array
    {
        $reconciliation = DB::table('runtime_reconciliation_states')
            ->where('target_type', 'signaling_registration')
            ->where('target_id', $row->id)
            ->first();

        return [
            'desired_state' => $row->desired_state,
            'observed_state' => $row->observed_state,
            'observed_at' => $row->observed_at,
            'observed_expires_at' => $row->observed_expires_at,
            'last_event_type' => $row->last_event_type,
            'failure_class' => $row->failure_class,
            'pending_removal' => $row->desired_state === 'removed' && in_array($row->observed_state, ['registered', 'pending_removal'], true),
            'reconciliation_status' => $reconciliation?->status,
            'reconciliation_reason' => $reconciliation?->blocked_reason,
        ];
    }
}
