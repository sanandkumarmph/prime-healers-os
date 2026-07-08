<?php

namespace Tests\Feature\Regression;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\RentalAsset;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorOrderDetail;
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

    public function test_asset_store_accepts_new_condition_status(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'New Condition Warehouse',
            'code' => 'NEW',
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'New Condition Product',
            'product_type' => Product::TYPE_BOTH,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
            'price_per_day' => 250,
            'sale_price' => 1800,
            'rental_price' => 250,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $this->post(route('assets.store'), [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Fresh Rental Unit',
            'serial_number' => 'NEW-COND-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => Asset::CONDITION_STATUS_NEW,
            'asset_status' => Asset::STATUS_AVAILABLE,
        ])->assertRedirect();

        $this->assertDatabaseHas('assets', [
            'product_id' => $product->id,
            'serial_number' => 'NEW-COND-001',
            'condition_status' => Asset::CONDITION_STATUS_NEW,
        ]);
    }

    public function test_bulk_asset_store_creates_multiple_assets_with_common_fields_and_movements(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Bulk Warehouse',
            'code' => 'BLK',
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Bulk Oxygen Unit',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'price_per_day' => 250,
            'sale_price' => 0,
            'rental_price' => 250,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $this->post(route('assets.bulk-store'), [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => Asset::CONDITION_STATUS_NEW,
            'purchase_date' => '2026-06-14',
            'purchase_cost' => '12500',
            'last_service_date' => '2026-06-10',
            'next_service_date' => '2026-09-10',
            'notes' => 'Bulk scan intake.',
            'serial_numbers' => "BULK-001\nBULK-002 BULK-003",
        ])->assertRedirect(route('assets.index'))
            ->assertSessionHas('success', '3 assets added successfully.');

        foreach (['BULK-001', 'BULK-002', 'BULK-003'] as $serial) {
            $this->assertDatabaseHas('assets', [
                'organization_id' => $organization->id,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'serial_number' => $serial,
                'barcode_value' => $serial,
                'condition_status' => Asset::CONDITION_STATUS_NEW,
                'purchase_cost' => 12500,
                'notes' => 'Bulk scan intake.',
            ]);
        }

        $this->assertSame(3, StockMovement::query()
            ->where('organization_id', $organization->id)
            ->where('product_id', $product->id)
            ->where('movement_type', StockMovement::TYPE_ADD_STOCK)
            ->count());
    }

    public function test_bulk_asset_store_rejects_duplicate_serials_in_batch(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        [$product, $warehouse] = $this->bulkAssetProductAndWarehouse($organization->id);

        $this->from(route('assets.create', ['mode' => 'bulk']))
            ->post(route('assets.bulk-store'), [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'asset_stage' => Asset::STAGE_RENTAL_STOCK,
                'condition_status' => Asset::CONDITION_STATUS_NEW,
                'serial_numbers' => "DUP-001\nDUP-001",
            ])
            ->assertRedirect(route('assets.create', ['mode' => 'bulk']))
            ->assertSessionHasErrors('serial_numbers');

        $this->assertDatabaseMissing('assets', [
            'serial_number' => 'DUP-001',
        ]);
    }

    public function test_bulk_asset_store_rejects_existing_serials(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        [$product, $warehouse] = $this->bulkAssetProductAndWarehouse($organization->id);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Existing Bulk Asset',
            'serial_number' => 'EXISTS-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => Asset::CONDITION_STATUS_GOOD,
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        $this->from(route('assets.create', ['mode' => 'bulk']))
            ->post(route('assets.bulk-store'), [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'asset_stage' => Asset::STAGE_RENTAL_STOCK,
                'condition_status' => Asset::CONDITION_STATUS_NEW,
                'serial_numbers' => 'EXISTS-001 NEW-001',
            ])
            ->assertRedirect(route('assets.create', ['mode' => 'bulk']))
            ->assertSessionHasErrors('serial_numbers');

        $this->assertDatabaseMissing('assets', [
            'serial_number' => 'NEW-001',
        ]);
    }

    public function test_bulk_asset_store_requires_asset_create_permission(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization, ['role' => User::ROLE_DELIVERY]));

        [$product, $warehouse] = $this->bulkAssetProductAndWarehouse($organization->id);

        $this->post(route('assets.bulk-store'), [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => Asset::CONDITION_STATUS_NEW,
            'serial_numbers' => 'NO-PERM-001',
        ])->assertRedirect()
            ->assertSessionHas('error', 'You are not authorized to access this section.');

        $this->assertDatabaseMissing('assets', [
            'serial_number' => 'NO-PERM-001',
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

    public function test_rental_create_page_shows_add_rental_stock_for_asset_creators(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $this->get(route('rentals.create'))
            ->assertOk()
            ->assertSee('openRentalStockModal', false)
            ->assertSee('+ <span class="desktop-label">Add Rental Stock</span>', false)
            ->assertSee('type="button"', false)
            ->assertSee('data-open-rental-stock-modal', false)
            ->assertSee('data-add-rental-stock-trigger', false)
            ->assertSee('id="rentalStockModal"', false)
            ->assertSee('hidden', false)
            ->assertSee('data-close-rental-stock-modal', false)
            ->assertSee('id="rentalStockForm" data-action', false)
            ->assertDontSee('<form id="rentalStockForm"', false);
    }

    public function test_rental_create_page_hides_add_rental_stock_for_users_without_asset_create_permission(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]));

        $this->get(route('rentals.create'))
            ->assertSessionHas('error', 'You are not authorized to access this section.')
            ->assertDontSee('data-open-rental-stock-modal', false)
            ->assertDontSee('data-add-rental-stock-trigger', false)
            ->assertDontSee('+ <span class="desktop-label">Add Rental Stock</span>', false);
    }

    public function test_rental_stock_modal_endpoint_rejects_users_without_asset_create_permission(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization, [
            'role' => User::ROLE_SALES,
        ]);
        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Unauthorized Rental Stock Warehouse',
            'code' => 'NO-ASSET-PERM',
        ]);
        $product = $this->trackedRentalProduct($organization->id);

        $this->assertFalse($user->canAccessModule('assets', 'create'));

        $this->actingAs($user)
            ->postJson(route('rentals.rental-stock.store'), [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'serial_number' => 'NO-ASSET-PERM-001',
                'condition_status' => Asset::CONDITION_STATUS_GOOD,
                'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_IN_HOUSE,
            ])
            ->assertForbidden();
    }

    public function test_rental_stock_modal_endpoint_blocks_sale_only_products_and_vendor_fulfilment(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Rental Warehouse',
            'code' => 'RWH',
            'is_active' => true,
        ]);

        $saleOnlyProduct = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Consumable Mask',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'sale_price' => 150,
            'rental_price' => 0,
            'price_per_day' => 0,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $this->postJson(route('rentals.rental-stock.store'), [
            'product_id' => $saleOnlyProduct->id,
            'warehouse_id' => $warehouse->id,
            'fulfilment_source' => 'in_house',
            'serial_number' => 'MASK-RENT-001',
            'condition_status' => Asset::CONDITION_STATUS_GOOD,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('product_id')
            ->assertJsonFragment(['Rental stock can be added only for reusable/rentable equipment.']);

        $trackedRentalProduct = $this->trackedRentalProduct($organization->id);

        $this->postJson(route('rentals.rental-stock.store'), [
            'product_id' => $trackedRentalProduct->id,
            'warehouse_id' => $warehouse->id,
            'fulfilment_source' => 'vendor_supplied',
            'serial_number' => 'VENDOR-BLOCK-001',
            'condition_status' => Asset::CONDITION_STATUS_GOOD,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('fulfilment_source')
            ->assertJsonFragment(['Vendor-supplied rentals do not require PH rental stock. Please continue with vendor fulfilment.']);
    }

    public function test_rental_stock_modal_endpoint_requires_warehouse_for_in_house_stock(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));
        $product = $this->trackedRentalProduct($organization->id);

        $this->postJson(route('rentals.rental-stock.store'), [
            'product_id' => $product->id,
            'fulfilment_source' => 'in_house',
            'serial_number' => 'NO-WAREHOUSE-001',
            'condition_status' => Asset::CONDITION_STATUS_GOOD,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('warehouse_id');
    }

    public function test_rental_stock_modal_endpoint_creates_available_asset_and_picker_returns_it(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Bengaluru Warehouse',
            'code' => 'BNG',
            'is_active' => true,
        ]);
        $product = $this->trackedRentalProduct($organization->id);

        $response = $this->postJson(route('rentals.rental-stock.store'), [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'fulfilment_source' => 'in_house',
            'serial_number' => 'OC-NEW-001',
            'barcode_value' => 'OC-NEW-001',
            'condition_status' => Asset::CONDITION_STATUS_GOOD,
            'notes' => 'Added during rental create.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Rental stock added and available for selection.')
            ->assertJsonPath('asset.serial_number', 'OC-NEW-001')
            ->assertJsonPath('asset.asset_status', Asset::STATUS_AVAILABLE)
            ->assertJsonPath('asset.warehouse_id', $warehouse->id);

        $this->assertDatabaseHas('assets', [
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial_number' => 'OC-NEW-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'movement_type' => StockMovement::TYPE_ADD_STOCK,
            'quantity' => 1,
        ]);

        $pickerResponse = $this->getJson(route('rentals.available-assets', [
            'product_id' => $product->id,
            'dispatch_warehouse_id' => $warehouse->id,
        ]));

        $pickerResponse->assertOk()
            ->assertJsonFragment([
                'serial_number' => 'OC-NEW-001',
                'warehouse_id' => $warehouse->id,
            ]);
    }

    public function test_rental_create_primary_asset_picker_uses_hidden_inputs_as_only_submit_source(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $response = $this->get(route('rentals.create'));

        $response->assertOk()
            ->assertSee('window.__phosPrimaryRentalAssetIds', false)
            ->assertSee('window.__phosRentalStepDebug', false)
            ->assertSee('window.__phosRentalAssetState', false)
            ->assertSee('window.__phosSyncRentalItem', false)
            ->assertSee('window.__phosRentalAdditionalSummary', false)
            ->assertSee('data-summary-product', false)
            ->assertSee('data-summary-rental-total', false)
            ->assertSee('data-summary-assets', false)
            ->assertSee('data-summary-net-total', false)
            ->assertSee('data-rental-line', false)
            ->assertSee('data-sale-line', false)
            ->assertSee('data-line-total', false)
            ->assertSee('data-selected-assets', false)
            ->assertSee('function syncRentalItem(index)', false)
            ->assertSee('function addAssetToRentalItemCache', false)
            ->assertSee('addAssetToRentalItemCache(normalizedAsset)', false)
            ->assertSee('data-open-rental-asset-modal', false)
            ->assertSee('data-close-rental-asset-modal', false)
            ->assertSee('rentalAssetAssignmentModal', false)
            ->assertSee('rentalAssignAssetButton', false)
            ->assertSee('Select product and stock.', false)
            ->assertSee('Assign rental asset.', false)
            ->assertSee('Asset not assigned', false)
            ->assertSee('Create Rental', false)
            ->assertSee('1 asset', false)
            ->assertSee('selected < required', false)
            ->assertSee('data-asset-id="${normalizedAssetId}"', false)
            ->assertSee('type="hidden" name="asset_ids[]"', false)
            ->assertDontSee('debugAssignAssetButton', false)
            ->assertDontSee('assetAssignDebugPanel', false)
            ->assertDontSee('[AssetAssignDebug]', false)
            ->assertDontSee('Debug:', false)
            ->assertDontSee('window.__phosRentalAssetModalDebug', false)
            ->assertDontSee("console.log('Assign Asset button found')", false)
            ->assertDontSee("console.log('Assign Asset clicked')", false)
            ->assertDontSee("console.log('Opening modal')", false)
            ->assertDontSee("console.log('Rental create JS initialized successfully')", false)
            ->assertDontSee('name="asset_ids[]" value="${asset.id}"', false);
    }

    public function test_in_house_tracked_rental_accepts_one_unique_assigned_asset(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Asset Match Customer',
            'phone' => '9000000091',
        ]);
        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Asset Match Warehouse',
            'code' => 'AMW',
            'is_active' => true,
        ]);
        $product = $this->trackedRentalProduct($organization->id);
        $asset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Asset Match Unit',
            'serial_number' => 'ASSET-MATCH-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => Asset::CONDITION_STATUS_GOOD,
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        $response = $this->post(route('rentals.store'), $this->rentalCreatePayload($customer->id, $product->id, [
            'dispatch_warehouse_id' => $warehouse->id,
            'asset_ids' => [(string) $asset->id],
        ]));

        $response->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));
        $this->assertSame(1, RentalAsset::query()->where('asset_id', $asset->id)->count());
        $this->assertSame(Asset::STATUS_RESERVED, $asset->fresh()->asset_status);
        $this->assertDatabaseHas('rental_items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
    }

    public function test_in_house_tracked_rental_accepts_primary_asset_ids_fallback(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Primary Fallback Customer',
            'phone' => '9000000191',
        ]);
        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Primary Fallback Warehouse',
            'code' => 'PFW',
            'is_active' => true,
        ]);
        $product = $this->trackedRentalProduct($organization->id);
        $asset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Primary Fallback Unit',
            'serial_number' => 'PRIMARY-FALLBACK-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => Asset::CONDITION_STATUS_GOOD,
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        $response = $this->post(route('rentals.store'), $this->rentalCreatePayload($customer->id, $product->id, [
            'dispatch_warehouse_id' => $warehouse->id,
            'primary_asset_ids' => (string) $asset->id,
        ]));

        $response->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));
        $this->assertSame(1, RentalAsset::query()->where('asset_id', $asset->id)->count());
        $this->assertSame(Asset::STATUS_RESERVED, $asset->fresh()->asset_status);
    }

    public function test_in_house_tracked_rental_with_additional_item_keeps_primary_and_additional_assets_separate(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Multi Asset Customer',
            'phone' => '9000000193',
        ]);
        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Multi Asset Warehouse',
            'code' => 'MAW',
            'is_active' => true,
        ]);
        $primaryProduct = $this->trackedRentalProduct($organization->id);
        $additionalProduct = $this->trackedRentalProduct($organization->id);
        $additionalProduct->update(['name' => 'Additional Rental Stock Product']);

        $primaryAsset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $primaryProduct->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Primary Multi Unit',
            'serial_number' => 'PRIMARY-MULTI-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => Asset::CONDITION_STATUS_GOOD,
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);
        $additionalAsset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $additionalProduct->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Additional Multi Unit',
            'serial_number' => 'ADDITIONAL-MULTI-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => Asset::CONDITION_STATUS_GOOD,
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        $response = $this->post(route('rentals.store'), $this->rentalCreatePayload($customer->id, $primaryProduct->id, [
            'dispatch_warehouse_id' => $warehouse->id,
            'primary_asset_ids' => (string) $primaryAsset->id,
            'rental_items' => [
                [
                    'product_id' => $additionalProduct->id,
                    'quantity' => 1,
                    'unit_rental_amount' => 300,
                    'start_date' => '2026-06-12',
                    'end_date' => '2026-06-18',
                    'asset_ids' => [(string) $additionalAsset->id],
                ],
            ],
        ]));

        $response->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));
        $this->assertSame(1, RentalAsset::query()->where('asset_id', $primaryAsset->id)->count());
        $this->assertSame(1, RentalAsset::query()->where('asset_id', $additionalAsset->id)->count());
        $this->assertSame(Asset::STATUS_RESERVED, $primaryAsset->fresh()->asset_status);
        $this->assertSame(Asset::STATUS_RESERVED, $additionalAsset->fresh()->asset_status);
    }
    public function test_in_house_tracked_rental_accepts_item_zero_asset_ids_fallback(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Item Zero Fallback Customer',
            'phone' => '9000000192',
        ]);
        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Item Zero Fallback Warehouse',
            'code' => 'IZW',
            'is_active' => true,
        ]);
        $product = $this->trackedRentalProduct($organization->id);
        $asset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Item Zero Fallback Unit',
            'serial_number' => 'ITEM-ZERO-FALLBACK-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => Asset::CONDITION_STATUS_GOOD,
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        $response = $this->post(route('rentals.store'), $this->rentalCreatePayload($customer->id, $product->id, [
            'dispatch_warehouse_id' => $warehouse->id,
            'rental_items' => [
                ['asset_ids' => [(string) $asset->id]],
            ],
        ]));

        $response->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));
        $this->assertSame(1, RentalAsset::query()->where('asset_id', $asset->id)->count());
        $this->assertSame(Asset::STATUS_RESERVED, $asset->fresh()->asset_status);
    }
    public function test_rental_create_shows_visible_asset_submission_error_when_asset_payload_is_invalid(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Asset Error Customer',
            'phone' => '9000000095',
        ]);
        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Asset Error Warehouse',
            'code' => 'AEW',
            'is_active' => true,
        ]);
        $selectedProduct = $this->trackedRentalProduct($organization->id);
        $otherProduct = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Different Tracked Rental Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'price_per_day' => 250,
            'rental_price' => 250,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);
        $mismatchedAsset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $otherProduct->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Wrong Product Unit',
            'serial_number' => 'WRONG-PRODUCT-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => Asset::CONDITION_STATUS_GOOD,
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        $response = $this->from(route('rentals.create'))
            ->followingRedirects()
            ->post(route('rentals.store'), $this->rentalCreatePayload($customer->id, $selectedProduct->id, [
                'dispatch_warehouse_id' => $warehouse->id,
                'asset_ids' => [(string) $mismatchedAsset->id],
            ]));

        $response->assertOk()
            ->assertSee('Selected asset was not submitted correctly. Please reselect asset.', false)
            ->assertSee('One or more selected assets do not match the product or warehouse for the primary rental line.', false);
        $this->assertSame(0, RentalAsset::query()->count());
    }

    public function test_vendor_supplied_tracked_rental_does_not_require_ph_asset(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Vendor Asset Customer',
            'phone' => '9000000092',
        ]);
        $product = $this->trackedRentalProduct($organization->id);
        $vendor = Vendor::create([
            'organization_id' => $organization->id,
            'name' => 'Vendor Asset Source',
            'contact_person' => 'Vendor Ops',
            'phone' => '9000000093',
            'vendor_type' => 'Fulfilment Partner',
            'is_active' => true,
        ]);

        $response = $this->post(route('rentals.store'), $this->rentalCreatePayload($customer->id, $product->id, [
            'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED,
            'vendor_id' => $vendor->id,
            'asset_ids' => [],
        ]));

        $response->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));
        $this->assertSame(0, RentalAsset::query()->count());
    }

    public function test_untracked_rentable_product_does_not_require_asset_assignment(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Untracked Rental Customer',
            'phone' => '9000000094',
        ]);
        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Untracked Rental Warehouse',
            'code' => 'URW',
            'is_active' => true,
        ]);
        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Untracked Rental Consumable',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 150,
            'rental_price' => 150,
            'available_quantity' => 5,
            'total_quantity' => 5,
        ]);

        $response = $this->post(route('rentals.store'), $this->rentalCreatePayload($customer->id, $product->id, [
            'dispatch_warehouse_id' => $warehouse->id,
            'asset_ids' => [],
            'rental_amount' => 150,
        ]));

        $response->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));
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

        $response->assertRedirect(route('sales.index', ['sort_by' => 'latest']));

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

    private function rentalCreatePayload(int $customerId, int $productId, array $overrides = []): array
    {
        return array_merge([
            'customer_type' => 'direct_customer',
            'customer_id' => $customerId,
            'customer_name' => 'Asset Match Customer',
            'phone' => '9000000091',
            'product_id' => $productId,
            'quantity' => 1,
            'start_date' => '2026-06-12',
            'end_date' => '2026-06-18',
            'rental_amount' => 450,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
            'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_IN_HOUSE,
            'delivery_responsibility' => 'ph_internal_delivery',
            'pickup_responsibility' => 'ph_internal_pickup',
        ], $overrides);
    }
    private function trackedRentalProduct(int $organizationId): Product
    {
        return Product::create([
            'organization_id' => $organizationId,
            'name' => 'Oxygen Concentrator Rental Stock',
            'product_type' => Product::TYPE_BOTH,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
            'price_per_day' => 450,
            'sale_price' => 25000,
            'rental_price' => 450,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);
    }

    private function bulkAssetProductAndWarehouse(int $organizationId): array
    {
        $warehouse = Warehouse::create([
            'organization_id' => $organizationId,
            'name' => 'Bulk Helper Warehouse',
            'code' => 'BHW',
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $organizationId,
            'name' => 'Bulk Helper Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'price_per_day' => 250,
            'sale_price' => 0,
            'rental_price' => 250,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        return [$product, $warehouse];
    }
}
