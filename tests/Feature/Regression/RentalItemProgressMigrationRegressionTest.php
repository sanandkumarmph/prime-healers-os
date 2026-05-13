<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestData;
use Tests\TestCase;

class RentalItemProgressMigrationRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_loads_with_rental_item_progress_columns_present(): void
    {
        $this->assertTrue(Schema::hasColumn('rental_items', 'ordered_quantity'));
        $this->assertTrue(Schema::hasColumn('rental_items', 'delivered_quantity'));
        $this->assertTrue(Schema::hasColumn('rental_items', 'returned_quantity'));

        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Dashboard Rental Customer',
            'phone' => '9876543299',
            'city' => 'Bengaluru',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Dashboard Rental Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 500,
            'rental_price' => 500,
            'sale_price' => 0,
            'available_quantity' => 3,
            'total_quantity' => 3,
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'rental_amount' => 500,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
            'status' => 'active',
        ]);

        RentalItem::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'ordered_quantity' => 1,
            'delivered_quantity' => 1,
            'returned_quantity' => 0,
            'unit_rental_amount' => 500,
            'line_total' => 500,
        ]);

        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'scheduled_at' => now()->subDay(),
            'status' => 'completed',
            'completed_at' => now()->subDay(),
        ]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Dashboard Rental Product');
    }
}
