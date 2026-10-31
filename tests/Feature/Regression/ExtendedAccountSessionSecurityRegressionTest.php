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

class ExtendedAccountSessionSecurityRegressionTest extends TestCase
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
