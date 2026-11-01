<?php

namespace Tests\Feature\Regression;

use App\Models\{Role, User};
use App\Support\LocalRedirect;
use App\Services\NotificationCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Support\Str;
use Tests\Support\TestData;
use Tests\TestCase;

class ApplicationSecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirects_reject_external_and_ambiguous_urls_and_preserve_local_filters(): void
    {
        config(['app.url' => 'https://uat-admin.primehealers.com']);
        foreach (['https://evil.invalid/login', '//evil.invalid', '/%2fevil.invalid', '/\\evil.invalid', '/%5cevil.invalid', " /rentals", "/rentals\r\nLocation:evil", '/%0aevil', 'javascript:alert(1)', 'data:text/html,test', 'https://uat-admin.primehealers.com@evil.invalid', 'http://uat-admin.primehealers.com/rentals', 'https://uat-admin.primehealers.com:444/rentals', ['x']] as $url) {
            $this->assertSame('/notifications', LocalRedirect::path($url, '/notifications'));
        }
        $this->assertSame('/rentals?status=active#history', LocalRedirect::path('/rentals?status=active#history'));
        $this->assertSame('/rentals?status=active#history', LocalRedirect::path('https://uat-admin.primehealers.com/rentals?status=active#history'));
    }

    public function test_notification_actions_cannot_redirect_to_a_phishing_site(): void
    {
        $user = TestData::user();
        $this->actingAs($user);
        $notification = DatabaseNotification::create(['id' => (string) Str::uuid(), 'type' => 'SyntheticSecurityNotice', 'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'data' => ['title' => 'Test', 'action_url' => 'https://evil.invalid/login']]);
        $this->post(route('notifications.read', $notification->id), ['redirect_to' => '//evil.invalid'])->assertRedirect(route('notifications.index'));
        $this->post(route('notifications.read-all'), ['redirect_to' => 'https://evil.invalid/login'])->assertRedirect(route('notifications.index'));
        $transformed = app(NotificationCenterService::class)->transform($notification);
        $this->assertSame(route('notifications.index'), $transformed['action_url']);
        $other = TestData::user($user->organization);
        $this->actingAs($other)->post(route('notifications.read', $notification->id))->assertNotFound();
    }

    public function test_profile_delete_cannot_remove_the_last_super_admin(): void
    {
        $owner = TestData::user();
        $this->actingAs($owner)->delete(route('profile.destroy'), ['password' => 'password'])->assertForbidden();
        $this->assertNotNull($owner->fresh());
        $this->assertAuthenticatedAs($owner);
        TestData::user($owner->organization);
        $this->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');
        $this->assertNull($owner->fresh());
    }

    public function test_password_change_rotates_remember_token_and_revokes_only_this_users_database_sessions(): void
    {
        $user = TestData::user(null, ['remember_token' => 'old-remember-token']);
        $other = TestData::user($user->organization);
        foreach (['old-device-one' => $user->id, 'old-device-two' => $user->id, 'other-user-device' => $other->id] as $id => $userId) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => base64_encode(serialize([])), 'last_activity' => time()]);
        }
        config(['session.driver' => 'database']);
        $this->actingAs($user)->from('/profile')->put(route('password.update'), ['current_password' => 'password', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertSessionHasNoErrors()->assertRedirect('/profile');
        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
        $this->assertNotSame('old-remember-token', $user->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-device-one']);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-device-two']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-user-device']);
        $this->get(route('profile.edit'))->assertOk();
    }

    public function test_password_confirmation_and_login_have_independent_abuse_limits(): void
    {
        foreach (range(1, 30) as $attempt) {
            $this->post('/login', ['email' => 'missing-'.$attempt.'@example.test', 'password' => 'invalid'])->assertStatus(302);
        }
        $this->post('/login', ['email' => 'another@example.test', 'password' => 'invalid'])->assertStatus(429);
        $this->actingAs(TestData::user());
        foreach (range(1, 5) as $attempt) $this->post('/confirm-password', ['password' => 'invalid'])->assertStatus(302);
        $this->post('/confirm-password', ['password' => 'invalid'])->assertStatus(429);
    }

    public function test_weak_settings_editor_cannot_change_a_super_admin_dashboard(): void
    {
        $organization = TestData::organization();
        $ownerRole = Role::create(['organization_id' => $organization->id, 'name' => 'Owner', 'slug' => 'super_admin', 'is_active' => true, 'permissions' => []]);
        $weakRole = Role::create(['organization_id' => $organization->id, 'name' => 'Settings editor', 'slug' => 'limited-settings', 'is_active' => true, 'permissions' => ['settings' => ['read','update']]]);
        $editor = TestData::user($organization, ['role_id' => $weakRole->id, 'role' => 'staff']);
        $this->actingAs($editor)->put(route('organization.dashboard-settings.update'), ['role_id' => $ownerRole->id, 'widgets' => []])->assertForbidden();
        $this->assertSame(0, DB::table('role_dashboard_widgets')->where('role_id', $ownerRole->id)->count());
        $this->actingAs(TestData::user($organization))->put(route('organization.dashboard-settings.update'), ['role_id' => $ownerRole->id, 'widgets' => []])->assertRedirect();
    }

    public function test_sensitive_pages_have_private_cache_and_compatible_browser_protections(): void
    {
        $response = $this->get('https://localhost/login')->assertOk();
        $response->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Strict-Transport-Security', 'max-age=15552000')
            ->assertHeader('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'")
            ->assertHeader('Permissions-Policy', 'camera=(self), geolocation=(self), microphone=()');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->actingAs(TestData::user());
        $response = $this->get(route('customers.index'))->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    public function test_unknown_hosts_are_rejected_in_the_uat_environment(): void
    {
        config(['app.url' => 'https://uat-admin.primehealers.com']);
        $this->app->instance('env', 'uat');
        try {
            $this->get('https://evil.invalid/login')->assertStatus(400);
            $this->get('https://uat-admin.primehealers.com/login')->assertOk();
        } finally {
            \Illuminate\Http\Request::setTrustedHosts([]);
            \Illuminate\Http\Middleware\TrustHosts::flushState();
            $this->app->instance('env', 'testing');
        }
    }
}
