<?php

namespace Tests\Feature\Regression;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class RolesAccessControlCenterRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_index_renders_access_control_center_surface(): void
    {
        $organization = TestData::organization();

        $adminRole = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Roles Admin',
            'slug' => 'roles_admin',
            'description' => 'Manages roles.',
            'permissions' => Role::normalizePermissions([
                'roles' => ['read', 'create', 'update', 'delete'],
                'users' => ['read'],
            ]),
            'is_active' => true,
        ]);

        $deliveryRole = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Delivery Team',
            'slug' => User::ROLE_DELIVERY,
            'description' => 'Handles deliveries and pickups.',
            'permissions' => Role::normalizePermissions([
                'dashboard' => ['read'],
                'deliveries' => ['read', 'update'],
                'rentals' => ['read'],
                'assets' => ['read'],
            ]),
            'is_active' => true,
            'is_system' => false,
        ]);

        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'role_id' => $adminRole->id,
            'role' => 'roles_admin',
            'is_internal' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        User::factory()->create([
            'organization_id' => $organization->id,
            'role_id' => $deliveryRole->id,
            'role' => User::ROLE_DELIVERY,
            'name' => 'Ravi Kumar',
            'is_internal' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('roles.index'));

        $response->assertOk()
            ->assertSeeText('Manage reusable access templates for users, operations, reports, and settings.')
            ->assertSeeText('Total Roles')
            ->assertSeeText('System Roles')
            ->assertSeeText('Custom Roles')
            ->assertSeeText('Total Assigned Users')
            ->assertSeeText('Delivery Team')
            ->assertSeeText('Handles deliveries and pickups.')
            ->assertSeeText('Modules')
            ->assertSeeText('Permissions')
            ->assertSeeText('Ravi Kumar')
            ->assertSee('data-role-card', false)
            ->assertSee('data-role-drawer', false)
            ->assertSee('data-role-filter="system"', false)
            ->assertDontSeeText('Remove assigned users before deleting this role.');
    }
}
