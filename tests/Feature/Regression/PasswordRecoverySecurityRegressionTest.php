<?php

namespace Tests\Feature\Regression;

use App\Models\Role;
use App\Services\Auth\PasswordRecovery;
use App\Services\Auth\RevokeAccountSessions;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TestData;
use Tests\TestCase;

class PasswordRecoverySecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'app.url' => 'https://uat-admin.primehealers.com',
            'mail.default' => 'smtp',
            'mail.from.address' => 'support@primehealers.com',
            'mail.mailers.smtp.host' => 'smtp.zoho.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'support@primehealers.com',
            'mail.mailers.smtp.password' => 'synthetic-only-not-a-credential',
            'mail.mailers.smtp.url' => null,
        ]);
    }

    public function test_unconfigured_email_never_claims_to_send_or_creates_a_reset_token(): void
    {
        Notification::fake();
        $user = TestData::user();
        config(['mail.default' => 'log']);
        $this->get('/forgot-password')->assertOk()->assertSee(PasswordRecovery::UNAVAILABLE)
            ->assertSee('disabled', false)->assertSee('Back to sign in');
        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect('/forgot-password')->assertSessionHasErrors(['email' => PasswordRecovery::UNAVAILABLE]);
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_a_logging_fallback_or_placeholder_sender_is_not_deliverable(): void
    {
        $recovery = app(PasswordRecovery::class);
        $this->assertTrue($recovery->available());
        config(['mail.default' => 'failover']);
        $this->assertFalse($recovery->available());
        config(['mail.default' => 'smtp', 'mail.from.address' => 'hello@example.com']);
        $this->assertFalse($recovery->available());
    }

    public function test_known_unknown_and_disabled_accounts_get_the_same_public_response(): void
    {
        Notification::fake();
        $org = TestData::organization();
        $active = TestData::user($org);
        $inactive = TestData::user($org, ['is_active' => false]);
        foreach ([$active->email, 'not-an-account@primehealers.test', $inactive->email] as $email) {
            $this->from('/forgot-password')->post('/forgot-password', ['email' => $email])
                ->assertRedirect('/forgot-password')->assertSessionHasNoErrors()
                ->assertSessionHas('status', PasswordRecovery::RECEIVED);
        }
        Notification::assertSentTo($active, ResetPassword::class);
        Notification::assertNotSentTo($inactive, ResetPassword::class);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_recovery_url_uses_configured_origin_even_with_a_hostile_host_header(): void
    {
        Notification::fake();
        $user = TestData::user(null, ['email' => 'Team.Member@primehealers.test']);
        $this->withServerVariables(['HTTP_HOST' => 'attacker.invalid'])->post('/forgot-password', ['email' => strtoupper($user->email)])
            ->assertSessionHasNoErrors();
        Notification::assertSentTo($user, ResetPassword::class, function ($notice) use ($user) {
            $url = $notice->toMail($user)->actionUrl;
            $this->assertStringStartsWith('https://uat-admin.primehealers.com/reset-password/', $url);
            $this->assertStringNotContainsString('attacker.invalid', $url);
            $this->assertStringContainsString('email=Team.Member%40primehealers.test', $url);
            return true;
        });
    }

    public function test_mail_is_deferred_until_after_the_public_http_response(): void
    {
        Notification::fake();
        $user = TestData::user();
        $request = \Illuminate\Http\Request::create('https://uat-admin.primehealers.com/forgot-password', 'POST', ['email' => $user->email]);
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        $this->assertSame(302, $response->getStatusCode());
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $kernel->terminate($request, $response);
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_mail_delivery_exception_has_generic_response_and_no_token_or_secret_log(): void
    {
        $user = TestData::user();
        Log::spy();
        Password::shouldReceive('sendResetLink')->once()->andThrow(new \RuntimeException('secret transport detail'));
        Password::shouldReceive('deleteToken')->once()->withArgs(fn ($candidate) => $candidate->is($user))->andReturnNull();
        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors()->assertSessionHas('status', PasswordRecovery::RECEIVED);
        Log::shouldHaveReceived('error')->once()->with('Password recovery delivery failed.', ['exception_type' => \RuntimeException::class]);
    }

    public function test_recovery_endpoint_limits_unknown_accounts_too_and_validates_array_input(): void
    {
        Notification::fake();
        $this->post('/forgot-password', ['email' => ['invalid']])->assertSessionHasErrors('email');
        for ($i = 0; $i < 2; $i++) {
            $this->post('/forgot-password', ['email' => 'unknown@primehealers.test'])->assertRedirect();
        }
        $this->post('/forgot-password', ['email' => 'unknown@primehealers.test'])->assertStatus(429);
        Notification::assertNothingSent();
    }

    public function test_valid_reset_is_single_use_rotates_remember_token_and_revokes_old_database_sessions(): void
    {
        $user = TestData::user();
        $other = TestData::user($user->organization);
        $remember = $user->remember_token;
        $token = Password::createToken($user);
        foreach (['legacy-a' => $user, 'legacy-b' => $user, 'other' => $other] as $id => $owner) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $owner->id, 'payload' => '', 'last_activity' => time()]);
        }
        config(['session.driver' => 'database']);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'a unique recovery passphrase', 'password_confirmation' => 'a unique recovery passphrase'];
        $this->post('/reset-password', $payload)->assertSessionHasNoErrors()->assertRedirect(route('login'));
        $this->assertTrue(Hash::check($payload['password'], $user->fresh()->password));
        $this->assertNotSame($remember, $user->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'legacy-a']);
        $this->assertDatabaseMissing('sessions', ['id' => 'legacy-b']);
        $this->assertDatabaseHas('sessions', ['id' => 'other']);
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_expired_token_and_disabled_role_cannot_reset_a_password(): void
    {
        $user = TestData::user();
        $hash = $user->password;
        $token = Password::createToken($user);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'a unique recovery passphrase', 'password_confirmation' => 'a unique recovery passphrase'];
        $this->travel(61)->minutes();
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
        $this->travelBack();
        $role = Role::create(['organization_id' => $user->organization_id, 'name' => 'Disabled', 'slug' => 'disabled', 'is_active' => false, 'permissions' => []]);
        $user->update(['role_id' => $role->id]);
        $payload['token'] = Password::createToken($user);
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_failed_session_revocation_rolls_back_the_password_change(): void
    {
        $user = TestData::user();
        $oldHash = $user->password;
        $oldRemember = $user->remember_token;
        $token = Password::createToken($user);
        $revoker = \Mockery::mock(RevokeAccountSessions::class);
        $revoker->shouldReceive('forUser')->once()->andThrow(new \RuntimeException('Synthetic revocation failure'));
        $this->app->instance(RevokeAccountSessions::class, $revoker);
        $this->withoutExceptionHandling();
        try {
            $this->post('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'new recovery passphrase', 'password_confirmation' => 'new recovery passphrase']);
            $this->fail('The synthetic session failure must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic revocation failure', $exception->getMessage());
        }
        $this->assertSame($oldHash, $user->fresh()->password);
        $this->assertSame($oldRemember, $user->fresh()->remember_token);
        $this->assertTrue(Password::tokenExists($user->fresh(), $token));
    }

    public function test_existing_inactive_sessions_and_invalid_roles_are_rejected(): void
    {
        $user = TestData::user(null, ['is_active' => false]);
        $this->actingAs($user)->get('/profile')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->actingAs($user)->getJson('/profile')->assertForbidden();
        $this->assertGuest();
        $user->update(['is_active' => true, 'role_id' => Role::create(['organization_id' => $user->organization_id, 'name' => 'Disabled', 'slug' => 'disabled', 'is_active' => false, 'permissions' => []])->id]);
        $this->actingAs($user->fresh())->get('/profile')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_established_session_with_an_old_password_hash_is_logged_out(): void
    {
        $user = TestData::user();
        $oldHash = $user->password;
        $user->update(['password' => 'new recovery phrase']);
        $this->actingAs($user->fresh())->withSession(['password_hash_web' => $oldHash])->get('/profile')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_legacy_non_database_session_without_hash_needs_fresh_login(): void
    {
        $user = TestData::user();
        $key = \Illuminate\Support\Facades\Auth::guard('web')->getName();
        $this->actingAs($user)->withSession([$key => $user->id])->get('/profile')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertNotEmpty(session('password_hash_web'));
        $this->get('/profile')->assertOk();
    }

    public function test_recovery_pages_disable_caching_and_referrers_and_keep_delivery_camera_permissions(): void
    {
        $response = $this->get('https://uat-admin.primehealers.com/forgot-password')->assertOk();
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("object-src 'none'", $response->headers->get('Content-Security-Policy'));
        $response->assertHeader('Permissions-Policy', 'camera=(self), geolocation=(self), microphone=()');
        $response->assertHeader('Strict-Transport-Security', 'max-age=15552000');
    }

    public function test_smtp_transports_require_encrypted_connections_without_sending_mail(): void
    {
        foreach ([['smtp', 587], ['smtps', 465]] as [$scheme, $port]) {
            config(['mail.mailers.smtp.scheme' => $scheme, 'mail.mailers.smtp.port' => $port]);
            Mail::purge('smtp');
            $transport = Mail::mailer('smtp')->getSymfonyTransport();
            $this->assertTrue($transport->isTlsRequired());
            if ($scheme === 'smtps') {
                $this->assertTrue($transport->getStream()->isTls());
            }
        }
    }

    public function test_configured_host_policy_rejects_other_domains_and_subdomains(): void
    {
        $this->get('/login')->assertOk();
        $hosts = (new \Illuminate\Http\Middleware\TrustHosts($this->app))->hosts();
        $this->assertCount(1, $hosts);
        \Illuminate\Http\Request::setTrustedHosts($hosts);
        try {
            $request = \Illuminate\Http\Request::create('https://uat-admin.primehealers.com/login');
            $this->assertSame('uat-admin.primehealers.com', $request->getHost());
            foreach (['attacker.invalid', 'attacker.uat-admin.primehealers.com'] as $host) {
                try {
                    \Illuminate\Http\Request::create('https://'.$host.'/login')->getHost();
                    $this->fail('Untrusted host accepted.');
                } catch (\Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            \Illuminate\Http\Request::setTrustedHosts([]);
        }
    }
}
