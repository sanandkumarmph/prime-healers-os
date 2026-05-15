<?php

namespace Tests\Feature\Regression;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalAsset;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class RentalAvailabilityConsistencyRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_master_and_rental_create_share_same_rental_ready_count(): void
    {
        [, $product] = $this->seedRentalAvailabilityContext();

        $productsIndex = $this->get(route('products.index'));
        $productsIndex->assertOk();

        $catalogProduct = $productsIndex->viewData('products')->getCollection()->firstWhere('id', $product->id);
        $this->assertNotNull($catalogProduct);
        $this->assertSame(2, (int) ($catalogProduct->available_assets_count ?? 0));

        $rentalCreate = $this->get(route('rentals.create'));
        $rentalCreate->assertOk();

        $rentalProduct = collect($rentalCreate->viewData('rentalProducts'))->firstWhere('id', $product->id);
        $this->assertNotNull($rentalProduct);
        $this->assertSame(2, (int) ($rentalProduct->rental_available_quantity ?? 0));
        $this->assertSame('Rental Available 2', (string) $rentalProduct->rental_dropdown_label);
    }

    public function test_rental_available_assets_endpoint_respects_any_and_specific_warehouse(): void
    {
        [, $product, $warehouseA, $warehouseB, $readyAssetA, $readyAssetB] = $this->seedRentalAvailabilityContext();

        $allResponse = $this->getJson(route('rentals.available-assets', [
            'product_id' => $product->id,
        ]));

        $allResponse->assertOk();
        $this->assertEqualsCanonicalizing(
            [$readyAssetA->id, $readyAssetB->id],
            collect($allResponse->json('data'))->pluck('id')->all()
        );

        $warehouseResponse = $this->getJson(route('rentals.available-assets', [
            'product_id' => $product->id,
            'dispatch_warehouse_id' => $warehouseA->id,
        ]));

        $warehouseResponse->assertOk();
        $this->assertSame(
            [$readyAssetA->id],
            collect($warehouseResponse->json('data'))->pluck('id')->all()
        );

        $warehouseBResponse = $this->getJson(route('rentals.available-assets', [
            'product_id' => $product->id,
            'dispatch_warehouse_id' => $warehouseB->id,
        ]));

        $warehouseBResponse->assertOk();
        $this->assertSame(
            [$readyAssetB->id],
            collect($warehouseBResponse->json('data'))->pluck('id')->all()
        );
    }

    public function test_rental_ready_counts_ignore_stale_assignments_and_non_ready_assets_using_product_id_only(): void
    {
        [$organization, $product] = $this->seedRentalAvailabilityContext();

        $lookalikeProduct = Product::create([
            'organization_id' => $organization->id,
            'name' => 'hospital bed',
            'brand' => 'kraft',
            'model_name' => '5 FUNCTION MANUAL',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'sale_price' => 0,
            'rental_price' => 650,
            'price_per_day' => 650,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $createResponse = $this->get(route('rentals.create'));
        $createResponse->assertOk();

        $rentalProducts = collect($createResponse->viewData('rentalProducts'));
        $primaryProduct = $rentalProducts->firstWhere('id', $product->id);
        $lookalike = $rentalProducts->firstWhere('id', $lookalikeProduct->id);

        $this->assertSame(2, (int) ($primaryProduct->rental_available_quantity ?? 0));
        $this->assertSame('Rental Available 2', (string) $primaryProduct->rental_dropdown_label);
        $this->assertSame(0, (int) ($lookalike->rental_available_quantity ?? 0));
        $this->assertSame('No rental assets available', (string) $lookalike->rental_dropdown_label);
    }

    public function test_rental_create_customer_select_uses_utf8_bullet_label(): void
    {
        $organization = TestData::organization(['name' => 'UTF Customer Org']);
        $this->actingAs(TestData::user($organization, ['email' => 'utf-customer@example.com']));

        Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Amit Iyer',
            'phone' => '+919739886843',
            'state' => 'Karnataka',
        ]);

        $response = $this->get(route('rentals.create'));

        $response->assertOk()
            ->assertSee('Amit Iyer • +919739886843')
            ->assertDontSee('Amit Iyer â€¢ +919739886843');
    }

    private function seedRentalAvailabilityContext(): array
    {
        $organization = TestData::organization(['name' => 'Rental Availability Org']);
        $this->actingAs(TestData::user($organization, ['email' => 'rental-availability@example.com']));

        $warehouseA = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Bengaluru Main',
            'code' => 'BLR',
            'is_active' => true,
        ]);

        $warehouseB = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Bengaluru East',
            'code' => 'BLRE',
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Hospital Bed Manual',
            'brand' => 'Kraft',
            'model_name' => '5 Function',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'sale_price' => 0,
            'rental_price' => 650,
            'price_per_day' => 650,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $readyAssetA = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouseA->id,
            'asset_name' => 'Hospital Bed A',
            'serial_number' => 'HB-A-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        $readyAssetB = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouseB->id,
            'asset_name' => 'Hospital Bed B',
            'serial_number' => 'HB-B-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouseA->id,
            'asset_name' => 'Hospital Bed Stale Assigned',
            'serial_number' => 'HB-A-STALE',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouseA->id,
            'asset_name' => 'Hospital Bed Maintenance',
            'serial_number' => 'HB-A-MAINT',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_MAINTENANCE,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouseA->id,
            'asset_name' => 'Hospital Bed Repair',
            'serial_number' => 'HB-A-REPAIR',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'repair',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Assigned Customer',
            'phone' => '9000000011',
            'state' => 'Karnataka',
        ]);

        $staleRental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-10',
            'status' => 'active',
            'rental_amount' => 650,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
        ]);

        $staleAsset = Asset::query()
            ->where('organization_id', $organization->id)
            ->where('serial_number', 'HB-A-STALE')
            ->firstOrFail();

        RentalAsset::create([
            'organization_id' => $organization->id,
            'rental_id' => $staleRental->id,
            'asset_id' => $staleAsset->id,
            'assigned_at' => now(),
            'returned_at' => null,
        ]);

        $otherOrganization = TestData::organization(['name' => 'Other Rental Availability Org']);
        Asset::create([
            'organization_id' => $otherOrganization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouseA->id,
            'asset_name' => 'Cross Org Asset',
            'serial_number' => 'HB-OTHER-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        return [$organization, $product, $warehouseA, $warehouseB, $readyAssetA, $readyAssetB];
    }
}
