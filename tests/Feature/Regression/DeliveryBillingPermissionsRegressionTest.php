<?php

namespace Tests\Feature\Regression;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalAsset;
use App\Models\RentalItem;
use App\Models\SaleInventory;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestData;
use Tests\TestCase;

class DeliveryBillingPermissionsRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'proof.storage_disk' => 'local',
            'proof.image_max_kb' => 100,
            'proof.image_target_kb' => 50,
            'proof.image_max_dimension' => 1024,
        ]);
    }

    public function test_partial_pickup_moves_tracked_rental_assets_to_awaiting_verification(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Pickup Customer',
            'phone' => '8888888888',
        ]);

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Rental Warehouse',
            'code' => 'RNT',
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'CPAP Machine',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'price_per_day' => 1000,
            'sale_price' => 0,
            'rental_price' => 1000,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $asset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Rental Asset',
            'serial_number' => 'PICKUP-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_RENTED,
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'dispatch_warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'rental_amount' => 1000,
            'status' => 'active',
        ]);

        $item = RentalItem::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'product_id' => $product->id,
            'asset_ids' => [$asset->id],
            'quantity' => 1,
            'delivered_quantity' => 1,
            'returned_quantity' => 0,
            'unit_rental_amount' => 1000,
            'line_total' => 1000,
        ]);

        RentalAsset::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'asset_id' => $asset->id,
            'assigned_at' => now()->subDay(),
        ]);

        $pickup = Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'scheduled_at' => now(),
            'status' => 'pending',
            'notes' => 'Pickup task',
        ]);

        $response = $this->from(route('rentals.show', $rental))->put(route('deliveries.partial_pickup', $pickup), [
            'rental_item_id' => $item->id,
            'quantity' => 1,
            'notes' => 'Picked up one asset',
        ]);

        $response->assertRedirect(route('rentals.show', $rental));

        $this->assertSame(1, $item->fresh()->returned_quantity);
        $this->assertSame('in_progress', $pickup->fresh()->status);
        $this->assertSame(Asset::STATUS_AWAITING_VERIFICATION, $asset->fresh()->asset_status);
        $this->assertNotNull(
            RentalAsset::query()->where('rental_id', $rental->id)->where('asset_id', $asset->id)->first()?->returned_at
        );
    }

    public function test_marking_delivery_in_progress_reserves_tracked_rental_assets(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Reserve Customer',
            'phone' => '8888888890',
        ]);

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Reserve Warehouse',
            'code' => 'RSV',
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'BiPAP Machine',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'price_per_day' => 1200,
            'sale_price' => 0,
            'rental_price' => 1200,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $asset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Reserve Asset',
            'serial_number' => 'RESERVE-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => 'available',
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'dispatch_warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'rental_amount' => 1200,
            'status' => 'active',
        ]);

        RentalItem::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'product_id' => $product->id,
            'asset_ids' => [$asset->id],
            'quantity' => 1,
            'delivered_quantity' => 0,
            'returned_quantity' => 0,
            'unit_rental_amount' => 1200,
            'line_total' => 1200,
        ]);

        RentalAsset::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'asset_id' => $asset->id,
            'assigned_at' => now(),
        ]);

        $delivery = Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'scheduled_at' => now(),
            'status' => 'pending',
            'notes' => 'Reserve task',
        ]);

        $response = $this->from(route('deliveries.show', $delivery))->put(
            route('deliveries.in_progress', $delivery),
            $this->startCapturePayload()
        );

        $response->assertRedirect(route('deliveries.show', $delivery));
        $this->assertSame('in_progress', $delivery->fresh()->status);
        $this->assertSame('reserved', $asset->fresh()->asset_status);
    }

    public function test_marking_pickup_completed_releases_remaining_assets_and_returns_rental(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Pickup Complete Customer',
            'phone' => '8888888891',
        ]);

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Pickup Complete Warehouse',
            'code' => 'PCK',
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Ventilator',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'price_per_day' => 2000,
            'sale_price' => 0,
            'rental_price' => 2000,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $asset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Pickup Complete Asset',
            'serial_number' => 'PICK-COMP-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_RENTED,
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'dispatch_warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'rental_amount' => 2000,
            'status' => 'active',
        ]);

        $item = RentalItem::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'product_id' => $product->id,
            'asset_ids' => [$asset->id],
            'quantity' => 1,
            'delivered_quantity' => 1,
            'returned_quantity' => 0,
            'unit_rental_amount' => 2000,
            'line_total' => 2000,
        ]);

        RentalAsset::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'asset_id' => $asset->id,
            'assigned_at' => now()->subDay(),
        ]);

        $pickup = Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'scheduled_at' => now(),
            'status' => 'in_progress',
            'notes' => 'Pickup complete task',
        ]);

        $response = $this->from(route('deliveries.show', $pickup))->put(route('deliveries.complete', $pickup), [
            ...$this->completionCapturePayload('pickup'),
            'confirm_partial' => 1,
        ]);

        $response->assertRedirect(route('deliveries.show', $pickup));

        $this->assertSame('completed', $pickup->fresh()->status);
        $this->assertSame(1, $item->fresh()->returned_quantity);
        $this->assertSame('returned', $rental->fresh()->status);
        $this->assertNotNull($rental->fresh()->returned_at);
        $this->assertSame(Asset::STATUS_AWAITING_VERIFICATION, $asset->fresh()->asset_status);
        $this->assertNotNull(
            RentalAsset::query()->where('rental_id', $rental->id)->where('asset_id', $asset->id)->first()?->returned_at
        );
    }

    public function test_marking_rental_delivery_completed_keeps_asset_statuses_synced(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Delivery Complete Customer',
            'phone' => '8888888892',
        ]);

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Delivery Complete Warehouse',
            'code' => 'DCP',
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Delivery Complete Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'price_per_day' => 1800,
            'sale_price' => 0,
            'rental_price' => 1800,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $asset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Delivery Complete Asset',
            'serial_number' => 'DEL-COMP-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_RENTED,
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'dispatch_warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'rental_amount' => 1800,
            'status' => 'active',
        ]);

        RentalItem::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'product_id' => $product->id,
            'asset_ids' => [$asset->id],
            'quantity' => 1,
            'delivered_quantity' => 1,
            'returned_quantity' => 0,
            'unit_rental_amount' => 1800,
            'line_total' => 1800,
        ]);

        RentalAsset::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'asset_id' => $asset->id,
            'assigned_at' => now(),
        ]);

        $delivery = Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'scheduled_at' => now(),
            'status' => 'in_progress',
            'notes' => 'Delivery completion task',
        ]);

        $response = $this->from(route('deliveries.show', $delivery))->put(
            route('deliveries.complete', $delivery),
            $this->completionCapturePayload('delivery')
        );

        $response->assertRedirect(route('deliveries.show', $delivery));
        $this->assertSame('completed', $delivery->fresh()->status);
        $this->assertSame(Asset::STATUS_RENTED, $asset->fresh()->asset_status);
    }

    public function test_delivery_detail_prefers_delivered_status_and_pickup_not_assigned_copy(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Delivery Detail Customer',
            'phone' => '8888888899',
        ]);

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Delivery Detail Warehouse',
            'code' => 'DDW',
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator 5 LP',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'price_per_day' => 1800,
            'sale_price' => 0,
            'rental_price' => 1800,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $asset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Delivery Detail Asset',
            'serial_number' => 'DD-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_RENTED,
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'dispatch_warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'rental_amount' => 1800,
            'status' => 'active',
        ]);

        RentalItem::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'product_id' => $product->id,
            'asset_ids' => [$asset->id],
            'quantity' => 1,
            'delivered_quantity' => 1,
            'returned_quantity' => 0,
            'unit_rental_amount' => 1800,
            'line_total' => 1800,
        ]);

        RentalAsset::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'asset_id' => $asset->id,
            'assigned_at' => now()->subHour(),
        ]);

        $delivery = Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'scheduled_at' => now(),
            'status' => 'in_progress',
            'notes' => 'Delivery detail wording task',
        ]);

        $response = $this->get(route('deliveries.show', $delivery));

        $response->assertOk();
        $response->assertSee('>Delivered<', false);
        $response->assertDontSee('>In Progress<', false);
        $response->assertSeeText('Pickup Not Assigned');
        $response->assertDontSeeText('Pickup Pending');
        $response->assertDontSee('>Completed<', false);
        $response->assertDontSee('>Pending<', false);
    }

    public function test_delivery_index_prefers_delivered_status_badge_for_fully_delivered_rental(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Delivery Index Customer',
            'phone' => '8888888800',
        ]);

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Delivery Index Warehouse',
            'code' => 'DIW',
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'BiPAP Machine',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'price_per_day' => 2200,
            'sale_price' => 0,
            'rental_price' => 2200,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $asset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Delivery Index Asset',
            'serial_number' => 'DI-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_RENTED,
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'dispatch_warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'rental_amount' => 2200,
            'status' => 'active',
        ]);

        RentalItem::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'product_id' => $product->id,
            'asset_ids' => [$asset->id],
            'quantity' => 1,
            'delivered_quantity' => 1,
            'returned_quantity' => 0,
            'unit_rental_amount' => 2200,
            'line_total' => 2200,
        ]);

        RentalAsset::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'asset_id' => $asset->id,
            'assigned_at' => now()->subHour(),
        ]);

        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'scheduled_at' => now(),
            'status' => 'in_progress',
            'notes' => 'Delivery index wording task',
        ]);

        $response = $this->get(route('deliveries.index'));

        $response->assertOk();
        $response->assertSee('>Delivered<', false);
        $response->assertDontSee('class="rn-badge rn-badge-active">In Progress</span>', false);
        $response->assertSeeText('1/1 delivered');
    }

    public function test_delivery_index_excludes_completed_pickups_from_pending_widget_and_hides_complete_action(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Pickup Widget Customer',
            'phone' => '8888888801',
        ]);

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Pickup Widget Warehouse',
            'code' => 'PWW',
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Suction Machine',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'price_per_day' => 1900,
            'sale_price' => 0,
            'rental_price' => 1900,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $asset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Pickup Widget Asset',
            'serial_number' => 'PW-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AWAITING_VERIFICATION,
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'dispatch_warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'start_date' => now()->subDays(4)->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
            'rental_amount' => 1900,
            'status' => 'returned',
            'returned_at' => now()->subHour(),
        ]);

        RentalItem::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'product_id' => $product->id,
            'asset_ids' => [$asset->id],
            'quantity' => 1,
            'delivered_quantity' => 1,
            'returned_quantity' => 1,
            'unit_rental_amount' => 1900,
            'line_total' => 1900,
        ]);

        RentalAsset::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'asset_id' => $asset->id,
            'assigned_at' => now()->subDays(2),
            'returned_at' => now()->subHour(),
        ]);

        $pickup = Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'scheduled_at' => now(),
            'status' => 'in_progress',
            'notes' => 'Pickup widget wording task',
        ]);

        $response = $this->get(route('deliveries.index'));

        $response->assertOk();
        $response->assertSee('>Picked Up<', false);
        $response->assertSeeText('No pending pickups');
        $response->assertDontSee('title="Complete task"', false);
        $response->assertDontSee('title="Complete partial task"', false);
    }

    public function test_delivery_index_shows_serial_and_select_all_checkbox_markup(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Board Selection Customer',
            'phone' => '8888888802',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Board Selection Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 1800,
            'sale_price' => 0,
            'rental_price' => 1800,
            'available_quantity' => 5,
            'total_quantity' => 5,
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'rental_amount' => 1800,
            'status' => 'active',
        ]);

        RentalItem::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'delivered_quantity' => 0,
            'returned_quantity' => 0,
            'unit_rental_amount' => 1800,
            'line_total' => 1800,
        ]);

        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'scheduled_at' => now(),
            'status' => 'pending',
            'notes' => 'Board selection task',
        ]);

        $response = $this->get(route('deliveries.index'));

        $response->assertOk()
            ->assertSee('id="deliverySelectAll"', false)
            ->assertSee('class="ops-task-checkbox"', false);

        $this->assertMatchesRegularExpression(
            '/<td class="ops-col-serial ops-serial-cell" data-label="No\.">\s*1\s*<\/td>/',
            $response->getContent()
        );
    }

    public function test_marking_sale_delivery_completed_marks_sale_asset_sold(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Sale Delivery Customer',
            'phone' => '8888888893',
        ]);

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Sale Delivery Warehouse',
            'code' => 'SDL',
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Sale Delivery Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_SALE,
            'price_per_day' => 0,
            'rental_price' => 0,
            'sale_price' => 4000,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $asset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Sale Delivery Asset',
            'serial_number' => 'SALE-COMP-001',
            'asset_stage' => Asset::STAGE_NEW_STOCK,
            'condition_status' => 'good',
            'asset_status' => 'reserved',
        ]);

        $sale = Sale::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'asset_id' => $asset->id,
            'quantity' => 1,
            'sale_date' => now()->toDateString(),
            'sale_amount' => 4000,
            'payment_status' => 'pending',
            'status' => 'completed',
        ]);

        $delivery = Delivery::create([
            'organization_id' => $organization->id,
            'sale_id' => $sale->id,
            'type' => 'delivery',
            'scheduled_at' => now(),
            'status' => 'in_progress',
            'notes' => 'Sale delivery completion task',
        ]);

        $response = $this->from(route('deliveries.show', $delivery))->put(
            route('deliveries.complete', $delivery),
            $this->completionCapturePayload('delivery')
        );

        $response->assertRedirect(route('deliveries.show', $delivery));
        $this->assertSame('completed', $delivery->fresh()->status);
        $this->assertSame('sold', $asset->fresh()->asset_status);
    }

    public function test_delivery_user_can_view_same_org_task_but_only_update_assigned_task(): void
    {
        $organization = TestData::organization();
        $assignedUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
            'email' => 'assigned-delivery@example.com',
        ]);
        $otherUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
            'email' => 'other-delivery@example.com',
        ]);

        $ownDelivery = Delivery::create([
            'organization_id' => $organization->id,
            'type' => 'delivery',
            'scheduled_at' => now(),
            'status' => 'pending',
            'assigned_user_id' => $assignedUser->id,
            'notes' => 'Own task',
        ]);

        $otherDelivery = Delivery::create([
            'organization_id' => $organization->id,
            'type' => 'delivery',
            'scheduled_at' => now(),
            'status' => 'pending',
            'assigned_user_id' => $otherUser->id,
            'notes' => 'Other task',
        ]);

        $this->actingAs($assignedUser)
            ->get(route('deliveries.show', $ownDelivery))
            ->assertOk();

        $this->actingAs($assignedUser)
            ->get(route('deliveries.show', $otherDelivery))
            ->assertOk();

        $this->actingAs($assignedUser)
            ->from(route('deliveries.show', $ownDelivery))
            ->put(route('deliveries.in_progress', $ownDelivery), $this->startCapturePayload())
            ->assertRedirect(route('deliveries.show', $ownDelivery));

        $this->assertSame('in_progress', $ownDelivery->fresh()->status);

        $this->actingAs($assignedUser)
            ->put(route('deliveries.in_progress', $otherDelivery))
            ->assertForbidden();
    }

    public function test_cross_organization_delivery_access_is_denied(): void
    {
        $organization = TestData::organization();
        $otherOrganization = TestData::organization();
        $user = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $foreignDelivery = Delivery::create([
            'organization_id' => $otherOrganization->id,
            'type' => 'delivery',
            'scheduled_at' => now(),
            'status' => 'pending',
            'notes' => 'Foreign task',
        ]);

        $this->actingAs($user)
            ->get(route('deliveries.show', $foreignDelivery))
            ->assertForbidden();
    }

    public function test_taskboard_shows_view_but_hides_update_actions_for_unassigned_delivery_user(): void
    {
        $organization = TestData::organization();
        $assignedUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
            'email' => 'taskboard-view-only@example.com',
        ]);
        $otherUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
            'email' => 'taskboard-owner@example.com',
        ]);

        $delivery = Delivery::create([
            'organization_id' => $organization->id,
            'type' => 'delivery',
            'scheduled_at' => now(),
            'status' => 'pending',
            'assigned_user_id' => $otherUser->id,
            'notes' => 'Visible but not editable',
        ]);

        $response = $this->actingAs($assignedUser)
            ->get(route('deliveries.index'));

        $response->assertOk()
            ->assertSee(route('deliveries.show', $delivery), false)
            ->assertDontSee(route('deliveries.edit', $delivery), false)
            ->assertDontSee(route('deliveries.in_progress', $delivery), false)
            ->assertDontSee(route('deliveries.complete', $delivery), false);
    }

    public function test_delivery_team_can_cancel_allowed_pending_delivery_with_reason(): void
    {
        $organization = TestData::organization();
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
            'email' => 'cancel-delivery@example.com',
        ]);

        $delivery = Delivery::create([
            'organization_id' => $organization->id,
            'type' => 'delivery',
            'scheduled_at' => now(),
            'status' => 'pending',
            'assigned_user_id' => $deliveryUser->id,
            'notes' => 'Cancelable task',
        ]);

        $response = $this->actingAs($deliveryUser)
            ->from(route('deliveries.show', $delivery))
            ->put(route('deliveries.cancel', $delivery), [
                'cancellation_reason' => 'customer_unavailable',
                'cancellation_notes' => 'Customer asked to retry tomorrow.',
            ]);

        $response->assertRedirect(route('deliveries.show', $delivery));

        $delivery->refresh();
        $this->assertSame('cancelled', $delivery->status);
        $this->assertSame('customer_unavailable', $delivery->cancellation_reason);
        $this->assertSame('Customer asked to retry tomorrow.', $delivery->cancellation_notes);

        $this->actingAs($deliveryUser)
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->assertSeeText('Cancellation Reason')
            ->assertSeeText('Customer unavailable')
            ->assertSeeText('Customer asked to retry tomorrow.');
    }

    public function test_cancellation_without_reason_fails_validation(): void
    {
        $organization = TestData::organization();
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $delivery = Delivery::create([
            'organization_id' => $organization->id,
            'type' => 'pickup',
            'scheduled_at' => now(),
            'status' => 'pending',
            'assigned_user_id' => $deliveryUser->id,
            'notes' => 'Needs cancellation reason',
        ]);

        $this->actingAs($deliveryUser)
            ->from(route('deliveries.show', $delivery))
            ->put(route('deliveries.cancel', $delivery), [
                'cancellation_reason' => '',
            ])
            ->assertRedirect(route('deliveries.show', $delivery))
            ->assertSessionHasErrors('cancellation_reason');

        $this->assertSame('pending', $delivery->fresh()->status);
    }

    public function test_cancelled_task_is_excluded_from_pending_counts_and_not_counted_as_completed(): void
    {
        $organization = TestData::organization();
        $admin = TestData::user($organization);
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $pendingDelivery = Delivery::create([
            'organization_id' => $organization->id,
            'type' => 'delivery',
            'scheduled_at' => now()->addHour(),
            'status' => 'pending',
            'assigned_user_id' => $deliveryUser->id,
        ]);

        Delivery::create([
            'organization_id' => $organization->id,
            'type' => 'delivery',
            'scheduled_at' => now()->subHour(),
            'status' => 'completed',
            'assigned_user_id' => $deliveryUser->id,
            'completed_at' => now()->subMinutes(30),
        ]);

        $this->actingAs($deliveryUser)
            ->put(route('deliveries.cancel', $pendingDelivery), [
                'cancellation_reason' => 'payment_issue',
            ])
            ->assertRedirect(route('deliveries.show', $pendingDelivery));

        $board = $this->actingAs($admin)->get(route('deliveries.index'));
        $board->assertOk();
        $this->assertSame(0, (int) $board->viewData('pendingDeliveryCount'));
        $this->assertSame(1, (int) $board->viewData('completedDeliveryCount'));
    }

    public function test_cross_org_or_completed_tasks_cannot_be_cancelled_by_delivery_team(): void
    {
        $organization = TestData::organization();
        $otherOrganization = TestData::organization();
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $completedDelivery = Delivery::create([
            'organization_id' => $organization->id,
            'type' => 'delivery',
            'scheduled_at' => now(),
            'status' => 'completed',
            'assigned_user_id' => $deliveryUser->id,
            'completed_at' => now(),
        ]);

        $foreignDelivery = Delivery::create([
            'organization_id' => $otherOrganization->id,
            'type' => 'delivery',
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);

        $this->actingAs($deliveryUser)
            ->from(route('deliveries.show', $completedDelivery))
            ->put(route('deliveries.cancel', $completedDelivery), [
                'cancellation_reason' => 'wrong_address',
            ])
            ->assertRedirect(route('deliveries.show', $completedDelivery))
            ->assertSessionHas('error');

        $this->assertSame('completed', $completedDelivery->fresh()->status);

        $this->actingAs($deliveryUser)
            ->put(route('deliveries.cancel', $foreignDelivery), [
                'cancellation_reason' => 'wrong_address',
            ])
            ->assertForbidden();
    }

    public function test_invoice_payment_status_and_balances_sync_from_payments(): void
    {
        $organization = TestData::organization();

        $invoice = Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-TEST-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'subtotal' => 1000,
            'discount_amount' => 0,
            'deposit_amount' => 0,
            'shipping_charges' => 0,
            'taxable_amount' => 1000,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'igst_amount' => 0,
            'total_tax_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'balance_amount' => 1000,
        ]);

        $invoice->syncFinancialStatus();
        $this->assertSame('overdue', $invoice->fresh()->payment_status);

        Payment::create([
            'organization_id' => $organization->id,
            'customer_id' => null,
            'invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'amount' => 400,
            'payment_method' => 'upi',
        ]);

        $invoice->refresh()->syncFinancialStatus();
        $this->assertSame('partial', $invoice->payment_status);
        $this->assertSame(400.0, (float) $invoice->paid_amount);
        $this->assertSame(600.0, (float) $invoice->balance_amount);

        Payment::create([
            'organization_id' => $organization->id,
            'customer_id' => null,
            'invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'amount' => 600,
            'payment_method' => 'bank_transfer',
        ]);

        $invoice->refresh()->syncFinancialStatus();
        $this->assertSame('paid', $invoice->payment_status);
        $this->assertSame(1000.0, (float) $invoice->paid_amount);
        $this->assertSame(0.0, (float) $invoice->balance_amount);
    }

    public function test_rental_payment_can_be_added_when_invoice_has_direct_rental_link(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Direct Rental Customer',
            'phone' => '9000000001',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Direct Linked Rental Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 1,
            'total_quantity' => 1,
            'price_per_day' => 1500,
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'rental_amount' => 1500,
            'status' => 'active',
        ]);

        $invoice = Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-DIRECT-RENT-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'rental_id' => $rental->id,
            'bill_to_name' => $customer->name,
            'bill_to_phone' => $customer->phone,
            'tax_type' => 'exclusive',
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'subtotal' => 1500,
            'taxable_amount' => 1500,
            'total_amount' => 1500,
            'paid_amount' => 0,
            'balance_amount' => 1500,
        ]);

        $response = $this->post(route('payments.store', $rental), [
            'payment_date' => now()->toDateString(),
            'amount' => 1500,
            'payment_method' => 'cash',
        ]);

        $response->assertRedirect(route('rentals.show', $rental));

        $payment = Payment::firstOrFail();
        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertSame($rental->id, $payment->rental_id);
        $this->assertSame('paid', $invoice->fresh()->payment_status);
        $this->assertSame(0.0, (float) $invoice->fresh()->balance_amount);
    }

    public function test_rental_payment_can_be_added_when_only_legacy_invoice_item_link_exists(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Legacy Rental Customer',
            'phone' => '9000000002',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Legacy Linked Rental Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 1,
            'total_quantity' => 1,
            'price_per_day' => 1200,
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'rental_amount' => 1200,
            'status' => 'active',
        ]);

        $invoice = Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-LEGACY-RENT-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'bill_to_name' => $customer->name,
            'bill_to_phone' => $customer->phone,
            'tax_type' => 'exclusive',
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'subtotal' => 1200,
            'taxable_amount' => 1200,
            'total_amount' => 1200,
            'paid_amount' => 0,
            'balance_amount' => 1200,
        ]);

        $invoice->items()->create([
            'product_id' => $product->id,
            'source_type' => 'rental',
            'source_id' => $rental->id,
            'description' => $product->name,
            'quantity' => 1,
            'unit' => 'rental',
            'rate' => 1200,
            'taxable_amount' => 1200,
            'line_total' => 1200,
        ]);

        $response = $this->post(route('payments.store', $rental), [
            'payment_date' => now()->toDateString(),
            'amount' => 600,
            'payment_method' => 'upi',
        ]);

        $response->assertRedirect(route('rentals.show', $rental));

        $payment = Payment::firstOrFail();
        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertSame('partial', $invoice->fresh()->payment_status);
        $this->assertSame(600.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame(600.0, (float) $invoice->fresh()->balance_amount);
    }

    public function test_invoice_payment_can_be_added_when_invoice_has_direct_sale_link(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Direct Sale Customer',
            'phone' => '9000000003',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Direct Sale Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 1,
            'total_quantity' => 1,
            'price_per_day' => 0,
            'sale_price' => 2500,
        ]);

        $sale = Sale::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'sale_date' => now()->toDateString(),
            'sale_amount' => 2500,
            'payment_status' => 'pending',
            'status' => 'completed',
        ]);

        $invoice = Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-DIRECT-SALE-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'bill_to_name' => $customer->name,
            'bill_to_phone' => $customer->phone,
            'tax_type' => 'exclusive',
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'subtotal' => 2500,
            'taxable_amount' => 2500,
            'total_amount' => 2500,
            'paid_amount' => 0,
            'balance_amount' => 2500,
        ]);

        $response = $this->post(route('invoices.payments.store', $invoice), [
            'payment_date' => now()->toDateString(),
            'amount' => 2500,
            'payment_method' => 'bank_transfer',
        ]);

        $response->assertRedirect(route('invoices.show', $invoice->id));

        $payment = Payment::firstOrFail();
        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertNull($payment->rental_id);
        $this->assertSame('paid', $invoice->fresh()->payment_status);
        $this->assertSame(0.0, (float) $invoice->fresh()->balance_amount);
    }

    public function test_inventory_dashboard_widgets_use_separated_sale_and_rental_counts(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization, [
            'email' => 'inventory-admin@example.com',
        ]));

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Widget Warehouse',
            'code' => 'WGT',
            'is_active' => true,
        ]);

        $sellable = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Sale Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_SALE,
            'price_per_day' => 0,
            'sale_price' => 500,
            'rental_price' => 0,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $rentable = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Rental Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'price_per_day' => 250,
            'sale_price' => 0,
            'rental_price' => 250,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $sellable->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Sale Asset',
            'serial_number' => 'INV-SALE-001',
            'asset_stage' => Asset::STAGE_NEW_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE_FOR_SALE,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $rentable->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Rental Asset Available',
            'serial_number' => 'INV-RENT-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $rentable->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Rental Asset Rented',
            'serial_number' => 'INV-RENT-002',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_RENTED,
        ]);

        SaleInventory::syncFromSaleUnits($organization->id, $sellable->id);

        $response = $this->get(route('inventory.dashboard'));

        $response->assertOk()
            ->assertViewHas('dashboard', function (array $dashboard) {
                return $dashboard['total_products'] === 2
                    && $dashboard['sellable_products'] === 1
                    && $dashboard['rentable_products'] === 1
                    && $dashboard['sale_stock'] === 1
                    && $dashboard['total_assets'] === 2
                    && $dashboard['available_assets'] === 1
                    && $dashboard['rented_assets'] === 1;
            });
    }

    public function test_inventory_dashboard_product_stock_position_uses_opening_quantity_for_untracked_products(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization, [
            'email' => 'inventory-untracked@example.com',
        ]));

        Product::create([
            'organization_id' => $organization->id,
            'name' => 'BiPAP Disposable Filter',
            'category' => 'Consumables',
            'brand' => 'ResMed',
            'model_name' => 'Filter Pack',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 0,
            'sale_price' => 180,
            'rental_price' => null,
            'available_quantity' => 1,
            'total_quantity' => 1,
        ]);

        $response = $this->get(route('inventory.dashboard'));

        $response->assertOk()
            ->assertSee('Product Stock Position')
            ->assertSee('untracked products use the opening quantity from Product Master')
            ->assertSee('BiPAP Disposable Filter')
            ->assertSee('Untracked Opening Stock')
            ->assertSeeInOrder(['Sale Units', 'Available to Sell'], false)
            ->assertSeeInOrder(['BiPAP Disposable Filter', '>1<', '>1<'], false);
    }

    public function test_permissions_and_mobile_critical_routes_do_not_error(): void
    {
        $organization = TestData::organization();

        $restrictedUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);
        $this->actingAs($restrictedUser);

        $this->get(route('products.index'))
            ->assertRedirect($restrictedUser->defaultRedirectPath());

        $this->get(route('deliveries.assigned'))
            ->assertOk();

        $admin = TestData::user($organization, [
            'email' => 'mobile-admin@example.com',
        ]);

        $headers = [
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        ];

        $routes = [
            route('inventory.dashboard'),
            route('products.index'),
            route('assets.index'),
            route('assets.pending-verification'),
            route('rentals.index'),
            route('rentals.create'),
            route('sales.index'),
            route('sales.create'),
            route('deliveries.index'),
            route('pickups.index'),
            route('customers.index'),
            route('invoices.index'),
        ];

        if (DB::getDriverName() !== 'sqlite') {
            array_unshift($routes, route('dashboard'));
        }

        foreach ($routes as $url) {
            $response = $this->withHeaders($headers)
                ->actingAs($admin)
                ->get($url);

            $this->assertNotSame(500, $response->getStatusCode(), 'Route failed on mobile smoke check: ' . $url);
        }
    }

    private function startCapturePayload(): array
    {
        return [
            'workflow_capture_form' => '1',
            'location_latitude' => '12.971599',
            'location_longitude' => '77.594566',
            'location_accuracy' => '15.4',
            'location_captured_at' => now()->toIso8601String(),
        ];
    }

    private function completionCapturePayload(string $type = 'delivery'): array
    {
        $base = [
            'workflow_capture_form' => '1',
            'signature_data' => $this->signatureDataUrl(),
            'location_missing_reason' => 'Indoor coverage blocked GPS at this step.',
            'proof_notes' => 'Captured during regression test.',
        ];

        if ($type === 'pickup') {
            return array_merge($base, [
                'pickup_device_photos' => [
                    $this->fakeImageUpload('pickup-device.jpg', 40),
                ],
            ]);
        }

        return array_merge($base, [
            'delivery_device_photos' => [
                $this->fakeImageUpload('delivery-device.jpg', 40),
            ],
            'premises_photo' => $this->fakeImageUpload('premises.jpg', 40),
        ]);
    }

    private function signatureDataUrl(): string
    {
        return 'data:image/png;base64,' . base64_encode(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAECAIAAADJUWIXAAAAGElEQVQImWNgoBpgYGBg+A8jGEmBgYGBAQAAegQF4g1r0cQAAAAASUVORK5CYII=',
            true
        ));
    }

    private function fakeImageUpload(string $name, int $sizeKb): UploadedFile
    {
        $tinyPng = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO0pJ2sAAAAASUVORK5CYII=',
            true
        );

        $targetBytes = max($sizeKb * 1024, strlen($tinyPng));
        $padding = max($targetBytes - strlen($tinyPng), 0);

        return UploadedFile::fake()->createWithContent($name, $tinyPng . str_repeat(' ', $padding));
    }
}
