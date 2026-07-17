<?php

namespace Tests\Feature\TelephonyDomain;

use App\Identity\IdentityIds;
use App\RuntimeEngine\Events\EventNormalizerWorker;
use App\RuntimeEngine\Reconciliation\ReconciliationRepository;
use App\RuntimeEngine\Reconciliation\ReconciliationWorker;
use App\TelephonyDomain\Signaling\KamailioRegistrationObserver;
use App\TelephonyDomain\Signaling\KamailioRegistrationPollHealthRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class KamailioRegistrationObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_observer_classifies_registration_snapshot_changes_and_projects_safely(): void
    {
        $this->ensureLocationTable();
        [$tenantId, $sessionId, $identity] = $this->registrationFixture();

        $this->writeLocation($identity, 'ruid-1', 'sip:'.$identity.'@browser.invalid;transport=ws', now()->addSeconds(60), now());

        $observer = app(KamailioRegistrationObserver::class);
        $normalizer = app(EventNormalizerWorker::class);

        $accepted = $observer->pollOnce('observer-a');
        $this->assertSame('polled', $accepted['status']);
        $this->assertSame(1, $accepted['receipts']);
        $normalizer->workOnce('normalizer-a', 10);

        $this->assertDatabaseHas('signaling_registration_observations', [
            'tenant_id' => $tenantId,
            'telephony_session_id' => $sessionId,
            'observed_state' => 'registered',
            'last_event_type' => 'kamailio.registration.accepted',
        ]);
        $payload = (string) DB::table('runtime_event_receipts')->where('event_type', 'kamailio.registration.accepted')->value('sanitized_payload');
        $this->assertStringNotContainsString('browser.invalid', $payload);
        $this->assertStringNotContainsString('Authorization', $payload);

        $unchanged = $observer->pollOnce('observer-a');
        $this->assertSame(0, $unchanged['receipts']);

        DB::table('location')->where('username', $identity)->update(['last_modified' => now()->addSeconds(10)]);
        $refreshed = $observer->pollOnce('observer-a');
        $this->assertSame(1, $refreshed['receipts']);
        $normalizer->workOnce('normalizer-b', 10);
        $this->assertDatabaseHas('signaling_registration_observations', [
            'telephony_session_id' => $sessionId,
            'last_event_type' => 'kamailio.registration.refreshed',
            'observed_state' => 'registered',
        ]);

        DB::table('location')->where('username', $identity)->update([
            'ruid' => 'ruid-2',
            'contact' => 'sip:'.$identity.'@replacement.invalid;transport=ws',
            'last_modified' => now()->addSeconds(20),
        ]);
        $replaced = $observer->pollOnce('observer-a');
        $this->assertSame(1, $replaced['receipts']);
        $normalizer->workOnce('normalizer-c', 10);
        $this->assertDatabaseHas('signaling_registration_observations', [
            'telephony_session_id' => $sessionId,
            'contact_ruid' => 'ruid-2',
            'last_event_type' => 'kamailio.registration.replaced',
        ]);

        DB::table('location')->where('username', $identity)->delete();
        $removed = $observer->pollOnce('observer-a');
        $this->assertSame(1, $removed['receipts']);
        $normalizer->workOnce('normalizer-d', 10);
        $this->assertDatabaseHas('signaling_registration_observations', [
            'telephony_session_id' => $sessionId,
            'observed_state' => 'unregistered',
            'last_event_type' => 'kamailio.registration.removed',
        ]);

        $this->writeLocation($identity, 'ruid-3', 'sip:'.$identity.'@expiry.invalid;transport=ws', now()->addSecond(), now()->addSeconds(30));
        $observer->pollOnce('observer-a');
        $normalizer->workOnce('normalizer-e', 10);
        DB::table('location')->where('username', $identity)->delete();
        DB::table('runtime_projection_checkpoints')
            ->where('projector', KamailioRegistrationObserver::PROJECTOR)
            ->update(['checkpoint_payload' => json_encode([
                'collected_at' => now()->toISOString(),
                'identities' => [
                    $identity => [
                        'ruid' => 'ruid-3',
                        'expires_at' => now()->subSecond()->toISOString(),
                        'last_modified_at' => now()->toISOString(),
                        'contact_fingerprint' => hash('sha256', 'safe'),
                    ],
                ],
            ], JSON_THROW_ON_ERROR)]);

        $expired = $observer->pollOnce('observer-a');
        $this->assertSame(1, $expired['receipts']);
        $normalizer->workOnce('normalizer-f', 10);
        $this->assertDatabaseHas('signaling_registration_observations', [
            'telephony_session_id' => $sessionId,
            'observed_state' => 'expired',
            'last_event_type' => 'kamailio.registration.expired',
        ]);
    }

    public function test_signaling_registration_reconciliation_reports_pending_removal_without_mutating_observation(): void
    {
        $this->ensureLocationTable();
        [, $sessionId] = $this->registrationFixture();
        DB::table('signaling_registration_observations')->where('telephony_session_id', $sessionId)->update([
            'desired_state' => 'removed',
            'desired_generation' => 2,
            'observed_state' => 'registered',
        ]);
        $registration = DB::table('signaling_registration_observations')->where('telephony_session_id', $sessionId)->first();
        app(ReconciliationRepository::class)
            ->ensureTarget((string) $registration->tenant_id, 'signaling_registration', (string) $registration->id, 2);

        app(ReconciliationWorker::class)->workOnce('reconciler-a', 10);

        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'target_type' => 'signaling_registration',
            'target_id' => $registration->id,
            'status' => 'waiting',
        ]);
        $this->assertSame('registered', DB::table('signaling_registration_observations')->where('id', $registration->id)->value('observed_state'));
    }

    public function test_unexpected_deregistration_while_still_eligible_reopens_a_converged_reconciliation_target(): void
    {
        $this->ensureLocationTable();
        [$tenantId, $sessionId, $identity] = $this->registrationFixture();
        DB::table('telephony_signaling_credentials')->insert([
            'id' => IdentityIds::new(),
            'tenant_id' => $tenantId,
            'telephony_session_id' => $sessionId,
            'username' => $identity,
            'realm' => 'sip.utcp.local.test',
            'algorithm' => 'MD5',
            'ha1' => md5($identity.':sip.utcp.local.test:secret'),
            'issued_at' => now(),
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->writeLocation($identity, 'ruid-1', 'sip:'.$identity.'@browser.invalid;transport=ws', now()->addSeconds(60), now());

        $observer = app(KamailioRegistrationObserver::class);
        $normalizer = app(EventNormalizerWorker::class);

        $observer->pollOnce('observer-a');
        $normalizer->workOnce('normalizer-a', 10);
        $registration = DB::table('signaling_registration_observations')->where('telephony_session_id', $sessionId)->first();
        app(ReconciliationWorker::class)->workOnce('reconciler-a', 10);
        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'target_type' => 'signaling_registration',
            'target_id' => $registration->id,
            'status' => 'converged',
        ]);

        DB::table('location')->where('username', $identity)->delete();
        $observer->pollOnce('observer-a');
        $normalizer->workOnce('normalizer-b', 10);

        $this->assertSame('eligible', DB::table('signaling_registration_observations')->where('id', $registration->id)->value('desired_state'));
        $this->assertSame('unregistered', DB::table('signaling_registration_observations')->where('id', $registration->id)->value('observed_state'));
        $this->assertDatabaseHas('runtime_reconciliation_states', [
            'target_type' => 'signaling_registration',
            'target_id' => $registration->id,
            'status' => 'waiting',
        ]);
    }

    public function test_poll_health_repository_records_success_and_failure_counts_independently_of_checkpoint_state(): void
    {
        $health = new KamailioRegistrationPollHealthRepository;
        $this->assertNull($health->current());

        $health->recordSuccess();
        $health->recordSuccess();
        $health->recordFailure();

        $current = $health->current();
        $this->assertSame(2, $current['poll_success_count']);
        $this->assertSame(1, $current['poll_failure_count']);
        $this->assertNotNull($current['last_success_at']);
        $this->assertNotNull($current['last_failure_at']);
        $this->assertDatabaseCount('kamailio_registration_poll_health', 1);
    }

    public function test_poll_failure_does_not_advance_checkpoint_or_emit_a_removal_receipt(): void
    {
        $this->ensureLocationTable();
        [, $sessionId, $identity] = $this->registrationFixture();
        $this->writeLocation($identity, 'ruid-1', 'sip:'.$identity.'@browser.invalid;transport=ws', now()->addSeconds(60), now());

        $observer = app(KamailioRegistrationObserver::class);
        $observer->pollOnce('observer-a');
        app(EventNormalizerWorker::class)->workOnce('normalizer-a', 10);
        $checkpointBefore = DB::table('runtime_projection_checkpoints')
            ->where('projector', KamailioRegistrationObserver::PROJECTOR)
            ->first();
        $receiptCountBefore = DB::table('runtime_event_receipts')->where('adapter_key', 'kamailio-registration')->count();

        // Simulate a poll-time failure the same way the console command's catch path does:
        // the transaction never runs, so neither the checkpoint nor a removal/expired
        // receipt is produced solely because a poll attempt failed.
        $health = new KamailioRegistrationPollHealthRepository;
        $health->recordFailure();

        $checkpointAfter = DB::table('runtime_projection_checkpoints')
            ->where('projector', KamailioRegistrationObserver::PROJECTOR)
            ->first();
        $this->assertSame($checkpointBefore->sequence, $checkpointAfter->sequence);
        $this->assertSame($checkpointBefore->checkpoint_hash, $checkpointAfter->checkpoint_hash);
        $this->assertSame($receiptCountBefore, DB::table('runtime_event_receipts')->where('adapter_key', 'kamailio-registration')->count());
        $this->assertDatabaseHas('signaling_registration_observations', [
            'telephony_session_id' => $sessionId,
            'observed_state' => 'registered',
        ]);
        $this->assertSame(1, $health->current()['poll_failure_count']);
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function registrationFixture(): array
    {
        $tenantId = IdentityIds::new();
        $userId = IdentityIds::new();
        $sessionId = IdentityIds::new();
        $identity = 'ts-'.str_replace('-', '', $sessionId);

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'slug' => 'observer-test',
            'display_name' => 'Observer Test',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'id' => $userId,
            'email' => 'observer@utcp.local.test',
            'normalized_email' => 'observer@utcp.local.test',
            'display_name' => 'Observer User',
            'password' => 'not-used',
            'status' => 'active',
            'password_change_required' => false,
            'session_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('telephony_sessions')->insert([
            'id' => $sessionId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => 'active',
            'issued_at' => now(),
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('signaling_registration_observations')->insert([
            'id' => IdentityIds::new(),
            'tenant_id' => $tenantId,
            'telephony_session_id' => $sessionId,
            'signaling_identity' => $identity,
            'desired_state' => 'eligible',
            'desired_generation' => 1,
            'observed_state' => 'unknown',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$tenantId, $sessionId, $identity];
    }

    private function writeLocation(string $identity, string $ruid, string $contact, mixed $expires, mixed $lastModified): void
    {
        DB::table('location')->updateOrInsert(
            ['username' => $identity],
            [
                'ruid' => $ruid,
                'domain' => 'sip.utcp.local.test',
                'contact' => $contact,
                'expires' => $expires,
                'last_modified' => $lastModified,
            ],
        );
    }

    private function ensureLocationTable(): void
    {
        if (Schema::hasTable('location')) {
            return;
        }

        Schema::create('location', function ($table): void {
            $table->id();
            $table->string('ruid', 64)->unique();
            $table->string('username', 64);
            $table->string('domain', 128)->nullable();
            $table->string('contact', 512);
            $table->timestamp('expires');
            $table->timestamp('last_modified');
        });
    }
}
