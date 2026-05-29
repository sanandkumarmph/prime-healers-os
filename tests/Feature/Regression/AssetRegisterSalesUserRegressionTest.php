<?php

namespace Tests\Feature\Regression;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\Role;
use App\Models\Rental;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestData;
use Tests\TestCase;

class AssetRegisterSalesUserRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole($organization, string $name, array $permissions): User
    {
        $role = Role::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'permissions' => Role::normalizePermissions($permissions),
        ]);

        $user = User::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
        ]);

        return $user;
    }

    public function test_sales_style_read_only_user_can_open_asset_register_without_server_error(): void
    {
        $organization = TestData::organization();
        $salesUser = $this->userWithRole($organization, 'Sales Asset Reader', [
            'assets' => ['read'],
            'sales' => ['read'],
        ]);

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Main Warehouse',
            'code' => 'MWH',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Asset Customer',
            'phone' => '9999999999',
            'city' => 'Bengaluru',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Linked Asset Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'rental_price' => 1000,
            'price_per_day' => 1000,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDays(5),
            'rental_amount' => 1000,
            'status' => 'active',
        ]);

        $sale = Sale::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'sale_date' => now()->toDateString(),
            'total_amount' => 1200,
            'amount' => 1200,
            'payment_status' => 'pending',
        ]);

        $asset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Linked Asset Unit',
            'serial_number' => 'ASSET-SALES-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        $rental->activeRentalAssets()->create([
            'organization_id' => $organization->id,
            'asset_id' => $asset->id,
            'assigned_at' => now(),
        ]);

        $asset->sales()->syncWithoutDetaching([
            $sale->id => ['organization_id' => $organization->id],
        ]);

        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'status' => 'pending',
            'scheduled_at' => now(),
        ]);

        $response = $this->actingAs($salesUser)->get(route('assets.index'));

        $response->assertOk();
        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertSee('Asset Register');
        $response->assertSee('ASSET-SALES-001');
    }
}
