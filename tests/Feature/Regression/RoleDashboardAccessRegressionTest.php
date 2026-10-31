<?php

namespace Tests\Feature\Regression;

use App\Models\Role;
use App\Models\User;
use App\Services\DashboardWidgetRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class RoleDashboardAccessRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_owner_can_block_dashboard_without_removing_work_permissions_or_creating_a_redirect_loop(): void
    {
        $organization = TestData::organization();
        $owner = TestData::user($organization);
        $role = $this->role($organization->id, User::ROLE_SALES, ['customers' => ['read'], '__special' => []]);
        $worker = TestData::user($organization, ['role_id' => $role->id, 'role' => User::ROLE_SUPER_ADMIN]);
        $this->assertTrue($worker->canAccessDashboard());
        $this->actingAs($owner)->put(route('roles.update', $role), [
            'name' => $role->name, 'slug' => $role->slug, 'is_active' => true,
            'permissions' => $role->permissions, 'dashboard_access' => 'deny',
        ])->assertRedirect();
        $worker = $worker->fresh();
        $this->assertFalse($worker->canAccessDashboard());
        $this->assertTrue($worker->canAccessModule('customers', 'read'));
        $this->assertSame('customers.index', $worker->defaultRedirectRoute());
        $this->assertSame([], app(DashboardWidgetRegistryService::class)->widgetKeysForUser($worker));
        $this->actingAs($worker)->getJson(route('dashboard'))->assertForbidden();
        $this->get('/')->assertRedirect(route('customers.index'));
        $this->get(route('customers.index'))->assertOk();
    }

    public function test_explicit_allow_changes_landing_page_without_granting_finance_or_team_permissions(): void
    {
        $organization = TestData::organization();
        $role = $this->role($organization->id, User::ROLE_DELIVERY, ['deliveries' => ['read'], '__special' => [], '__dashboard' => 'allow']);
        $worker = TestData::user($organization, ['role_id' => $role->id, 'role' => 'super_admin']);
        $this->assertSame('dashboard', $worker->defaultRedirectRoute());
        $this->assertTrue($worker->canAccessDashboard());
        $this->assertFalse($worker->canViewFinanceDashboard());
        $this->assertFalse($worker->canViewManagementAnalytics());
        $this->assertFalse($worker->canAccessModule('users', 'update'));
        $this->assertSame('assigned', $worker->scopeType('deliveries'));
        $response = $this->actingAs($worker)->get(route('dashboard', ['dashboard_tab' => 'revenue']))->assertOk();
        $response->assertDontSee('data-dashboard-tab="revenue"', false)
            ->assertDontSee('data-dashboard-tab="team"', false);
    }

    public function test_automatic_access_preserves_existing_profiles_and_disabled_roles_override_explicit_allow(): void
    {
        $organization = TestData::organization();
        $role = $this->role($organization->id, 'user_directory', ['users' => ['read'], '__special' => []]);
        $user = TestData::user($organization, ['role_id' => $role->id, 'role' => 'super_admin']);
        $this->assertFalse($user->canAccessDashboard());
        $this->assertSame('users.index', $user->defaultRedirectRoute());
        $role->update(['permissions' => array_merge($role->permissions, ['__dashboard' => 'allow']), 'is_active' => false]);
        $this->assertFalse($user->fresh()->canAccessDashboard());
        $this->assertFalse($user->fresh()->hasActiveAccess());
    }

    public function test_dashboard_denied_delegate_cannot_grant_dashboard_access(): void
    {
        $organization = TestData::organization();
        $role = $this->role($organization->id, 'restricted_role_manager', ['roles' => ['read', 'create', 'update'], '__special' => [], '__dashboard' => 'deny']);
        $manager = TestData::user($organization, ['role_id' => $role->id, 'role' => $role->slug]);
        $this->actingAs($manager)->putJson(route('roles.update', $role), [
            'name' => $role->name, 'slug' => $role->slug, 'is_active' => true,
            'permissions' => ['roles' => ['read', 'create', 'update'], '__special' => []], 'dashboard_access' => 'allow',
        ])->assertForbidden();
        $this->assertSame('deny', $role->fresh()->permissions['__dashboard']);
    }

    public function test_role_and_user_details_show_effective_access_and_edit_form_preserves_dashboard_selection(): void
    {
        $organization = TestData::organization();
        $owner = TestData::user($organization);
        $role = $this->role($organization->id, User::ROLE_DELIVERY, ['deliveries' => ['read'], '__special' => [], '__dashboard' => 'deny']);
        $worker = TestData::user($organization, ['role_id' => $role->id, 'role' => $role->slug]);
        $this->actingAs($owner)->get(route('roles.show', $role))->assertOk()->assertSeeText('Effective access')->assertSeeText('Assigned work')->assertSeeText('Blocked');
        $this->get(route('users.show', $worker))->assertOk()->assertSeeText('Effective access')->assertSeeText('Team performance');
        $this->get(route('roles.edit', $role))->assertOk()->assertSee('value="deny" selected', false);
        $this->putJson(route('roles.update', $role), ['name' => $role->name, 'dashboard_access' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('dashboard_access');
    }

    private function role(int $organizationId, string $slug, array $permissions): Role
    {
        return Role::create(['organization_id' => $organizationId, 'slug' => $slug, 'name' => $slug,
            'permissions' => $permissions, 'is_active' => true, 'is_system' => false]);
    }

    public function test_editing_legacy_staff_role_preserves_its_existing_dashboard_only_grant(): void
    {
        $organization = TestData::organization();
        $owner = TestData::user($organization);
        $role = $this->role($organization->id, 'staff', ['dashboard' => ['read']]);
        $worker = TestData::user($organization, ['role_id' => $role->id, 'role' => 'staff']);
        $this->actingAs($owner)->put(route('roles.update', $role), [
            'name' => $role->name, 'slug' => $role->slug, 'is_active' => true,
            'permissions' => ['__special' => []], 'dashboard_access' => 'auto',
        ])->assertRedirect();
        $this->assertTrue($worker->fresh()->canAccessDashboard());
        $this->assertSame('dashboard', $worker->fresh()->defaultRedirectRoute());
    }

    public function test_disabled_role_blocks_login_and_an_existing_authenticated_session(): void
    {
        $organization = TestData::organization();
        $role = $this->role($organization->id, User::ROLE_DELIVERY, ['deliveries' => ['read']]);
        $worker = TestData::user($organization, ['role_id' => $role->id, 'role' => $role->slug]);
        $role->update(['is_active' => false]);
        $this->post('/login', ['email' => $worker->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($worker->fresh())->getJson('/profile')->assertForbidden();
        $this->assertGuest();
    }
}
