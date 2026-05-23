<?php

namespace Tests\Feature\Regression;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class MobileMenuVisibilityRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_navigation_hides_unauthorized_modules_instead_of_rendering_disabled_links(): void
    {
        $organization = TestData::organization();
        $role = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Customers Only',
            'slug' => 'customers-only',
            'permissions' => Role::normalizePermissions([
                'customers' => ['read'],
            ]),
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => 'staff',
            'role_id' => $role->id,
            'is_internal' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('Customers')
            ->assertDontSee('Rentals')
            ->assertDontSee('Sales')
            ->assertDontSee('Product Master')
            ->assertDontSee('Asset Register')
            ->assertSee('mobile-notification-menu', false)
            ->assertSee('mobile-notification-scroll', false)
            ->assertSee('mobile-notification-footer', false)
            ->assertSee('data-notification-sound-toggle', false)
            ->assertDontSee('mobile-nav-item is-disabled', false)
            ->assertDontSee('mobile-more-link is-disabled', false);
    }

    public function test_delivery_user_mobile_navigation_prioritizes_field_ops_links(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('Tasks')
            ->assertSee('Notifications')
            ->assertDontSee('Pickups')
            ->assertDontSee('Customers')
            ->assertDontSee('Rentals')
            ->assertDontSee('Sales');
    }
}
