<?php

namespace Tests\Feature\Regression;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class DashboardWidgetSettingsRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_settings_page_loads_for_settings_admin(): void
    {
        $organization = TestData::organization();
        $adminRole = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Settings Admin',
            'slug' => 'settings_admin',
            'permissions' => Role::normalizePermissions([
                'settings' => ['read', 'update'],
                'roles' => ['read'],
            ]),
        ]);

        $deliveryRole = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Delivery Team',
            'slug' => User::ROLE_DELIVERY,
            'permissions' => Role::normalizePermissions([
                'deliveries' => ['read', 'update'],
                'rentals' => ['read'],
            ]),
        ]);

        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'role_id' => $adminRole->id,
            'role' => 'settings_admin',
            'is_internal' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('organization.dashboard-settings.edit', ['role_id' => $deliveryRole->id]));

        $response->assertOk()
            ->assertSeeText('Dashboard Settings')
            ->assertSeeText('Delivery Team')
            ->assertSeeText('Active Rentals')
            ->assertSeeText('Today\'s Deliveries');
    }

    public function test_dashboard_settings_save_can_disable_delivery_widgets(): void
    {
        $organization = TestData::organization();
        $adminRole = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Settings Admin',
            'slug' => 'settings_admin',
            'permissions' => Role::normalizePermissions([
                'settings' => ['read', 'update'],
                'roles' => ['read'],
            ]),
        ]);

        $deliveryRole = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Delivery Team',
            'slug' => User::ROLE_DELIVERY,
            'permissions' => Role::normalizePermissions([
                'deliveries' => ['read', 'update'],
                'rentals' => ['read'],
            ]),
        ]);

        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'role_id' => $adminRole->id,
            'role' => 'settings_admin',
            'is_internal' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $deliveryUser = User::factory()->create([
            'organization_id' => $organization->id,
            'role_id' => $deliveryRole->id,
            'role' => User::ROLE_DELIVERY,
            'is_internal' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($admin)->put(route('organization.dashboard-settings.update'), [
            'role_id' => $deliveryRole->id,
            'widgets' => [
                'widget_today_deliveries' => ['is_enabled' => '0', 'sort_order' => 230],
                'widget_today_pickups' => ['is_enabled' => '1', 'sort_order' => 220],
                'primary_pending_deliveries' => ['is_enabled' => '1', 'sort_order' => 10],
                'primary_pending_pickups' => ['is_enabled' => '1', 'sort_order' => 40],
                'kpi_deliveries_today' => ['is_enabled' => '1', 'sort_order' => 70],
            ],
        ]);

        $response->assertRedirect(route('organization.dashboard-settings.edit', ['role_id' => $deliveryRole->id]));
        $response->assertSessionHas('success', 'Dashboard widget settings updated successfully.');

        $dashboard = $this->actingAs($deliveryUser)->get(route('dashboard'));

        $dashboard->assertOk()
            ->assertDontSeeText('Today\'s Deliveries')
            ->assertSeeText('My Pending Pickups');
    }
}
