<?php

namespace Tests\Feature\Identity;

use App\Identity\IdentityIds;
use App\Identity\UserAccess\ResetUserPasswordService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\TestCase;

final class UserAccessResetPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_requires_exact_user_reason_and_bounded_expiration(): void
    {
        $this->artisan('utcp:user-access:reset-password', [
            '--reason' => 'Operator review',
        ])->assertExitCode(2);

        $this->artisan('utcp:user-access:reset-password', [
            '--user' => 'missing@utcp.local.test',
            '--reason' => 'Operator review',
        ])->assertExitCode(1);

        $this->artisan('utcp:user-access:reset-password', [
            '--user' => 'not a selector',
            '--reason' => 'Operator review',
        ])->assertExitCode(1);

        $user = $this->createUser('contract-target@utcp.local.test', 'Contract Target');

        $this->artisan('utcp:user-access:reset-password', [
            '--user' => $user->email,
            '--reason' => ' ',
        ])->assertExitCode(2);

        $this->artisan('utcp:user-access:reset-password', [
            '--user' => $user->email,
            '--reason' => 'Operator review',
            '--expires-in' => 4,
        ])->assertExitCode(1);

        $this->artisan('utcp:user-access:reset-password', [
            '--user' => $user->email,
            '--reason' => 'Operator review',
            '--expires-in' => 1441,
        ])->assertExitCode(1);
    }

    public function test_command_rejects_plaintext_password_options(): void
    {
        $this->createUser('no-secret-arg@utcp.local.test', 'No Secret Argument');

        $this->expectException(InvalidOptionException::class);

        $this->artisan('utcp:user-access:reset-password', [
            '--user' => 'no-secret-arg@utcp.local.test',
            '--reason' => 'Operator review',
            '--password' => 'caller-supplied-password',
        ])->run();
    }

    public function test_command_resets_password_without_printing_secret_by_default(): void
    {
        $user = $this->createUser('reset-target@utcp.local.test', 'Reset Target');
        $oldHash = (string) DB::table('users')->where('id', $user->id)->value('password');
        $oldRememberToken = (string) DB::table('users')->where('id', $user->id)->value('remember_token');

        $this->artisan('utcp:user-access:reset-password', [
            '--user' => $user->email,
            '--reason' => 'Operator browser review',
            '--expires-in' => 30,
        ])
            ->expectsOutput('Temporary login credential issued.')
            ->expectsOutput('User: '.$user->email)
            ->expectsOutput('Password change required: yes')
            ->expectsOutput('Existing sessions revoked: yes')
            ->expectsOutput('Temporary password displayed: no')
            ->assertExitCode(0);

        $row = DB::table('users')->where('id', $user->id)->first();
        $this->assertNotSame($oldHash, $row->password);
        $this->assertFalse(Hash::check('correct-password-123', (string) $row->password));
        $this->assertTrue((bool) $row->password_change_required);
        $this->assertNotNull($row->temporary_password_issued_at);
        $this->assertNotNull($row->temporary_password_expires_at);
        $this->assertSame(2, (int) $row->session_version);
        $this->assertNotSame($oldRememberToken, (string) $row->remember_token);
        $this->assertSame('active', $row->status);

        $audit = json_encode(DB::table('control_plane_audit_records')->get()->all(), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('identity.user_password_reset_issued', $audit);
        $this->assertStringContainsString('Operator browser review', $audit);
        $this->assertStringNotContainsString($oldHash, $audit);
        $this->assertStringNotContainsString('correct-password-123', $audit);
    }

    public function test_show_password_outputs_the_temporary_password_once_and_normal_flow_rotates_it(): void
    {
        [$user, $tenantId] = $this->createPlatformAdminWithTenant();

        $this->artisan('utcp:user-access:reset-password', [
            '--user' => $user->id,
            '--reason' => 'Natural browser acceptance preparation',
            '--expires-in' => 30,
            '--show-password' => 1,
        ])
            ->expectsOutputToContain('Temporary login credential issued.')
            ->expectsOutputToContain('Temporary password: utcp-')
            ->assertExitCode(0);

        $temporaryPassword = $this->resetForTest($user->email, 'Normal forced password flow');

        $this->assertTrue(Hash::check($temporaryPassword, (string) DB::table('users')->where('id', $user->id)->value('password')));

        $this->withSession(['_token' => 'csrf-token'])
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => $temporaryPassword,
                '_token' => 'csrf-token',
            ])
            ->assertOk();

        $this->getJson('/api/v1/auth/session')
            ->assertOk()
            ->assertJsonPath('user.password_change_required', true);

        $this->postJson('/api/v1/auth/tenant-context', ['tenant_id' => $tenantId])
            ->assertForbidden()
            ->assertJsonPath('message', 'Password change required.');

        $this->getJson('/api/v1/admin/users')
            ->assertForbidden()
            ->assertJsonPath('message', 'Password change required.');

        $this->withSession(['_token' => 'csrf-token'])
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => $temporaryPassword,
                'new_password' => 'rotated-password-456',
                '_token' => 'csrf-token',
            ])
            ->assertOk();

        $row = DB::table('users')->where('id', $user->id)->first();
        $this->assertFalse((bool) $row->password_change_required);
        $this->assertNull($row->temporary_password_issued_at);
        $this->assertNull($row->temporary_password_expires_at);
        $this->assertNotNull($row->password_changed_at);
        $this->assertFalse(Hash::check($temporaryPassword, (string) $row->password));
        $this->assertTrue(Hash::check('rotated-password-456', (string) $row->password));

        $this->getJson('/api/v1/admin/users')->assertOk();

        $this->withSession(['_token' => 'csrf-token'])
            ->postJson('/api/v1/auth/logout', ['_token' => 'csrf-token'])
            ->assertOk();

        $this->withSession(['_token' => 'csrf-token'])
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => $temporaryPassword,
                '_token' => 'csrf-token',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $audit = json_encode(DB::table('control_plane_audit_records')->get()->all(), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('identity.user_password_changed', $audit);
        $this->assertStringNotContainsString($temporaryPassword, $audit);
    }

    public function test_temporary_password_expires_and_prior_reset_is_invalidated_by_repeated_reset(): void
    {
        $user = $this->createUser('repeat-target@utcp.local.test', 'Repeat Target');

        $firstPassword = $this->resetForTest($user->email, 'First reset');
        $secondPassword = $this->resetForTest($user->email, 'Second reset');

        $this->assertNotSame($firstPassword, $secondPassword);
        $hash = (string) DB::table('users')->where('id', $user->id)->value('password');
        $this->assertFalse(Hash::check($firstPassword, $hash));
        $this->assertTrue(Hash::check($secondPassword, $hash));

        DB::table('users')->where('id', $user->id)->update(['temporary_password_expires_at' => now()->subMinute()]);

        $this->withSession(['_token' => 'csrf-token'])
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => $secondPassword,
                '_token' => 'csrf-token',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_reset_revokes_existing_sessions_preserves_memberships_and_suspended_status(): void
    {
        [$admin, $tenantId, $membershipId] = $this->createPlatformAdminWithTenant();
        $member = $this->createUser('member@utcp.local.test', 'Member User', 'suspended');
        DB::table('tenant_memberships')->insert([
            'id' => IdentityIds::new(),
            'user_id' => $member->id,
            'tenant_id' => $tenantId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($member)
            ->withSession(['user_session_version' => 1])
            ->getJson('/api/v1/auth/session')
            ->assertUnauthorized();

        $this->withSession(['_token' => 'csrf-token'])
            ->postJson('/api/v1/auth/login', [
                'email' => $admin->email,
                'password' => 'correct-password-123',
                '_token' => 'csrf-token',
            ])
            ->assertOk();

        $this->getJson('/api/v1/auth/session')->assertOk();

        $this->artisan('utcp:user-access:reset-password', [
            '--user' => $member->email,
            '--reason' => 'Suspended account recovery preparation',
            '--expires-in' => 30,
        ])->assertExitCode(0);

        $memberRow = DB::table('users')->where('id', $member->id)->first();
        $this->assertSame('suspended', $memberRow->status);
        $this->assertSame(1, DB::table('tenant_memberships')->where('user_id', $member->id)->count());
        $this->assertDatabaseHas('tenant_memberships', ['id' => $membershipId, 'status' => 'active']);

        $this->getJson('/api/v1/auth/session')->assertOk();

        $this->artisan('utcp:user-access:reset-password', [
            '--user' => $admin->email,
            '--reason' => 'Reset current browser session target',
            '--expires-in' => 30,
        ])->assertExitCode(0);

        Auth::forgetGuards();

        $this->getJson('/api/v1/auth/session')
            ->assertUnauthorized();
    }

    private function resetForTest(string $selector, string $reason): string
    {
        $result = $this->app->make(ResetUserPasswordService::class)->resetFromConsole($selector, $reason, 30);

        return $result->temporaryPassword;
    }

    /**
     * @return array{0: User, 1: string, 2: string}
     */
    private function createPlatformAdminWithTenant(): array
    {
        $user = $this->createUser('admin@utcp.local.test', 'Admin User');
        $tenantId = IdentityIds::new();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'slug' => 'local',
            'display_name' => 'Local Tenant',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $membershipId = IdentityIds::new();
        DB::table('tenant_memberships')->insert([
            'id' => $membershipId,
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('platform_role_assignments')->insert([
            'id' => IdentityIds::new(),
            'user_id' => $user->id,
            'role_key' => 'platform-admin',
            'assigned_by_user_id' => null,
            'created_at' => now(),
        ]);
        DB::table('tenant_role_assignments')->insert([
            'id' => IdentityIds::new(),
            'membership_id' => $membershipId,
            'role_key' => 'tenant-admin',
            'assigned_by_user_id' => null,
            'created_at' => now(),
        ]);

        return [$user, $tenantId, $membershipId];
    }

    private function createUser(string $email, string $displayName, string $status = 'active'): User
    {
        return User::query()->create([
            'id' => IdentityIds::new(),
            'email' => $email,
            'normalized_email' => $email,
            'display_name' => $displayName,
            'password' => Hash::make('correct-password-123'),
            'status' => $status,
            'password_change_required' => false,
            'session_version' => 1,
            'remember_token' => 'remember-'.$email,
        ]);
    }
}
