<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\DashboardVisibilityService;
use App\Services\DashboardWidgetRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestData;
use Tests\TestCase;

class AccessAuthorityRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_accounts_and_invalid_assigned_roles_never_keep_legacy_owner_authority(): void
    {
        $organization = TestData::organization();
        $other = TestData::organization(['name' => 'Other authority organization']);
        $disabledOwner = TestData::user($organization, ['is_active' => false]);
        $disabledRole = $this->role($organization->id, User::ROLE_SUPER_ADMIN, [], false);
        $foreignRole = $this->role($other->id, User::ROLE_SUPER_ADMIN, []);
        $disabledAssigned = TestData::user($organization, ['role' => User::ROLE_SUPER_ADMIN, 'role_id' => $disabledRole->id]);
        $foreignAssigned = TestData::user($organization, ['role' => User::ROLE_SUPER_ADMIN, 'role_id' => $foreignRole->id]);
        $missingAssigned = TestData::user($organization);
        $missingAssigned->role_id = 999999;
        $missingAssigned->unsetRelation('assignedRole');

        foreach ([$disabledOwner, $disabledAssigned, $foreignAssigned, $missingAssigned] as $user) {
            $this->assertFalse($user->hasActiveAccess());
            $this->assertFalse($user->isSuperAdmin());
            $this->assertFalse($user->canAccessModule('users', 'update'));
            $this->assertFalse($user->hasModulePermission('rentals', 'read'));
            $this->assertFalse($user->hasPermission('finance.view_profit'));
            $this->assertFalse($user->canViewRecordFinance());
            $this->assertFalse($user->canViewManagementAnalytics());
            $this->assertFalse($user->canAccessDashboard());
            $this->assertSame([], app(DashboardWidgetRegistryService::class)->widgetKeysForUser($user));
            $visibility = app(DashboardVisibilityService::class)->forUser($user);
            $this->assertSame([], $visibility['widget_keys']);
            $this->assertFalse($visibility['finance_widgets']);
            $this->assertFalse($visibility['organization_analytics']);
        }
        $this->assertSame('staff', $foreignAssigned->effective_role);
        $this->assertSame('staff', $missingAssigned->effective_role);
        $this->assertSame(User::ROLE_SUPER_ADMIN, $disabledAssigned->effective_role, 'The admin UI should still identify a disabled role accurately.');
    }

    public function test_assigned_role_denies_override_stale_legacy_role_and_dashboard_rejects_before_business_queries(): void
    {
        $organization = TestData::organization();
        $role = $this->role($organization->id, User::ROLE_ADMIN_OPERATIONS, ['users' => ['read'], '__special' => []]);
        $reader = TestData::user($organization, ['role' => User::ROLE_SUPER_ADMIN, 'role_id' => $role->id]);
        $this->assertSame(User::ROLE_ADMIN_OPERATIONS, $reader->effective_role);
        $this->assertFalse($reader->isSuperAdmin());
        $this->assertFalse($reader->canAccessModule('rentals', 'read'));
        $this->assertFalse($reader->canAccessDashboard());
        $this->assertSame('users.index', $reader->defaultRedirectRoute());
        $this->assertSame([], app(DashboardWidgetRegistryService::class)->widgetKeysForUser($reader));
        $this->actingAs($reader);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson(route('dashboard'))->assertForbidden();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], array_values(array_filter($queries, fn (array $query) => preg_match('/\bfrom\s+["`\[]?(rentals|sales|invoices|payments|customers|products)\b/i', $query['query']))));
        $this->get(route('users.index'))->assertOk();
    }

    public function test_explicit_empty_sensitive_permissions_are_authoritative_without_rewriting_legacy_defaults(): void
    {
        $organization = TestData::organization();
        $legacyRole = $this->role($organization->id, User::ROLE_FINANCE, ['invoices' => ['read'], 'payments' => ['read']]);
        $legacy = TestData::user($organization, ['role' => User::ROLE_FINANCE, 'role_id' => $legacyRole->id]);
        $this->assertTrue($legacy->canViewFinanceDashboard());
        $this->assertTrue($legacy->hasPermission('invoices.export'));
        $this->assertArrayNotHasKey('__special', $legacyRole->fresh()->permissions);

        $permissions = Role::normalizePermissions(['invoices' => ['read'], 'payments' => ['read'], '__special' => []]);
        $this->assertSame([], $permissions['__special']);
        $legacyRole->permissions = $permissions;
        $legacyRole->save();
        $legacy->unsetRelation('assignedRole');
        $this->assertTrue($legacy->canAccessModule('invoices', 'read'));
        $this->assertTrue($legacy->canAccessModule('payments', 'read'));
        $this->assertFalse($legacy->hasPermission('invoices.export'));
        $this->assertFalse($legacy->hasPermission('finance.view_profit'));
        $this->assertFalse($legacy->canViewFinanceDashboard());
        $keys = app(DashboardWidgetRegistryService::class)->widgetKeysForUser($legacy);
        $this->assertNotContains('section_finance_summary', $keys);
        $this->assertNotContains('primary_outstanding_dues', $keys);
        $response = $this->actingAs($legacy)->get(route('dashboard'))->assertOk();
        $this->assertFalse(str_contains($response->getContent(), 'data-dashboard-tab="revenue"'), 'Finance-denied profile must not receive the finance tab.');
        $this->assertFalse(str_contains($response->getContent(), 'data-dashboard-tab="team"'));
        $legacyRole->permissions = Role::normalizeExplicitPermissions(['invoices' => ['read'], '__special' => ['invoices.export']]);
        $legacyRole->save();
        $legacy->unsetRelation('assignedRole');
        $this->assertTrue($legacy->hasPermission('invoices.export'));
        $this->assertFalse($legacy->hasPermission('payments.export'));
        $this->assertFalse($legacy->canViewFinanceDashboard());
    }

    public function test_user_manager_cannot_assign_owner_role_and_legacy_role_payload_is_ignored(): void
    {
        $organization = TestData::organization();
        $managerRole = $this->role($organization->id, 'delegated_user_manager', [
            'users' => ['read', 'create', 'update'], '__special' => [],
        ]);
        $ownerRole = $this->role($organization->id, User::ROLE_SUPER_ADMIN, []);
        $manager = TestData::user($organization, ['role' => $managerRole->slug, 'role_id' => $managerRole->id]);
        $this->actingAs($manager);
        $this->get(route('users.create'))->assertOk()->assertViewHas('roles', fn ($roles) => $roles->pluck('id')->all() === [$managerRole->id]);
        $payload = ['name' => $manager->name, 'email' => $manager->email, 'role_id' => $ownerRole->id, 'is_active' => true];
        $this->putJson(route('users.update', $manager), $payload)->assertForbidden();
        $this->assertSame($managerRole->id, $manager->fresh()->role_id);
        $this->postJson(route('users.store'), array_merge($payload, [
            'email' => 'owner-escalation@example.test', 'password' => 'Local test password 123!',
        ]))->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'owner-escalation@example.test']);

        $this->put(route('users.update', $manager), array_merge($payload, ['role_id' => $managerRole->id, 'role' => User::ROLE_SUPER_ADMIN]))->assertRedirect();
        $this->assertSame($managerRole->slug, $manager->fresh()->role);
        $this->assertSame($managerRole->id, $manager->fresh()->role_id);
        $this->assertFalse($manager->fresh()->isSuperAdmin());
    }

    public function test_delegated_role_manager_cannot_add_module_special_or_owner_profile_privileges_to_own_role(): void
    {
        $organization = TestData::organization();
        $permissions = ['roles' => ['read', 'create', 'update', 'delete'], 'users' => ['read'], '__special' => []];
        $role = $this->role($organization->id, 'delegated_access_manager', $permissions);
        $manager = TestData::user($organization, ['role' => $role->slug, 'role_id' => $role->id]);
        $this->actingAs($manager);
        $base = ['name' => $role->name, 'slug' => $role->slug, 'is_active' => true, 'permissions' => $permissions];
        foreach ([
            array_merge($base, ['slug' => User::ROLE_SUPER_ADMIN]),
            array_merge($base, ['slug' => User::ROLE_ADMIN_OPERATIONS]),
            array_merge($base, ['permissions' => array_merge($permissions, ['payments' => ['read', 'create']])]),
            array_merge($base, ['permissions' => array_merge($permissions, ['__special' => ['finance.view_profit', 'customers.export']])]),
        ] as $attempt) {
            $this->putJson(route('roles.update', $role), $attempt)->assertForbidden();
            $this->assertSame($base['slug'], $role->fresh()->slug);
            $this->assertSame($permissions, $role->fresh()->permissions);
        }
        $this->postJson(route('roles.store'), ['name' => 'Owner impersonation', 'slug' => User::ROLE_SUPER_ADMIN, 'is_active' => true])->assertForbidden();
        $this->assertDatabaseMissing('roles', ['slug' => User::ROLE_SUPER_ADMIN]);
    }

    public function test_authorized_delegation_preserves_existing_custom_access_and_owner_can_manage_roles(): void
    {
        $organization = TestData::organization();
        $role = $this->role($organization->id, 'customer_access_manager', [
            'roles' => ['read', 'create', 'update'], 'users' => ['read', 'create', 'update'],
            'customers' => ['read'], '__special' => ['customers.export'],
        ]);
        $manager = TestData::user($organization, ['role' => $role->slug, 'role_id' => $role->id]);
        $this->actingAs($manager)->post(route('roles.store'), [
            'name' => 'Permitted customer reader', 'slug' => 'permitted_customer_reader', 'is_active' => true,
            'permissions' => ['customers' => ['read'], '__special' => ['customers.export']],
        ])->assertRedirect();
        $created = Role::where('slug', 'permitted_customer_reader')->firstOrFail();
        $this->assertSame(['customers' => ['read'], '__special' => ['customers.export'], '__special_explicit' => true], $created->permissions);
        $this->post(route('users.store'), [
            'name' => 'Permitted reader', 'email' => 'permitted-reader@example.test',
            'password' => 'Local test password 123!', 'role_id' => $created->id, 'is_active' => true,
        ])->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'permitted-reader@example.test', 'role_id' => $created->id, 'role' => $created->slug]);

        $owner = TestData::user($organization);
        $this->actingAs($owner)->post(route('roles.store'), [
            'name' => 'Owner approved finance', 'slug' => User::ROLE_FINANCE, 'is_active' => true,
            'permissions' => ['invoices' => ['read'], '__special' => ['finance.view_revenue']],
        ])->assertRedirect();
        $this->assertDatabaseHas('roles', ['slug' => User::ROLE_FINANCE]);
    }

    public function test_delegated_role_editor_cannot_disable_or_strip_a_stronger_existing_role(): void
    {
        $organization = TestData::organization();
        $editorRole = $this->role($organization->id, 'limited_role_editor', ['roles' => ['read', 'update', 'delete'], '__special' => []]);
        $editor = TestData::user($organization, ['role_id' => $editorRole->id, 'role' => $editorRole->slug]);
        $permissions = ['invoices' => ['read', 'create'], 'payments' => ['read'], '__special' => ['finance.view_profit']];
        $stronger = $this->role($organization->id, 'stronger_finance_role', $permissions);
        $assigned = TestData::user($organization, ['role_id' => $stronger->id, 'role' => $stronger->slug]);
        $original = $stronger->fresh()->getRawOriginal();
        $base = ['name' => $stronger->name, 'slug' => $stronger->slug, 'permissions' => $permissions, 'is_active' => true];

        $this->actingAs($editor)->get(route('roles.edit', $stronger))->assertForbidden();
        $this->putJson(route('roles.update', $stronger), array_merge($base, ['is_active' => false]))->assertForbidden();
        $this->putJson(route('roles.update', $stronger), array_merge($base, ['permissions' => ['__special' => []]]))->assertForbidden();
        $this->deleteJson(route('roles.destroy', $stronger))->assertForbidden();
        $this->assertSame($original, $stronger->fresh()->getRawOriginal());
        $this->assertTrue($assigned->fresh()->hasActiveAccess());
        $this->assertTrue($assigned->fresh()->hasPermission('finance.view_profit'));
    }

    public function test_inactive_stronger_role_is_protected_but_super_admin_can_still_delete_it(): void
    {
        $organization = TestData::organization();
        $editorRole = $this->role($organization->id, 'inactive_role_editor', ['roles' => ['read', 'update', 'delete'], '__special' => []]);
        $editor = TestData::user($organization, ['role_id' => $editorRole->id, 'role' => $editorRole->slug]);
        $stronger = $this->role($organization->id, 'inactive_stronger_finance', ['invoices' => ['read'], '__special' => ['finance.view_profit']], false);

        $this->actingAs($editor)->get(route('roles.edit', $stronger))->assertForbidden();
        $this->deleteJson(route('roles.destroy', $stronger))->assertForbidden();
        $this->assertDatabaseHas('roles', ['id' => $stronger->id, 'is_active' => false]);
        $owner = TestData::user($organization);
        $this->actingAs($owner)->delete(route('roles.destroy', $stronger))->assertRedirect(route('roles.index'));
        $this->assertDatabaseMissing('roles', ['id' => $stronger->id]);
    }

    public function test_delegated_role_edits_and_deletion_within_existing_authority_remain_allowed(): void
    {
        $organization = TestData::organization();
        $editorRole = $this->role($organization->id, 'permitted_role_editor', [
            'roles' => ['read', 'update', 'delete'], 'customers' => ['read'], '__special' => [],
        ]);
        $editor = TestData::user($organization, ['role_id' => $editorRole->id, 'role' => $editorRole->slug]);
        $weaker = $this->role($organization->id, 'permitted_customer_role', ['customers' => ['read'], '__special' => []]);
        $this->actingAs($editor)->get(route('roles.edit', $weaker))->assertOk();
        $this->put(route('roles.update', $weaker), ['name' => 'Reviewed customer role', 'slug' => $weaker->slug,
            'permissions' => ['customers' => ['read'], '__special' => []], 'is_active' => false])->assertRedirect(route('roles.show', $weaker));
        $this->assertDatabaseHas('roles', ['id' => $weaker->id, 'name' => 'Reviewed customer role', 'is_active' => false]);
        $this->delete(route('roles.destroy', $weaker))->assertRedirect(route('roles.index'));
        $this->assertDatabaseMissing('roles', ['id' => $weaker->id]);
    }

    public function test_user_manager_cannot_reset_more_privileged_accounts_or_assign_inactive_roles(): void
    {
        $organization = TestData::organization();
        $role = $this->role($organization->id, 'user_editor', ['users' => ['read', 'update'], '__special' => []]);
        $manager = TestData::user($organization, ['role' => $role->slug, 'role_id' => $role->id]);
        $owner = TestData::user($organization);
        $before = $owner->getAuthPassword();
        $this->actingAs($manager)->putJson(route('users.update', $owner), [
            'name' => $owner->name, 'email' => $owner->email, 'password' => 'Unauthorized password change 123!', 'role_id' => $role->id, 'is_active' => true,
        ])->assertForbidden();
        $this->assertSame($before, $owner->fresh()->getAuthPassword());
        $inactive = $this->role($organization->id, 'disabled_reader', ['users' => ['read']], false);
        $this->actingAs($owner)->putJson(route('users.update', $manager), [
            'name' => $manager->name, 'email' => $manager->email, 'role_id' => $inactive->id, 'is_active' => true,
        ])->assertForbidden();
        $this->assertSame($role->id, $manager->fresh()->role_id);
    }

    public function test_role_pages_do_not_expose_users_from_another_organization_with_a_bad_role_link(): void
    {
        $organization = TestData::organization();
        $other = TestData::organization(['name' => 'Foreign role organization']);
        $role = $this->role($organization->id, 'local_role', ['customers' => ['read']]);
        TestData::user($other, ['role_id' => $role->id, 'name' => 'Foreign role-linked sentinel', 'email' => 'foreign-role@example.test']);
        $owner = TestData::user($organization);
        $index = $this->actingAs($owner)->get(route('roles.index'))->assertOk()->assertDontSeeText('Foreign role-linked sentinel')->assertDontSeeText('foreign-role@example.test');
        $this->assertSame(0, $index->viewData('roles')->firstWhere('id', $role->id)->users_count);
        $this->get(route('roles.show', $role))->assertOk()->assertDontSeeText('Foreign role-linked sentinel')->assertDontSeeText('foreign-role@example.test');
    }

    public function test_user_pages_do_not_expose_foreign_role_metadata_from_a_bad_assignment(): void
    {
        $organization = TestData::organization();
        $other = TestData::organization(['name' => 'Foreign metadata organization']);
        $foreignRole = $this->role($other->id, 'foreign_role_metadata_sentinel', ['users' => ['read']]);
        $target = TestData::user($organization, ['role' => 'staff', 'role_id' => $foreignRole->id]);
        $owner = TestData::user($organization);
        $index = $this->actingAs($owner)->get(route('users.index'))->assertOk();
        $this->assertNull($index->viewData('users')->firstWhere('id', $target->id)->assignedRole);
        $this->assertFalse(str_contains($index->getContent(), $foreignRole->name));
        $show = $this->get(route('users.show', $target))->assertOk();
        $this->assertNull($show->viewData('user')->assignedRole);
        $this->assertFalse(str_contains($show->getContent(), $foreignRole->name));
    }

    public function test_role_editor_shows_legacy_sensitive_grants_and_preserves_clear_all_after_validation_error(): void
    {
        $organization = TestData::organization();
        $role = $this->role($organization->id, User::ROLE_FINANCE, ['invoices' => ['read']]);
        $owner = TestData::user($organization);
        $before = $this->actingAs($owner)->get(route('roles.edit', $role))->assertOk();
        $this->assertTrue(str_contains($before->getContent(), 'value="finance.view_profit" data-special-permission checked'));
        $this->from(route('roles.edit', $role))->put(route('roles.update', $role), [
            'name' => '', 'slug' => $role->slug, 'is_active' => true, 'permissions' => ['__special' => ['__none']],
        ])->assertSessionHasErrors('name')->assertSessionHasInput('permissions.__special.0', '__none');
        $after = $this->get(route('roles.edit', $role))->assertOk();
        $document = new \DOMDocument;
        $document->loadHTML($after->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $this->assertSame(0, (new \DOMXPath($document))->query('//input[@data-special-permission and @checked]')->length);
        $this->assertArrayNotHasKey('__special', $role->fresh()->permissions);
    }

    public function test_last_active_owner_cannot_be_disabled_or_demoted_but_another_valid_owner_allows_it(): void
    {
        $organization = TestData::organization();
        $other = TestData::organization(['name' => 'Foreign owner organization']);
        $ownerRole = $this->role($organization->id, User::ROLE_SUPER_ADMIN, []);
        $readerRole = $this->role($organization->id, 'owner_replacement_reader', ['users' => ['read'], '__special' => []]);
        $owner = TestData::user($organization, ['role' => User::ROLE_SUPER_ADMIN, 'role_id' => $ownerRole->id]);
        TestData::user($organization, ['is_active' => false]);
        $foreignRole = $this->role($other->id, User::ROLE_SUPER_ADMIN, []);
        TestData::user($organization, ['role' => User::ROLE_SUPER_ADMIN, 'role_id' => $foreignRole->id]);
        TestData::user($other);
        $payload = ['name' => $owner->name, 'email' => $owner->email, 'role_id' => $ownerRole->id, 'is_active' => true];
        $this->actingAs($owner)->putJson(route('users.update', $owner), array_merge($payload, ['is_active' => false]))->assertForbidden();
        $this->putJson(route('users.update', $owner), array_merge($payload, ['role_id' => $readerRole->id]))->assertForbidden();
        $this->assertTrue($owner->fresh()->isSuperAdmin());

        $remaining = TestData::user($organization);
        $this->put(route('users.update', $owner), array_merge($payload, ['role_id' => $readerRole->id]))->assertRedirect();
        $this->assertSame($readerRole->id, $owner->fresh()->role_id);
        $this->assertTrue($remaining->fresh()->isSuperAdmin());
        $this->assertFalse($owner->fresh()->isSuperAdmin());
    }

    public function test_disabling_or_renaming_shared_owner_role_cannot_remove_every_active_owner(): void
    {
        $organization = TestData::organization();
        $other = TestData::organization(['name' => 'Separate active owner organization']);
        $ownerRole = $this->role($organization->id, User::ROLE_SUPER_ADMIN, []);
        $owner = TestData::user($organization, ['role' => User::ROLE_SUPER_ADMIN, 'role_id' => $ownerRole->id]);
        TestData::user($organization, ['role' => User::ROLE_SUPER_ADMIN, 'role_id' => $ownerRole->id]);
        TestData::user($other);
        $payload = ['name' => $ownerRole->name, 'slug' => User::ROLE_SUPER_ADMIN, 'is_active' => true, 'permissions' => []];
        $this->actingAs($owner)->putJson(route('roles.update', $ownerRole), array_merge($payload, ['is_active' => false]))->assertForbidden();
        $this->putJson(route('roles.update', $ownerRole), array_merge($payload, ['slug' => 'renamed_owner_role']))->assertForbidden();
        $this->deleteJson(route('roles.destroy', $ownerRole))->assertForbidden();
        $this->assertTrue($ownerRole->fresh()->is_active);
        $this->assertSame(User::ROLE_SUPER_ADMIN, $ownerRole->fresh()->slug);

        $legacyOwner = TestData::user($organization);
        $this->put(route('roles.update', $ownerRole), array_merge($payload, ['is_active' => false]))->assertRedirect();
        $this->assertFalse($ownerRole->fresh()->is_active);
        $this->assertTrue($legacyOwner->fresh()->isSuperAdmin());
        $this->assertFalse($owner->fresh()->isSuperAdmin());
    }

    public function test_owner_account_can_be_deleted_when_another_valid_active_owner_remains(): void
    {
        $organization = TestData::organization();
        $ownerRole = $this->role($organization->id, User::ROLE_SUPER_ADMIN, []);
        $target = TestData::user($organization, ['role' => User::ROLE_SUPER_ADMIN, 'role_id' => $ownerRole->id]);
        $remaining = TestData::user($organization);
        $this->actingAs($remaining)->delete(route('users.destroy', $target))->assertRedirect();
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertTrue($remaining->fresh()->isSuperAdmin());
    }

    public function test_admin_password_change_and_deactivation_revoke_only_target_database_sessions_and_remember_token(): void
    {
        config(['session.driver' => 'database']);
        $organization = TestData::organization();
        $role = $this->role($organization->id, 'session_reader', ['users' => ['read'], '__special' => []]);
        $target = TestData::user($organization, ['role' => $role->slug, 'role_id' => $role->id, 'remember_token' => 'old-target-token']);
        $other = TestData::user($organization);
        foreach ([['access-target-session', $target], ['access-other-session', $other]] as [$id, $user]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1',
                'user_agent' => 'Synthetic access regression', 'payload' => base64_encode(serialize([])), 'last_activity' => time()]);
        }
        $payload = ['name' => $target->name, 'email' => $target->email, 'role_id' => $role->id, 'is_active' => true];
        $this->actingAs($other)->put(route('users.update', $target), $payload + ['password' => 'Owner approved password 123!'])->assertRedirect();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertDatabaseHas('sessions', ['id' => 'access-other-session', 'user_id' => $other->id]);
        $this->assertNotSame('old-target-token', $target->fresh()->remember_token);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Owner approved password 123!', $target->fresh()->password));
        $token = $target->fresh()->remember_token;
        DB::table('sessions')->insert(['id' => 'access-target-new-session', 'user_id' => $target->id,
            'payload' => base64_encode(serialize([])), 'last_activity' => time()]);
        $this->put(route('users.update', $target), array_merge($payload, ['is_active' => false]))->assertRedirect();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertNotSame($token, $target->fresh()->remember_token);
        $this->assertDatabaseHas('sessions', ['id' => 'access-other-session', 'user_id' => $other->id]);
    }

    public function test_dashboard_self_created_scope_and_organization_boundary_survive_dynamic_role_resolution(): void
    {
        $organization = TestData::organization();
        $other = TestData::organization(['name' => 'Foreign dashboard organization']);
        $owner = TestData::user($organization);
        $salesRole = $this->role($organization->id, User::ROLE_SALES, ['rentals' => ['read'], 'customers' => ['read'], '__special' => []]);
        $sales = TestData::user($organization, ['role' => User::ROLE_SUPER_ADMIN, 'role_id' => $salesRole->id]);
        $mine = $this->rental($organization->id, $sales->id, 'My scoped customer');
        $this->rental($organization->id, $owner->id, 'Another user customer');
        $this->rental($other->id, TestData::user($other)->id, 'Foreign dashboard sentinel');
        $before = Rental::count();
        $response = $this->actingAs($sales)->get(route('dashboard', ['dashboard_tab' => 'team']))->assertOk();
        $this->assertSame(1, $response->viewData('totalRentals'));
        $this->assertSame([$mine->id], $response->viewData('recentRentals')->pluck('id')->all());
        $this->assertFalse(str_contains($response->getContent(), 'Foreign dashboard sentinel'), 'Foreign tenant data must not appear.');
        $this->assertFalse(str_contains($response->getContent(), 'data-dashboard-tab="team"'), 'Sales profile must not receive the Team tab.');
        $this->assertFalse($response->viewData('dashboardVisibility')['finance_widgets']);
        $ownerResponse = $this->actingAs($owner)->get(route('dashboard'))->assertOk();
        $this->assertSame(2, $ownerResponse->viewData('totalRentals'));
        $this->assertFalse(str_contains($ownerResponse->getContent(), 'Foreign dashboard sentinel'));
        $this->assertSame($before, Rental::count());
    }

    public function test_finance_deny_survives_all_tab_urls_raw_view_data_and_direct_exports(): void
    {
        $organization = TestData::organization();
        $owner = TestData::user($organization);
        $rental = $this->rental($organization->id, $owner->id, 'Finance sentinel customer');
        $rental->update(['rental_amount' => 6843.29, 'deposit_amount' => 999.33, 'transport_amount' => 17.21]);
        $sale = Sale::create(['organization_id' => $organization->id, 'customer_id' => $rental->customer_id,
            'product_id' => $rental->product_id, 'quantity' => 1, 'unit_price' => 2334.17, 'sale_amount' => 2334.17,
            'sale_date' => now()->toDateString(), 'payment_status' => 'pending']);
        $invoice = Invoice::create(['organization_id' => $organization->id, 'customer_id' => $rental->customer_id,
            'rental_id' => $rental->id, 'invoice_number' => 'ACCESS-FINANCE-SENTINEL', 'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDay()->toDateString(), 'bill_to_name' => 'Finance sentinel customer',
            'subtotal' => 10989.68, 'total_amount' => 10989.68, 'paid_amount' => 1852.43,
            'balance_amount' => 9137.25, 'status' => 'issued', 'payment_status' => 'partial']);
        Payment::create(['organization_id' => $organization->id, 'customer_id' => $rental->customer_id,
            'invoice_id' => $invoice->id, 'rental_id' => $rental->id, 'amount' => 1852.43,
            'payment_date' => now()->toDateString(), 'payment_method' => 'cash']);
        $permissions = array_fill_keys(['rentals', 'sales', 'customers', 'products', 'assets', 'warehouses', 'invoices', 'payments', 'deliveries'], ['read']);
        $role = $this->role($organization->id, 'operational_reader_without_finance', $permissions + ['__special' => []]);
        $role->name = 'Super Admin';
        $role->save();
        $reader = TestData::user($organization, ['role' => User::ROLE_SUPER_ADMIN, 'role_id' => $role->id]);
        $this->assertFalse($reader->canViewFinanceDashboard());
        $this->assertFalse($reader->canViewRecordFinance());
        $rentalBefore = $rental->fresh()->getRawOriginal();
        foreach (['overview', 'team', 'operations', 'revenue', 'inventory', 'activity'] as $tab) {
            $response = $this->actingAs($reader)->get(route('dashboard', ['dashboard_tab' => $tab]))->assertOk();
            $this->assertFalse($response->viewData('showTeamPerformanceTable'), 'A display name must not grant owner-only Team access.');
            foreach (['totalRentalValue', 'totalDepositValue', 'totalTransportValue', 'totalOtherValue',
                'totalSalesAmount', 'salesThisMonthAmount', 'outstandingDueAmount', 'pendingReceivableAmount',
                'paymentsReceivedToday', 'paymentsReceivedThisMonth'] as $field) {
                $this->assertEquals(0, $response->viewData($field), $field . ' exposed to finance-denied tab ' . $tab);
            }
            foreach (['6843.29', '6,843.29', '999.33', '9137.25', '9,137.25', '1852.43', '1,852.43', '2334.17', '2,334.17'] as $sentinel) {
                $this->assertFalse(str_contains($response->getContent(), $sentinel), 'Financial sentinel ' . $sentinel . ' leaked on ' . $tab);
            }
            $this->assertEquals(0, $response->viewData('recentRentals')->first()->rental_amount);
            $this->assertEquals(0, $response->viewData('recentSales')->first()->sale_amount);
        }
        $csv = $this->get(route('rentals.export.csv'))->assertOk()->streamedContent();
        $this->assertFalse(str_contains($csv, 'Total Amount'));
        $this->assertFalse(str_contains($csv, '6843.29'));
        $this->getJson(route('invoices.export.csv'))->assertForbidden();
        $this->getJson(route('dashboard.export.csv'))->assertForbidden();
        $this->assertSame($rentalBefore, $rental->fresh()->getRawOriginal());
        $this->assertSame(2334.17, (float) $sale->fresh()->sale_amount);
        $ownerView = $this->actingAs($owner)->get(route('dashboard'))->assertOk();
        $this->assertSame(6843.29, (float) $ownerView->viewData('totalRentalValue'));
        $this->assertSame(1852.43, (float) $ownerView->viewData('paymentsReceivedToday'));
        $ownerCsv = $this->get(route('rentals.export.csv'))->assertOk()->streamedContent();
        $this->assertTrue(str_contains($ownerCsv, 'Total Amount'));
        $this->assertTrue(str_contains($ownerCsv, '999.33'));
    }

    public function test_existing_role_profile_boundaries_and_optional_synthetic_capability_matrix(): void
    {
        $organization = TestData::organization();
        $matrix = ['synthetic' => true, 'legacy_profiles' => [], 'system_templates' => []];
        foreach ([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN_OPERATIONS, User::ROLE_FINANCE,
            User::ROLE_SALES, User::ROLE_SALES_RENEWALS, User::ROLE_DELIVERY, User::ROLE_DELIVERY_EXECUTIVE,
            User::ROLE_VENDOR, User::ROLE_THIRD_PARTY, User::ROLE_SERVICE, User::ROLE_OPERATIONS_EXECUTIVE,
            'admin', 'operations', 'delivery_coordinator', 'staff'] as $slug) {
            $user = new User(['organization_id' => $organization->id, 'role' => $slug, 'is_active' => true]);
            $matrix['legacy_profiles'][$slug] = $this->capabilitySnapshot($user);
        }
        foreach (Role::defaultSystemRoleTemplates() as $slug => $template) {
            $role = $this->role($organization->id, $slug, $template['permissions']);
            $user = TestData::user($organization, ['role' => $slug, 'role_id' => $role->id]);
            $matrix['system_templates'][$slug] = $this->capabilitySnapshot($user);
        }
        $this->assertTrue($matrix['legacy_profiles'][User::ROLE_SUPER_ADMIN]['team_performance']);
        $this->assertTrue($matrix['legacy_profiles'][User::ROLE_FINANCE]['finance_dashboard']);
        $this->assertFalse($matrix['legacy_profiles'][User::ROLE_ADMIN_OPERATIONS]['finance_dashboard']);
        $this->assertTrue($matrix['legacy_profiles'][User::ROLE_ADMIN_OPERATIONS]['management_analytics']);
        $this->assertTrue($matrix['legacy_profiles'][User::ROLE_SALES]['record_finance']);
        $this->assertFalse($matrix['legacy_profiles'][User::ROLE_SALES]['finance_dashboard']);
        $this->assertSame('self_created', $matrix['legacy_profiles'][User::ROLE_SALES]['rental_scope']);
        $this->assertSame('assigned', $matrix['legacy_profiles'][User::ROLE_DELIVERY]['task_scope']);
        $this->assertFalse($matrix['legacy_profiles'][User::ROLE_DELIVERY]['record_finance']);
        $this->assertTrue($matrix['system_templates']['staff']['dashboard']);
        $this->assertSame('dashboard', $matrix['system_templates']['staff']['default_redirect']);
        $this->assertSame('profile.edit', $matrix['legacy_profiles']['staff']['default_redirect']);
        $directory = getenv('PHOS_ROLE_AUDIT_EXPORT_DIR');
        if (is_string($directory) && $directory !== '') {
            if (! is_dir($directory)) {
                $this->assertTrue(mkdir($directory, 0777, true));
            }
            $this->assertNotFalse(file_put_contents($directory . DIRECTORY_SEPARATOR . 'role-capabilities.json', json_encode($matrix, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
        }
    }

    private function capabilitySnapshot(User $user): array
    {
        $modules = [];
        foreach (Role::moduleOptions() as $module => $label) {
            $modules[$module] = array_values(array_filter(Role::actionOptions(), fn ($action) => $user->canAccessModule($module, $action)));
        }

        return [
            'modules' => $modules,
            'special_permissions' => array_values(array_filter(array_keys(Role::specialPermissionOptions()), fn ($permission) => $user->hasPermission($permission))),
            'dashboard' => $user->canAccessDashboard(), 'record_finance' => $user->canViewRecordFinance(),
            'finance_dashboard' => $user->canViewFinanceDashboard(), 'profitability' => $user->canViewProfitability(),
            'management_analytics' => $user->canViewManagementAnalytics(), 'team_performance' => $user->isSuperAdmin(),
            'general_reports' => $user->canAccessGeneralReports(), 'rental_scope' => $user->scopeType('rentals'),
            'task_scope' => $user->scopeType('deliveries'), 'default_redirect' => $user->defaultRedirectRoute(),
        ];
    }

    private function role(int $organizationId, string $slug, array $permissions, bool $active = true): Role
    {
        return Role::create(['organization_id' => $organizationId, 'name' => str_replace('_', ' ', $slug), 'slug' => $slug,
            'permissions' => $permissions, 'is_active' => $active, 'is_system' => false]);
    }

    private function rental(int $organizationId, int $creator, string $name): Rental
    {
        $customer = Customer::create(['organization_id' => $organizationId, 'name' => $name, 'phone' => '9876543210']);
        $product = Product::create(['organization_id' => $organizationId, 'name' => $name . ' equipment',
            'product_type' => Product::TYPE_RENTABLE, 'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 100, 'rental_price' => 100, 'available_quantity' => 2, 'total_quantity' => 2]);
        $rental = Rental::create(['organization_id' => $organizationId, 'customer_id' => $customer->id, 'product_id' => $product->id,
            'customer_name' => $name, 'phone' => $customer->phone, 'created_by_user_id' => $creator, 'quantity' => 1,
            'start_date' => now()->subDay(), 'end_date' => now()->addDays(5), 'status' => 'active', 'rental_amount' => 100,
            'deposit_amount' => 0, 'transport_amount' => 0, 'other_amount' => 0]);
        Delivery::create(['organization_id' => $organizationId, 'rental_id' => $rental->id, 'type' => 'delivery',
            'status' => 'completed', 'scheduled_at' => now()->subDay(), 'completed_at' => now()->subDay()]);

        return $rental;
    }
}
