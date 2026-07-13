<?php

namespace Tests\Feature\Regression;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SaleInventory;
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
        $salesContent = file_get_contents(resource_path('views/sales/partials/form.blade.php'));

        $this->assertStringContainsString('id="customer_id"', $salesContent);
        $this->assertStringContainsString('id="saleItemsList"', $salesContent);
        $this->assertStringContainsString('Add Product', $salesContent);
        $this->assertStringContainsString('Products', $salesContent);
        $this->assertStringContainsString('data-searchable-select', $salesContent);
        $this->assertStringContainsString('data-search-placeholder="Search customer by name, phone, email, or city"', $salesContent);
        $this->assertStringContainsString('data-search-placeholder="Search product by name, brand, model, SKU, or code"', $salesContent);
        $this->assertStringContainsString('name="sale_items[${index}][tax_percentage]"', $salesContent);
        $this->assertStringContainsString('name="sale_items[${index}][tax_type]"', $salesContent);
        $this->assertStringContainsString('Tax Type', $salesContent);
        $this->assertStringContainsString('CGST + SGST', $salesContent);
        $this->assertStringContainsString('IGST', $salesContent);
        $this->assertStringContainsString('gstOptionsHtml(item.tax_percentage)', $salesContent);
        $this->assertStringNotContainsString('class="gst-percent-input no-auto-select"', $salesContent);
        $this->assertStringNotContainsString('data-no-auto-select', $salesContent);
    }

    public function test_rentals_create_page_has_searchable_customer_and_product_selects(): void
    {
        $response = $this->get(route('rentals.create'));

        $response->assertOk()
            ->assertSee('id="customer_id"', false)
            ->assertSee('id="product_id"', false)
            ->assertSee('name="gst_rate" id="gst_rate"', false)
            ->assertSee('data-searchable-select', false)
            ->assertSee('data-search-placeholder="Search customer by name or phone"', false)
            ->assertSee('data-search-placeholder="Search product by name, brand, model, SKU, or code"', false)
            ->assertSee('gstOptionsHtml(item.gst_rate', false)
            ->assertSee('name="rental_items[${index}][product_id]"', false)
            ->assertSee('data-rental-product-index="${index}"', false)
            ->assertSee('enhanceSearchableSelect(productSelectEl);', false)
            ->assertSee('name="sale_items[${index}][product_id]"', false)
            ->assertSee('data-sale-product-index="${index}"', false)
            ->assertSee('data-search-placeholder="Search new product by name, brand, model, SKU, or code"', false)
            ->assertDontSee('class="gst-percent-input no-auto-select"', false)
            ->assertDontSee('data-no-auto-select', false);
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

        $dualPurposeProduct = $this->makeProduct([
            'name' => 'Hospital Bed',
            'product_type' => Product::TYPE_BOTH,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
        ]);

        $rentableOnlyProduct = $this->makeProduct([
            'name' => 'CPAP Rental Only',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 2,
            'total_quantity' => 2,
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
            'product_id' => $dualPurposeProduct->id,
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
        $this->assertStringContainsString($dualPurposeProduct->name, $rentalProductOptions);
        $this->assertStringContainsString($rentableOnlyProduct->name, $rentalProductOptions);

        $salesResponse = $this->get(route('sales.create'));

        $salesResponse->assertOk()
            ->assertSee($saleOnlyProduct->name)
            ->assertSee($dualPurposeProduct->name)
            ->assertDontSee($rentableOnlyProduct->name);

        $salesContent = $salesResponse->getContent();
        $this->assertStringContainsString('"product_type":"both"', $salesContent);
        $this->assertStringContainsString('data-sale-product-line', $salesContent);
        $this->assertStringContainsString('data-sale-product-select', $salesContent);
        $this->assertStringContainsString("['sellable', 'both'].includes(productType)", $salesContent);
        $this->assertStringContainsString('function resolveSaleStockProduct(button)', $salesContent);
        $this->assertStringContainsString('const candidateIds = [', $salesContent);
    }

    public function test_sales_create_stock_summary_uses_serialized_sale_asset_counts_when_product_quantities_are_stale(): void
    {
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct([
            'name' => 'Serialized Sale Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_SALE,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        foreach ([
            ['serial_number' => 'SALE-AVAILABLE-1', 'asset_status' => Asset::STATUS_AVAILABLE_FOR_SALE],
            ['serial_number' => 'SALE-AVAILABLE-2', 'asset_status' => Asset::STATUS_AVAILABLE_FOR_SALE],
            ['serial_number' => 'SALE-RESERVED-1', 'asset_status' => Asset::STATUS_RESERVED_FOR_SALE],
            ['serial_number' => 'SALE-SOLD-1', 'asset_status' => Asset::STATUS_SOLD],
            ['serial_number' => 'SALE-TRANSIT-1', 'asset_status' => Asset::STATUS_CONVERTED_TO_RENTAL],
        ] as $assetData) {
            Asset::create(array_merge([
                'organization_id' => $this->organizationId,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'asset_name' => 'Serialized Unit',
                'asset_stage' => Asset::STAGE_NEW_STOCK,
                'condition_status' => 'good',
            ], $assetData));
        }

        $response = $this->get(route('sales.create'));

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('"name":"Serialized Sale Product"', $content);
        $this->assertStringContainsString('"sale_stock_summary":{"available":2,"sold":1,"reserved":1,"in_transit":1,"total":5}', $content);
        $this->assertStringContainsString('SALE-AVAILABLE-1', $content);
        $this->assertStringContainsString('SALE-AVAILABLE-2', $content);
        $this->assertStringNotContainsString('SALE-SOLD-1', $content);
        $this->assertStringNotContainsString('SALE-RESERVED-1', $content);
    }

    public function test_sales_create_stock_summary_uses_sale_inventory_counts_when_no_serialized_asset_is_linked(): void
    {
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct([
            'name' => 'Inventory Backed Sale Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_SALE,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        SaleInventory::create([
            'organization_id' => $this->organizationId,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity_in_stock' => 7,
            'reserved_quantity' => 2,
            'reorder_level' => 0,
            'purchase_cost' => 0,
        ]);

        $response = $this->get(route('sales.create'));

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('"name":"Inventory Backed Sale Product"', $content);
        $this->assertStringContainsString('"sale_stock_summary":{"available":7,"sold":0,"reserved":2,"in_transit":0,"total":9}', $content);
    }

    public function test_sales_create_uses_compact_sale_stock_modal_and_ajax_stock_creation_refreshes_summary(): void
    {
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct([
            'name' => 'Compact Sale Stock Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_SALE,
        ]);

        $page = $this->get(route('sales.create'));

        $page->assertOk()
            ->assertSee('id="saleStockPopup"', false)
            ->assertSee('data-sale-stock-modal-form', false)
            ->assertSee('id="saleStockWarehouseId"', false)
            ->assertSee('id="saleStockSerialNumber"', false)
            ->assertDontSee('id="saleStockPopupFrame"', false)
            ->assertDontSee('class="sales-stock-popup-frame"', false);

        $response = $this->postJson(route('assets.store'), [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_stage' => Asset::STAGE_NEW_STOCK,
            'asset_status' => Asset::STATUS_AVAILABLE_FOR_SALE,
            'asset_name' => $product->name,
            'serial_number' => 'SALE-MODAL-001',
            'barcode_value' => 'SALE-MODAL-BARCODE-001',
            'condition_status' => 'new',
            'purchase_date' => now()->toDateString(),
            'purchase_cost' => 1250,
            'notes' => 'Added from sales compact modal.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('asset.product_id', $product->id)
            ->assertJsonPath('asset.serial_number', 'SALE-MODAL-001')
            ->assertJsonPath('asset.warehouse_name', $warehouse->name)
            ->assertJsonPath('sale_stock_summary.available', 1)
            ->assertJsonPath('sale_stock_summary.total', 1);

        $this->assertDatabaseHas('assets', [
            'organization_id' => $this->organizationId,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial_number' => 'SALE-MODAL-001',
            'asset_stage' => Asset::STAGE_NEW_STOCK,
            'asset_status' => Asset::STATUS_AVAILABLE_FOR_SALE,
        ]);
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

    public function test_inventory_dashboard_counts_both_products_under_sellable_and_rentable(): void
    {
        $this->makeProduct([
            'name' => 'Sale Only',
            'product_type' => Product::TYPE_SELLABLE,
        ]);

        $this->makeProduct([
            'name' => 'Rental Only',
            'product_type' => Product::TYPE_RENTABLE,
        ]);

        $bothProduct = $this->makeProduct([
            'name' => 'Dual Purpose',
            'product_type' => Product::TYPE_BOTH,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
        ]);

        $response = $this->get(route('inventory.dashboard'));

        $response->assertOk()
            ->assertViewHas('dashboard', function (array $dashboard) {
                return ($dashboard['sellable_products'] ?? null) === 2
                    && ($dashboard['rentable_products'] ?? null) === 2;
            });

        $this->assertSame(Product::TYPE_BOTH, $bothProduct->fresh()->product_type);
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
