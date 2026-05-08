<?php

namespace Tests\Feature\Regression;

use App\Providers\AppServiceProvider;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\TestData;
use Tests\TestCase;

class SidebarNotificationCacheRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_counts_are_isolated_by_organization_and_user_permissions(): void
    {
        Cache::flush();

        $organizationA = TestData::organization(['name' => 'Org A']);
        $organizationB = TestData::organization(['name' => 'Org B']);

        $authorizedUser = TestData::user($organizationA, [
            'email' => 'authorized@example.com',
        ]);

        $restrictedRole = Role::create([
            'organization_id' => $organizationA->id,
            'name' => 'Restricted Staff',
            'slug' => 'restricted_staff',
            'description' => 'Restricted Staff',
            'permissions' => Role::normalizePermissions([]),
            'is_system' => false,
            'is_active' => true,
        ]);

        $restrictedUser = TestData::user($organizationA, [
            'email' => 'restricted@example.com',
            'role' => 'staff',
            'role_id' => $restrictedRole->id,
        ]);

        $otherOrgUser = TestData::user($organizationB, [
            'email' => 'other-org@example.com',
        ]);

        $this->createOverdueDeliveredRental($organizationA->id);

        $this->actingAs($authorizedUser)
            ->get(route('profile.edit'))
            ->assertOk();

        $provider = new AppServiceProvider($this->app);

        $authorizedData = $this->invokeSidebarCounts($provider, $organizationA->id, $authorizedUser);
        $this->assertSame(1, (int) (($authorizedData['sidebarPendingCounts']['rentals'] ?? 0)));
        $this->assertSame(1, (int) ($authorizedData['overdueRentalsCount'] ?? 0));

        $this->actingAs($restrictedUser)
            ->get(route('profile.edit'))
            ->assertOk();

        $restrictedData = $this->invokeSidebarCounts($provider, $organizationA->id, $restrictedUser);
        $this->assertArrayNotHasKey('rentals', $restrictedData['sidebarPendingCounts'] ?? []);
        $this->assertSame(0, (int) ($restrictedData['overdueRentalsCount'] ?? 0));

        $this->actingAs($otherOrgUser)
            ->get(route('profile.edit'))
            ->assertOk();

        $otherOrgData = $this->invokeSidebarCounts($provider, $organizationB->id, $otherOrgUser);
        $this->assertArrayNotHasKey('rentals', $otherOrgData['sidebarPendingCounts'] ?? []);
        $this->assertSame(0, (int) ($otherOrgData['overdueRentalsCount'] ?? 0));
    }

    private function createOverdueDeliveredRental(int $organizationId): void
    {
        $customer = Customer::create([
            'organization_id' => $organizationId,
            'name' => 'Overdue Customer',
            'customer_type' => 'Individual',
            'phone' => '9876543210',
        ]);

        $product = Product::create([
            'organization_id' => $organizationId,
            'name' => 'Overdue Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 1,
            'total_quantity' => 1,
            'price_per_day' => 500,
            'rental_price' => 500,
        ]);

        $rental = Rental::create([
            'organization_id' => $organizationId,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
            'rental_amount' => 500,
            'status' => 'active',
        ]);

        Delivery::create([
            'organization_id' => $organizationId,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'scheduled_at' => now()->subDays(6),
            'completed_at' => now()->subDays(6),
            'status' => 'completed',
            'notes' => 'Delivered',
        ]);
    }

    private function invokeSidebarCounts(AppServiceProvider $provider, int $organizationId, object $user): array
    {
        $method = new \ReflectionMethod($provider, 'sidebarNotificationCounts');
        $method->setAccessible(true);

        /** @var array $counts */
        $counts = $method->invoke($provider, $organizationId, $user);

        return $counts;
    }
}
