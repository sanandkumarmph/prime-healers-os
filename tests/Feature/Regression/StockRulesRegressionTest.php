<?php

namespace Tests\Feature\Regression;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class StockRulesRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_tracked_product_stock_summaries_do_not_mix_sale_and_rental_assets(): void
    {
        $organization = TestData::organization();
        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Primary Warehouse',
            'code' => 'PRM',
            'is_active' => true,
        ]);

        $trackedSale = Product::create([
            'organization_id' => $organization->id,
            'name' => 'BiPAP Machine',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_SALE,
            'price_per_day' => 0,
            'sale_price' => 0,
            'rental_price' => 0,
            'available_quantity' => 99,
            'total_quantity' => 99,
        ]);

        $trackedRental = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'price_per_day' => 500,
            'sale_price' => 0,
            'rental_price' => 500,
            'available_quantity' => 25,
            'total_quantity' => 25,
        ]);

        $trackedBoth = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Dual Mode Unit',
            'product_type' => Product::TYPE_BOTH,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
            'price_per_day' => 300,
            'sale_price' => 1200,
            'rental_price' => 300,
            'available_quantity' => 50,
            'total_quantity' => 50,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $trackedSale->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Sale Unit A',
            'serial_number' => 'SALE-001',
            'asset_stage' => Asset::STAGE_NEW_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE_FOR_SALE,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $trackedSale->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Sale Unit B',
            'serial_number' => 'SALE-002',
            'asset_stage' => Asset::STAGE_NEW_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE_FOR_SALE,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $trackedRental->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Rental Unit A',
            'serial_number' => 'RENT-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $trackedRental->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Rental Unit B',
            'serial_number' => 'RENT-002',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_RENTED,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $trackedBoth->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Both Sale Unit',
            'serial_number' => 'BOTH-SALE-001',
            'asset_stage' => Asset::STAGE_NEW_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE_FOR_SALE,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $trackedBoth->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Both Rental Unit',
            'serial_number' => 'BOTH-RENT-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        $trackedSale->syncLegacyStockFields();
        $trackedRental->syncLegacyStockFields();
        $trackedBoth->syncLegacyStockFields();

        $this->assertSame(Product::STOCK_MODE_TRACKED_SALE, $trackedSale->fresh()->stock_mode);
        $this->assertSame(2, $trackedSale->fresh()->available_quantity);
        $this->assertSame(2, $trackedSale->fresh()->total_quantity);

        $this->assertSame(Product::STOCK_MODE_TRACKED_RENTAL, $trackedRental->fresh()->stock_mode);
        $this->assertSame(1, $trackedRental->fresh()->available_quantity);
        $this->assertSame(2, $trackedRental->fresh()->total_quantity);

        $this->assertSame(Product::STOCK_MODE_TRACKED_BOTH, $trackedBoth->fresh()->stock_mode);
        $this->assertTrue($trackedBoth->fresh()->canSell());
        $this->assertTrue($trackedBoth->fresh()->canRent());
        $this->assertSame(1, $trackedBoth->fresh()->available_quantity);
        $this->assertSame(1, $trackedBoth->fresh()->total_quantity);
    }

    public function test_products_marked_both_accept_sale_units_and_rental_assets_while_single_purpose_products_do_not(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Compatibility Warehouse',
            'code' => 'CMP',
            'is_active' => true,
        ]);

        $bothProduct = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Both Product',
            'product_type' => Product::TYPE_BOTH,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 200,
            'sale_price' => 1500,
            'rental_price' => 200,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $sellableOnly = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Sellable Only Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 0,
            'sale_price' => 1200,
            'rental_price' => 0,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $rentableOnly = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Rentable Only Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 300,
            'sale_price' => 0,
            'rental_price' => 300,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $this->post(route('assets.store'), [
            'product_id' => $bothProduct->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Both Rental Unit',
            'serial_number' => 'BOTH-RENT-COMPAT',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ])->assertRedirect();

        $this->post(route('assets.store'), [
            'product_id' => $bothProduct->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Both Sale Unit',
            'serial_number' => 'BOTH-SALE-COMPAT',
            'asset_stage' => Asset::STAGE_NEW_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE_FOR_SALE,
        ])->assertRedirect();

        $this->from(route('assets.create'))->post(route('assets.store'), [
            'product_id' => $sellableOnly->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Blocked Rental Unit',
            'serial_number' => 'SELL-RENT-BLOCK',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ])->assertRedirect(route('assets.create'))
            ->assertSessionHasErrors('product_id');

        $this->from(route('assets.create'))->post(route('assets.store'), [
            'product_id' => $rentableOnly->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Blocked Sale Unit',
            'serial_number' => 'RENT-SALE-BLOCK',
            'asset_stage' => Asset::STAGE_NEW_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE_FOR_SALE,
        ])->assertRedirect(route('assets.create'))
            ->assertSessionHasErrors('product_id');

        $this->assertDatabaseHas('assets', [
            'product_id' => $bothProduct->id,
            'serial_number' => 'BOTH-RENT-COMPAT',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
        ]);
        $this->assertDatabaseHas('assets', [
            'product_id' => $bothProduct->id,
            'serial_number' => 'BOTH-SALE-COMPAT',
            'asset_stage' => Asset::STAGE_NEW_STOCK,
        ]);
    }

    public function test_rental_available_assets_endpoint_returns_only_rental_stock_and_filters_by_warehouse(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
            'price_per_day' => 450,
            'sale_price' => 0,
            'rental_price' => 450,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $warehouseA = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_active' => true,
        ]);

        $warehouseB = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Branch Warehouse',
            'code' => 'BRANCH',
            'is_active' => true,
        ]);

        $rentalAvailable = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouseA->id,
            'asset_name' => 'Rental Available',
            'serial_number' => 'R-AVL-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouseA->id,
            'asset_name' => 'Rental Rented',
            'serial_number' => 'R-RENT-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_RENTED,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouseA->id,
            'asset_name' => 'Sale Stock',
            'serial_number' => 'S-AVL-001',
            'asset_stage' => Asset::STAGE_NEW_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE_FOR_SALE,
        ]);

        $warehouseBAsset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouseB->id,
            'asset_name' => 'Branch Rental Available',
            'serial_number' => 'R-AVL-002',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        $allResponse = $this->getJson(route('rentals.available-assets', [
            'product_id' => $product->id,
        ]));

        $allResponse->assertOk();
        $this->assertEqualsCanonicalizing(
            [$rentalAvailable->id, $warehouseBAsset->id],
            collect($allResponse->json('data'))->pluck('id')->all()
        );

        $warehouseResponse = $this->getJson(route('rentals.available-assets', [
            'product_id' => $product->id,
            'dispatch_warehouse_id' => $warehouseA->id,
        ]));

        $warehouseResponse->assertOk();
        $this->assertSame(
            [$rentalAvailable->id],
            collect($warehouseResponse->json('data'))->pluck('id')->all()
        );
    }

    public function test_tracked_sale_consumes_sale_assets_and_creates_invoice_without_touching_rental_stock(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Alice Customer',
            'phone' => '9999999999',
        ]);

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Sales Warehouse',
            'code' => 'SALE',
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'BiPAP Machine',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_SALE,
            'price_per_day' => 0,
            'sale_price' => 1500,
            'rental_price' => 0,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $saleAsset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Sale Unit',
            'serial_number' => 'SALE-TRACK-001',
            'asset_stage' => Asset::STAGE_NEW_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE_FOR_SALE,
        ]);

        $rentalAsset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Rental Unit',
            'serial_number' => 'RENT-TRACK-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        $response = $this->post(route('sales.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 1500,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => now()->toDateString(),
            'sale_amount' => 1500,
            'payment_status' => 'pending',
            'notes' => 'Regression sale',
        ]);

        $response->assertRedirect(route('sales.index'));

        $sale = Sale::query()->where('organization_id', $organization->id)->firstOrFail();
        $invoice = Invoice::query()->where('organization_id', $organization->id)->firstOrFail();

        $this->assertSame($customer->id, $sale->customer_id);
        $this->assertSame($product->id, $sale->product_id);
        $this->assertTrue((bool) $sale->stock_applied);

        $this->assertSame(Asset::STATUS_SOLD, $saleAsset->fresh()->asset_status);
        $this->assertSame(Asset::STATUS_AVAILABLE, $rentalAsset->fresh()->asset_status);

        $this->assertSame($customer->id, $invoice->customer_id);
        $this->assertSame(1500.0, (float) $invoice->total_amount);
        $this->assertSame('unpaid', $invoice->payment_status);

        $this->assertSame(0, $product->fresh()->available_quantity);
        $this->assertSame(Product::STOCK_MODE_TRACKED_SALE, $product->fresh()->stock_mode);
    }
}
