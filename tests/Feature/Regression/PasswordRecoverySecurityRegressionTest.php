<?php

namespace Tests\Feature\Regression;

use App\Models\Role;
use App\Services\Auth\PasswordRecovery;
use App\Services\Auth\RevokeAccountSessions;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Support\TestData;
use Tests\TestCase;

class PasswordRecoverySecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        config([
            'app.url' => 'https://recovery.phos.test',
            'mail.default' => 'smtp',
            'mail.from.address' => 'support@phos.test',
            'mail.mailers.smtp.host' => 'smtp.phos.test',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'support@phos.test',
            'mail.mailers.smtp.password' => 'synthetic-test-value',
            'mail.mailers.smtp.url' => null,
        ]);
    }

    public function test_unconfigured_email_never_claims_delivery_or_creates_a_token(): void
    {
        Notification::fake();
        $user = TestData::user();
        config(['mail.default' => 'log']);

        $this->get('/forgot-password')->assertOk()->assertSee(PasswordRecovery::UNAVAILABLE)
            ->assertSee('disabled', false)->assertSee('Back to sign in');
        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect('/forgot-password')
            ->assertSessionHasErrors(['email' => PasswordRecovery::UNAVAILABLE]);

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_logging_fallback_placeholder_sender_and_unsafe_base_url_are_unavailable(): void
    {
        $recovery = app(PasswordRecovery::class);
        $this->assertTrue($recovery->available());
        config(['mail.default' => 'failover']);
        $this->assertFalse($recovery->available());
        config(['mail.default' => 'smtp', 'mail.from.address' => 'hello@example.com']);
        $this->assertFalse($recovery->available());
        config(['mail.from.address' => 'support@phos.test', 'app.url' => 'https://user:password@recovery.phos.test']);
        $this->assertFalse($recovery->available());
        config(['app.url' => 'https://recovery.phos.test/?redirect=elsewhere']);
        $this->assertFalse($recovery->available());
    }

    public function test_known_unknown_disabled_and_broker_throttled_accounts_have_the_same_public_status(): void
    {
        Notification::fake();
        $active = TestData::user();
        $inactive = TestData::user($active->organization, ['is_active' => false]);

        foreach ([$active->email, 'unknown@phos.test', $inactive->email, strtoupper($active->email)] as $email) {
            $this->from('/forgot-password')->post('/forgot-password', ['email' => $email])
                ->assertRedirect('/forgot-password')->assertSessionHasNoErrors()
                ->assertSessionHas('status', PasswordRecovery::RECEIVED);
        }

        Notification::assertSentToTimes($active, ResetPassword::class, 1);
        Notification::assertNotSentTo($inactive, ResetPassword::class);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_case_insensitive_recovery_uses_only_the_configured_origin(): void
    {
        Notification::fake();
        $user = TestData::user(null, ['email' => 'Team.Member@phos.test']);
        $this->withServerVariables(['HTTP_HOST' => 'attacker.invalid'])
            ->post('/forgot-password', ['email' => strtoupper($user->email)])->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class, function ($notice) use ($user) {
            $url = $notice->toMail($user)->actionUrl;
            $this->assertStringStartsWith('https://recovery.phos.test/reset-password/', $url);
            $this->assertStringNotContainsString('attacker.invalid', $url);
            $this->assertStringContainsString('email=Team.Member%40phos.test', $url);
            return true;
        });
    }

    public function test_case_variant_account_collision_cannot_select_an_arbitrary_tenant_or_active_account(): void
    {
        Notification::fake();
        $first = TestData::user(null, ['email' => 'Same.Address@phos.test']);
        $second = TestData::user(null, ['email' => 'same.address@phos.test', 'is_active' => false]);
        $hash = $first->password;
        $token = Password::createToken($first);

        $this->post('/forgot-password', ['email' => strtoupper($first->email)])
            ->assertSessionHasNoErrors()->assertSessionHas('status', PasswordRecovery::RECEIVED);
        $this->post('/reset-password', $this->resetPayload($first->email, $token))->assertSessionHasErrors('email');

        Notification::assertNothingSent();
        $this->assertNull(app(PasswordRecovery::class)->eligibleUser($first->email));
        $this->assertSame($hash, $first->fresh()->password);
        $this->assertTrue(Password::tokenExists($first, $token));
        $this->assertFalse($second->is_active);
    }

    public function test_disabled_and_cross_tenant_assigned_roles_cannot_request_or_consume_reset_tokens(): void
    {
        Notification::fake();
        $user = TestData::user();
        $otherOrg = TestData::organization();
        $hash = $user->password;
        $remember = $user->remember_token;

        foreach ([[$user->organization_id, false], [$otherOrg->id, true]] as $index => [$organizationId, $active]) {
            $role = Role::create(['organization_id' => $organizationId, 'name' => 'Recovery role '.$index,
                'slug' => 'recovery-role-'.$index, 'is_active' => $active, 'permissions' => []]);
            $user->update(['role_id' => $role->id]);
            $token = Password::createToken($user);

            $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors()
                ->assertSessionHas('status', PasswordRecovery::RECEIVED);
            $this->post('/reset-password', $this->resetPayload($user->email, $token))->assertSessionHasErrors('email');
            $this->assertTrue(Password::tokenExists($user, $token));
        }

        Notification::assertNothingSent();
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame($remember, $user->fresh()->remember_token);
    }

    public function test_eligible_assigned_role_requires_no_new_administrative_privilege(): void
    {
        Notification::fake();
        $user = TestData::user(null, ['role' => 'staff']);
        $role = Role::create(['organization_id' => $user->organization_id, 'name' => 'Read only',
            'slug' => 'recovery-read-only', 'is_active' => true, 'permissions' => ['rentals' => ['read']]]);
        $user->update(['role_id' => $role->id]);

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertSame('staff', $user->fresh()->role);
        $this->assertSame(['rentals' => ['read']], $role->fresh()->permissions);
    }

    public function test_real_http_kernel_defers_mail_and_token_creation_until_termination(): void
    {
        Notification::fake();
        $user = TestData::user();
        [$kernel, $request, $response] = $this->beginRecovery($user->email);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(PasswordRecovery::RECEIVED, $request->session()->get('status'));
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);

        $kernel->terminate($request, $response);

        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_deferred_mail_rechecks_account_status_before_creating_a_token(): void
    {
        Notification::fake();
        $user = TestData::user();
        [$kernel, $request, $response] = $this->beginRecovery($user->email);
        $user->update(['is_active' => false]);

        $kernel->terminate($request, $response);

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertSame(PasswordRecovery::RECEIVED, $request->session()->get('status'));
    }

    public function test_actual_broker_delivery_failure_removes_only_its_token_and_logs_no_transport_secret(): void
    {
        $user = TestData::user();
        $other = TestData::user($user->organization);
        $otherToken = Password::createToken($other);
        $secret = 'synthetic-smtp-password-and-private-recipient';
        Log::spy();
        Notification::shouldReceive('send')->once()->andReturnUsing(function () use ($user, $secret) {
            $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
            throw new \RuntimeException($secret);
        });
        [$kernel, $request, $response] = $this->beginRecovery($user->email);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(PasswordRecovery::RECEIVED, $request->session()->get('status'));

        $kernel->terminate($request, $response);

        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertTrue(Password::tokenExists($other, $otherToken));
        Log::shouldHaveReceived('error')->once()->with('Password recovery delivery failed.', ['exception_type' => \RuntimeException::class]);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_recovery_throttles_unknown_addresses_across_case_variants_and_rejects_array_input(): void
    {
        Notification::fake();
        $this->post('/forgot-password', ['email' => ['invalid']])->assertSessionHasErrors('email');
        $this->post('/forgot-password', ['email' => 'unknown@phos.test'])->assertRedirect();
        $this->post('/forgot-password', ['email' => 'UNKNOWN@phos.test'])->assertRedirect();
        $this->post('/forgot-password', ['email' => 'unknown@phos.test'])->assertStatus(429);

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_reset_submission_throttle_also_applies_to_invalid_accounts(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/reset-password', $this->resetPayload('unknown@phos.test', 'invalid-token'))
                ->assertSessionHasErrors('email');
        }
        $this->post('/reset-password', $this->resetPayload('unknown@phos.test', 'invalid-token'))->assertStatus(429);
    }

    public function test_valid_reset_is_single_use_rotates_remember_token_and_revokes_only_target_database_sessions(): void
    {
        $user = TestData::user(null, ['email' => 'Reset.Target@phos.test']);
        $other = TestData::user($user->organization);
        $remember = $user->remember_token;
        $token = Password::createToken($user);
        foreach (['target-a' => $user->id, 'target-b' => $user->id, 'other' => $other->id, 'guest' => null] as $id => $owner) {
            $this->sessionRow($id, $owner);
        }
        config(['session.driver' => 'database']);
        $payload = $this->resetPayload(strtoupper($user->email), $token);

        $this->post('/reset-password', $payload)->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $this->assertTrue(Hash::check($payload['password'], $user->fresh()->password));
        $this->assertNotSame($remember, $user->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-a']);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-b']);
        $this->assertDatabaseHas('sessions', ['id' => 'other', 'user_id' => $other->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'guest', 'user_id' => null]);
        $newHash = $user->fresh()->password;
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
        $this->assertSame($newHash, $user->fresh()->password);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_non_database_session_store_does_not_purge_database_sessions(): void
    {
        $user = TestData::user();
        $this->sessionRow('unrelated-store', $user->id);
        $token = Password::createToken($user);

        $this->post('/reset-password', $this->resetPayload($user->email, $token))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sessions', ['id' => 'unrelated-store', 'user_id' => $user->id]);
    }

    public function test_expired_token_and_deactivation_after_issue_leave_password_remember_and_sessions_unchanged(): void
    {
        $user = TestData::user();
        $hash = $user->password;
        $remember = $user->remember_token;
        $this->sessionRow('unchanged', $user->id);
        config(['session.driver' => 'database']);
        $token = Password::createToken($user);
        $this->travel(61)->minutes();
        $this->post('/reset-password', $this->resetPayload($user->email, $token))->assertSessionHasErrors('email');
        $this->travelBack();
        $token = Password::createToken($user);
        $user->update(['is_active' => false]);
        $this->post('/reset-password', $this->resetPayload($user->email, $token))->assertSessionHasErrors('email');

        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame($remember, $user->fresh()->remember_token);
        $this->assertDatabaseHas('sessions', ['id' => 'unchanged', 'user_id' => $user->id]);
        $this->assertTrue(Password::tokenExists($user, $token));
    }

    public function test_failed_session_purge_rolls_back_password_remember_token_token_consumption_and_deleted_sessions(): void
    {
        $user = TestData::user();
        $other = TestData::user($user->organization);
        $hash = $user->password;
        $remember = $user->remember_token;
        $token = Password::createToken($user);
        $this->sessionRow('rollback-target', $user->id);
        $this->sessionRow('rollback-other', $other->id);
        config(['session.driver' => 'database']);
        $revoker = \Mockery::mock(RevokeAccountSessions::class);
        $revoker->shouldReceive('forUser')->once()->withArgs(fn ($current) => $current->is($user))
            ->andReturnUsing(function ($current) {
                DB::table('sessions')->where('user_id', $current->id)->delete();
                throw new \RuntimeException('Synthetic revocation failure');
            });
        $this->app->instance(RevokeAccountSessions::class, $revoker);
        $this->withoutExceptionHandling();

        try {
            $this->post('/reset-password', $this->resetPayload($user->email, $token));
            $this->fail('A failed purge must prevent a partially completed reset.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic revocation failure', $exception->getMessage());
        }

        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame($remember, $user->fresh()->remember_token);
        $this->assertTrue(Password::tokenExists($user, $token));
        $this->assertDatabaseHas('sessions', ['id' => 'rollback-target', 'user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'rollback-other', 'user_id' => $other->id]);
    }

    public function test_recovery_headers_cover_only_the_four_reset_routes(): void
    {
        foreach (['password.request', 'password.email', 'password.reset', 'password.store'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertContains(\App\Http\Middleware\PasswordRecoveryHeaders::class, $route->gatherMiddleware());
        }
        foreach (['login', 'dashboard', 'profile.edit'] as $name) {
            $this->assertNotContains(\App\Http\Middleware\PasswordRecoveryHeaders::class,
                app('router')->getRoutes()->getByName($name)->gatherMiddleware());
        }
        foreach (['/forgot-password', '/reset-password/synthetic-token?email=user%40phos.test'] as $url) {
            $response = $this->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $response = $this->post('/reset-password', $this->resetPayload('unknown@phos.test', 'invalid-token'));
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_baseline_smtp_transports_require_tls_without_connecting_or_sending(): void
    {
        foreach ([['smtp', 587], ['smtps', 465]] as [$scheme, $port]) {
            config(['mail.mailers.smtp.scheme' => $scheme, 'mail.mailers.smtp.port' => $port]);
            Mail::purge('smtp');
            $transport = Mail::mailer('smtp')->getSymfonyTransport();
            $this->assertTrue($transport->isTlsRequired());
            $this->assertTrue($transport->isAutoTls());
            if ($scheme === 'smtps') {
                $this->assertTrue($transport->getStream()->isTls());
            }
        }
    }

    private function resetPayload(string $email, string $token): array
    {
        return ['email' => $email, 'token' => $token, 'password' => 'a unique recovery passphrase',
            'password_confirmation' => 'a unique recovery passphrase'];
    }

    private function sessionRow(string $id, ?int $userId): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => time()]);
    }

    private function beginRecovery(string $email): array
    {
        $request = Request::create('https://recovery.phos.test/forgot-password', 'POST', ['email' => $email]);
        $kernel = $this->app->make(Kernel::class);
        return [$kernel, $request, $kernel->handle($request)];
    }
}
