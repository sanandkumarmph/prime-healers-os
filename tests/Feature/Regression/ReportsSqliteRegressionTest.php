<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class ReportsSqliteRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_page_loads_under_sqlite_with_repeat_customer_counts(): void
    {
        $organization = TestData::organization();
        $reportUser = $this->userWithRole($organization, 'Report Reader', [
            'reports' => ['read'],
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Report Rental Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 5,
            'total_quantity' => 5,
            'price_per_day' => 200,
            'rental_price' => 1200,
            'sale_price' => 0,
            'gst_tax_type' => 'none',
            'gst_calculation_mode' => 'exclusive',
        ]);

        $repeatCustomer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Repeat Customer',
            'phone' => '9000000001',
            'city' => 'Bengaluru',
        ]);

        $singleRentalCustomer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Single Rental Customer',
            'phone' => '9000000002',
            'city' => 'Bengaluru',
        ]);

        $noRentalCustomer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'No Rental Customer',
            'phone' => '9000000003',
            'city' => 'Bengaluru',
        ]);

        $this->createRental($organization->id, $product->id, $repeatCustomer, now()->subDays(10)->toDateString());
        $this->createRental($organization->id, $product->id, $repeatCustomer, now()->subDays(5)->toDateString());
        $this->createRental($organization->id, $product->id, $singleRentalCustomer, now()->subDays(2)->toDateString());

        $response = $this->actingAs($reportUser)->get(route('reports.index'));

        $response->assertOk()
            ->assertViewIs('reports.index')
            ->assertSee('Repeat Customer', false)
            ->assertDontSee('HAVING clause on a non-aggregate query', false);
    }

    private function createRental(int $organizationId, int $productId, Customer $customer, string $startDate): Rental
    {
        return Rental::create([
            'organization_id' => $organizationId,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $productId,
            'quantity' => 1,
            'start_date' => $startDate,
            'end_date' => now()->addDays(5)->toDateString(),
            'status' => 'active',
            'rental_amount' => 1200,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
        ]);
    }

    private function userWithRole(Organization $organization, string $name, array $permissions): User
    {
        $role = Role::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'slug' => str($name)->slug('_'),
            'description' => $name,
            'permissions' => Role::normalizePermissions($permissions),
            'is_system' => false,
            'is_active' => true,
        ]);

        return TestData::user($organization, [
            'organization_id' => $organization->id,
            'role' => 'staff',
            'role_id' => $role->id,
        ]);
    }
}
