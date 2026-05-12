<?php

namespace Tests\Feature\Regression;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class SalesRentalSearchableSelectRegressionTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = TestData::organization();
        $this->organizationId = $organization->id;
        $this->actingAs(TestData::user($organization));
    }

    public function test_sales_create_page_has_searchable_customer_and_product_selects(): void
    {
        $response = $this->get(route('sales.create'));

        $response->assertOk()
            ->assertSee('id="customer_id"', false)
            ->assertSee('id="product_id"', false)
            ->assertSee('data-searchable-select', false)
            ->assertSee('data-search-placeholder="Search customer by name or phone"', false)
            ->assertSee('data-search-placeholder="Search product by name, brand, model, SKU, or code"', false);
    }

    public function test_rentals_create_page_has_searchable_customer_and_product_selects(): void
    {
        $response = $this->get(route('rentals.create'));

        $response->assertOk()
            ->assertSee('id="customer_id"', false)
            ->assertSee('id="product_id"', false)
            ->assertSee('data-searchable-select', false)
            ->assertSee('data-search-placeholder="Search customer by name or phone"', false)
            ->assertSee('data-search-placeholder="Search product by name, brand, model, SKU, or code"', false);
    }

    public function test_rentals_create_page_excludes_sale_only_products_but_keeps_rental_eligible_products(): void
    {
        $warehouse = $this->makeWarehouse();

        $saleOnlyProduct = $this->makeProduct([
            'name' => 'Adult Diapers Pant Type L - Svach',
            'category' => 'Consumables',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 25,
            'total_quantity' => 25,
        ]);

        $rentableProduct = $this->makeProduct([
            'name' => 'Oxygen Concentrator',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
        ]);

        $mixedRentalProduct = $this->makeProduct([
            'name' => 'Hospital Bed',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
        ]);

        Asset::create([
            'organization_id' => $this->organizationId,
            'product_id' => $rentableProduct->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Oxygen Unit 1',
            'serial_number' => 'OXY-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        Asset::create([
            'organization_id' => $this->organizationId,
            'product_id' => $mixedRentalProduct->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Bed Unit 1',
            'serial_number' => 'BED-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        $rentalResponse = $this->get(route('rentals.create'));

        $rentalResponse->assertOk();
        $rentalProductOptions = $this->extractSelectHtml($rentalResponse->getContent(), 'product_id');

        $this->assertStringNotContainsString($saleOnlyProduct->name, $rentalProductOptions);
        $this->assertStringContainsString($rentableProduct->name, $rentalProductOptions);
        $this->assertStringContainsString($mixedRentalProduct->name, $rentalProductOptions);

        $salesResponse = $this->get(route('sales.create'));

        $salesResponse->assertOk()
            ->assertSee($saleOnlyProduct->name);
    }

    public function test_rental_creation_rejects_sale_only_product_submission(): void
    {
        $customer = Customer::create([
            'organization_id' => $this->organizationId,
            'name' => 'Rental Customer',
            'phone' => '9876543210',
            'address' => 'Prime Healers Street',
            'city' => 'Bengaluru',
        ]);

        $saleOnlyProduct = $this->makeProduct([
            'name' => 'Adult Diapers Pant Type M - Svach',
            'category' => 'Consumables',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 10,
            'total_quantity' => 10,
        ]);

        $response = $this->from(route('rentals.create'))->post(route('rentals.store'), [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $saleOnlyProduct->id,
            'quantity' => 1,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'rental_amount' => 500,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
        ]);

        $response->assertRedirect(route('rentals.create'));
        $response->assertSessionHasErrors(['product_id']);
        $this->assertDatabaseCount('rentals', 0);
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $this->organizationId,
            'name' => 'Test Product',
            'category' => 'General',
            'brand' => 'Prime',
            'model_name' => 'Base',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 100,
            'rental_price_15_days' => 1200,
            'rental_price_30_days' => 2200,
            'rental_price_3_months' => 6000,
            'sale_price' => 5000,
            'rental_price' => 2200,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ], $attributes));
    }

    private function makeWarehouse(): Warehouse
    {
        return Warehouse::create([
            'organization_id' => $this->organizationId,
            'name' => 'Rental Warehouse',
            'code' => 'RWH',
            'is_active' => true,
        ]);
    }

    private function extractSelectHtml(string $html, string $selectId): string
    {
        $pattern = '/<select[^>]*id="'.preg_quote($selectId, '/').'"[^>]*>(.*?)<\/select>/is';

        if (!preg_match($pattern, $html, $matches)) {
            $this->fail('Unable to find select with id "'.$selectId.'".');
        }

        return $matches[1];
    }
}
